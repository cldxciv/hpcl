<?php
declare(strict_types=1);

// Adds @prohpcl's new Instagram reels to the home page (includes/instagram-sync.php). Needs an
// access token saved at Admin > Integrations. Run it every hour from the server's cron:
//
//   hPanel > Advanced > Cron Jobs > Custom, "Every hour" (0 * * * *), command:
//     /usr/bin/php /home/<hosting user>/domains/prohpcl.com/public_html/bin/sync-instagram.php >/dev/null 2>&1
//   (or the "PHP" cron type with the path domains/prohpcl.com/public_html/bin/sync-instagram.php)
//
// By hand:  php bin/sync-instagram.php            the latest 50 posts
//           php bin/sync-instagram.php --pages=4  the latest 200 posts (a first, deeper sync)
// Exit code 0 when the sync worked, 1 when it failed or no token is saved.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/site.php';
require_once __DIR__ . '/../includes/instagram-sync.php';

$pages = 1;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('~^--pages=(\d{1,2})$~', $argument, $m)) $pages = max(1, min(10, (int) $m[1]));
    else { fwrite(STDERR, "Unknown option $argument. Use --pages=N (1 to 10).\n"); exit(2); }
}
$report = instagramSync($pages);
echo date('Y-m-d H:i:s') . ' ' . $report['summary'] . "\n";
foreach (array_slice($report['errors'], 1, 9) as $error) echo "  $error\n";
exit($report['ok'] ? 0 : 1);
