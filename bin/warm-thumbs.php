<?php
declare(strict_types=1);
// Pre-generates thumbnails (includes/thumb.php) so the first visitor does not wait for them.
// Usage: php bin/warm-thumbs.php [folder ...] [--limit=N]   (default folders: players, teams,
// registrations/photos, blog, home-sliders, owners, seasons under storage/uploads)
require_once dirname(__DIR__) . '/includes/thumb.php';
if (PHP_SAPI !== 'cli') exit(1);
if (!thumbAvailable()) { fwrite(STDERR, "GD is not available.\n"); exit(1); }

$folders = [];
$limit = PHP_INT_MAX;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) $limit = max(1, (int) substr($arg, 8));
    else $folders[] = trim($arg, '/');
}
$folders = $folders ?: ['players', 'teams', 'registrations/photos', 'blog', 'home-sliders', 'owners', 'seasons'];
// The common widths from thumbWidths(), as WebP (what current browsers ask for); other formats
// are generated on first request. Each one decodes the original, so large folders take a while.
$widths = [160, 240, 480, 960, 1600];
$done = 0;
$started = microtime(true);
foreach ($folders as $folder) {
    foreach (glob(dirname(__DIR__) . '/storage/uploads/' . $folder . '/*.*') ?: [] as $file) {
        if ($done >= $limit) break 2;
        $relative = 'storage/uploads/' . $folder . '/' . basename($file);
        if (thumbSource($relative) === null || filesize($file) < 40000) continue;
        foreach ($widths as $width) thumbCache($file, $relative, $width, thumbFormat(strtolower(pathinfo($file, PATHINFO_EXTENSION)), true));
        $done++;
    }
}
echo "Warmed $done images in " . round(microtime(true) - $started, 1) . "s.\n";
