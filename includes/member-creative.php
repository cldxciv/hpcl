<?php
declare(strict_types=1);

// The social-media creative of a member: a 1080 x 1080 PNG with their photo (or logo, or initial),
// business name and tier, drawn with GD. It is also the preview image of their public profile when
// the profile link is shared, so it is public: /member/<slug>/creative.png.
//
// To change the design (for example one made in Canva): export a 1080 x 1080 PNG over
// assets/creative/bg-square.png and, if the artwork needs it, move things in memberCreativeLayout().
// bin/build-creative-assets.php rewrites the default background and a sample.png preview that shows
// where the photo, name, tier and link land. See assets/creative/README.md.

require_once __DIR__ . '/membership.php'; // MEMBERSHIP_TIERS

const CREATIVE_VERSION = 1; // raise it to redraw every saved creative after changing the layout here
const CREATIVE_DIR = 'storage/cache/creatives';

/** Where and how everything is drawn. Colours are hex; sizes and positions are pixels of the 1080 canvas. */
function memberCreativeLayout(): array {
    return [
        'size' => 1080,
        'background' => 'assets/creative/bg-square.png',
        'logo' => ['file' => 'logo.png', 'x' => 56, 'y' => 44, 'width' => 250],
        'fonts' => ['display' => 'assets/fonts/BebasNeue-Regular.ttf', 'bold' => 'assets/fonts/Barlow-Bold.ttf'],
        'label' => ['text' => 'MEMBERSHIP PROGRAMME', 'right' => 1024, 'baseline' => 108, 'size' => 24, 'tracking' => 5, 'color' => '#ffffff'],
        'headline' => ['text' => 'PROUD MEMBER', 'baseline' => 278, 'size' => 138, 'color' => '#ffffff'],
        'subline' => ['text' => 'OF THE PRO HPCL FAMILY', 'baseline' => 344, 'size' => 54, 'tracking' => 3, 'color' => '#ff7a18'],
        'avatar' => ['center_y' => 578, 'diameter' => 330, 'ring' => 14, 'ring_color' => '#ff7a18', 'gap_color' => '#ffffff', 'gap' => 8, 'fill' => '#fd6f3b', 'initial_color' => '#0a1f44'],
        'name' => ['first_baseline' => 868, 'size' => 86, 'min_size' => 56, 'line_height' => 80, 'max_width' => 940, 'color' => '#ffffff'],
        'tier' => ['gap' => 26, 'height' => 52, 'size' => 30, 'tracking' => 3, 'pad' => 30, 'fill' => '#ff7a18', 'color' => '#0a1f44'],
        'footer' => ['top' => 1004, 'fill' => '#06162f', 'size' => 33, 'color' => '#ffffff', 'tracking' => 1],
    ];
}

function creativeRgb(GdImage $image, string $hex, int $alpha = 0): int {
    [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');
    return imagecolorallocatealpha($image, $r, $g, $b, $alpha);
}

function creativeAbsolute(string $relative): string {
    return dirname(__DIR__) . '/' . $relative;
}

/** Whether this server can draw creatives (GD with FreeType and the fonts). */
function memberCreativeAvailable(): bool {
    $fonts = memberCreativeLayout()['fonts'];
    return function_exists('imagettftext') && function_exists('imagecreatetruecolor') && is_file(creativeAbsolute($fonts['display'])) && is_file(creativeAbsolute($fonts['bold']));
}

/** The address a member's profile is shared at, shown on the creative without the scheme. */
function memberCreativeLink(array $member): string {
    return preg_replace('~^https?://~', '', absoluteUrl('{{BASE}}/member/' . rawurlencode((string) $member['slug'])));
}

/** A short fingerprint of everything the creative shows, so a changed profile gets a new picture. */
function memberCreativeKey(array $member): string {
    $layout = memberCreativeLayout();
    $mtime = fn (?string $path) => $path && is_file(creativeAbsolute($path)) ? (string) filemtime(creativeAbsolute($path)) : '0';
    return substr(md5(json_encode([CREATIVE_VERSION, $member['business_name'], $member['tier'], $member['city'] ?? '', $member['slug'], $member['photo'] ?? '', $mtime($member['photo'] ?? null),
        $member['logo'] ?? '', $mtime($member['logo'] ?? null), $mtime($layout['background']), $mtime($layout['logo']['file']), memberCreativeLink($member)])), 0, 12);
}

/** Loads a JPEG, PNG or WebP file as a GD image, or null. */
function creativeLoadImage(?string $relative): ?GdImage {
    if (!$relative || str_contains($relative, '..')) return null;
    $file = creativeAbsolute($relative);
    if (!is_file($file)) return null;
    $type = @getimagesize($file)[2] ?? null;
    $image = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
        IMAGETYPE_PNG => @imagecreatefrompng($file),
        IMAGETYPE_WEBP => @imagecreatefromwebp($file),
        default => false,
    };
    return $image ?: null;
}

/** Draws text left-aligned at x with $tracking extra pixels between letters; returns the width used. */
function creativeText(GdImage $image, string $font, int $size, int $x, int $baseline, int $color, string $text, int $tracking = 0): int {
    if ($tracking === 0) {
        imagettftext($image, $size, 0, $x, $baseline, $color, $font, $text);
        return creativeTextWidth($font, $size, $text);
    }
    $start = $x;
    foreach (mb_str_split($text) as $char) {
        imagettftext($image, $size, 0, $x, $baseline, $color, $font, $char);
        $x += creativeTextWidth($font, $size, $char === ' ' ? 'n' : $char) + $tracking;
    }
    return $x - $start - $tracking;
}
function creativeTextWidth(string $font, int $size, string $text, int $tracking = 0): int {
    $box = imagettfbbox($size, 0, $font, $text);
    return (int) (abs($box[2] - $box[0]) + max(0, mb_strlen($text) - 1) * $tracking);
}
/** The width of tracked text (letters spaced out), as creativeText() draws it. */
function creativeTrackedWidth(string $font, int $size, string $text, int $tracking): int {
    $width = 0;
    foreach (mb_str_split($text) as $char) $width += creativeTextWidth($font, $size, $char === ' ' ? 'n' : $char) + $tracking;
    return max(0, $width - $tracking);
}
function creativeCentered(GdImage $image, string $font, int $size, int $baseline, int $color, string $text, int $tracking = 0, int $center = 540): void {
    $width = $tracking ? creativeTrackedWidth($font, $size, $text, $tracking) : creativeTextWidth($font, $size, $text);
    creativeText($image, $font, $size, (int) round($center - $width / 2), $baseline, $color, $text, $tracking);
}

/** Wraps $text into at most two lines that fit $maxWidth, shrinking the type down to $minSize first. Returns [lines, size]. */
function creativeWrapName(string $font, string $text, int $size, int $minSize, int $maxWidth): array {
    for ($s = $size; $s >= $minSize; $s -= 4) {
        $lines = [];
        $line = '';
        foreach (preg_split('~\s+~u', trim($text)) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && creativeTextWidth($font, $s, $try) > $maxWidth) { $lines[] = $line; $line = $word; } else $line = $try;
        }
        $lines[] = $line;
        if (count($lines) <= 2 && max(array_map(fn ($l) => creativeTextWidth($font, $s, $l), $lines)) <= $maxWidth) return [$lines, $s];
    }
    // Still too long: keep two lines and cut the second.
    $words = preg_split('~\s+~u', trim($text));
    $first = array_shift($words);
    $rest = implode(' ', $words);
    while ($rest !== '' && creativeTextWidth($font, $minSize, $rest . '…') > $maxWidth) $rest = mb_substr($rest, 0, -1);
    return [array_filter([mb_substr($first, 0, 40), $rest === '' ? '' : rtrim($rest) . '…']), $minSize];
}

/** The round picture: photo, else logo, else initial, with the ring around it, drawn at double size and shrunk for smooth edges. */
function creativeAvatar(array $member, array $layout): GdImage {
    $a = $layout['avatar'];
    $d = $a['diameter'];
    $total = $d + 2 * ($a['ring'] + $a['gap']);
    $scale = 2;
    $S = $total * $scale;
    $D = $d * $scale;

    // The picture inside the circle, square.
    $content = imagecreatetruecolor($D, $D);
    $photo = creativeLoadImage($member['photo'] ?? null);
    $logo = $photo ? null : creativeLoadImage($member['logo'] ?? null);
    if ($photo) {
        [$w, $h] = [imagesx($photo), imagesy($photo)];
        $side = min($w, $h);
        // Faces sit in the upper part of a portrait: crop a little above the centre.
        $srcY = $h > $w ? (int) round(($h - $side) * 0.25) : 0;
        imagecopyresampled($content, $photo, 0, 0, (int) (($w - $side) / 2), $srcY, $D, $D, $side, $side);
    } elseif ($logo) {
        imagefill($content, 0, 0, creativeRgb($content, '#ffffff'));
        [$w, $h] = [imagesx($logo), imagesy($logo)];
        $fit = min($D * 0.72 / $w, $D * 0.72 / $h);
        [$nw, $nh] = [(int) round($w * $fit), (int) round($h * $fit)];
        imagealphablending($content, true);
        imagecopyresampled($content, $logo, (int) (($D - $nw) / 2), (int) (($D - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
    } else {
        imagefill($content, 0, 0, creativeRgb($content, $a['fill']));
        $initial = mb_strtoupper(mb_substr(trim((string) $member['business_name']), 0, 1)) ?: 'P';
        $size = (int) round($D * 0.5 * 0.75);
        $box = imagettfbbox($size, 0, creativeAbsolute($layout['fonts']['display']), $initial);
        $x = (int) (($D - ($box[2] - $box[0])) / 2 - $box[0]);
        $y = (int) (($D - ($box[1] - $box[7])) / 2 - $box[7]);
        imagettftext($content, $size, 0, $x, $y, creativeRgb($content, $a['initial_color']), creativeAbsolute($layout['fonts']['display']), $initial);
    }

    // The circle: outer ring, gap, then the picture cut to a disc.
    $avatar = imagecreatetruecolor($S, $S);
    imagealphablending($avatar, false);
    imagesavealpha($avatar, true);
    imagefill($avatar, 0, 0, imagecolorallocatealpha($avatar, 0, 0, 0, 127));
    imagealphablending($avatar, true);
    imagefilledellipse($avatar, (int) ($S / 2), (int) ($S / 2), $S, $S, creativeRgb($avatar, $a['ring_color']));
    imagefilledellipse($avatar, (int) ($S / 2), (int) ($S / 2), $S - 2 * $a['ring'] * $scale, $S - 2 * $a['ring'] * $scale, creativeRgb($avatar, $a['gap_color']));
    $offset = (int) (($S - $D) / 2);
    $r = $D / 2;
    for ($y = 0; $y < $D; $y++) {
        $half = (int) floor(sqrt(max(0.0, $r * $r - ($y + 0.5 - $r) ** 2)));
        $from = (int) ($r - $half);
        if ($half > 0) imagecopy($avatar, $content, $offset + $from, $offset + $y, $from, $y, $half * 2, 1);
    }
    $final = imagecreatetruecolor($total, $total);
    imagealphablending($final, false);
    imagesavealpha($final, true);
    imagefill($final, 0, 0, imagecolorallocatealpha($final, 0, 0, 0, 127));
    imagecopyresampled($final, $avatar, 0, 0, 0, 0, $total, $total, $S, $S);
    return $final;
}

/** Draws the creative and returns it as a GD image. */
function memberCreativeImage(array $member): GdImage {
    $layout = memberCreativeLayout();
    $size = $layout['size'];
    $display = creativeAbsolute($layout['fonts']['display']);
    $bold = creativeAbsolute($layout['fonts']['bold']);

    $image = imagecreatetruecolor($size, $size);
    $background = creativeLoadImage($layout['background']);
    if ($background) imagecopyresampled($image, $background, 0, 0, 0, 0, $size, $size, imagesx($background), imagesy($background));
    else imagecopy($image, creativeDefaultBackground($size), 0, 0, 0, 0, $size, $size);
    imagealphablending($image, true);
    imagesavealpha($image, false);

    // Logo and label along the top.
    $l = $layout['logo'];
    if ($logo = creativeLoadImage($l['file'])) {
        $w = $l['width'];
        $h = (int) round(imagesy($logo) * $w / imagesx($logo));
        imagecopyresampled($image, $logo, $l['x'], $l['y'], 0, 0, $w, $h, imagesx($logo), imagesy($logo));
    }
    $t = $layout['label'];
    $labelWidth = creativeTrackedWidth($bold, $t['size'], $t['text'], $t['tracking']);
    creativeText($image, $bold, $t['size'], $t['right'] - $labelWidth, $t['baseline'], creativeRgb($image, $t['color'], 30), $t['text'], $t['tracking']);

    // Headline.
    $h = $layout['headline'];
    creativeCentered($image, $display, $h['size'], $h['baseline'], creativeRgb($image, $h['color']), $h['text']);
    $s = $layout['subline'];
    creativeCentered($image, $display, $s['size'], $s['baseline'], creativeRgb($image, $s['color']), $s['text'], $s['tracking']);

    // The round picture.
    $avatar = creativeAvatar($member, $layout);
    $total = imagesx($avatar);
    imagealphablending($image, true);
    imagecopy($image, $avatar, (int) (($size - $total) / 2), (int) ($layout['avatar']['center_y'] - $total / 2), 0, 0, $total, $total);

    // Business name (one or two lines), then the tier badge.
    $n = $layout['name'];
    [$lines, $nameSize] = creativeWrapName($display, mb_strtoupper(preg_replace('~[\x00-\x1F]~', ' ', (string) $member['business_name'])), $n['size'], $n['min_size'], $n['max_width']);
    $baseline = $n['first_baseline'];
    foreach ($lines as $i => $line) {
        creativeCentered($image, $display, $nameSize, $baseline + $i * $n['line_height'], creativeRgb($image, $n['color']), $line);
    }
    $lastBaseline = $baseline + (count($lines) - 1) * $n['line_height'];
    $badge = $layout['tier'];
    $tierName = mb_strtoupper((MEMBERSHIP_TIERS[strtolower((string) $member['tier'])]['name'] ?? ucfirst((string) $member['tier'])) . ' MEMBER');
    $badgeWidth = creativeTrackedWidth($bold, $badge['size'], $tierName, $badge['tracking']) + 2 * $badge['pad'];
    $left = (int) (($size - $badgeWidth) / 2);
    $top = $lastBaseline + $badge['gap'];
    $radius = (int) ($badge['height'] / 2);
    $fill = creativeRgb($image, $badge['fill']);
    imagefilledrectangle($image, $left + $radius, $top, $left + $badgeWidth - $radius, $top + $badge['height'], $fill);
    imagefilledellipse($image, $left + $radius, $top + $radius, $badge['height'], $badge['height'], $fill);
    imagefilledellipse($image, $left + $badgeWidth - $radius, $top + $radius, $badge['height'], $badge['height'], $fill);
    creativeText($image, $bold, $badge['size'], $left + $badge['pad'], $top + (int) ($badge['height'] / 2 + $badge['size'] * 0.36), creativeRgb($image, $badge['color']), $tierName, $badge['tracking']);

    // Footer band with the profile link.
    $f = $layout['footer'];
    imagefilledrectangle($image, 0, $f['top'], $size, $size, creativeRgb($image, $f['fill'], 10));
    $link = memberCreativeLink($member);
    creativeCentered($image, $bold, $f['size'], $f['top'] + (int) (($size - $f['top']) / 2 + $f['size'] * 0.36), creativeRgb($image, $f['color']), $link, $f['tracking']);
    return $image;
}

/** The default background: a navy gradient with orange accents. Used until assets/creative/bg-square.png exists. */
function creativeDefaultBackground(int $size = 1080): GdImage {
    $scale = 2;
    $S = $size * $scale;
    $image = imagecreatetruecolor($S, $S);
    // Vertical gradient from deep navy through the brand blue.
    $stops = [[0.0, [7, 28, 66]], [0.5, [11, 80, 167]], [1.0, [6, 22, 47]]];
    for ($y = 0; $y < $S; $y++) {
        $p = $y / ($S - 1);
        foreach ($stops as $i => [$at, $rgb]) {
            if ($p <= $at || $i === count($stops) - 1) {
                [$prevAt, $prevRgb] = $stops[max(0, $i - 1)];
                $k = $at === $prevAt ? 0 : ($p - $prevAt) / ($at - $prevAt);
                $c = array_map(fn ($a, $b) => (int) round($a + ($b - $a) * $k), $prevRgb, $rgb);
                break;
            }
        }
        imageline($image, 0, $y, $S, $y, imagecolorallocate($image, ...$c));
    }
    imagealphablending($image, true);
    // Orange sweeps in the corners.
    $orange = imagecolorallocatealpha($image, 253, 111, 59, 12);
    imagefilledpolygon($image, [0, (int) ($S * 0.80), 0, $S, (int) ($S * 0.34), $S], $orange);
    imagefilledpolygon($image, [$S, 0, $S, (int) ($S * 0.20), (int) ($S * 0.70), 0], $orange);
    $light = imagecolorallocatealpha($image, 255, 255, 255, 112);
    imagefilledellipse($image, (int) ($S * 0.50), (int) ($S * 0.52), (int) ($S * 0.80), (int) ($S * 0.80), $light);
    // A grid of dots behind the headline, as on the website.
    $dot = imagecolorallocatealpha($image, 255, 255, 255, 108);
    for ($y = (int) ($S * 0.20); $y < $S * 0.34; $y += 36 * $scale) {
        for ($x = (int) ($S * 0.08); $x < $S * 0.92; $x += 36 * $scale) imagefilledellipse($image, $x, $y, 7 * $scale, 7 * $scale, $dot);
    }
    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $image, 0, 0, 0, 0, $size, $size, $S, $S);
    return $out;
}

/** The saved creative's file for a member, drawing it first when needed; null if it can't be drawn. */
function memberCreativeFile(array $member): ?string {
    if (!memberCreativeAvailable()) return null;
    $folder = creativeAbsolute(CREATIVE_DIR);
    if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) return null;
    $id = (int) $member['id'];
    $file = $folder . '/' . $id . '-' . memberCreativeKey($member) . '.png';
    if (is_file($file)) return $file;
    try {
        $image = memberCreativeImage($member);
    } catch (Throwable $error) {
        error_log('Member creative for ' . $id . ' failed: ' . $error->getMessage());
        return null;
    }
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (!imagepng($image, $tmp, 6)) return null;
    rename($tmp, $file);
    foreach (glob($folder . '/' . $id . '-*.png') ?: [] as $old) if ($old !== $file) @unlink($old);
    return $file;
}

/** /member/<slug>/creative.png (?download=1 saves it): the creative of a published member. */
function serveMemberCreative(string $slug): never {
    $member = query('SELECT * FROM members WHERE slug = ? AND published = 1', [rawurldecode($slug)])[0] ?? null;
    $file = $member ? memberCreativeFile($member) : null;
    if (!$file) notFound();
    $etag = '"' . memberCreativeKey($member) . '"';
    header('Content-Type: image/png');
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=3600');
    if (isset($_GET['download'])) header('Content-Disposition: attachment; filename="pro-hpcl-member-' . preg_replace('~[^a-z0-9-]+~', '', (string) $member['slug']) . '.png"');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    header('Content-Length: ' . filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
    exit;
}
