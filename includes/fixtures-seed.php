<?php
declare(strict_types=1);

// Loads the Season 4 schedule ("PRO HPCL 2026 Fixtures" PDF) into season_fixtures and puts the 12
// teams in it into Group A or B (season_teams.pool). Used by bin/seed-season4-fixtures.php and by
// the one-off runner that sets up the live database. Safe to run again: it replaces Season 4's
// fixtures each time. Not loaded by the website.
require_once __DIR__ . '/site.php';

// The name used in the PDF => the team's slug on the site.
const FIXTURE_TEAM_SLUGS = [
    'Cyberbell Tech' => 'cyberbells-tech-titans-kotkhai', 'Rohru Knight Hawks' => 'knight-hawks', 'Indian Saga' => 'indian-saga-royals-shimla',
    'Kanwills Titans' => 'kanwills-titans', 'Shimla Royals' => 'shimla-royals', 'Mandi Marvels' => 'mandi-marvels',
    'Shilon Bagh Royals' => 'shilonbagh-royals', 'Shangrila Farms' => 'shangrila-farms', 'Rising Star Baldeyan' => 'rising-star-baldeyan',
    'Aspire 11' => 'aspire-11', 'Shimla Titans' => 'shimla-titans', 'Chudeshwar Legends' => 'chudeshwar-legends-sirmaur',
];
const FIXTURE_POOLS = [
    'A' => ['Cyberbell Tech', 'Rohru Knight Hawks', 'Indian Saga', 'Kanwills Titans', 'Shimla Royals', 'Mandi Marvels'],
    'B' => ['Shilon Bagh Royals', 'Shangrila Farms', 'Rising Star Baldeyan', 'Aspire 11', 'Shimla Titans', 'Chudeshwar Legends'],
];
// date => the matches of the day, in the PDF's Match 1, 2, 3 order. A group match is [team, team];
// 'break' is a rest day; a knockout match is [stage, label, detail].
const FIXTURE_DAYS = [
    '2026-11-17' => [['Cyberbell Tech', 'Rohru Knight Hawks'], ['Mandi Marvels', 'Shimla Royals'], ['Cyberbell Tech', 'Kanwills Titans']],
    '2026-11-18' => [['Cyberbell Tech', 'Indian Saga'], ['Rohru Knight Hawks', 'Kanwills Titans'], ['Mandi Marvels', 'Cyberbell Tech']],
    '2026-11-19' => [['Kanwills Titans', 'Shimla Royals'], ['Mandi Marvels', 'Rohru Knight Hawks'], ['Shimla Royals', 'Indian Saga']],
    '2026-11-20' => [['Mandi Marvels', 'Kanwills Titans'], ['Rohru Knight Hawks', 'Shimla Royals'], ['Mandi Marvels', 'Indian Saga']],
    '2026-11-21' => [['Rohru Knight Hawks', 'Indian Saga'], ['Cyberbell Tech', 'Shimla Royals'], ['Kanwills Titans', 'Indian Saga']],
    '2026-11-22' => ['break'],
    '2026-11-23' => [['Shilon Bagh Royals', 'Shangrila Farms'], ['Shimla Titans', 'Rising Star Baldeyan'], ['Shilon Bagh Royals', 'Chudeshwar Legends']],
    '2026-11-24' => [['Rising Star Baldeyan', 'Shangrila Farms'], ['Shimla Titans', 'Shilon Bagh Royals'], ['Rising Star Baldeyan', 'Chudeshwar Legends']],
    '2026-11-25' => [['Rising Star Baldeyan', 'Shilon Bagh Royals'], ['Shimla Titans', 'Chudeshwar Legends'], ['Shangrila Farms', 'Aspire 11']],
    '2026-11-26' => [['Shilon Bagh Royals', 'Aspire 11'], ['Shimla Titans', 'Shangrila Farms'], ['Aspire 11', 'Chudeshwar Legends']],
    '2026-11-27' => [['Rising Star Baldeyan', 'Aspire 11'], ['Shangrila Farms', 'Chudeshwar Legends'], ['Shimla Titans', 'Aspire 11']],
    '2026-11-28' => ['break'],
    '2026-11-29' => [['quarter_final', 'Quarter Final 1', 'Group A – 1st Position vs Group B – 4th Position'],
                     ['quarter_final', 'Quarter Final 2', 'Group B – 2nd Position vs Group A – 3rd Position'],
                     ['quarter_final', 'Quarter Final 3', 'Group B – 1st Position vs Group A – 4th Position']],
    '2026-11-30' => [['quarter_final', 'Quarter Final 4', 'Group A – 2nd Position vs Group B – 3rd Position'],
                     ['semi_final', 'Semi Final 1', 'Winner QF 1 vs Winner QF 2']],
    '2026-12-01' => [['semi_final', 'Semi Final 2', 'Winner QF 3 vs Winner QF 4'],
                     ['final', 'Final', 'Winner SF 1 vs Winner SF 2']],
];

/** Creates the season_fixtures table when it is missing (the same as database/fixtures.sql). */
function fixturesCreateTable(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS season_fixtures (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        season_id   INT UNSIGNED NOT NULL,
        match_date  DATE         NOT NULL,
        slot        TINYINT UNSIGNED NOT NULL DEFAULT 1,
        stage       VARCHAR(16)  NOT NULL DEFAULT \'group\',
        pool        VARCHAR(16)  DEFAULT NULL,
        team_a_id   INT UNSIGNED DEFAULT NULL,
        team_b_id   INT UNSIGNED DEFAULT NULL,
        label       VARCHAR(255) DEFAULT NULL,
        detail      VARCHAR(255) DEFAULT NULL,
        created_at  DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_fixture_season_date (season_id, match_date, slot)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/**
 * Saves the Season 4 schedule and groups. With $removeOthers the teams that are not in the schedule
 * leave Season 4's team list (their squads stay in the database). Returns the lines to show; the
 * last ones are the SQL that puts any removed teams back. Throws RuntimeException, changing nothing,
 * when a team can't be found.
 */
function seedSeason4Fixtures(bool $removeOthers): array {
    fixturesCreateTable();
    $season = query('SELECT id FROM seasons WHERE route_key = 4')[0] ?? null;
    if (!$season) throw new RuntimeException('Season 4 not found');
    $seasonId = (int) $season['id'];

    $ids = [];
    foreach (FIXTURE_TEAM_SLUGS as $name => $slug) {
        $team = query('SELECT t.id FROM teams t JOIN season_teams st ON st.team_id = t.id WHERE t.slug = ? AND st.season_id = ? AND t.deleted_at IS NULL', [$slug, $seasonId])[0] ?? null;
        if (!$team) throw new RuntimeException("No Season 4 team with the slug $slug (for \"$name\")");
        $ids[$name] = (int) $team['id'];
    }
    $poolOf = [];
    foreach (FIXTURE_POOLS as $pool => $names) foreach ($names as $name) $poolOf[$name] = $pool;

    $pdo = db();
    $lines = [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM season_fixtures WHERE season_id = ?')->execute([$seasonId]);
        $insert = $pdo->prepare('INSERT INTO season_fixtures (season_id, match_date, slot, stage, pool, team_a_id, team_b_id, label, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $count = 0;
        foreach (FIXTURE_DAYS as $date => $matches) {
            foreach ($matches as $i => $match) {
                if ($match === 'break') { $insert->execute([$seasonId, $date, 1, 'break', null, null, null, 'Pitch preparation', null]); $count++; continue; }
                if (isset($ids[$match[0]])) {
                    if ($poolOf[$match[0]] !== $poolOf[$match[1]]) throw new RuntimeException("{$match[0]} and {$match[1]} are in different groups");
                    $insert->execute([$seasonId, $date, $i + 1, 'group', $poolOf[$match[0]], $ids[$match[0]], $ids[$match[1]], null, null]);
                } else {
                    $insert->execute([$seasonId, $date, $i + 1, $match[0], null, null, null, $match[1], $match[2]]);
                }
                $count++;
            }
        }
        $setPool = $pdo->prepare('UPDATE season_teams SET pool = ? WHERE season_id = ? AND team_id = ?');
        foreach ($ids as $name => $id) $setPool->execute([$poolOf[$name], $seasonId, $id]);
        $lines[] = "Season 4: $count schedule rows saved; " . count($ids) . ' teams placed in Group A or B.';

        if ($removeOthers) {
            $keep = implode(',', array_map('intval', $ids));
            $others = query("SELECT st.team_id, st.pool, t.name FROM season_teams st JOIN teams t ON t.id = st.team_id WHERE st.season_id = ? AND st.team_id NOT IN ($keep) ORDER BY t.name", [$seasonId]);
            $pdo->prepare("DELETE FROM season_teams WHERE season_id = ? AND team_id NOT IN ($keep)")->execute([$seasonId]);
            $lines[] = 'Took ' . count($others) . ' teams out of Season 4 (their squads are kept). To put them back:';
            foreach ($others as $team) $lines[] = "INSERT INTO season_teams (season_id, team_id, pool) VALUES ($seasonId, {$team['team_id']}, " . ($team['pool'] === null ? 'NULL' : $pdo->quote($team['pool'])) . "); -- {$team['name']}";
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw new RuntimeException('Nothing was changed: ' . $error->getMessage(), 0, $error);
    }
    return $lines;
}
