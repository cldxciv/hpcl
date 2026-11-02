<?php
declare(strict_types=1);

// Creates the season_fixtures table (database/fixtures.sql) if it is missing; safe to run again:
//   php bin/migrate-fixtures.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/fixtures-seed.php';

fixturesCreateTable();
echo "season_fixtures is ready\n";
