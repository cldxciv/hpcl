<?php
declare(strict_types=1);

// Adds users.team_prompted_at (database/users-team-prompt.sql), skipping it when it already
// exists, so it is safe to run more than once:  php bin/migrate-users-team-prompt.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/site.php';

if (query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['users', 'team_prompted_at'])) {
    echo "users.team_prompted_at already exists\n";
    exit;
}
db()->exec('ALTER TABLE users ADD COLUMN team_prompted_at DATETIME NULL AFTER favourite_team_id');
echo "added users.team_prompted_at\n";
