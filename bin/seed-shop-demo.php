<?php
declare(strict_types=1);

// A DEMO catalogue for trying the shop: team jerseys and t-shirts, caps, wristbands, a kit bag, a bat and a ball,
// with indicative prices and on-brand placeholder pictures. It is NOT the real catalogue (that comes from the
// owner's list, see bin/import-shop-products.php) and it never shows publicly by itself:
//
//   php bin/seed-shop-demo.php             create/refresh the demo products as DRAFTS (is_active = 0)
//   php bin/seed-shop-demo.php --publish   same, but switched on, to click through the public shop locally
//   php bin/seed-shop-demo.php --reset     remove exactly what this script created and nothing else
//
// Everything it creates is easy to tell apart: product slugs start with "demo-", their pictures are named
// demo-*.jpg, and the categories it had to create are recorded in site_settings (shop_demo_seed) so --reset can
// remove those too, when they are empty. Run bin/migrate-shop.php first. Do not run this on the live site.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/shop-import.php';

const DEMO_PREFIX = 'demo-';
const DEMO_SETTING = 'shop_demo_seed';
$root = dirname(__DIR__);
$options = array_slice($argv, 1);
foreach ($options as $option) if (!in_array($option, ['--publish', '--reset'], true)) { fwrite(STDERR, "Usage: php bin/seed-shop-demo.php [--publish | --reset]\n"); exit(2); }
if (appEnv() === 'production') { fwrite(STDERR, "Refusing to seed demo products when APP_ENV=production.\n"); exit(1); }

if (in_array('--reset', $options, true)) {
    echo implode("\n", shopDemoReset($root)), "\n";
    exit(0);
}

$images = $root . '/storage/uploads/shop/placeholders';
if (!is_file($images . '/jersey.jpg')) {
    echo "Drawing placeholder pictures first...\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build-shop-placeholders.php'), $code);
    if ($code !== 0) exit($code);
}
$publish = in_array('--publish', $options, true);
$note = 'DEMO PRODUCT: price and details are indicative and will be replaced by the real catalogue.';
$active = $publish ? '1' : '0';
$records = [
    ['category' => 'Apparel > Jerseys', 'name' => 'Pro HPCL Season 4 Official Jersey', 'slug' => DEMO_PREFIX . 'official-jersey', 'brand' => 'Pro HPCL',
        'short_description' => 'Breathable match jersey in Pro HPCL colours.', 'description' => "Lightweight dry-fit jersey with the Pro HPCL crest, made for match day and the stands.\n\n$note",
        'price' => '899', 'compare_price' => '1199', 'gst_rate' => '12', 'colours' => 'Orange:#ff7a18|Navy:#0b2a5b', 'sizes' => 'S,M,L,XL,XXL', 'stock' => '10',
        'image' => 'jersey.jpg', 'gallery' => 'jersey-2.jpg;jersey-3.jpg', 'features' => 'Dry-fit polyester;Moisture wicking;Pro HPCL crest on the chest;Machine washable',
        'specs' => 'Fabric=Polyester;Fit=Regular;Wash=Machine wash cold'],
    ['category' => 'Apparel > Jerseys', 'name' => 'Team Jersey - custom name & number', 'slug' => DEMO_PREFIX . 'custom-jersey', 'brand' => 'Pro HPCL',
        'short_description' => 'Your team colours with your own name and number.', 'description' => "Order a jersey with the name and number you want. Tell us the name and number in the order note at checkout; made to order in about 7 days.\n\n$note",
        'price' => '1299', 'gst_rate' => '12', 'colours' => 'Orange:#ff7a18|Navy:#0b2a5b', 'sizes' => 'S,M,L,XL', 'stock_S' => '6', 'stock_M' => '12', 'stock_L' => '12', 'stock_XL' => '6',
        'image' => 'custom-jersey.jpg', 'gallery' => 'custom-jersey-2.jpg;custom-jersey-3.jpg', 'features' => 'Printed name and number;Made to order, allow 7 days;Same fabric as the official jersey', 'specs' => 'Fabric=Polyester;Print=Heat transfer'],
    ['category' => 'Apparel > T-Shirts', 'name' => 'Pro HPCL Fan T-Shirt', 'slug' => DEMO_PREFIX . 'fan-tshirt', 'brand' => 'Pro HPCL',
        'short_description' => 'Soft cotton fan tee with the Pro HPCL print.', 'description' => "Everyday cotton t-shirt for the fans.\n\n$note",
        'price' => '449', 'compare_price' => '599', 'gst_rate' => '12', 'colours' => 'White|Navy|Orange:#ff7a18', 'sizes' => 'S,M,L,XL,XXL', 'stock_total' => '150',
        'image' => 'tshirt.jpg', 'gallery' => 'tshirt-2.jpg;tshirt-3.jpg', 'features' => '100% combed cotton;Screen-printed logo;Unisex fit', 'specs' => 'Fabric=Cotton;GSM=180'],
    ['category' => 'Accessories > Caps', 'name' => 'Pro HPCL Cap', 'slug' => DEMO_PREFIX . 'cap', 'brand' => 'Pro HPCL',
        'short_description' => 'Adjustable cotton cap with an embroidered logo.', 'description' => "Adjustable strap, one size fits all.\n\n$note",
        'price' => '299', 'gst_rate' => '12', 'colours' => 'Navy:#0b2a5b', 'stock' => '30', 'image' => 'cap.jpg', 'gallery' => 'cap-2.jpg;cap-3.jpg',
        'features' => 'Adjustable strap;Embroidered logo;Breathable cotton', 'specs' => 'Size=Free size'],
    ['name' => 'Pro HPCL Cap', 'slug' => DEMO_PREFIX . 'cap', 'colours' => 'Orange:#ff7a18', 'stock' => '30'],
    ['category' => 'Accessories > Wristbands', 'name' => 'Pro HPCL Wristband (pair)', 'slug' => DEMO_PREFIX . 'wristband', 'brand' => 'Pro HPCL',
        'short_description' => 'Sweat-absorbing terry wristbands, sold as a pair.', 'description' => "A pair of terry wristbands.\n\n$note",
        'price' => '149', 'gst_rate' => '12', 'stock' => '200', 'image' => 'wristband.jpg', 'gallery' => 'wristband-2.jpg;wristband-3.jpg', 'features' => 'Pair of two;Machine washable'],
    ['category' => 'Accessories > Bags', 'name' => 'Pro HPCL Kit Bag', 'slug' => DEMO_PREFIX . 'kit-bag', 'brand' => 'Pro HPCL',
        'short_description' => 'Roomy kit bag with a bat sleeve and a shoe compartment.', 'description' => "Heavy-duty kit bag for the whole kit.\n\n$note",
        'price' => '1799', 'compare_price' => '2299', 'gst_rate' => '18', 'colours' => 'Navy:#0b2a5b|Black', 'stock' => '10', 'image' => 'kitbag.jpg', 'gallery' => 'kitbag-2.jpg;kitbag-3.jpg',
        'features' => 'Shoe compartment;Padded shoulder straps;Water-resistant base', 'specs' => 'Capacity=60 L'],
    ['category' => 'Cricket Gear > Bats', 'name' => 'Cricket Bat (Kashmir willow)', 'slug' => DEMO_PREFIX . 'kashmir-willow-bat', 'brand' => 'Pro HPCL',
        'short_description' => 'Kashmir willow bat for tennis-ball and practice cricket.', 'description' => "Pre-knocked Kashmir willow bat with Pro HPCL stickers.\n\n$note",
        'price' => '1499', 'gst_rate' => '12', 'sizes' => 'Short Handle,Full Size', 'stock_total' => '24', 'image' => 'bat.jpg', 'gallery' => 'bat-2.jpg;bat-3.jpg',
        'features' => 'Cane handle;Pre-knocked;Free bat grip', 'specs' => 'Willow=Kashmir;Weight=1.1-1.2 kg'],
    ['category' => 'Cricket Gear > Balls', 'name' => 'Cricket Ball (leather)', 'slug' => DEMO_PREFIX . 'leather-ball', 'brand' => 'Pro HPCL',
        'short_description' => 'Four-piece hand-stitched leather ball.', 'description' => "Hand-stitched four-piece leather ball.\n\n$note",
        'price' => '349', 'gst_rate' => '12', 'colours' => 'Red:#b3202a', 'stock' => '100', 'image' => 'ball.jpg', 'gallery' => 'ball-2.jpg;ball-3.jpg', 'features' => 'Hand stitched;Four piece', 'specs' => 'Weight=156 g'],
];
foreach ($records as $i => &$record) { $record['_line'] = $i + 2; $record['active'] = $active; }
unset($record);

$report = shopImportRecords($records, ['images_dir' => $images, 'default_active' => $publish ? 1 : 0]);
echo shopImportSummary($report), "\n";
if (!$report['ok']) exit(1);
if ($report['category_ids']) {
    $marker = json_decode((string) (query('SELECT value FROM site_settings WHERE name = ?', [DEMO_SETTING])[0]['value'] ?? ''), true) ?: ['categories' => []];
    $marker['categories'] = array_values(array_unique([...$marker['categories'], ...$report['category_ids']]));
    db()->prepare('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([DEMO_SETTING, json_encode($marker)]);
}
echo $publish ? "The demo products are ACTIVE. Remove them with: php bin/seed-shop-demo.php --reset\n" : "The demo products are DRAFTS (hidden). Preview locally with --publish; remove with --reset.\n";

/** Removes the demo products (slug demo-*), their pictures and the categories the seed created, if empty. */
function shopDemoReset(string $root): array {
    $out = [];
    $removed = 0;
    foreach (query('SELECT id, slug, image FROM shop_products WHERE slug LIKE ?', [DEMO_PREFIX . '%']) as $product) {
        $id = (int) $product['id'];
        try { $ordered = (bool) query('SELECT 1 FROM shop_order_items WHERE product_id = ? LIMIT 1', [$id]); } catch (Throwable) { $ordered = false; }
        if ($ordered) { $out[] = "kept {$product['slug']}: an order refers to it (deactivated instead)"; db()->prepare('UPDATE shop_products SET is_active = 0 WHERE id = ?')->execute([$id]); continue; }
        $files = array_merge([$product['image']], array_column(query('SELECT image FROM shop_product_images WHERE product_id = ?', [$id]), 'image'));
        foreach (['shop_product_variants', 'shop_product_colours', 'shop_product_images', 'shop_product_features', 'shop_product_specs', 'shop_reviews'] as $table) {
            db()->prepare("DELETE FROM $table WHERE product_id = ?")->execute([$id]);
        }
        db()->prepare('DELETE FROM shop_products WHERE id = ?')->execute([$id]);
        foreach ($files as $file) {
            // Only the demo's own pictures, and only inside the shop upload folder.
            if (preg_match('~^storage/uploads/shop/products/(gallery/)?' . preg_quote(DEMO_PREFIX, '~') . '[a-z0-9-]+\.jpg$~', (string) $file) && is_file($root . '/' . $file)) @unlink($root . '/' . $file);
        }
        $removed++;
    }
    $out[] = "removed $removed demo product(s)";
    $marker = json_decode((string) (query('SELECT value FROM site_settings WHERE name = ?', [DEMO_SETTING])[0]['value'] ?? ''), true) ?: ['categories' => []];
    $gone = 0;
    // Deepest first: children before the parents they hang under.
    foreach (query('SELECT id, name, parent_id FROM shop_categories WHERE id IN (' . implode(',', array_map('intval', $marker['categories'] ?: [0])) . ') ORDER BY parent_id IS NULL, id DESC') as $category) {
        $used = query('SELECT (SELECT COUNT(*) FROM shop_products WHERE category_id = ?) + (SELECT COUNT(*) FROM shop_categories WHERE parent_id = ?) AS n', [$category['id'], $category['id']])[0]['n'];
        if ($used) { $out[] = "kept category {$category['name']}: still in use"; continue; }
        db()->prepare('DELETE FROM shop_categories WHERE id = ?')->execute([$category['id']]);
        $gone++;
    }
    $out[] = "removed $gone categor" . ($gone === 1 ? 'y' : 'ies') . ' created by the seed';
    db()->prepare('DELETE FROM site_settings WHERE name = ?')->execute([DEMO_SETTING]);
    return $out;
}
