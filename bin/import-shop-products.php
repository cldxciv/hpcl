<?php
declare(strict_types=1);

// Loads the owner's product list into the shop from a CSV file.
//
//   php bin/import-shop-products.php products.csv --images=C:\path\to\photos [--dry-run] [--keep-stock] [--draft]
//
//   --dry-run     check everything and print what would happen; nothing is written (images are only checked)
//   --images=DIR  folder holding the photo files named in the 'image' and 'gallery' columns (URLs also work)
//   --keep-stock  on a re-run, leave the stock of variants that already exist alone (only new variants get the CSV stock)
//   --draft       products without an 'active' value are saved hidden (is_active = 0) instead of live
//
// Run bin/migrate-shop.php once first. The command is safe to run again: products are matched by slug and
// updated, not duplicated, and an unchanged photo is reused. If any row is wrong nothing at all is saved.
// By default a re-run also resets stock to the CSV values: use --keep-stock once the shop is taking orders.
// Photos are re-encoded as JPEG (longest side 1600 px) into storage/uploads/shop/products/ and
// storage/uploads/shop/products/gallery/. That folder is not in git: upload it to the live server with the
// other files after importing (or run the importer on the server).
//
// CSV FORMAT (UTF-8; comma, semicolon or tab separated; the first row is the header; Excel "CSV UTF-8" is fine)
//   name*           product name
//   price*          selling price in rupees, GST included (500 or 499.50)
//   category        'Apparel > Jerseys' (Parent > Child, at most two levels; missing ones are created)
//   slug            URL name (letters, numbers, dashes); made from the name when empty. It identifies the product on re-runs.
//   brand, short_description, description
//   compare_price   the old / MRP price; shown struck through and as "% off" when above price
//   gst_rate        e.g. 12 (information only; prices already include GST)
//   colours         'Navy:#0b2a5b|White:#ffffff' (name:hex, separated by |). Common names (navy, white, orange...) need no hex.
//   sizes           'S,M,L,XL'
//   stock           units for EACH colour/size combination
//   stock_total     alternatively a total, split evenly over the combinations
//   stock_S, stock_M, stock_XL ...   alternatively one column per size (applies to every colour)
//   image           main photo: a file name inside --images, or an http(s) URL (required for a new product)
//   gallery         more photos, separated by ;
//   features        bullet points, separated by ;
//   specs           'Fabric=Polyester;Fit=Regular' (label=value pairs, separated by ;)
//   active          1 or 0 (yes/no too); 0 keeps the product hidden until you switch it on in admin
// A product can be one row (all its colours and sizes in the cells) or several rows with the same slug/name,
// one per colour or size; fields on later rows are optional but may not contradict the first row.
// A product without colours or sizes is sold from a single stock figure (stock or stock_total).
// See database/shop-products-sample.csv.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/shop-import.php';

$file = null;
$options = ['dry_run' => false, 'images_dir' => null, 'keep_stock' => false, 'default_active' => 1];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') $options['dry_run'] = true;
    elseif ($arg === '--keep-stock') $options['keep_stock'] = true;
    elseif ($arg === '--draft') $options['default_active'] = 0;
    elseif (str_starts_with($arg, '--images=')) $options['images_dir'] = substr($arg, 9);
    elseif ($arg === '--help' || $arg === '-h') { echo "Usage: php bin/import-shop-products.php products.csv [--images=DIR] [--dry-run] [--keep-stock] [--draft]\nSee the top of this file for the CSV format.\n"; exit(0); }
    elseif (str_starts_with($arg, '--')) { fwrite(STDERR, "Unknown option $arg\n"); exit(2); }
    else $file = $arg;
}
if ($file === null) { fwrite(STDERR, "Usage: php bin/import-shop-products.php products.csv [--images=DIR] [--dry-run]\n"); exit(2); }
if ($options['images_dir'] !== null && !is_dir($options['images_dir'])) { fwrite(STDERR, "Images folder not found: {$options['images_dir']}\n"); exit(2); }
try {
    $records = shopImportReadCsv($file);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
$report = shopImportRecords($records, $options);
echo shopImportSummary($report), "\n";
exit($report['ok'] ? 0 : 1);
