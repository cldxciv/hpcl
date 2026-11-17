<?php
declare(strict_types=1);

// One-off import of Instagram reels into social_reels, from a JSON list and a folder of covers:
//   php bin/import-instagram-reels.php reels.json covers/
// reels.json: [{"code": "<shortcode>", "caption": "...", "cover": "<file in covers/>"}, ...], newest
// first. Covers are re-encoded into storage/uploads/social/reels. Re-running updates captions and
// covers of reels already there and keeps whether they are hidden or pinned.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/social.php';

[$json, $dir] = [$argv[1] ?? '', rtrim($argv[2] ?? '', '/\\')];
if (!is_file($json) || !is_dir($dir)) { fwrite(STDERR, "Usage: php bin/import-instagram-reels.php reels.json covers-folder\n"); exit(1); }
$rows = json_decode((string) file_get_contents($json), true);
if (!is_array($rows)) { fwrite(STDERR, "reels.json is not a JSON list\n"); exit(1); }
echo implode("\n", importInstagramReels($rows, $dir)), "\n";

function importInstagramReels(array $rows, string $dir): array {
    $out = [];
    // Oldest first, so the newest reel gets the highest id and is listed first.
    foreach (array_reverse($rows) as $row) {
        $code = socialReelShortcode((string) ($row['code'] ?? ''));
        if ($code === null) { $out[] = 'skipped: no shortcode'; continue; }
        $file = $dir . '/' . basename((string) ($row['cover'] ?? ''));
        $cover = !empty($row['cover']) && is_file($file) ? socialSaveCover($code, (string) file_get_contents($file)) : null;
        socialReelUpsert(['shortcode' => $code, 'caption' => $row['caption'] ?? '', 'cover' => $cover, 'source' => 'import']);
        $out[] = $code . ($cover ? ' imported' : ' imported without a cover');
    }
    return $out;
}
