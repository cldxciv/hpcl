<?php
declare(strict_types=1);

// Shop products in the admin (/admin/shop/products): a list with filters and quick actions, the add/edit
// form (details, photos, colours and sizes with a stock grid, features, specs) and delete.
// Deleting removes a product nobody ever ordered; an ordered one can only be hidden.
require_once __DIR__ . '/admin-shop.php';

const SHOP_ADMIN_STOCK_EXPR = 'COALESCE((SELECT SUM(v.stock) FROM shop_product_variants v WHERE v.product_id = p.id), p.stock)';
const SHOP_ADMIN_MAX_COLOURS = 12;
const SHOP_ADMIN_MAX_SIZES = 20;
const SHOP_ADMIN_MAX_GALLERY = 10;
const SHOP_ADMIN_BLANK_COLOUR_ROWS = 2;

/** The list's filters from the query string. */
function shopAdminProductFilters(): array {
    $category = is_string($_GET['category'] ?? null) && ctype_digit($_GET['category']) ? (int) $_GET['category'] : 0;
    $status = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['active', 'hidden', 'out', 'low'], true) ? $_GET['status'] : '';
    return ['q' => adminSearch(), 'category' => $category, 'status' => $status];
}
function shopAdminProductListUrl(array $filters, ?int $page = null): string {
    return '{{BASE}}/admin/shop/products' . (($query = http_build_query(array_filter([...$filters, 'page' => $page > 1 ? $page : null]))) !== '' ? '?' . escape($query) : '');
}

/** Everything a list row needs for stock: [label HTML, state: out|low|ok]. */
function shopAdminProductStockLabel(array $row): array {
    $total = (int) $row['total_stock'];
    if ($total <= 0) return ['<span class="admin-badge admin-badge--shop-cancelled">Out of stock</span>', 'out'];
    $partial = (int) $row['variant_count'] > 0 && (int) $row['min_stock'] === 0 ? ' <span class="admin-muted">(some options out)</span>' : '';
    if ($total <= SHOP_ADMIN_LOW_STOCK) return ['<span class="admin-badge admin-badge--shop-pending">Low: ' . $total . '</span>' . $partial, 'low'];
    return [number_format($total) . $partial, 'ok'];
}

function renderAdminShopProducts(array $admin): string {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') shopAdminProductsPost();
    $filters = shopAdminProductFilters();
    $where = ['1 = 1'];
    $params = [];
    if ($filters['q'] !== '') { $where[] = '(p.name LIKE ? OR p.brand LIKE ?)'; $params[] = $params[] = likePattern($filters['q']); }
    if ($filters['category']) { $where[] = '(p.category_id = ? OR c.parent_id = ?)'; $params[] = $params[] = $filters['category']; }
    $where[] = match ($filters['status']) { 'active' => 'p.is_active = 1', 'hidden' => 'p.is_active = 0', 'out' => SHOP_ADMIN_STOCK_EXPR . ' = 0', 'low' => SHOP_ADMIN_STOCK_EXPR . ' BETWEEN 1 AND ' . SHOP_ADMIN_LOW_STOCK, default => '1 = 1' };
    $from = 'FROM shop_products p LEFT JOIN shop_categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $where);
    $total = (int) query("SELECT COUNT(*) AS n $from", $params)[0]['n'];
    $lastPage = max(1, (int) ceil($total / SHOP_ADMIN_PER_PAGE));
    $page = min(shopAdminPage(), $lastPage);
    $products = query('SELECT p.id, p.name, p.price, p.image, p.is_active, p.stock, c.name AS category, ' . SHOP_ADMIN_STOCK_EXPR . ' AS total_stock,
        (SELECT COUNT(*) FROM shop_product_variants v WHERE v.product_id = p.id) AS variant_count, (SELECT MIN(v.stock) FROM shop_product_variants v WHERE v.product_id = p.id) AS min_stock '
        . $from . ' ORDER BY p.position, p.id DESC LIMIT ' . SHOP_ADMIN_PER_PAGE . ' OFFSET ' . (($page - 1) * SHOP_ADMIN_PER_PAGE), $params);

    $rows = implode('', array_map(function ($p) {
        [$stock] = shopAdminProductStockLabel($p);
        $id = (int) $p['id'];
        $quick = (int) $p['variant_count'] === 0
            ? '<label class="admin-visually-hidden" for="stock-' . $id . '">Stock of ' . escape($p['name']) . '</label><input class="admin-input-small admin-shop-qty" id="stock-' . $id . '" type="number" min="0" max="99999" name="stock[' . $id . ']" value="' . (int) $p['stock'] . '">'
            : $stock;
        return '<tr><td><label class="admin-check"><input type="checkbox" name="ids[]" value="' . $id . '"><span class="admin-visually-hidden">Select ' . escape($p['name']) . '</span></label></td>'
            . '<td class="admin-cell-team">' . shopAdminThumb($p['image']) . '<span><a href="{{BASE}}/admin/shop/products/' . $id . '">' . escape($p['name']) . '</a><br><span class="admin-muted">' . ($p['category'] ? escape($p['category']) : 'No category') . '</span></span></td>'
            . '<td class="admin-num">' . shopAdminRupees($p['price']) . '</td><td>' . $quick . '</td>'
            . '<td>' . ($p['is_active'] ? '<span class="admin-badge admin-badge--ongoing">Shown</span>' : '<span class="admin-badge admin-badge--unknown">Hidden</span>') . '</td>'
            . '<td class="admin-actions"><a href="{{BASE}}/admin/shop/products/' . $id . '">Edit</a><button type="submit" name="row_action" value="toggle:' . $id . '" class="admin-button-secondary">' . ($p['is_active'] ? 'Hide' : 'Show') . '</button></td></tr>';
    }, $products));
    $table = $products
        ? '<div class="admin-table-wrap"><table class="admin-table admin-table--shop"><thead><tr><th><label class="admin-check"><input type="checkbox" data-select-all><span class="admin-visually-hidden">Select all products on this page</span></label></th><th>Product</th><th class="admin-num">Price</th><th>Stock</th><th>Status</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        : '<p class="admin-empty">' . ($filters['q'] . $filters['status'] !== '' || $filters['category'] ? 'No products match these filters.' : 'No products yet. <a href="{{BASE}}/admin/shop/products/new">Add the first product</a>.') . '</p>';

    $categories = shopAdminCategoryTree();
    $categoryOptions = '<option value="">All categories</option>' . implode('', array_map(fn ($c) => '<option value="' . (int) $c['id'] . '"' . ($filters['category'] === (int) $c['id'] ? ' selected' : '') . '>' . ($c['depth'] ? '— ' : '') . escape($c['name']) . '</option>', $categories));
    $statusOptions = implode('', array_map(fn ($key, $label) => '<option value="' . $key . '"' . ($filters['status'] === $key ? ' selected' : '') . '>' . $label . '</option>',
        ['', 'active', 'hidden', 'out', 'low'], ['Any status', 'Shown in the shop', 'Hidden', 'Out of stock', 'Low stock']));
    $counts = query('SELECT COUNT(*) AS n, COALESCE(SUM(is_active), 0) AS shown FROM shop_products')[0];
    return adminView('templates.admin.shop-products', $admin, '/admin/shop/products', [
        'summary' => number_format((int) $counts['n']) . ' products, ' . number_format((int) $counts['shown']) . ' shown in the shop' . ($total !== (int) $counts['n'] ? '; ' . number_format($total) . ' match' : ''),
        'search' => escape($filters['q']), 'category_options' => $categoryOptions, 'status_options' => $statusOptions, 'table' => $table,
        'pager' => shopAdminPager($page, $lastPage, fn (int $n) => shopAdminProductListUrl($filters, $n)), 'return' => escape((string) http_build_query(array_filter($filters))),
        'bulk' => $products ? '<div class="admin-shop-bulk"><label for="bulk_action">With the selected products</label><select id="bulk_action" name="bulk_action"><option value="show">Show in the shop</option><option value="hide">Hide from the shop</option><option value="out">Mark out of stock</option></select><button type="submit" name="row_action" value="bulk" class="admin-button-secondary">Apply</button>'
            . '<button type="submit" name="row_action" value="save_stock" class="admin-button-secondary">Save the stock numbers above</button></div>' : '',
    ]);
}

/** The list's quick actions (show/hide one product, bulk show/hide/out of stock, save typed stock numbers), then back to the list. */
function shopAdminProductsPost(): never {
    $action = postedText('row_action');
    $ids = array_values(array_filter(array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []), fn ($id) => $id > 0));
    $message = 'Nothing was changed.';
    if (preg_match('~^toggle:(\d+)$~', $action, $m)) {
        adminExecute('UPDATE shop_products SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?', [(int) $m[1]]);
        $message = 'Saved.';
    } elseif ($action === 'bulk' && $ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $bulk = postedText('bulk_action');
        if ($bulk === 'show' || $bulk === 'hide') {
            adminExecute("UPDATE shop_products SET is_active = ?, updated_at = NOW() WHERE id IN ($marks)", [$bulk === 'show' ? 1 : 0, ...$ids]);
            $message = count($ids) . ' product' . (count($ids) === 1 ? '' : 's') . ($bulk === 'show' ? ' shown in the shop.' : ' hidden from the shop.');
        } elseif ($bulk === 'out') {
            adminExecute("UPDATE shop_product_variants SET stock = 0 WHERE product_id IN ($marks)", $ids);
            adminExecute("UPDATE shop_products SET stock = 0, updated_at = NOW() WHERE id IN ($marks)", $ids);
            $message = count($ids) . ' product' . (count($ids) === 1 ? '' : 's') . ' marked out of stock.';
        }
    } elseif ($action === 'bulk') {
        $message = 'Tick the products to change first.';
    } elseif ($action === 'save_stock' && is_array($_POST['stock'] ?? null)) {
        $changed = 0;
        foreach ($_POST['stock'] as $id => $qty) {
            if (!is_string($qty) || !ctype_digit($qty) || (int) $qty > 99999 || !ctype_digit((string) $id)) continue;
            $statement = db()->prepare('UPDATE shop_products p SET p.stock = ?, p.updated_at = NOW() WHERE p.id = ? AND p.stock <> ? AND NOT EXISTS (SELECT 1 FROM shop_product_variants v WHERE v.product_id = p.id)');
            $statement->execute([(int) $qty, (int) $id, (int) $qty]);
            $changed += $statement->rowCount();
        }
        $message = $changed ? 'Updated the stock of ' . $changed . ' product' . ($changed === 1 ? '' : 's') . '.' : 'No stock numbers were changed.';
    }
    adminFlash($message);
    $return = postedText('return');
    adminRedirect('/admin/shop/products' . ($return !== '' && preg_match('~^[A-Za-z0-9_=&%.+-]+$~', $return) ? '?' . $return : ''));
}

// ---------------------------------------------------------------- the form

/** A product as the form shows it: the saved one, or the blank defaults. */
function shopAdminProductLoad(?int $id): array {
    $blank = ['id' => null, 'name' => '', 'slug' => '', 'brand' => '', 'category_id' => '', 'short_description' => '', 'description' => '', 'price' => '', 'compare_price' => '', 'gst_rate' => '',
        'position' => '0', 'is_active' => 1, 'stock' => '0', 'image' => null, 'sizes' => '', 'features' => '', 'specs' => '', 'colours' => [], 'gallery' => [], 'variants' => []];
    if ($id === null) return $blank;
    $p = query('SELECT * FROM shop_products WHERE id = ?', [$id])[0] ?? notFound();
    $p['category_id'] = (string) ($p['category_id'] ?? '');
    $p['price'] = rtrim(rtrim((string) $p['price'], '0'), '.');
    $p['compare_price'] = $p['compare_price'] === null ? '' : rtrim(rtrim((string) $p['compare_price'], '0'), '.');
    $p['gst_rate'] = $p['gst_rate'] === null ? '' : rtrim(rtrim((string) $p['gst_rate'], '0'), '.');
    $p['position'] = (string) $p['position'];
    $p['stock'] = (string) $p['stock'];
    $p['colours'] = query('SELECT id, name, hex FROM shop_product_colours WHERE product_id = ? ORDER BY position, id', [$id]);
    $p['variants'] = query('SELECT id, colour_id, size, stock FROM shop_product_variants WHERE product_id = ? ORDER BY id', [$id]);
    $p['sizes'] = implode(', ', shopAdminProductSizes($p['variants']));
    $p['gallery'] = query('SELECT position, image FROM shop_product_images WHERE product_id = ? ORDER BY position', [$id]);
    $p['features'] = implode("\n", array_column(query('SELECT feature FROM shop_product_features WHERE product_id = ? ORDER BY position', [$id]), 'feature'));
    $p['specs'] = implode("\n", array_map(fn ($s) => $s['name'] . ': ' . $s['value'], query('SELECT name, value FROM shop_product_specs WHERE product_id = ? ORDER BY position', [$id])));
    return $p + $blank;
}
/** The distinct sizes of a product's variants, in the order they were first saved ('' = no size). */
function shopAdminProductSizes(array $variants): array {
    $sizes = [];
    foreach ($variants as $v) if ($v['size'] !== '' && !in_array($v['size'], $sizes, true)) $sizes[] = $v['size'];
    return $sizes;
}

function renderAdminShopProductForm(array $admin, ?int $id): string {
    $product = shopAdminProductLoad($id);
    $input = $product;
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') [$input, $errors] = saveAdminShopProduct($product);
    return shopAdminProductFormPage($admin, $product, $input, $errors);
}


function shopAdminProductFormPage(array $admin, array $saved, array $input, array $errors): string {
    $isNew = $saved['id'] === null;
    $tree = shopAdminCategoryTree();
    $options = '<option value="">No category</option>' . implode('', array_map(fn ($c) => '<option value="' . (int) $c['id'] . '"' . ((string) $input['category_id'] === (string) $c['id'] ? ' selected' : '') . '>' . ($c['depth'] ? '— ' : '') . escape($c['name']) . '</option>', $tree));
    $e = $errors;

    $basics = '<fieldset class="admin-panel admin-form admin-shop-section"><legend>Details</legend>'
        . shopAdminField('name', 'Product name', shopAdminInput('name', 'text', (string) $input['name'], $e, ' maxlength="512" required'), $e)
        . '<div class="admin-fields-2">' . shopAdminField('category_id', 'Category', '<select id="category_id" name="category_id"' . fieldInvalid($e, 'category_id') . '>' . $options . '</select>', $e)
        . shopAdminField('brand', 'Brand', shopAdminInput('brand', 'text', (string) $input['brand'], $e, ' maxlength="255"'), $e, '', true) . '</div>'
        . shopAdminField('short_description', 'Short description', '<textarea id="short_description" name="short_description" rows="2" maxlength="500"' . fieldInvalid($e, 'short_description') . '>' . escape((string) $input['short_description']) . '</textarea>', $e, 'One or two lines shown under the name in the shop.', true)
        . shopAdminField('description', 'Full description', '<textarea id="description" name="description" rows="6" maxlength="10000"' . fieldInvalid($e, 'description') . '>' . escape((string) $input['description']) . '</textarea>', $e, 'Material, fit, what makes it special. Use a blank line between paragraphs.', true)
        . '<div class="admin-fields-2">' . shopAdminField('slug', 'Web address', shopAdminInput('slug', 'text', (string) $input['slug'], $e, ' maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*"'), $e, 'Lower case letters, digits and hyphens. Leave empty to make it from the name.', true)
        . shopAdminField('position', 'Order in the shop', shopAdminInput('position', 'number', (string) $input['position'], $e, ' min="0" max="999" inputmode="numeric"'), $e, 'Lower numbers come first.', true) . '</div>'
        . '<label class="admin-check"><input type="checkbox" name="is_active" value="1"' . ($input['is_active'] ? ' checked' : '') . '>Show this product in the shop</label></fieldset>';

    $pricing = '<fieldset class="admin-panel admin-form admin-shop-section"><legend>Price</legend><div class="admin-fields-2">'
        . shopAdminField('price', 'Price (₹)', shopAdminInput('price', 'text', (string) $input['price'], $e, ' inputmode="decimal" required'), $e, 'What the customer pays, including GST.')
        . shopAdminField('compare_price', 'Compare-at price (₹)', shopAdminInput('compare_price', 'text', (string) $input['compare_price'], $e, ' inputmode="decimal"'), $e, 'A higher “was” price shown struck through, to show the discount.', true)
        . shopAdminField('gst_rate', 'GST rate (%)', shopAdminInput('gst_rate', 'text', (string) $input['gst_rate'], $e, ' inputmode="decimal"'), $e, 'Prices include GST; this is shown on the invoice.', true) . '</div></fieldset>';

    // Photos
    $main = $saved['image'] && $saved['image'] !== SHOP_ADMIN_PLACEHOLDER_IMAGE ? '<div class="admin-logo-current">' . shopAdminThumb($saved['image'], 'admin-logo-preview') . '<span class="admin-hint">Current main photo. Choose a file to replace it.</span></div>' : '';
    $gallery = '';
    foreach ($saved['gallery'] as $g) {
        $pos = (int) $g['position'];
        $gallery .= '<li class="admin-shop-gallery__item">' . shopAdminThumb($g['image'], 'admin-shop-gallery__img')
            . '<label>Order <input class="admin-input-small" type="number" min="0" max="99" name="gallery_order[' . $pos . ']" value="' . $pos . '"></label>'
            . '<label class="admin-check"><input type="checkbox" name="gallery_remove[]" value="' . $pos . '">Remove</label></li>';
    }
    $photos = '<fieldset class="admin-panel admin-form admin-shop-section"><legend>Photos</legend>'
        . '<div class="admin-field"><label for="image">Main photo</label>' . $main . '<input id="image" name="image" type="file" accept="image/png,image/jpeg,image/webp"' . fieldInvalid($e, 'image') . '>'
        . '<p class="admin-hint">PNG, JPEG or WebP up to 8 MB; it is resized for the web. Without a photo the shop shows the Pro HPCL placeholder.</p>' . fieldError($e, 'image') . '</div>'
        . '<div class="admin-field"><span class="admin-field-label" id="gallery-label">More photos</span>' . ($gallery ? '<ul class="admin-shop-gallery">' . $gallery . '</ul>' : '')
        . '<input id="gallery" name="gallery[]" type="file" multiple accept="image/png,image/jpeg,image/webp" aria-labelledby="gallery-label"' . fieldInvalid($e, 'gallery') . '>'
        . '<p class="admin-hint">Choose several at once (up to ' . SHOP_ADMIN_MAX_GALLERY . ' in total). Order 0 comes first after the main photo.</p>' . fieldError($e, 'gallery') . '</div></fieldset>';

    // Colours, sizes, stock
    $rows = '';
    $colours = $input['colours'];
    for ($i = 0; $i < SHOP_ADMIN_BLANK_COLOUR_ROWS; $i++) $colours[] = ['id' => '', 'name' => '', 'hex' => '#0b50a7'];
    foreach ($colours as $i => $c) {
        $rows .= '<tr><td><input type="hidden" name="colours[' . $i . '][id]" value="' . escape((string) $c['id']) . '"><label class="admin-visually-hidden" for="colour-name-' . $i . '">Colour name, row ' . ($i + 1) . '</label>'
            . '<input id="colour-name-' . $i . '" name="colours[' . $i . '][name]" type="text" maxlength="64" placeholder="e.g. Navy" value="' . escape((string) $c['name']) . '"></td>'
            . '<td><label class="admin-visually-hidden" for="colour-hex-' . $i . '">Colour swatch, row ' . ($i + 1) . '</label><input id="colour-hex-' . $i . '" name="colours[' . $i . '][hex]" type="color" value="' . escape(preg_match('~^#[0-9a-fA-F]{6}$~', (string) $c['hex']) ? (string) $c['hex'] : '#0b50a7') . '"></td></tr>';
    }
    $savedSizes = shopAdminProductSizes($saved['variants']);
    $stockGrid = '';
    if ($saved['colours'] || $savedSizes) {
        $stock = [];
        foreach ($saved['variants'] as $v) $stock[(int) $v['colour_id'] . '|' . $v['size']] = (int) $v['stock'];
        $cols = $savedSizes ?: [''];
        $head = '<tr><th scope="col">' . ($saved['colours'] ? 'Colour' : 'Size') . '</th>' . implode('', array_map(fn ($s) => '<th scope="col" class="admin-num">' . ($s === '' ? 'Stock' : escape($s)) . '</th>', $cols)) . '</tr>';
        $body = '';
        foreach ($saved['colours'] ?: [['id' => 0, 'name' => 'All', 'hex' => '']] as $c) {
            $body .= '<tr><th scope="row">' . ($c['hex'] ? '<svg class="admin-shop-swatch" viewBox="0 0 12 12" aria-hidden="true"><rect width="12" height="12" rx="3" fill="' . escape($c['hex']) . '" stroke="#475569" stroke-width="0.6"/></svg> ' : '') . escape($c['name']) . '</th>';
            foreach ($cols as $s) $body .= '<td class="admin-num"><label class="admin-visually-hidden" for="vs-' . (int) $c['id'] . '-' . md5($s) . '">Stock for ' . escape($c['name'] . ($s !== '' ? ', ' . $s : '')) . '</label><input id="vs-' . (int) $c['id'] . '-' . md5($s) . '" class="admin-input-small admin-shop-qty" type="number" min="0" max="99999" name="vstock[' . (int) $c['id'] . '][' . escape($s) . ']" value="' . (int) ($stock[(int) $c['id'] . '|' . $s] ?? 0) . '"></td>';
            $body .= '</tr>';
        }
        $stockGrid = '<div class="admin-field"><p class="admin-field-label">Stock for each option</p><div class="admin-table-wrap"><table class="admin-table admin-table--form"><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table></div>'
            . '<p class="admin-hint">A colour or size you add above gets 0 stock the first time you save; come back here to set its quantity.</p></div>';
    }
    $variants = '<fieldset class="admin-panel admin-form admin-shop-section"><legend>Colours, sizes and stock</legend>'
        . '<p class="admin-hint">Leave colours and sizes empty for a product with one version, and just enter the stock below.</p>'
        . '<div class="admin-field"><p class="admin-field-label">Colours</p><div class="admin-table-wrap"><table class="admin-table admin-table--form"><thead><tr><th scope="col">Name</th><th scope="col">Swatch</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<p class="admin-hint">To remove a colour, clear its name. Save to get more empty rows.</p>' . fieldError($e, 'colours') . '</div>'
        . shopAdminField('sizes', 'Sizes', shopAdminInput('sizes', 'text', (string) $input['sizes'], $e, ' maxlength="400"'), $e, 'Separated by commas, such as S, M, L, XL (or 6-8 yrs, 8-10 yrs).', true)
        . $stockGrid
        . ($saved['colours'] || $savedSizes ? '' : shopAdminField('stock', 'Stock (number in hand)', shopAdminInput('stock', 'number', (string) $input['stock'], $e, ' min="0" max="99999" inputmode="numeric"'), $e))
        . '</fieldset>';

    $extra = '<fieldset class="admin-panel admin-form admin-shop-section"><legend>Features and specifications</legend>'
        . shopAdminField('features', 'Features', '<textarea id="features" name="features" rows="4" maxlength="4000">' . escape((string) $input['features']) . '</textarea>', $e, 'One feature per line, shown as bullet points.', true)
        . shopAdminField('specs', 'Specifications', '<textarea id="specs" name="specs" rows="4" maxlength="4000">' . escape((string) $input['specs']) . '</textarea>', $e, 'One per line as Name: value, such as Fabric: 100% polyester.', true) . '</fieldset>';

    return adminView('templates.admin.shop-product-form', $admin, '/admin/shop/products', [
        'title' => $isNew ? 'Add a product' : 'Edit ' . escape($saved['name']),
        'action' => '{{BASE}}/admin/shop/products/' . ($isNew ? 'new' : (int) $saved['id']),
        'summary' => $errors ? '<p class="admin-alert" role="alert">Please correct the highlighted fields' . ($errors && imageWasChosen('image') && !isset($errors['image']) ? ', and choose the photos again' : '') . '.</p>' : '',
        'sections' => $basics . $pricing . $photos . $variants . $extra,
        'submit' => $isNew ? 'Add product' : 'Save changes',
        'view' => $isNew ? '' : '<a class="admin-button-secondary" href="{{BASE}}/shop/' . rawurlencode((string) $saved['slug']) . '">View in the shop</a>',
        'delete' => $isNew || !adminCan('delete') ? '' : '<a class="admin-danger-link" href="{{BASE}}/admin/shop/products/' . (int) $saved['id'] . '/delete">Delete this product</a>',
    ]);
}

/** Splits "S, M,L" into sizes; returns [sizes, error]. */
function shopAdminParseSizes(string $text): array {
    $sizes = [];
    foreach (preg_split('~[,\n]+~', $text) as $size) {
        $size = trim($size);
        if ($size === '') continue;
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9 ./+-]{0,30}$~', $size)) return [[], '“' . $size . '” isn’t a valid size. Use letters, digits, spaces and - . / + only.'];
        if (!in_array(strtolower($size), array_map('strtolower', $sizes), true)) $sizes[] = $size;
    }
    return count($sizes) > SHOP_ADMIN_MAX_SIZES ? [[], 'A product can have up to ' . SHOP_ADMIN_MAX_SIZES . ' sizes.'] : [$sizes, null];
}

/** Validates and saves the posted product; redirects on success, else returns [input, errors] to show again. */
function saveAdminShopProduct(array $saved): array {
    $id = $saved['id'];
    $input = ['id' => $id, 'name' => postedText('name'), 'slug' => postedText('slug'), 'brand' => postedText('brand'), 'category_id' => postedText('category_id'), 'short_description' => postedText('short_description'),
        'description' => postedText('description'), 'price' => postedText('price'), 'compare_price' => postedText('compare_price'), 'gst_rate' => postedText('gst_rate'), 'position' => postedText('position') ?: '0',
        'is_active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0, 'stock' => postedText('stock') ?: '0', 'sizes' => postedText('sizes'), 'features' => postedText('features'), 'specs' => postedText('specs'),
        'image' => $saved['image'], 'gallery' => $saved['gallery'], 'variants' => $saved['variants'], 'colours' => []];
    $errors = [];
    if ($input['name'] === '') $errors['name'] = 'Enter the product name.';
    elseif (mb_strlen($input['name']) > 512) $errors['name'] = 'The name must be 512 characters or fewer.';
    if (mb_strlen($input['brand']) > 255) $errors['brand'] = 'The brand must be 255 characters or fewer.';
    if (mb_strlen($input['short_description']) > 500) $errors['short_description'] = 'The short description must be 500 characters or fewer.';
    if (mb_strlen($input['description']) > 10000) $errors['description'] = 'The description must be 10,000 characters or fewer.';
    if ($input['slug'] !== '' && !preg_match('~^[a-z0-9]+(-[a-z0-9]+)*$~', $input['slug'])) $errors['slug'] = 'Use lower case letters, digits and hyphens only, or leave it empty.';
    $price = shopAdminAmount($input['price']);
    if ($price === null || $price <= 0) $errors['price'] = 'Enter the price in rupees, more than 0.';
    $compare = null;
    if ($input['compare_price'] !== '') {
        $compare = shopAdminAmount($input['compare_price']);
        if ($compare === null) $errors['compare_price'] = 'Enter an amount in rupees, or leave it empty.';
        elseif ($price !== null && $compare <= $price) $errors['compare_price'] = 'The compare-at price must be higher than the price.';
    }
    $gst = null;
    if ($input['gst_rate'] !== '') {
        $gst = shopAdminAmount($input['gst_rate'], 28);
        if ($gst === null) $errors['gst_rate'] = 'Enter a GST rate from 0 to 28, or leave it empty.';
    }
    if (!ctype_digit($input['position']) || (int) $input['position'] > 999) $errors['position'] = 'Order must be a whole number from 0 to 999.';
    $categoryId = null;
    if ($input['category_id'] !== '') {
        $categoryId = ctype_digit($input['category_id']) && query('SELECT 1 FROM shop_categories WHERE id = ?', [(int) $input['category_id']]) ? (int) $input['category_id'] : null;
        if ($categoryId === null) $errors['category_id'] = 'Choose a category from the list.';
    }

    // Colours (kept by id so stock and past orders stay attached), sizes and the stock grid.
    $existingColours = array_column($saved['colours'], null, 'id');
    $colours = [];
    foreach (is_array($_POST['colours'] ?? null) ? $_POST['colours'] : [] as $row) {
        if (!is_array($row)) continue;
        $name = trim(is_string($row['name'] ?? null) ? $row['name'] : '');
        $hex = is_string($row['hex'] ?? null) ? $row['hex'] : '';
        $rowId = is_string($row['id'] ?? null) && ctype_digit($row['id']) && isset($existingColours[(int) $row['id']]) ? (int) $row['id'] : null;
        if ($name === '') continue;
        if (mb_strlen($name) > 64) $errors['colours'] = 'A colour name must be 64 characters or fewer.';
        if (!preg_match('~^#[0-9a-fA-F]{6}$~', $hex)) $hex = '#0b50a7';
        $colours[] = ['id' => $rowId ?? '', 'name' => $name, 'hex' => strtolower($hex)];
    }
    if (count($colours) > SHOP_ADMIN_MAX_COLOURS) $errors['colours'] = 'A product can have up to ' . SHOP_ADMIN_MAX_COLOURS . ' colours.';
    if (count(array_unique(array_map(fn ($c) => strtolower($c['name']), $colours))) < count($colours)) $errors['colours'] = 'Each colour name can be used once.';
    $input['colours'] = $colours;
    [$sizes, $sizeError] = shopAdminParseSizes($input['sizes']);
    if ($sizeError) $errors['sizes'] = $sizeError;
    $variantsOn = $colours || $sizes;
    if (!$variantsOn && (!ctype_digit($input['stock']) || (int) $input['stock'] > 99999)) $errors['stock'] = 'Stock must be a whole number from 0 to 99,999.';
    $posted = is_array($_POST['vstock'] ?? null) ? $_POST['vstock'] : [];
    foreach ($posted as $bySize) foreach (is_array($bySize) ? $bySize : [] as $qty) if (!is_string($qty) || !ctype_digit($qty) || (int) $qty > 99999) $errors['sizes'] ??= 'Each stock number must be a whole number from 0 to 99,999.';

    // Photos: checked before anything is written.
    $mainUpload = shopAdminPhotoCheck('image', null, 'main photo');
    if (is_string($mainUpload)) $errors['image'] = $mainUpload;
    $galleryUploads = [];
    $names = $_FILES['gallery']['name'] ?? [];
    foreach (is_array($names) ? array_keys($names) : [] as $i) {
        $check = shopAdminPhotoCheck('gallery', (int) $i, 'photo “' . (string) $names[$i] . '”');
        if (is_string($check)) { $errors['gallery'] = $check; break; }
        if (is_array($check)) $galleryUploads[] = $check;
    }
    $removeGallery = array_map('intval', is_array($_POST['gallery_remove'] ?? null) ? $_POST['gallery_remove'] : []);
    $keep = array_values(array_filter($saved['gallery'], fn ($g) => !in_array((int) $g['position'], $removeGallery, true)));
    if (count($keep) + count($galleryUploads) > SHOP_ADMIN_MAX_GALLERY) $errors['gallery'] ??= 'A product can have up to ' . SHOP_ADMIN_MAX_GALLERY . ' extra photos.';
    $slug = null;
    if (!$errors) {
        $slug = $input['slug'] !== '' ? $input['slug'] : shopAdminSlug($input['name']);
        if ($input['slug'] !== '' && query('SELECT 1 FROM shop_products WHERE slug = ?' . ($id ? ' AND id <> ' . (int) $id : ''), [$slug])) $errors['slug'] = 'Another product already uses this web address.';
        else $slug = shopAdminUniqueSlug('shop_products', $slug, $id ? (int) $id : null);
    }
    if ($errors) return [$input, $errors];

    // Store the photos (they are removed again if the database write fails).
    $newFiles = [];
    $mainPath = $saved['image'] ?: SHOP_ADMIN_PLACEHOLDER_IMAGE;
    if (is_array($mainUpload)) {
        $path = shopAdminPhotoSave($mainUpload, SHOP_ADMIN_PRODUCT_DIR);
        if (!$path) return [$input, ['image' => 'The photo could not be saved: the folder ' . SHOP_ADMIN_PRODUCT_DIR . ' isn’t writable by the web server.']];
        $newFiles[] = $mainPath = $path;
    }
    $galleryPaths = array_column($keep, 'image');
    $orderOf = fn (array $g) => (int) ($_POST['gallery_order'][$g['position']] ?? $g['position']);
    usort($keep, fn ($a, $b) => [$orderOf($a), (int) $a['position']] <=> [$orderOf($b), (int) $b['position']]);
    $galleryPaths = array_column($keep, 'image');
    foreach ($galleryUploads as $upload) {
        $path = shopAdminPhotoSave($upload, SHOP_ADMIN_GALLERY_DIR);
        if ($path) { $newFiles[] = $path; $galleryPaths[] = $path; }
    }

    $features = array_values(array_filter(array_map('trim', explode("\n", $input['features'])), fn ($l) => $l !== ''));
    $specs = [];
    foreach (explode("\n", $input['specs']) as $line) {
        if (trim($line) === '') continue;
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $specs[] = [mb_substr(trim($name), 0, 255), mb_substr(trim($value), 0, 512)];
    }
    try {
        adminTransaction(function () use (&$id, $input, $slug, $price, $compare, $gst, $categoryId, $mainPath, $galleryPaths, $features, $specs, $colours, $sizes, $posted, $variantsOn) {
            $values = [$slug, $input['name'], $input['brand'] ?: null, $categoryId, $input['short_description'] ?: null, $input['description'] ?: null, $price, $compare, $gst, $mainPath, (int) $input['position'], $input['is_active']];
            if ($id === null) {
                adminExecute('INSERT INTO shop_products (slug, name, brand, category_id, short_description, description, price, compare_price, gst_rate, image, position, is_active, stock, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())', $values);
                $id = (int) db()->lastInsertId();
            } else {
                adminExecute('UPDATE shop_products SET slug = ?, name = ?, brand = ?, category_id = ?, short_description = ?, description = ?, price = ?, compare_price = ?, gst_rate = ?, image = ?, position = ?, is_active = ?, updated_at = NOW() WHERE id = ?', [...$values, $id]);
            }
            adminExecute('DELETE FROM shop_product_images WHERE product_id = ?', [$id]);
            foreach ($galleryPaths as $pos => $path) adminExecute('INSERT INTO shop_product_images (product_id, position, image) VALUES (?, ?, ?)', [$id, $pos, $path]);
            adminExecute('DELETE FROM shop_product_features WHERE product_id = ?', [$id]);
            foreach ($features as $pos => $feature) adminExecute('INSERT INTO shop_product_features (product_id, position, feature) VALUES (?, ?, ?)', [$id, $pos, mb_substr($feature, 0, 255)]);
            adminExecute('DELETE FROM shop_product_specs WHERE product_id = ?', [$id]);
            foreach ($specs as $pos => [$name, $value]) if ($name !== '' && $value !== '') adminExecute('INSERT INTO shop_product_specs (product_id, position, name, value) VALUES (?, ?, ?, ?)', [$id, $pos, $name, $value]);

            // Colours: update the ones kept, add new ones, remove those cleared (their variants go with them).
            $kept = [];
            $colourIds = [];
            foreach ($colours as $pos => $c) {
                if ($c['id'] !== '') { adminExecute('UPDATE shop_product_colours SET name = ?, hex = ?, position = ? WHERE id = ? AND product_id = ?', [$c['name'], $c['hex'], $pos, $c['id'], $id]); $colourIds[$pos] = (int) $c['id']; }
                else { adminExecute('INSERT INTO shop_product_colours (product_id, position, name, hex) VALUES (?, ?, ?, ?)', [$id, $pos, $c['name'], $c['hex']]); $colourIds[$pos] = (int) db()->lastInsertId(); }
                $kept[] = $colourIds[$pos];
            }
            adminExecute('DELETE FROM shop_product_colours WHERE product_id = ?' . ($kept ? ' AND id NOT IN (' . implode(',', $kept) . ')' : ''), [$id]);

            // Variants: one row per colour and size, updated in place so past carts and orders keep their ids.
            $existing = [];
            foreach (query('SELECT id, colour_id, size, stock FROM shop_product_variants WHERE product_id = ?', [$id]) as $v) $existing[(int) $v['colour_id'] . '|' . $v['size']] = $v;
            $wanted = [];
            $total = 0;
            if ($variantsOn) {
                foreach ($colourIds ?: [0] as $cid) foreach ($sizes ?: [''] as $size) {
                    $key = $cid . '|' . $size;
                    $qty = isset($posted[$cid][$size]) && is_string($posted[$cid][$size]) ? (int) $posted[$cid][$size] : (int) ($existing[$key]['stock'] ?? 0);
                    $total += $qty;
                    $wanted[$key] = true;
                    if (isset($existing[$key])) adminExecute('UPDATE shop_product_variants SET stock = ? WHERE id = ?', [$qty, $existing[$key]['id']]);
                    else adminExecute('INSERT INTO shop_product_variants (product_id, colour_id, size, stock) VALUES (?, ?, ?, ?)', [$id, $cid ?: null, $size, $qty]);
                }
            } else {
                $total = (int) $input['stock'];
            }
            foreach ($existing as $key => $v) if (!isset($wanted[$key])) adminExecute('DELETE FROM shop_product_variants WHERE id = ?', [$v['id']]);
            adminExecute('UPDATE shop_products SET stock = ? WHERE id = ?', [$total, $id]);
        });
    } catch (Throwable $error) {
        foreach ($newFiles as $path) shopAdminPhotoDelete($path);
        throw $error;
    }
    // Replaced photos are removed once the new ones are safely stored.
    if (is_array($mainUpload) && $saved['image'] && $saved['image'] !== SHOP_ADMIN_PLACEHOLDER_IMAGE) shopAdminPhotoDelete($saved['image']);
    foreach ($saved['gallery'] as $g) if (!in_array($g['image'], $galleryPaths, true)) shopAdminPhotoDelete($g['image']);
    adminFlash(($saved['id'] === null ? 'Added ' : 'Saved ') . $input['name'] . '.' . ($saved['id'] === null && $variantsOn ? ' Set the stock for each colour and size below.' : ''));
    adminRedirect('/admin/shop/products/' . $id);
}

/** Never-ordered products can be deleted for good; ordered ones stay (their orders name them) and can only be hidden. */
function renderAdminShopProductDelete(array $admin, int $id): string {
    $product = query('SELECT * FROM shop_products WHERE id = ?', [$id])[0] ?? notFound();
    $ordered = (int) query('SELECT COUNT(*) AS n FROM shop_order_items WHERE product_id = ?', [$id])[0]['n'];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if ($ordered) {
            adminExecute('UPDATE shop_products SET is_active = 0, updated_at = NOW() WHERE id = ?', [$id]);
            adminFlash($product['name'] . ' was ordered before, so it is hidden instead of deleted.');
            adminRedirect('/admin/shop/products');
        }
        $images = array_column(query('SELECT image FROM shop_product_images WHERE product_id = ?', [$id]), 'image');
        adminTransaction(function () use ($id) {
            foreach (['shop_product_images', 'shop_product_features', 'shop_product_specs', 'shop_product_variants', 'shop_product_colours', 'shop_reviews'] as $table) adminExecute("DELETE FROM $table WHERE product_id = ?", [$id]);
            adminExecute('DELETE FROM shop_products WHERE id = ?', [$id]);
        });
        foreach ([$product['image'], ...$images] as $path) shopAdminPhotoDelete($path);
        adminFlash('Deleted ' . $product['name'] . '.');
        adminRedirect('/admin/shop/products');
    }
    $body = $ordered
        ? '<p class="admin-alert" role="alert"><strong>' . escape($product['name']) . '</strong> has been ordered ' . $ordered . ' time' . ($ordered === 1 ? '' : 's') . ', so it can’t be deleted: past orders refer to it. You can hide it from the shop instead.</p>'
            . '<form method="post" action="{{BASE}}/admin/shop/products/' . $id . '/delete" class="admin-form-actions"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '"><button type="submit" class="admin-button-danger">Hide this product</button><a class="admin-button-secondary" href="{{BASE}}/admin/shop/products/' . $id . '">Cancel</a></form>'
        : deleteConfirmation('product', $product['name'], '/admin/shop/products/' . $id . '/delete', '/admin/shop/products/' . $id);
    return adminView('templates.admin.delete', $admin, '/admin/shop/products', ['title' => 'Delete ' . escape($product['name']), 'image' => shopAdminThumb($product['image'], 'admin-logo-preview'),
        'back' => '<a href="{{BASE}}/admin/shop/products">&larr; All products</a>', 'body' => $body]);
}
