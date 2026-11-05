<?php
declare(strict_types=1);

// Shop catalogue importer (no web route): CSV or ready-made records in, shop_* rows and images out.
// Used by bin/import-shop-products.php (the owner's product list) and bin/seed-shop-demo.php (a draft
// demo catalogue). The CSV format is documented at the top of bin/import-shop-products.php.
//
// How a run works: every row is checked first (errors carry the CSV line number); nothing is written
// when any row is wrong. Products are matched by slug, so re-running the same file updates instead of
// duplicating. The database part runs in one transaction (a dry run rolls it back, so its report is
// exactly what the real run would do); images are only written on a real run.
require_once __DIR__ . '/site.php';

const SHOP_IMAGE_DIR = 'storage/uploads/shop/products';
const SHOP_GALLERY_DIR = 'storage/uploads/shop/products/gallery';
const SHOP_IMAGE_MAX_SIDE = 1600;
const SHOP_IMAGE_MAX_BYTES = 12 * 1024 * 1024;
const SHOP_COLOUR_NAMES = ['black' => '#111111', 'white' => '#FFFFFF', 'navy' => '#0B2A5B', 'blue' => '#0B50A7', 'royal blue' => '#1F4FD8', 'sky blue' => '#5BB5E8',
    'orange' => '#FF7A18', 'red' => '#D62828', 'maroon' => '#7A1F2B', 'green' => '#1E8E3E', 'yellow' => '#F7C600', 'grey' => '#808080', 'gray' => '#808080',
    'pink' => '#E5477F', 'purple' => '#6B3FA0', 'brown' => '#7A4B28', 'gold' => '#C9A227', 'silver' => '#C0C0C0', 'cream' => '#F3E9D2'];
// Header aliases (after lower-casing and turning spaces into underscores) => the column name used below.
const SHOP_CSV_ALIASES = ['colors' => 'colours', 'color' => 'colours', 'colour' => 'colours', 'size' => 'sizes', 'mrp' => 'compare_price', 'compare' => 'compare_price',
    'gst' => 'gst_rate', 'gst_%' => 'gst_rate', 'short' => 'short_description', 'summary' => 'short_description', 'images' => 'gallery', 'gallery_images' => 'gallery',
    'is_active' => 'active', 'published' => 'active', 'qty' => 'stock', 'quantity' => 'stock', 'product' => 'name', 'title' => 'name', 'main_image' => 'image'];

function shopSlugify(string $text): string {
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : false;
    $text = strtolower($ascii !== false && $ascii !== '' ? $ascii : $text);
    return trim(preg_replace('~[^a-z0-9]+~', '-', $text), '-');
}

/** A category's comparison key: 'Jerseys', 'jersey' and 'JERSEY ' are the same category. */
function shopCategoryKey(string $name): string {
    return preg_replace('~s$~', '', preg_replace('~[^a-z0-9]+~', '', strtolower($name)));
}

/** The records of a CSV file: [['_line' => n, 'name' => ..., ...], ...]. Throws RuntimeException on a bad file. */
function shopImportReadCsv(string $path): array {
    $handle = @fopen($path, 'rb') ?: throw new RuntimeException("Cannot open $path");
    $first = (string) fgets($handle);
    rewind($handle);
    $first = preg_replace('~^\xEF\xBB\xBF~', '', $first);
    // Excel in many locales saves with ; or tabs.
    $delimiter = ',';
    foreach ([';', "\t"] as $candidate) if (substr_count($first, $candidate) > substr_count($first, $delimiter)) $delimiter = $candidate;
    $records = [];
    $header = null;
    $line = 0;
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $line++;
        if ($line === 1) $row[0] = preg_replace('~^\xEF\xBB\xBF~', '', (string) $row[0]);
        if ($row === [null] || trim(implode('', $row)) === '') continue;
        // Convert Windows-1252 saved by older Excel to UTF-8.
        $row = array_map(fn ($cell) => mb_check_encoding((string) $cell, 'UTF-8') ? (string) $cell : mb_convert_encoding((string) $cell, 'UTF-8', 'Windows-1252'), $row);
        if ($header === null) {
            $header = array_map(function ($name) {
                $name = preg_replace('~\s+~', '_', strtolower(trim($name)));
                return SHOP_CSV_ALIASES[$name] ?? $name;
            }, $row);
            foreach (['name', 'price'] as $required) if (!in_array($required, $header, true)) throw new RuntimeException("The CSV needs a '$required' column (found: " . implode(', ', $header) . ')');
            continue;
        }
        $record = ['_line' => $line];
        foreach ($header as $i => $name) if ($name !== '') $record[$name] = trim((string) ($row[$i] ?? ''));
        $records[] = $record;
    }
    fclose($handle);
    if ($header === null) throw new RuntimeException('The CSV is empty');
    return $records;
}

function shopImportNumber(string $value, string $label, int $line, array &$errors, bool $required = false): ?float {
    $clean = str_replace([',', '₹', 'Rs.', 'Rs', ' '], '', $value);
    if ($clean === '') {
        if ($required) $errors[] = "line $line: $label is required";
        return null;
    }
    if (!is_numeric($clean) || (float) $clean < 0) {
        $errors[] = "line $line: $label '$value' is not a valid number";
        return null;
    }
    return (float) $clean;
}

/** 'Navy:#0b2a5b|White:#fff' => [['name' => 'Navy', 'hex' => '#0B2A5B'], ...]; errors go to $errors. */
function shopImportColours(string $text, int $line, array &$errors): array {
    $colours = [];
    foreach (preg_split('~\s*\|\s*~', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $part) {
        [$name, $hex] = array_pad(array_map('trim', explode(':', $part, 2)), 2, '');
        if ($name === '') continue;
        if ($hex === '') $hex = SHOP_COLOUR_NAMES[strtolower($name)] ?? '';
        if (!preg_match('~^#?([0-9a-f]{3}|[0-9a-f]{6})$~i', $hex, $m)) {
            $errors[] = "line $line: colour '$name' needs a hex code, like $name:#0b2a5b";
            continue;
        }
        $digits = strlen($m[1]) === 3 ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] : $m[1];
        $colours[] = ['name' => mb_substr($name, 0, 64), 'hex' => '#' . strtoupper($digits)];
    }
    return $colours;
}

/**
 * Checks and groups records into products. Rows with the same slug (or the same name) are one product, so a
 * product can be one row (all colours/sizes in cells) or one row per colour/size. Returns [products, errors, warnings].
 */
function shopImportNormalise(array $records): array {
    $products = [];
    $errors = [];
    $warnings = [];
    foreach ($records as $record) {
        $line = (int) ($record['_line'] ?? 0);
        $name = trim((string) ($record['name'] ?? ''));
        if ($name === '') { $errors[] = "line $line: name is required"; continue; }
        $slug = shopSlugify((string) ($record['slug'] ?? '')) ?: shopSlugify($name);
        if ($slug === '') { $errors[] = "line $line: cannot make a slug from '$name'"; continue; }
        $product = &$products[$slug];
        $product ??= ['slug' => $slug, 'line' => $line, 'name' => $name, 'variants' => [], 'colours' => [], 'features' => [], 'specs' => [], 'gallery' => [],
            'category' => '', 'brand' => '', 'short_description' => '', 'description' => '', 'price' => null, 'compare_price' => null, 'gst_rate' => null,
            'stock' => null, 'image' => '', 'active' => null, 'has_features' => false, 'has_specs' => false, 'has_gallery' => false];
        // Product-level fields: the first non-empty value wins; a different later value is a mistake.
        foreach (['category', 'brand', 'short_description', 'description', 'image'] as $field) {
            $value = trim((string) ($record[$field] ?? ''));
            if ($value === '') continue;
            if ($field === 'category' && count(array_filter(preg_split('~\s*[>/]\s*~', $value))) > 2) $errors[] = "line $line: category '$value' is more than two levels deep (use Parent > Child)";
            if ($product[$field] === '') $product[$field] = $value;
            elseif ($product[$field] !== $value && $field !== 'description') $errors[] = "line $line: $field differs from line {$product['line']} for the same product '$slug'";
        }
        foreach (['price' => true, 'compare_price' => false, 'gst_rate' => false] as $field => $required) {
            $number = shopImportNumber((string) ($record[$field] ?? ''), $field, $line, $errors, $required && $product[$field] === null);
            if ($number === null) continue;
            if ($product[$field] === null) $product[$field] = $number;
            elseif (abs($product[$field] - $number) > 0.001) $errors[] = "line $line: $field $number differs from line {$product['line']} for '$slug' (one price per product: variants share it)";
        }
        if (($record['active'] ?? '') !== '') $product['active'] = in_array(strtolower((string) $record['active']), ['1', 'yes', 'y', 'true', 'active', 'on'], true) ? 1 : 0;
        if (($record['gallery'] ?? '') !== '') { $product['has_gallery'] = true; $product['gallery'] = array_values(array_filter(array_map('trim', explode(';', (string) $record['gallery'])))); }
        if (($record['features'] ?? '') !== '') { $product['has_features'] = true; $product['features'] = array_values(array_filter(array_map('trim', explode(';', (string) $record['features'])))); }
        if (($record['specs'] ?? '') !== '') {
            $product['has_specs'] = true;
            $product['specs'] = [];
            foreach (array_filter(array_map('trim', explode(';', (string) $record['specs']))) as $spec) {
                [$label, $value] = array_pad(array_map('trim', explode('=', $spec, 2)), 2, '');
                if ($label === '' || $value === '') $errors[] = "line $line: spec '$spec' should look like Fabric=Polyester";
                else $product['specs'][] = [$label, $value];
            }
        }
        // Variants of this row: colours x sizes, with the stock rules (per-size column > stock > stock_total split evenly).
        $colours = shopImportColours((string) ($record['colours'] ?? ''), $line, $errors);
        $sizes = array_values(array_filter(array_map('trim', preg_split('~[,;/]~', (string) ($record['sizes'] ?? ''))), fn ($size) => $size !== ''));
        foreach ($sizes as $size) if (mb_strlen($size) > 32) $errors[] = "line $line: size '$size' is too long";
        $perSize = [];
        foreach ($record as $column => $value) {
            if (!preg_match('~^stock_(.+)$~', (string) $column, $m) || $column === 'stock_total' || $value === '') continue;
            $perSize[strtolower(str_replace('_', ' ', $m[1]))] = shopImportNumber((string) $value, $column, $line, $errors);
        }
        $stock = shopImportNumber((string) ($record['stock'] ?? ''), 'stock', $line, $errors);
        $total = shopImportNumber((string) ($record['stock_total'] ?? ''), 'stock_total', $line, $errors);
        $combos = [];
        foreach ($colours ?: [null] as $colour) foreach ($sizes ?: [''] as $size) $combos[] = [$colour, $size];
        if ($colours === [] && $sizes === []) {
            $units = $perSize ? null : ($stock ?? $total);
            if ($units !== null) $product['stock'] = (int) $units;
            continue;
        }
        $split = $total !== null ? intdiv((int) $total, count($combos)) : null;
        $remainder = $total !== null ? (int) $total % count($combos) : 0;
        foreach ($combos as $i => [$colour, $size]) {
            $units = $perSize[strtolower($size)] ?? $stock ?? ($split !== null ? $split + ($i < $remainder ? 1 : 0) : null);
            if ($units === null) { $errors[] = "line $line: stock is missing (use stock, stock_total or a stock_<size> column)"; break; }
            $key = strtolower(($colour['name'] ?? '') . '|' . $size);
            foreach ($product['variants'] as $existing) if ($existing['key'] === $key) { $errors[] = "line $line: variant " . trim(($colour['name'] ?? '') . ' ' . $size) . " of '$slug' is listed twice"; continue 2; }
            if ($colour && !in_array($colour['name'], array_column($product['colours'], 'name'), true)) $product['colours'][] = $colour;
            $product['variants'][] = ['key' => $key, 'colour' => $colour['name'] ?? null, 'size' => $size, 'stock' => (int) $units];
        }
    }
    unset($product);
    foreach ($products as $slug => $product) {
        if ($product['price'] !== null && $product['price'] <= 0) $errors[] = "line {$product['line']}: price of '$slug' must be more than 0";
        if ($product['compare_price'] !== null && $product['price'] !== null && $product['compare_price'] <= $product['price']) {
            $warnings[] = "'$slug': compare_price is not above price, so no discount is shown";
            $products[$slug]['compare_price'] = null;
        }
        if ($product['gst_rate'] !== null && $product['gst_rate'] > 40) $errors[] = "line {$product['line']}: gst_rate {$product['gst_rate']} looks wrong";
        if ($product['category'] === '') $warnings[] = "'$slug': no category, so it is listed only on the main shop page";
        if ($product['stock'] === null && !$product['variants']) $products[$slug]['stock'] = 0;
        if (count($product['variants']) > 60) $errors[] = "line {$product['line']}: '$slug' has more than 60 colour/size combinations";
    }
    return [$products, $errors, $warnings];
}

/** Loads an image from a file in $imagesDir (name or sub-path, never outside it) or an http(s) URL; returns bytes or an error string. */
function shopImportImageBytes(string $source, ?string $imagesDir): array {
    if (preg_match('~^https?://~i', $source)) {
        $context = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1, 'max_redirects' => 4, 'header' => "User-Agent: ProHPCL-catalogue-import\r\n"], 'ssl' => ['verify_peer' => true]]);
        $bytes = @file_get_contents($source, false, $context, 0, SHOP_IMAGE_MAX_BYTES + 1);
        if ($bytes === false) return [null, "cannot download $source"];
    } else {
        if ($imagesDir === null) return [null, "image '$source' needs --images <folder> (or use a full URL)"];
        if (preg_match('~(^|[\\\\/])\.\.([\\\\/]|$)~', $source) || preg_match('~^([a-z]:|[\\\\/])~i', $source)) return [null, "image '$source' must be a file name inside the images folder"];
        $file = rtrim($imagesDir, '/\\') . '/' . str_replace('\\', '/', $source);
        if (!is_file($file)) return [null, "image file not found: $file"];
        $bytes = file_get_contents($file);
    }
    if (strlen($bytes) > SHOP_IMAGE_MAX_BYTES) return [null, "image '$source' is larger than 12 MB"];
    return [$bytes, null];
}

/**
 * Re-encodes an image as an upright JPEG (longest side at most 1600 px, flattened on white) into the shop
 * upload folder. The file name comes from the product slug and a hash of the pixels, so a re-run reuses the
 * same file. Returns [site-relative path, error].
 */
function shopImportStoreImage(string $source, ?string $imagesDir, string $slug, string $folder, string $tag, bool $write): array {
    [$bytes, $error] = shopImportImageBytes($source, $imagesDir);
    if ($error) return [null, $error];
    if (!function_exists('imagecreatefromstring')) return [null, 'the GD extension is needed to process images'];
    $image = @imagecreatefromstring($bytes);
    if (!$image) return [null, "image '$source' is not a readable JPEG, PNG, WebP or GIF"];
    if (function_exists('exif_read_data') && str_starts_with($bytes, "\xFF\xD8")) {
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $angle = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
        if ($angle) $image = imagerotate($image, $angle, 0) ?: $image;
    }
    $w = imagesx($image);
    $h = imagesy($image);
    $scale = min(1, SHOP_IMAGE_MAX_SIDE / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $canvas = imagecreatetruecolor($nw, $nh);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $hash = substr(md5($bytes), 0, 8);
    $relative = $folder . '/' . $slug . $tag . '-' . $hash . '.jpg';
    if ($write) {
        $absolute = dirname(__DIR__) . '/' . $relative;
        if (!is_dir(dirname($absolute)) && !@mkdir(dirname($absolute), 0775, true) && !is_dir(dirname($absolute))) return [null, 'cannot create ' . dirname($relative)];
        if (!is_file($absolute) && !imagejpeg($canvas, $absolute, 86)) return [null, "cannot write $relative"];
    }
    return [$relative, null];
}

/** The category id for 'Apparel > Jerseys' (two levels at most), creating missing ones; null for ''. $created collects [id, label] of new ones. */
function shopImportCategory(string $path, array &$created, array &$errors, string $slug): ?int {
    $parts = array_values(array_filter(array_map('trim', preg_split('~\s*[>/]\s*~', $path))));
    if (!$parts) return null;
    if (count($parts) > 2) { $errors[] = "'$slug': category '$path' is more than two levels deep (use Parent > Child)"; return null; }
    $parentId = null;
    foreach ($parts as $depth => $name) {
        $existing = null;
        foreach (query('SELECT id, name, slug, parent_id FROM shop_categories') as $category) {
            if ((int) ($category['parent_id'] ?? 0) === (int) $parentId && shopCategoryKey($category['name']) === shopCategoryKey($name)) { $existing = $category; break; }
        }
        if ($existing) { $parentId = (int) $existing['id']; continue; }
        $base = shopSlugify($name) ?: 'category';
        $slugTry = $base;
        if (query('SELECT 1 FROM shop_categories WHERE slug = ?', [$slugTry])) {
            $parentSlug = $parentId ? (string) query('SELECT slug FROM shop_categories WHERE id = ?', [$parentId])[0]['slug'] : '';
            $slugTry = trim($parentSlug . '-' . $base, '-');
            for ($n = 2; query('SELECT 1 FROM shop_categories WHERE slug = ?', [$slugTry]); $n++) $slugTry = $base . '-' . $n;
        }
        $position = 1 + (int) (query('SELECT MAX(position) AS p FROM shop_categories WHERE parent_id <=> ?', [$parentId])[0]['p'] ?? 0);
        db()->prepare('INSERT INTO shop_categories (parent_id, slug, name, position, is_active) VALUES (?, ?, ?, ?, 1)')->execute([$parentId, $slugTry, $name, $position]);
        $parentId = (int) db()->lastInsertId();
        $created[] = ['id' => $parentId, 'label' => ($depth ? implode(' > ', array_slice($parts, 0, $depth)) . ' > ' : '') . $name . " ($slugTry)"];
    }
    return $parentId;
}

/**
 * Imports records (see shopImportReadCsv for their shape). Options: dry_run, images_dir, keep_stock (leave the stock
 * of existing variants alone and only add new ones), default_active (0 or 1 when a row has no 'active' value).
 * Returns ['ok' => bool, 'errors' => [], 'warnings' => [], 'products' => [slug => info], 'categories_created' => [labels],
 * 'category_ids' => [ids of the categories created], 'images' => n, 'dry_run' => bool].
 */
function shopImportSortErrors(array $report): array {
    usort($report['errors'], fn ($a, $b) => (preg_match('~^line (\d+)~', $a, $x) ? (int) $x[1] : 0) <=> (preg_match('~^line (\d+)~', $b, $y) ? (int) $y[1] : 0));
    return $report;
}
function shopImportRecords(array $records, array $options = []): array {
    $dry = (bool) ($options['dry_run'] ?? false);
    $imagesDir = $options['images_dir'] ?? null;
    $keepStock = (bool) ($options['keep_stock'] ?? false);
    $defaultActive = (int) ($options['default_active'] ?? 1);
    [$products, $errors, $warnings] = shopImportNormalise($records);
    $report = ['ok' => false, 'dry_run' => $dry, 'errors' => $errors, 'warnings' => $warnings, 'products' => [], 'categories_created' => [], 'category_ids' => [], 'images' => 0];
    if (!$products && !$errors) { $report['errors'][] = 'no products found in the file'; return $report; }

    // Images are checked first without writing anything, so a bad row never leaves stray files behind; a real run then writes them.
    $stored = [];
    foreach ([false, true] as $write) {
        if ($write && ($dry || $report['errors'])) break;
        $stored = [];
        $report['images'] = 0;
        foreach ($products as $slug => $product) {
            $exists = query('SELECT image FROM shop_products WHERE slug = ?', [$slug])[0] ?? null;
            if ($product['image'] === '' && !$exists) { if (!$write) $report['errors'][] = "line {$product['line']}: image is required for the new product '$slug'"; continue; }
            if ($product['image'] !== '') {
                [$path, $error] = shopImportStoreImage($product['image'], $imagesDir, $slug, SHOP_IMAGE_DIR, '', $write);
                if ($error) { if (!$write) $report['errors'][] = "line {$product['line']}: $error"; } else { $stored[$slug]['image'] = $path; $report['images']++; }
            }
            foreach ($product['gallery'] as $n => $source) {
                [$path, $error] = shopImportStoreImage($source, $imagesDir, $slug, SHOP_GALLERY_DIR, '-' . ($n + 1), $write);
                if ($error) { if (!$write) $report['errors'][] = "line {$product['line']}: $error"; } else { $stored[$slug]['gallery'][] = $path; $report['images']++; }
            }
        }
    }
    if ($report['errors']) return shopImportSortErrors($report);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $now = date('Y-m-d H:i:s');
        foreach ($products as $slug => $p) {
            $catErrors = [];
            $created = [];
            $categoryId = shopImportCategory($p['category'], $created, $catErrors, $slug);
            if ($catErrors) throw new RuntimeException(implode('; ', $catErrors));
            foreach ($created as $new) { $report['categories_created'][] = $new['label']; $report['category_ids'][] = $new['id']; }
            $active = $p['active'] ?? $defaultActive;
            $total = $p['variants'] ? array_sum(array_column($p['variants'], 'stock')) : (int) $p['stock'];
            $row = query('SELECT id FROM shop_products WHERE slug = ?', [$slug])[0] ?? null;
            if ($row) {
                $id = (int) $row['id'];
                $set = ['name = ?', 'category_id = ?', 'brand = ?', 'short_description = ?', 'description = ?', 'price = ?', 'compare_price = ?', 'gst_rate = ?', 'is_active = ?', 'updated_at = ?'];
                $args = [$p['name'], $categoryId, $p['brand'] ?: null, $p['short_description'] ?: null, $p['description'] ?: null, $p['price'], $p['compare_price'], $p['gst_rate'], $active, $now];
                if (!($keepStock && $p['variants'])) { $set[] = 'stock = ?'; $args[] = $total; }
                if (isset($stored[$slug]['image'])) { $set[] = 'image = ?'; $args[] = $stored[$slug]['image']; }
                $pdo->prepare('UPDATE shop_products SET ' . implode(', ', $set) . ' WHERE id = ?')->execute([...$args, $id]);
                $action = 'updated';
            } else {
                $position = 1 + (int) (query('SELECT MAX(position) AS p FROM shop_products')[0]['p'] ?? 0);
                $pdo->prepare('INSERT INTO shop_products (slug, name, brand, category_id, short_description, description, price, compare_price, gst_rate, stock, image, position, is_active, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$slug, $p['name'], $p['brand'] ?: null, $categoryId, $p['short_description'] ?: null, $p['description'] ?: null, $p['price'], $p['compare_price'], $p['gst_rate'], $total,
                        $stored[$slug]['image'], $position, $active, $now, $now]);
                $id = (int) $pdo->lastInsertId();
                $action = 'created';
            }
            // Colours by name, then variants by (colour, size): ids stay stable so orders keep pointing at the right variant.
            $colourIds = [];
            $oldColours = array_column(query('SELECT id, name FROM shop_product_colours WHERE product_id = ?', [$id]), 'id', 'name');
            foreach ($p['colours'] as $position => $colour) {
                $match = null;
                foreach ($oldColours as $oldName => $oldId) if (strcasecmp($oldName, $colour['name']) === 0) $match = $oldId;
                if ($match) $pdo->prepare('UPDATE shop_product_colours SET position = ?, hex = ?, name = ? WHERE id = ?')->execute([$position, $colour['hex'], $colour['name'], $match]);
                else { $pdo->prepare('INSERT INTO shop_product_colours (product_id, position, name, hex) VALUES (?, ?, ?, ?)')->execute([$id, $position, $colour['name'], $colour['hex']]); $match = (int) $pdo->lastInsertId(); }
                $colourIds[$colour['name']] = (int) $match;
            }
            $kept = [];
            $variantInfo = ['added' => 0, 'updated' => 0, 'removed' => 0];
            foreach ($p['variants'] as $v) {
                $colourId = $v['colour'] !== null ? $colourIds[$v['colour']] : null;
                $old = query('SELECT id FROM shop_product_variants WHERE product_id = ? AND colour_id <=> ? AND size = ?', [$id, $colourId, $v['size']])[0] ?? null;
                if ($old) {
                    if (!$keepStock) $pdo->prepare('UPDATE shop_product_variants SET stock = ? WHERE id = ?')->execute([$v['stock'], $old['id']]);
                    $kept[] = (int) $old['id'];
                    $variantInfo['updated']++;
                } else {
                    $pdo->prepare('INSERT INTO shop_product_variants (product_id, colour_id, size, stock) VALUES (?, ?, ?, ?)')->execute([$id, $colourId, $v['size'], $v['stock']]);
                    $kept[] = (int) $pdo->lastInsertId();
                    $variantInfo['added']++;
                }
            }
            if ($p['variants']) {
                // Variants and colours that are no longer in the file: deleted, unless an order still points at the variant (then stock 0).
                foreach (query('SELECT id FROM shop_product_variants WHERE product_id = ?', [$id]) as $old) {
                    if (in_array((int) $old['id'], $kept, true)) continue;
                    try { $used = (bool) query('SELECT 1 FROM shop_order_items WHERE variant_id = ? LIMIT 1', [$old['id']]); } catch (Throwable) { $used = false; }
                    if ($used) $pdo->prepare('UPDATE shop_product_variants SET stock = 0 WHERE id = ?')->execute([$old['id']]);
                    else $pdo->prepare('DELETE FROM shop_product_variants WHERE id = ?')->execute([$old['id']]);
                    $variantInfo['removed']++;
                }
                $pdo->prepare('DELETE FROM shop_product_colours WHERE product_id = ? AND id NOT IN (SELECT colour_id FROM shop_product_variants WHERE product_id = ? AND colour_id IS NOT NULL)'
                    . ($colourIds ? ' AND id NOT IN (' . implode(',', array_map('intval', $colourIds)) . ')' : ''))->execute([$id, $id]);
            }
            // Gallery, features and specs have no ids: replaced as a whole, but only when the file gives them.
            if ($p['has_gallery']) {
                $pdo->prepare('DELETE FROM shop_product_images WHERE product_id = ?')->execute([$id]);
                foreach ($stored[$slug]['gallery'] ?? [] as $position => $path) $pdo->prepare('INSERT INTO shop_product_images (product_id, position, image) VALUES (?, ?, ?)')->execute([$id, $position, $path]);
            }
            if ($p['has_features']) {
                $pdo->prepare('DELETE FROM shop_product_features WHERE product_id = ?')->execute([$id]);
                foreach ($p['features'] as $position => $text) $pdo->prepare('INSERT INTO shop_product_features (product_id, position, feature) VALUES (?, ?, ?)')->execute([$id, $position, mb_substr($text, 0, 255)]);
            }
            if ($p['has_specs']) {
                $pdo->prepare('DELETE FROM shop_product_specs WHERE product_id = ?')->execute([$id]);
                foreach ($p['specs'] as $position => [$label, $value]) $pdo->prepare('INSERT INTO shop_product_specs (product_id, position, name, value) VALUES (?, ?, ?, ?)')->execute([$id, $position, mb_substr($label, 0, 255), mb_substr($value, 0, 512)]);
            }
            $report['products'][$slug] = ['action' => $action, 'id' => $id, 'name' => $p['name'], 'category' => $p['category'], 'price' => $p['price'], 'active' => (bool) $active,
                'stock' => $total, 'variants' => $variantInfo, 'line' => $p['line']];
        }
        $dry ? $pdo->rollBack() : $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $report['errors'][] = 'nothing was saved: ' . $error->getMessage();
        $report['products'] = [];
        return $report;
    }
    $report['ok'] = true;
    return $report;
}

/** A printable summary of a report from shopImportRecords(). */
function shopImportSummary(array $report): string {
    $out = [];
    $by = ['created' => 0, 'updated' => 0];
    foreach ($report['products'] as $slug => $p) {
        $by[$p['action']]++;
        $v = $p['variants'];
        $out[] = sprintf('  %-8s %-44s Rs %-8s %s stock %d%s%s', strtoupper($p['action']), $slug, rtrim(rtrim(number_format((float) $p['price'], 2, '.', ''), '0'), '.'), $p['active'] ? 'active  ' : 'DRAFT   ', $p['stock'],
            $v['added'] + $v['updated'] ? " (variants: +{$v['added']} ={$v['updated']} -{$v['removed']})" : '', $p['category'] ? "  [{$p['category']}]" : '');
    }
    foreach ($report['categories_created'] as $name) $out[] = '  new category: ' . $name;
    foreach ($report['warnings'] as $warning) $out[] = '  warning: ' . $warning;
    foreach ($report['errors'] as $error) $out[] = '  ERROR: ' . $error;
    $out[] = ($report['dry_run'] ? 'DRY RUN (nothing written): ' : '') . ($report['ok']
        ? "{$by['created']} to create, {$by['updated']} to update, {$report['images']} images" . ($report['dry_run'] ? ' checked' : ' written')
        : 'FAILED: ' . count($report['errors']) . ' error(s), nothing was saved');
    return implode("\n", $out);
}
