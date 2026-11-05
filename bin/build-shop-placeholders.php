<?php
declare(strict_types=1);

// Draws on-brand PLACEHOLDER product pictures for the shop (jersey, t-shirt, cap, wristband, kit bag, bat,
// ball, custom jersey) with GD + FreeType, 1000x1000 JPEG, three looks each, into
// storage/uploads/shop/placeholders/   (<key>.jpg main, <key>-2.jpg orange, <key>-3.jpg light).
// Every picture carries a "PLACEHOLDER" tag so nobody mistakes it for a real product photo; replace them
// with the owner's real photos (bin/import-shop-products.php, or admin).
//
//   php bin/build-shop-placeholders.php [--only=jersey,cap]
// Used by bin/seed-shop-demo.php. Fonts: assets/fonts (Bebas Neue, Barlow; SIL OFL).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const PLACEHOLDER_DIR = 'storage/uploads/shop/placeholders';
const PLACEHOLDER_ITEMS = [
    'jersey' => 'OFFICIAL JERSEY', 'tshirt' => 'FAN T-SHIRT', 'cap' => 'PRO HPCL CAP', 'wristband' => 'WRISTBANDS',
    'kitbag' => 'KIT BAG', 'bat' => 'CRICKET BAT', 'ball' => 'CRICKET BALL', 'custom-jersey' => 'CUSTOM JERSEY',
];
// Three looks: background, then the product's body / accent / trim colours.
const PLACEHOLDER_LOOKS = [
    1 => ['bg' => ['#0b50a7', '#072c5e'], 'body' => '#ff7a18', 'accent' => '#ffffff', 'trim' => '#0b2a5b', 'text' => '#ffffff', 'dots' => true, 'zoom' => 1.0],
    2 => ['bg' => ['#ff8a2e', '#d85c08'], 'body' => '#0b2a5b', 'accent' => '#ffffff', 'trim' => '#ff7a18', 'text' => '#0b2a5b', 'dots' => true, 'zoom' => 1.0],
    3 => ['bg' => ['#f6f8fc', '#dfe7f3'], 'body' => '#0b50a7', 'accent' => '#ff7a18', 'trim' => '#ffffff', 'text' => '#0b2a5b', 'dots' => false, 'zoom' => 1.08],
];

$root = dirname(__DIR__);
$fonts = ['display' => $root . '/assets/fonts/BebasNeue-Regular.ttf', 'bold' => $root . '/assets/fonts/Barlow-Bold.ttf', 'medium' => $root . '/assets/fonts/Barlow-Medium.ttf'];
foreach ($fonts as $font) if (!is_file($font)) { fwrite(STDERR, "Missing font $font\n"); exit(1); }
if (!function_exists('imagettftext')) { fwrite(STDERR, "GD with FreeType is needed\n"); exit(1); }
$only = null;
foreach (array_slice($argv, 1) as $arg) if (str_starts_with($arg, '--only=')) $only = explode(',', substr($arg, 7));

$out = $root . '/' . PLACEHOLDER_DIR;
if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) { fwrite(STDERR, "Cannot create $out\n"); exit(1); }
foreach (PLACEHOLDER_ITEMS as $key => $label) {
    if ($only && !in_array($key, $only, true)) continue;
    foreach (PLACEHOLDER_LOOKS as $look => $palette) {
        $image = placeholderImage($key, $label, $palette, $fonts);
        $file = $out . '/' . $key . ($look === 1 ? '' : '-' . $look) . '.jpg';
        imagejpeg($image, $file, 90);
        echo 'wrote ', PLACEHOLDER_DIR . '/' . basename($file), ' (', filesize($file), " bytes)\n";
    }
}

function phColor(GdImage $im, string $hex, int $alpha = 0): int {
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return imagecolorallocatealpha($im, $r, $g, $b, $alpha);
}
function phShade(string $hex, float $factor): string {
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    $f = fn ($c) => max(0, min(255, (int) round($factor >= 0 ? $c + (255 - $c) * $factor : $c * (1 + $factor))));
    return sprintf('#%02x%02x%02x', $f($r), $f($g), $f($b));
}
/** Text with letter spacing; returns its width. $anchor: left, center or right of $x. */
function phText(GdImage $im, string $font, float $size, int $x, int $baseline, int $color, string $text, int $tracking = 0, string $anchor = 'left'): int {
    $widths = [];
    foreach (mb_str_split($text) as $char) { $box = imagettfbbox($size, 0, $font, $char); $widths[] = ($box[2] - $box[0]) + ($char === ' ' ? (int) ($size * 0.3) : 0); }
    $total = array_sum($widths) + $tracking * max(0, count($widths) - 1);
    $cursor = match ($anchor) { 'center' => $x - intdiv($total, 2), 'right' => $x - $total, default => $x };
    foreach (mb_str_split($text) as $i => $char) { imagettftext($im, $size, 0, $cursor, $baseline, $color, $font, $char); $cursor += $widths[$i] + $tracking; }
    return $total;
}

function placeholderImage(string $key, string $label, array $look, array $fonts): GdImage {
    $S = 2000;
    $im = imagecreatetruecolor($S, $S);
    imagealphablending($im, true);
    // Background gradient, a soft glow and a dot grid, as on the website.
    [$top, $bottom] = $look['bg'];
    [$r1, $g1, $b1] = sscanf($top, '#%02x%02x%02x');
    [$r2, $g2, $b2] = sscanf($bottom, '#%02x%02x%02x');
    for ($y = 0; $y < $S; $y++) {
        $t = $y / ($S - 1);
        imageline($im, 0, $y, $S, $y, imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t)));
    }
    $glow = $look['dots'] ? phColor($im, '#ffffff', 112) : phColor($im, '#ffffff', 40);
    imagefilledellipse($im, (int) ($S * 0.5), (int) ($S * 0.48), (int) ($S * 0.84), (int) ($S * 0.84), $glow);
    imagefilledellipse($im, (int) ($S * 0.5), (int) ($S * 0.48), (int) ($S * 0.64), (int) ($S * 0.64), $glow);
    if ($look['dots']) {
        $dot = phColor($im, '#ffffff', 108);
        for ($y = (int) ($S * 0.14); $y < $S * 0.20; $y += 52) for ($x = (int) ($S * 0.58); $x < $S * 0.94; $x += 52) imagefilledellipse($im, $x, $y, 9, 9, $dot);
    }
    // Ground shadow.
    imagefilledellipse($im, (int) ($S * 0.5), (int) ($S * 0.80), (int) ($S * 0.56 * $look['zoom']), (int) ($S * 0.05), phColor($im, '#000000', 100));

    $k = $look['zoom'];
    $c = ['body' => phColor($im, $look['body']), 'bodyDark' => phColor($im, phShade($look['body'], -0.18)), 'bodyLight' => phColor($im, phShade($look['body'], 0.22)),
        'accent' => phColor($im, $look['accent']), 'trim' => phColor($im, $look['trim']), 'white' => phColor($im, '#ffffff'), 'ink' => phColor($im, '#0b2a5b')];
    $draw = 'placeholderDraw' . str_replace('-', '', ucwords($key, '-'));
    $draw($im, $S, $k, $c, $look, $fonts);

    // Brand and label, then the placeholder tag.
    $text = phColor($im, $look['text']);
    phText($im, $fonts['display'], 118, 110, 220, $text, 'PRO HPCL', 8);
    phText($im, $fonts['bold'], 38, 112, 280, phColor($im, $look['bg'][0] === '#0b50a7' ? '#ffb070' : ($look['dots'] ? '#0b2a5b' : '#ff7a18')), 'HIMACHAL PREMIUM CRICKET LEAGUE', 4);
    phText($im, $fonts['display'], 176, (int) ($S / 2), 1790, $text, $label, 8, 'center');
    $tag = phColor($im, '#ffffff');
    $tagWidth = phText($im, $fonts['bold'], 36, -9999, -9999, $tag, 'PLACEHOLDER IMAGE', 5);
    imagefilledrectangle($im, $S - 70 - $tagWidth - 44, 70, $S - 70, 150, phColor($im, $look['dots'] ? '#000000' : '#0b2a5b', $look['dots'] ? 70 : 20));
    phText($im, $fonts['bold'], 36, $S - 92, 124, phColor($im, '#ffffff'), 'PLACEHOLDER IMAGE', 5, 'right');

    $out = imagecreatetruecolor(1000, 1000);
    imagecopyresampled($out, $im, 0, 0, 0, 0, 1000, 1000, $S, $S);
    return $out;
}

/** Scales a list of [x, y] points (0..1 of the canvas) about the centre and returns the flat pixel list. */
function phPoints(array $points, int $S, float $k, float $cy = 0.5): array {
    $flat = [];
    foreach ($points as [$x, $y]) { $flat[] = (int) round(($S / 2) + ($x - 0.5) * $k * $S); $flat[] = (int) round($S * $cy + ($y - $cy) * $k * $S); }
    return $flat;
}
function phPoly(GdImage $im, array $points, int $S, float $k, int $color): void {
    imagefilledpolygon($im, phPoints($points, $S, $k), $color);
}
function phEllipse(GdImage $im, float $x, float $y, float $w, float $h, int $S, float $k, int $color): void {
    imagefilledellipse($im, (int) round($S / 2 + ($x - 0.5) * $k * $S), (int) round($S / 2 + ($y - 0.5) * $k * $S), (int) round($w * $k * $S), (int) round($h * $k * $S), $color);
}
function phRect(GdImage $im, float $x1, float $y1, float $x2, float $y2, int $S, float $k, int $color, int $radius = 0): void {
    $px = fn (float $x) => (int) round($S / 2 + ($x - 0.5) * $k * $S);
    [$a, $b, $c, $d] = [$px($x1), $px($y1), $px($x2), $px($y2)];
    if ($radius <= 0) { imagefilledrectangle($im, $a, $b, $c, $d, $color); return; }
    $r = (int) round($radius * $k);
    imagefilledrectangle($im, $a + $r, $b, $c - $r, $d, $color);
    imagefilledrectangle($im, $a, $b + $r, $c, $d - $r, $color);
    foreach ([[$a + $r, $b + $r], [$c - $r, $b + $r], [$a + $r, $d - $r], [$c - $r, $d - $r]] as [$cx, $cy]) imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
}

/** The jersey/t-shirt outline: $sleeve is how far the sleeves reach down. */
function phShirt(GdImage $im, int $S, float $k, int $body, int $dark, float $sleeve, bool $v, int $collar): void {
    $pts = [[0.41, 0.24], [0.30, 0.27], [0.15, 0.30 + $sleeve * 0.2], [0.14 + 0.02, 0.30 + $sleeve], [0.26, 0.36 + $sleeve], [0.30, 0.34], [0.30, 0.78], [0.70, 0.78], [0.70, 0.34],
        [0.74, 0.36 + $sleeve], [0.86 - 0.02, 0.30 + $sleeve], [0.85, 0.30 + $sleeve * 0.2], [0.70, 0.27], [0.59, 0.24]];
    phPoly($im, $pts, $S, $k, $body);
    // Shading on the right half and sleeve edges.
    phPoly($im, [[0.59, 0.24], [0.70, 0.27], [0.85, 0.30 + $sleeve * 0.2], [0.86 - 0.02, 0.30 + $sleeve], [0.74, 0.36 + $sleeve], [0.70, 0.34], [0.70, 0.78], [0.62, 0.78], [0.62, 0.30]], $S, $k, $dark);
    if ($v) phPoly($im, [[0.41, 0.24], [0.59, 0.24], [0.50, 0.37]], $S, $k, $collar);
    else { phEllipse($im, 0.5, 0.24, 0.20, 0.09, $S, $k, $collar); }
}

function placeholderDrawJersey(GdImage $im, int $S, float $k, array $c, array $look, array $fonts): void {
    phShirt($im, $S, $k, $c['body'], $c['bodyDark'], 0.08, true, $c['trim']);
    // Side stripes, chest wordmark and a number.
    phPoly($im, [[0.30, 0.38], [0.345, 0.38], [0.345, 0.78], [0.30, 0.78]], $S, $k, $c['accent']);
    phPoly($im, [[0.655, 0.38], [0.70, 0.38], [0.70, 0.78], [0.655, 0.78]], $S, $k, $c['accent']);
    phPoly($im, [[0.355, 0.38], [0.368, 0.38], [0.368, 0.78], [0.355, 0.78]], $S, $k, $c['trim']);
    phPoly($im, [[0.632, 0.38], [0.645, 0.38], [0.645, 0.78], [0.632, 0.78]], $S, $k, $c['trim']);
    phText($im, $fonts['display'], (int) (150 * $k), (int) ($S / 2), (int) ($S * (0.5 + (0.50 - 0.5) * $k) + 70 * $k), $c['accent'], 'HPCL', 6, 'center');
    phText($im, $fonts['display'], (int) (210 * $k), (int) ($S / 2), (int) ($S * 0.5 + 330 * $k), $c['trim'], '10', 4, 'center');
}
function placeholderDrawTshirt(GdImage $im, int $S, float $k, array $c, array $look, array $fonts): void {
    $body = $look['bg'][0] === '#0b50a7' ? $c['white'] : $c['body'];
    phShirt($im, $S, $k, $body, phColor($im, $look['bg'][0] === '#0b50a7' ? '#d6e0ef' : phShade($look['body'], -0.18)), 0.0, false, $c['trim']);
    $print = $look['bg'][0] === '#0b50a7' ? phColor($im, '#ff7a18') : $c['accent'];
    phText($im, $fonts['display'], (int) (190 * $k), (int) ($S / 2), (int) ($S * 0.5 + 40 * $k), $print, 'PRO', 6, 'center');
    phText($im, $fonts['display'], (int) (190 * $k), (int) ($S / 2), (int) ($S * 0.5 + 215 * $k), $print, 'HPCL', 6, 'center');
}
function placeholderDrawCustomJersey(GdImage $im, int $S, float $k, array $c, array $look, array $fonts): void {
    // Back view: round collar, a name above a big number.
    phShirt($im, $S, $k, $c['body'], $c['bodyDark'], 0.08, false, $c['trim']);
    phPoly($im, [[0.30, 0.34], [0.345, 0.34], [0.345, 0.78], [0.30, 0.78]], $S, $k, $c['accent']);
    phPoly($im, [[0.655, 0.34], [0.70, 0.34], [0.70, 0.78], [0.655, 0.78]], $S, $k, $c['accent']);
    phText($im, $fonts['bold'], (int) (64 * $k), (int) ($S / 2), (int) ($S * 0.5 - 130 * $k), $c['accent'], 'YOUR NAME', 10, 'center');
    phText($im, $fonts['display'], (int) (430 * $k), (int) ($S / 2), (int) ($S * 0.5 + 290 * $k), $c['accent'], '07', 4, 'center');
}
function placeholderDrawCap(GdImage $im, int $S, float $k, array $c, array $look, array $fonts): void {
    $cx = 0.5; $cy = 0.60;
    // Crown (half ellipse), seams, brim, button.
    imagefilledarc($im, (int) round($S / 2), (int) round($S / 2 + ($cy - 0.5) * $k * $S), (int) round(0.56 * $k * $S), (int) round(0.62 * $k * $S), 180, 360, $c['body'], IMG_ARC_PIE);
    imagefilledarc($im, (int) round($S / 2 + 0.08 * $k * $S), (int) round($S / 2 + ($cy - 0.5) * $k * $S), (int) round(0.40 * $k * $S), (int) round(0.62 * $k * $S), 180, 360, $c['bodyDark'], IMG_ARC_PIE);
    imagefilledarc($im, (int) round($S / 2 - 0.03 * $k * $S), (int) round($S / 2 + ($cy - 0.5) * $k * $S), (int) round(0.54 * $k * $S), (int) round(0.60 * $k * $S), 180, 360, $c['body'], IMG_ARC_PIE);
    phEllipse($im, 0.5, 0.60, 0.66, 0.09, $S, $k, $c['trim']);
    phPoly($im, [[0.22, 0.60], [0.78, 0.60], [0.86, 0.66], [0.50, 0.72], [0.14, 0.66]], $S, $k, $c['trim']);
    phEllipse($im, 0.5, 0.315, 0.05, 0.03, $S, $k, $c['trim']);
    phText($im, $fonts['display'], (int) (118 * $k), (int) ($S / 2 - 30 * $k), (int) ($S * 0.5 + 12 * $k), $c['accent'], 'HPCL', 5, 'center');
}
function placeholderDrawWristband(GdImage $im, int $S, float $k, array $c, array $look, array $fonts): void {
    $second = $look['dots'] ? 'trim' : 'ink';
    foreach ([[0.38, 'body', 'PRO HPCL'], [0.58, $second, 'PRO HPCL']] as [$y, $colour, $text]) {
        phRect($im, 0.22, $y - 0.075, 0.78, $y + 0.075, $S, $k, $c[$colour], 60);
        phRect($im, 0.22, $y - 0.075, 0.78, $y - 0.060, $S, $k, $c['accent'], 0);
        phRect($im, 0.22, $y + 0.060, 0.78, $y + 0.075, $S, $k, $c['accent'], 0);
        phText($im, $fonts['display'], (int) (92 * $k), (int) ($S / 2), (int) ($S * ($y - 0.02) + 50 * $k + ($S * 0.02)), $colour === 'body' ? $c['accent'] : ($colour === 'ink' ? $c['white'] : $c['body']), $text, 8, 'center');
    }
}
function placeholderDrawKitbag(GdImage $im, int $S, float $k, array $c, array $look, array $fonts): void {
    $body = $look['bg'][0] === '#0b50a7' ? phColor($im, '#0b2a5b') : $c['body'];
    $panel = $look['bg'][0] === '#0b50a7' ? $c['body'] : $c['accent'];
    // Handles, body, end panels, zip and wordmark.
    imagesetthickness($im, (int) (26 * $k));
    imagearc($im, (int) ($S * 0.40), (int) ($S * 0.40), (int) ($S * 0.22 * $k), (int) ($S * 0.20 * $k), 180, 360, $c['accent']);
    imagearc($im, (int) ($S * 0.60), (int) ($S * 0.40), (int) ($S * 0.22 * $k), (int) ($S * 0.20 * $k), 180, 360, $c['accent']);
    imagesetthickness($im, 1);
    phRect($im, 0.14, 0.40, 0.86, 0.74, $S, $k, $body, 110);
    phRect($im, 0.14, 0.40, 0.26, 0.74, $S, $k, $panel, 110);
    phRect($im, 0.74, 0.40, 0.86, 0.74, $S, $k, $panel, 110);
    phRect($im, 0.27, 0.43, 0.73, 0.445, $S, $k, $c['accent'], 0);
    phRect($im, 0.30, 0.56, 0.70, 0.70, $S, $k, phColor($im, '#000000', 95), 40);
    phText($im, $fonts['display'], (int) (150 * $k), (int) ($S / 2), (int) ($S * 0.5 + 130 * $k), $c['accent'], 'PRO HPCL', 8, 'center');
}
function placeholderDrawBat(GdImage $im, int $S, float $k, array $c, array $look, array $fonts): void {
    $angle = deg2rad(-38);
    $rot = function (array $pts) use ($angle, $S, $k) {
        $flat = [];
        foreach ($pts as [$x, $y]) {   // x across, y along the bat, centred on (0, 0)
            $flat[] = (int) round($S / 2 + ($x * cos($angle) - $y * sin($angle)) * $k * $S);
            $flat[] = (int) round($S * 0.50 + ($x * sin($angle) + $y * cos($angle)) * $k * $S);
        }
        return $flat;
    };
    $wood = phColor($im, '#e9c98f');
    $woodDark = phColor($im, '#c79a52');
    imagefilledpolygon($im, $rot([[-0.085, -0.02], [0.085, -0.02], [0.095, 0.38], [-0.095, 0.38]]), $wood);
    imagefilledpolygon($im, $rot([[0.015, -0.02], [0.085, -0.02], [0.095, 0.38], [0.015, 0.38]]), $woodDark);
    imagefilledpolygon($im, $rot([[-0.020, -0.30], [0.020, -0.30], [0.022, 0.0], [-0.022, 0.0]]), $wood);
    imagefilledpolygon($im, $rot([[-0.026, -0.30], [0.026, -0.30], [0.026, -0.12], [-0.026, -0.12]]), $c['trim'] === phColor($im, '#ffffff') ? $c['body'] : $c['trim']);
    imagefilledpolygon($im, $rot([[-0.026, -0.12], [0.026, -0.12], [0.026, -0.10], [-0.026, -0.10]]), $c['accent']);
    // Sticker on the blade.
    imagefilledpolygon($im, $rot([[-0.06, 0.12], [0.06, 0.12], [0.06, 0.22], [-0.06, 0.22]]), $c['body']);
    imagefilledpolygon($im, $rot([[-0.06, 0.12], [0.06, 0.12], [0.06, 0.135], [-0.06, 0.135]]), $c['accent']);
}
function placeholderDrawBall(GdImage $im, int $S, float $k, array $c, array $look, array $fonts): void {
    $r = (int) round(0.40 * $k * $S);
    $red = phColor($im, '#b3202a');
    imagefilledellipse($im, (int) ($S / 2), (int) ($S * 0.50), $r, $r, $red);
    imagefilledellipse($im, (int) ($S / 2 + $r * 0.12), (int) ($S * 0.50 + $r * 0.10), (int) ($r * 0.76), (int) ($r * 0.76), phColor($im, '#7d121b', 80));
    imagefilledellipse($im, (int) ($S / 2 - $r * 0.18), (int) ($S * 0.50 - $r * 0.20), (int) ($r * 0.34), (int) ($r * 0.22), phColor($im, '#ffffff', 92));
    // Seam: two arcs with stitches.
    $seam = phColor($im, '#f2e6cf');
    imagesetthickness($im, max(2, (int) (7 * $k)));
    foreach ([-1, 1] as $side) {
        $cx = (int) ($S / 2 + $side * $r * 0.52);
        imagearc($im, $cx, (int) ($S * 0.50), (int) ($r * 0.74), (int) ($r * 1.02), $side < 0 ? 300 : 120, $side < 0 ? 60 : 240, $seam);
        for ($a = ($side < 0 ? -58 : 122); $a <= ($side < 0 ? 58 : 238); $a += 11) {
            $x = $cx + (int) (cos(deg2rad($a)) * $r * 0.37);
            $y = (int) ($S * 0.50) + (int) (sin(deg2rad($a)) * $r * 0.51);
            imageline($im, $x - 14, $y - 6, $x + 14, $y + 6, $seam);
        }
    }
    imagesetthickness($im, 1);
}
