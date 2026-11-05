<?php
declare(strict_types=1);

// Creates the ground-booking tables (database/booking.sql), only the missing ones, so it can be re-run:
//   php bin/migrate-booking.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/site.php';
echo implode("\n", bookingMigrate()), "\n";

function bookingMigrate(): array {
    $out = [];
    $sql = file_get_contents(__DIR__ . '/../database/booking.sql');
    preg_match_all('~CREATE TABLE IF NOT EXISTS (\w+) .*?DEFAULT CHARSET=utf8mb4;~s', $sql, $tables, PREG_SET_ORDER);
    foreach ($tables as [$create, $name]) {
        db()->exec(preg_replace('~--[^\n]*~', '', $create));
        $out[] = "table $name ensured";
    }
    // Columns added after the first version of the tables (safe to re-run).
    $has = fn (string $t, string $c) => (bool) query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
    foreach ([['bookings', 'opponent', 'ALTER TABLE bookings ADD COLUMN opponent VARCHAR(255) DEFAULT NULL AFTER format'],
        ['bookings', 'players_per_team', 'ALTER TABLE bookings ADD COLUMN players_per_team SMALLINT UNSIGNED NOT NULL DEFAULT 11 AFTER opponent'],
        ['bookings', 'teams_paying', 'ALTER TABLE bookings ADD COLUMN teams_paying TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER players_per_team'],
        ['bookings', 'total_amount', 'ALTER TABLE bookings ADD COLUMN total_amount DECIMAL(10,2) DEFAULT NULL AFTER quoted_amount'],
        ['bookings', 'advance_percent', 'ALTER TABLE bookings ADD COLUMN advance_percent DECIMAL(5,2) NOT NULL DEFAULT 50 AFTER total_amount'],
        ['bookings', 'days', 'ALTER TABLE bookings ADD COLUMN days TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER booking_date'],
        ['bookings', 'end_date', 'ALTER TABLE bookings ADD COLUMN end_date DATE DEFAULT NULL AFTER days'],
        ['bookings', 'want_lunch', 'ALTER TABLE bookings ADD COLUMN want_lunch TINYINT(1) NOT NULL DEFAULT 1 AFTER teams_paying'],
        ['bookings', 'want_tea', 'ALTER TABLE bookings ADD COLUMN want_tea TINYINT(1) NOT NULL DEFAULT 1 AFTER want_lunch'],
        ['bookings', 'meal_deduction', 'ALTER TABLE bookings ADD COLUMN meal_deduction DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER want_tea'],
        ['bookings', 'stay_needed', 'ALTER TABLE bookings ADD COLUMN stay_needed TINYINT(1) NOT NULL DEFAULT 0 AFTER meal_deduction'],
        ['bookings', 'stay_people', 'ALTER TABLE bookings ADD COLUMN stay_people SMALLINT UNSIGNED DEFAULT NULL AFTER stay_needed'],
        ['bookings', 'stay_nights', 'ALTER TABLE bookings ADD COLUMN stay_nights TINYINT UNSIGNED DEFAULT NULL AFTER stay_people'],
        ['bookings', 'stay_notes', 'ALTER TABLE bookings ADD COLUMN stay_notes VARCHAR(500) DEFAULT NULL AFTER stay_nights'],
        ['bookings', 'stay_amount', 'ALTER TABLE bookings ADD COLUMN stay_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER stay_notes'],
        ['bookings', 'extra_amount', 'ALTER TABLE bookings ADD COLUMN extra_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER stay_amount'],
        ['bookings', 'extra_note', 'ALTER TABLE bookings ADD COLUMN extra_note VARCHAR(255) DEFAULT NULL AFTER extra_amount'],
        ['bookings', 'admin_modified', 'ALTER TABLE bookings ADD COLUMN admin_modified TINYINT(1) NOT NULL DEFAULT 0 AFTER extra_note'],
        ['bookings', 'request_snapshot', 'ALTER TABLE bookings ADD COLUMN request_snapshot TEXT DEFAULT NULL AFTER admin_modified'],
        ['bookings', 'balance_amount', 'ALTER TABLE bookings ADD COLUMN balance_amount DECIMAL(10,2) DEFAULT NULL AFTER amount']] as [$t, $c, $alter]) {
        if (!$has($t, $c)) { db()->exec($alter); $out[] = "added $t.$c"; }
    }
    // The published rate card (Shimla Cricket Ground), inserted only when a package has no row yet.
    $card = [
        ['single', 7500, 1, '3 hours', '20 overs', "Ground booking
2 umpires and 1 scorer
White leather balls
Player of the Match trophy
Fighter of the Match trophy
Complimentary lunch and tea"],
        ['double', 12650, 1, '6 hours + 20 minute break', '20 overs each', "Ground booking
2 umpires and 1 scorer
White leather balls
Player of the Match trophy (each match)
Fighter of the Match trophy (each match)
Complimentary lunch and tea"],
        ['full_day', 30000, 0, '9 hours', null, "Ground booking
2 umpires and 1 scorer
4 white leather balls
Player of the Match trophy (each match)
Fighter of the Match trophy (each match)
Complimentary lunch and tea"],
    ];
    $insert = db()->prepare('INSERT IGNORE INTO booking_rates (format, price, per_team, advance_percent, duration_label, overs_label, includes) VALUES (?, ?, ?, 50, ?, ?, ?)');
    foreach ($card as [$format, $price, $perTeam, $duration, $overs, $includes]) {
        $insert->execute([$format, $price, $perTeam, $duration, $overs, str_replace('\n', "
", $includes)]);
    }
    $out[] = 'rate card ensured (single, double, full day)';
    return $out;
}
