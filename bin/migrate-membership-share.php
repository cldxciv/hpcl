<?php
declare(strict_types=1);

// Adds the member photo and share-kit columns (database/membership-share.sql), skipping any that
// already exist, so it is safe to run more than once:  php bin/migrate-membership-share.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/site.php';

$has = fn (string $table, string $column) => (bool) query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
$steps = [
    ['membership_applications', 'photo', 'ALTER TABLE membership_applications ADD COLUMN photo VARCHAR(512) DEFAULT NULL AFTER message'],
    ['membership_applications', 'share_token', 'ALTER TABLE membership_applications ADD COLUMN share_token VARCHAR(40) DEFAULT NULL AFTER photo, ADD UNIQUE KEY uq_membership_share_token (share_token)'],
    ['members', 'photo', 'ALTER TABLE members ADD COLUMN photo VARCHAR(512) DEFAULT NULL AFTER logo'],
];
foreach ($steps as [$table, $column, $sql]) {
    if ($has($table, $column)) { echo "$table.$column already exists\n"; continue; }
    db()->exec($sql);
    echo "added $table.$column\n";
}
