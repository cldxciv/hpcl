<?php
declare(strict_types=1);

// Tests of the Instagram sync processing (includes/instagram-sync.php) against the LOCAL database,
// with a fixture payload and a fake image fetcher (no network):
//   php bin/test-instagram-sync.php
// It creates reels whose shortcodes start with "ZZIgTest" and removes them and their covers at the end.
// Refuses to run unless APP_ENV=local.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
if (appEnv() !== 'local') { fwrite(STDERR, "Run this only with APP_ENV=local.\n"); exit(1); }
if (!function_exists('escape')) { function escape(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
require_once __DIR__ . '/../includes/site.php';
require_once __DIR__ . '/../includes/instagram-sync.php';

$pass = 0;
$fail = 0;
function t(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
}
function reel(string $code): ?array {
    return query("SELECT * FROM social_reels WHERE platform = 'instagram' AND shortcode = ?", [$code])[0] ?? null;
}
function cleanup(): void {
    foreach (query("SELECT cover FROM social_reels WHERE shortcode LIKE 'ZZIgTest%'") as $row) {
        if ($row['cover'] && str_starts_with($row['cover'], SOCIAL_REEL_DIR . '/') && is_file(dirname(__DIR__) . '/' . $row['cover'])) unlink(dirname(__DIR__) . '/' . $row['cover']);
    }
    foreach (glob(dirname(__DIR__) . '/' . SOCIAL_REEL_DIR . '/ZZIgTest*.jpg') ?: [] as $file) unlink($file);
    db()->exec("DELETE FROM social_reels WHERE shortcode LIKE 'ZZIgTest%'");
}
function fakeJpeg(int $w = 540, int $h = 960): string {
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 122, 24));
    ob_start();
    imagejpeg($image, null, 80);
    imagedestroy($image);
    return (string) ob_get_clean();
}
cleanup();

$fetched = [];
$fetcher = function (string $url) use (&$fetched): ?string {
    $fetched[] = $url;
    return str_contains($url, 'broken') ? 'not an image' : fakeJpeg();
};
$payload = ['data' => [
    ['id' => '17900000000000001', 'caption' => "First reel line #ProHPCL\n\nMore text\nView all 12 comments", 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
        'permalink' => 'https://www.instagram.com/reel/ZZIgTestReelA1/', 'thumbnail_url' => 'https://scontent.cdninstagram.com/v/a1.jpg?x=1', 'timestamp' => '2026-11-16T10:30:00+0000'],
    ['id' => '17900000000000002', 'caption' => 'Second reel', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
        'permalink' => 'https://www.instagram.com/reel/ZZIgTestReelB2/', 'thumbnail_url' => 'https://scontent.cdninstagram.com/v/b2.jpg', 'timestamp' => '2026-11-17T06:00:00+0000'],
    ['id' => '17900000000000003', 'caption' => 'A photo', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
        'permalink' => 'https://www.instagram.com/p/ZZIgTestImageC3/', 'media_url' => 'https://scontent.cdninstagram.com/v/c3.jpg', 'timestamp' => '2026-11-15T06:00:00+0000'],
], 'paging' => ['cursors' => ['after' => 'x']]];

// 1. First sync: two reels added with covers, the photo skipped.
$report = instagramProcessMedia($payload, $fetcher);
t('reads 3 media items', $report['media'] === 3, json_encode($report));
t('keeps 2 reels and skips the photo', $report['reels'] === 2 && $report['skipped'] === 1, json_encode($report));
t('adds both reels as new', $report['added'] === 2 && $report['updated'] === 0, json_encode($report));
t('saves 2 covers, no errors', $report['covers'] === 2 && $report['errors'] === [], json_encode($report));
t('fetches exactly the two reel thumbnails', count($fetched) === 2 && !in_array('https://scontent.cdninstagram.com/v/c3.jpg', $fetched, true), json_encode($fetched));
$a = reel('ZZIgTestReelA1');
$b = reel('ZZIgTestReelB2');
t('the photo is not stored', reel('ZZIgTestImageC3') === null);
t('reel A stored from the API, visible, not pinned', $a && $a['source'] === 'api' && (int) $a['is_active'] === 1 && (int) $a['pinned'] === 0, json_encode($a));
t('reel A has the media id', $a && $a['media_id'] === '17900000000000001');
t('reel A permalink is the canonical reel link', $a && $a['permalink'] === 'https://www.instagram.com/reel/ZZIgTestReelA1/', (string) ($a['permalink'] ?? ''));
t('reel A caption cleaned ("View all comments" dropped)', $a && !str_contains((string) $a['caption'], 'View all') && str_starts_with((string) $a['caption'], 'First reel line'), (string) ($a['caption'] ?? ''));
$expected = (new DateTimeImmutable('2026-11-16T10:30:00+0000'))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
t('reel A posted_at converted to the site time zone', $a && $a['posted_at'] === $expected, ($a['posted_at'] ?? '') . " vs $expected");
t('reel A cover saved as a JPEG in the reels folder', $a && $a['cover'] === SOCIAL_REEL_DIR . '/ZZIgTestReelA1.jpg' && is_file(dirname(__DIR__) . '/' . $a['cover'])
    && getimagesize(dirname(__DIR__) . '/' . $a['cover'])[2] === IMAGETYPE_JPEG, (string) ($a['cover'] ?? ''));
t('reel B stored with its cover', $b && $b['cover'] && is_file(dirname(__DIR__) . '/' . $b['cover']));
$homeIds = array_map('intval', array_column(socialReelsForHome(30), 'id'));
t('both reels are eligible for the home page', $a && $b && in_array((int) $a['id'], $homeIds, true) && in_array((int) $b['id'], $homeIds, true));

// 2. Admin choices survive a later sync; covers are not downloaded again.
db()->prepare('UPDATE social_reels SET is_active = 0, pinned = 1 WHERE id = ?')->execute([$a['id']]);
$fetched = [];
$payload['data'][0]['caption'] = 'Edited on Instagram';
$report = instagramProcessMedia($payload, $fetcher);
$a2 = reel('ZZIgTestReelA1');
t('second sync updates both reels, adds none', $report['added'] === 0 && $report['updated'] === 2, json_encode($report));
t('second sync downloads no covers (they exist)', $fetched === [] && $report['covers'] === 0, json_encode($fetched));
t('hidden + pinned set in the admin are kept', $a2 && (int) $a2['is_active'] === 0 && (int) $a2['pinned'] === 1, json_encode($a2));
t('caption follows the edit on Instagram', $a2 && $a2['caption'] === 'Edited on Instagram');
t('a hidden reel is not on the home page', !in_array((int) $a['id'], array_map('intval', array_column(socialReelsForHome(30), 'id')), true));

// 3. A missing cover file is fetched again; a broken image is reported, the reel kept without cover.
unlink(dirname(__DIR__) . '/' . $b['cover']);
$fetched = [];
$payload['data'][] = ['id' => '17900000000000004', 'caption' => 'Broken cover', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
    'permalink' => 'https://www.instagram.com/reels/ZZIgTestReelD4/?igsh=abc', 'thumbnail_url' => 'https://scontent.cdninstagram.com/v/broken.jpg', 'timestamp' => '2026-11-17T07:00:00+0000'];
$payload['data'][] = ['id' => '17900000000000005', 'caption' => 'No link', 'media_type' => 'VIDEO', 'permalink' => 'https://example.com/whatever', 'timestamp' => '2026-11-17T07:00:00+0000'];
$report = instagramProcessMedia($payload, $fetcher);
$d = reel('ZZIgTestReelD4');
t('the cover deleted from disk is downloaded again', in_array('https://scontent.cdninstagram.com/v/b2.jpg', $fetched, true) && is_file(dirname(__DIR__) . '/' . SOCIAL_REEL_DIR . '/ZZIgTestReelB2.jpg'), json_encode($fetched));
t('a /reels/ link with a query string is read', $d !== null);
t('a reel whose cover is not an image is kept without a cover', $d && $d['cover'] === null);
t('the broken cover and the bad link are reported', count($report['errors']) === 2, json_encode($report['errors']));
t('a reel without a cover is not on the home page', $d && !in_array((int) $d['id'], array_map('intval', array_column(socialReelsForHome(30), 'id')), true));

// 4. Helpers.
t('reel detection: REELS product', instagramIsReel(['media_product_type' => 'REELS', 'media_type' => 'VIDEO']));
t('reel detection: plain VIDEO', instagramIsReel(['media_type' => 'VIDEO']));
t('reel detection: carousel is not a reel', !instagramIsReel(['media_type' => 'CAROUSEL_ALBUM', 'media_product_type' => 'FEED']));
t('image fetcher refuses other hosts without fetching', instagramFetchImage('https://example.com/a.jpg') === null && instagramFetchImage('http://scontent.cdninstagram.com/a.jpg') === null);
t('token renewal due after 7 days', instagramRefreshDue(date('Y-m-d H:i:s', time() - 8 * 86400), time()) && !instagramRefreshDue(date('Y-m-d H:i:s', time() - 2 * 86400), time()));
t('token renewal due when the date is unknown', instagramRefreshDue('', time()));
t('expired token error is readable and has no token', str_contains(instagramApiError(400, ['error' => ['code' => 190, 'message' => 'Error validating access token']]), 'Integrations'));
t('rate limit error', str_contains(instagramApiError(400, ['error' => ['code' => 4]]), 'limiting'));
t('bad timestamp gives null', instagramLocalTime('not a date') === null && instagramLocalTime(null) === null);
$summary = instagramSyncSummary(['ok' => true, 'reels' => 1, 'added' => 1, 'updated' => 0, 'covers' => 1, 'errors' => [], 'notes' => []]);
t('summary text', $summary === '1 reel checked: 1 new, 0 updated, 1 cover saved.', $summary);

// 5. Without a token, the sync reports it and changes nothing.
if (!instagramConfigured()) {
    $before = instagramState('instagram_last_sync');
    $report = instagramSync();
    t('no token: sync refuses with a clear message', !$report['ok'] && str_contains($report['summary'], 'No Instagram access token'), $report['summary']);
    t('no token: last sync time untouched', instagramState('instagram_last_sync') === $before);
} else {
    echo "SKIP no-token checks (a token is saved locally)\n";
}

cleanup();
t('cleanup removed the test reels and covers', !query("SELECT 1 FROM social_reels WHERE shortcode LIKE 'ZZIgTest%'") && !glob(dirname(__DIR__) . '/' . SOCIAL_REEL_DIR . '/ZZIgTest*'));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
