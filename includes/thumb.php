<?php
declare(strict_types=1);

// On-demand image thumbnails. /thumb/<width>/storage/uploads/... (and /thumb/<width>/assets/imgs/...)
// serves a resized copy of the image, cached under storage/cache/thumbs/. index.php serves it
// before any database or view work (thumbServe), and render() rewrites <img> tags to use it
// (thumbRewriteHtml). Needs the GD extension; without it the original file is served and the
// HTML is left alone.

const THUMB_WIDTHS = [160, 240, 320, 480, 640, 960, 1280, 1600, 1920, 2560];
// Only these folders can be served; private/, includes/ and everything else is never reachable.
const THUMB_ROOTS = ['storage/uploads', 'assets/imgs'];
const THUMB_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
const THUMB_MAX_PIXELS = 60000000;
const THUMB_QUALITY = 78;

function thumbAvailable(): bool {
    return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagejpeg');
}

/** Resolves a site-relative path to a real file inside an allowed folder, or null. */
function thumbSource(string $relative): ?string {
    if ($relative === '' || preg_match('~[\x00-\x1f\\\\]~', $relative) || str_contains($relative, '//')) return null;
    foreach (explode('/', $relative) as $part) if ($part === '' || $part[0] === '.') return null;
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if (!isset(THUMB_TYPES[$extension])) return null;
    foreach (THUMB_ROOTS as $root) {
        if (!str_starts_with($relative, $root . '/')) continue;
        $rootReal = realpath(__DIR__ . '/../' . $root);
        $real = realpath(__DIR__ . '/../' . $relative);
        if ($rootReal === false || $real === false || !is_file($real)) return null;
        // realpath resolves symlinks, so a link pointing out of the folder fails this check.
        return str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR) ? $real : null;
    }
    return null;
}

function thumbFail(int $status): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $status === 404 ? 'Not found' : 'Bad request';
    exit;
}

function thumbSend(string $file, string $type, string $etag, int $modified, bool $immutable): never {
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: ' . ($immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=86400'));
    header('Vary: Accept');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
    exit;
}

/** Handles GET /thumb/<width>/<path>. $path is the decoded request path without the base folder. */
function thumbServe(string $path): never {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) thumbFail(400);
    if (!preg_match('~^/thumb/(\d{2,4})/(.+)$~', $path, $match)) thumbFail(404);
    $width = (int) $match[1];
    if (!in_array($width, THUMB_WIDTHS, true)) thumbFail(400);
    $source = thumbSource($match[2]);
    if ($source === null) thumbFail(404);
    $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    $type = THUMB_TYPES[$extension];
    $modified = (int) filemtime($source);
    $size = (int) filesize($source);

    // Pick the output format from what the browser accepts.
    $format = thumbFormat($extension, str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'image/webp'));
    $key = sha1($match[2] . '|' . $modified . '|' . $size . '|' . $width . '|' . $format);
    $etag = '"' . substr($key, 0, 32) . '"';
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag || trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === 'W/' . $etag) {
        http_response_code(304);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Vary: Accept');
        header('ETag: ' . $etag);
        exit;
    }

    // Without GD the original is served (briefly cached).
    if (!thumbAvailable()) thumbSend($source, $type, $etag, $modified, false);
    $cached = thumbCache($source, $match[2], $width, $format);
    if ($cached === null) thumbSend($source, $type, $etag, $modified, false);
    thumbSend($cached, THUMB_TYPES[pathinfo($cached, PATHINFO_EXTENSION)], $etag, $modified, true);
}

/** Output format ('webp', 'jpg' or 'png') for a source file; the cache key in thumbServe uses it. */
function thumbFormat(string $extension, bool $acceptsWebp): string {
    if ($acceptsWebp && function_exists('imagewebp')) return 'webp';
    return in_array($extension, ['jpg', 'jpeg'], true) ? 'jpg' : 'png';
}

/** Path of the cached thumbnail, generating it when missing; null when it cannot be made. */
function thumbCache(string $source, string $relative, int $width, string $format): ?string {
    $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    $modified = (int) filemtime($source);
    $size = (int) filesize($source);
    $key = sha1($relative . '|' . $modified . '|' . $size . '|' . $width . '|' . $format);
    $dir = __DIR__ . '/../storage/cache/thumbs/' . substr($key, 0, 2);
    $base = $dir . '/' . $key;
    // A cache hit is either the resized file or, when resizing did not help, a copy of the original.
    foreach (array_unique([$format, $extension]) as $candidate) {
        if (is_file($base . '.' . $candidate)) return $base . '.' . $candidate;
    }
    $result = thumbGenerate($source, $extension, $width, $format);
    if ($result === null) return null;
    [$data, $outExtension] = $result;
    // Keep the original when re-encoding did not make it smaller.
    if (strlen($data) >= $size) { $data = null; $outExtension = $extension; }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
    $target = $base . '.' . $outExtension;
    $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $written = $data === null ? @copy($source, $temporary) : @file_put_contents($temporary, $data) !== false;
    if (!$written || !@rename($temporary, $target)) {
        @unlink($temporary);
        return null;
    }
    return $target;
}

/** Returns [bytes, extension] for the resized image, or null when it cannot be processed. */
function thumbGenerate(string $source, string $extension, int $width, string $format): ?array {
    $info = @getimagesize($source);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > THUMB_MAX_PIXELS) return null;
    @ini_set('memory_limit', '512M');
    $image = @imagecreatefromstring((string) file_get_contents($source));
    if (!$image) return null;

    // Browsers rotate JPEGs by their EXIF orientation; the resized copy has no EXIF, so rotate it here.
    if (in_array($extension, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
        $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if ($rotated && $rotated !== $image) $image = $rotated;
    }

    $sourceWidth = imagesx($image);
    $sourceHeight = imagesy($image);
    if ($sourceWidth > $width) {
        // Never upscale: images already narrower than the request keep their size.
        $height = max(1, (int) round($sourceHeight * $width / $sourceWidth));
        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        $image = $resized;
    } elseif (!imageistruecolor($image)) {
        imagepalettetotruecolor($image);
    }

    // Formats without transparency (JPEG) are flattened onto white. PNG sources without an alpha
    // channel become JPEG when the browser has no WebP.
    $alpha = $extension === 'png' ? in_array(ord((string) @file_get_contents($source, false, null, 25, 1)), [4, 6], true) || str_contains((string) @file_get_contents($source, false, null, 0, 4096), 'tRNS')
        : in_array($extension, ['webp', 'gif'], true);
    if ($format === 'png' && !$alpha) $format = 'jpg';
    if ($format === 'jpg') {
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        $image = $flat;
    } else {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    ob_start();
    match ($format) {
        'webp' => imagewebp($image, null, THUMB_QUALITY),
        'jpg' => imagejpeg($image, null, THUMB_QUALITY),
        default => imagepng($image, null, 7),
    };
    $data = (string) ob_get_clean();
    return $data === '' ? null : [$data, $format];
}

/** Picks the 1x and 2x widths for an <img>, from its class and folder. */
function thumbWidths(string $classes, string $relative): array {
    $classes = ' ' . $classes . ' ';
    if (str_contains($classes, 'hero-slide-image')) return [1600, 2560];
    if (str_contains($classes, 'teams-section__logo') || str_contains($classes, 'tpv2-card__badge') || str_contains($classes, 'leader-player')) return [160, 320];
    if (str_contains($classes, 'tpv2-card__player')) return [240, 480];
    if (str_contains($classes, 'blog-card-image')) return [480, 960];
    if (str_starts_with($relative, 'storage/uploads/blog/')) return [960, 1600];
    return [480, 960];
}

/** URL of the resized copy of a local image, or null when the image should be left alone. */
function thumbUrl(string $prefix, string $url, int $width): ?string {
    $url = html_entity_decode(trim($url));
    if (!str_starts_with($url, $prefix . '/')) return null;
    $file = rawurldecode((string) strtok(substr($url, strlen($prefix) + 1), '?#'));
    $real = thumbSource($file);
    if ($real === null) return null;
    // Small files are not worth another request; static assets only when they are heavy.
    if (filesize($real) < (str_starts_with($file, 'assets/') ? 150000 : 40000)) return null;
    return $prefix . '/thumb/' . $width . '/' . implode('/', array_map('rawurlencode', explode('/', $file))) . '?v=' . dechex((int) filemtime($real));
}

/**
 * Rewrites local <img>, <source srcset> and inline background-image URLs to thumbnails and adds
 * decoding/loading hints. Above-the-fold images (the first three, the navbar and logos) stay eager.
 */
function thumbRewriteHtml(string $html, string $prefix): string {
    if (!thumbAvailable() || (!str_contains($html, '<img') && !str_contains($html, 'url('))) return $html;
    $quoted = preg_quote($prefix, '~');

    // Inline background images (player photo on the profile page).
    $html = preg_replace_callback('~\sstyle=(["\'])((?:(?!\1).)*?/storage/uploads/(?:(?!\1).)*)\1~s', function ($m) use ($prefix, $quoted) {
        $style = preg_replace_callback('~url\(\s*([\'"]?)(' . $quoted . '/storage/uploads/[^\'")]+)\1\s*\)~', function ($u) use ($prefix) {
            $thumb = thumbUrl($prefix, $u[2], 960);
            return $thumb === null ? $u[0] : 'url(' . $u[1] . $thumb . $u[1] . ')';
        }, $m[2]);
        return ' style=' . $m[1] . $style . $m[1];
    }, $html) ?? $html;

    // Everything before the end of the first header/navbar counts as above the fold.
    $headerEnd = 0;
    foreach (['</header>', '</nav>'] as $close) {
        $at = strpos($html, $close);
        if ($at !== false) $headerEnd = max($headerEnd, $at);
    }
    $count = 0;
    $heroSeen = false;
    return preg_replace_callback('~<(img|source)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>~i', function ($m) use ($prefix, $headerEnd, &$count, &$heroSeen) {
        $selfClosing = preg_match('~\s*/\s*$~', $m[2][0]) === 1;
        $attributes = preg_replace('~\s*/\s*$~', '', $m[2][0]);
        $attr = fn (string $name) => preg_match('~\s' . $name . '=(?:"([^"]*)"|\'([^\']*)\')~i', $attributes, $a) ? ($a[1] !== '' ? $a[1] : ($a[2] ?? '')) : null;
        $set = fn (string $name, string $value) => preg_replace('~(\s' . $name . '=)(["\']).*?\2~is', '${1}"' . $value . '"', $attributes, 1);

        if (strtolower($m[1][0]) === 'source') {
            $srcset = $attr('srcset');
            if ($srcset === null || preg_match('~[\s,]~', trim($srcset))) return $m[0][0];
            $one = thumbUrl($prefix, $srcset, 960);
            $two = thumbUrl($prefix, $srcset, 1600);
            return $one === null || $two === null ? $m[0][0] : '<source' . $set('srcset', $one . ' 1x, ' . $two . ' 2x') . ($selfClosing ? ' />' : '>');
        }

        $count++;
        $src = $attr('src');
        $classes = (string) $attr('class');
        $hero = str_contains(' ' . $classes . ' ', ' hero-slide-image ');
        $firstHero = $hero && !$heroSeen;
        $heroSeen = $heroSeen || $hero;
        $aboveFold = $count <= 3 || $m[0][1] < $headerEnd || $firstHero;
        $isLogo = $src !== null && (stripos($src, 'logo') !== false || stripos($classes, 'logo') !== false
            || str_contains(' ' . $classes . ' ', ' element '));  // the decorative wave: lazy-loading it delays the page's LCP

        if ($src !== null && $attr('srcset') === null) {
            $relative = ltrim(substr(html_entity_decode($src), strlen($prefix)), '/');
            [$one, $two] = thumbWidths($classes, $relative);
            $oneUrl = thumbUrl($prefix, $src, $one);
            $twoUrl = thumbUrl($prefix, $src, $two);
            if ($oneUrl !== null && $twoUrl !== null) {
                $attributes = $set('src', $oneUrl);
                // A srcset would beat the src an onerror fallback sets, so the fallback drops it first.
                $attributes = preg_replace('~(\sonerror=)(["\'])~i', '$1$2this.removeAttribute(&quot;srcset&quot;);', $attributes, 1);
                $attributes = rtrim($attributes) . ' srcset="' . $oneUrl . ' 1x, ' . $twoUrl . ' 2x"';
            }
        }
        if ($attr('decoding') === null) $attributes = rtrim($attributes) . ' decoding="async"';
        if ($firstHero) {
            $attributes = preg_replace('~\sloading=(["\'])lazy\1~i', '', $attributes);
            if ($attr('fetchpriority') === null) $attributes = rtrim($attributes) . ' fetchpriority="high"';
        } elseif (!$aboveFold && !$isLogo && $attr('loading') === null) {
            $attributes = rtrim($attributes) . ' loading="lazy"';
        }
        return '<img' . $attributes . ($selfClosing ? ' />' : '>');
    }, $html, -1, $replaced, PREG_OFFSET_CAPTURE) ?? $html;
}
