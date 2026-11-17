<?php
declare(strict_types=1);

// Creates the Instagram reels table and its settings (database/social.sql). Safe to re-run:
//   php bin/migrate-social.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/site.php';
echo implode("\n", socialMigrate()), "\n";

function socialMigrate(): array {
    $out = [];
    $sql = preg_replace('~--[^\n]*~', '', (string) file_get_contents(__DIR__ . '/../database/social.sql'));
    foreach (array_filter(array_map('trim', preg_split('~;\s*(?:\n|$)~', $sql))) as $statement) {
        db()->exec($statement);
        $out[] = preg_match('~^(CREATE TABLE IF NOT EXISTS \w+|INSERT IGNORE INTO \w+)~', $statement, $m) ? $m[1] . ': done' : 'statement done';
    }
    $folder = dirname(__DIR__) . '/storage/uploads/social/reels';
    if (!is_dir($folder)) $out[] = @mkdir($folder, 0755, true) ? 'created storage/uploads/social/reels' : 'could not create storage/uploads/social/reels';
    return $out;
}
