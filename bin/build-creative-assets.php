<?php
declare(strict_types=1);

// Writes the member creative's default background (assets/creative/bg-square.png) and a sample
// preview (assets/creative/sample.png) showing where the photo, name, tier and link land:
//   php bin/build-creative-assets.php             write both (keeps an existing background)
//   php bin/build-creative-assets.php --reset     replace the background with the default again
//   php bin/build-creative-assets.php --sample    only redraw sample.png (after changing the design)
// To use your own design, export a 1080 x 1080 PNG over assets/creative/bg-square.png: see
// assets/creative/README.md.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/site.php';
require_once __DIR__ . '/../includes/member-creative.php';

$folder = __DIR__ . '/../assets/creative';
$background = $folder . '/bg-square.png';
if (!in_array('--sample', $argv, true) && (in_array('--reset', $argv, true) || !is_file($background))) {
    imagepng(creativeDefaultBackground(memberCreativeLayout()['size']), $background, 6);
    echo "wrote assets/creative/bg-square.png\n";
}
$sample = ['id' => 0, 'slug' => 'your-business', 'business_name' => 'Your Business Name', 'tier' => 'gold', 'city' => 'Shimla', 'photo' => null, 'logo' => null];
imagepng(memberCreativeImage($sample), $folder . '/sample.png', 6);
echo "wrote assets/creative/sample.png\n";
