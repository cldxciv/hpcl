<?php
declare(strict_types=1);

// Loads the Season 4 schedule ("PRO HPCL 2026 Fixtures" PDF) into season_fixtures and puts the 12
// teams in it into Group A or B (season_teams.pool); creates the table first when it is missing.
// Safe to run again: it replaces Season 4's fixtures each time.
//   php bin/seed-season4-fixtures.php                  add the fixtures and the groups
//   php bin/seed-season4-fixtures.php --remove-others  also take the 8 teams that are not in the
//                                                      schedule out of Season 4's team list; their
//                                                      squads are kept, and the SQL that puts the
//                                                      teams back is printed
// The schedule itself is in includes/fixtures-seed.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/fixtures-seed.php';

try {
    echo implode("\n", seedSeason4Fixtures(in_array('--remove-others', $argv, true))) . "\n";
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
