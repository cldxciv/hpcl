-- Match schedule of a season, shown at /fixtures (includes/fixtures.php). One row per match slot
-- of a day: a group match has two teams, a knockout match has a label ("Quarter Final 1") and a
-- detail line ("Group A – 1st Position vs Group B – 4th Position"), a break day has only a label.
-- Run once (bin/migrate-fixtures.php does the same and can be re-run); filled for Season 4 by
-- bin/seed-season4-fixtures.php.
CREATE TABLE IF NOT EXISTS season_fixtures (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    season_id   INT UNSIGNED NOT NULL,
    match_date  DATE         NOT NULL,
    slot        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    stage       VARCHAR(16)  NOT NULL DEFAULT 'group',   -- group | quarter_final | semi_final | final | break
    pool        VARCHAR(16)  DEFAULT NULL,               -- the group of a group match: A, B
    team_a_id   INT UNSIGNED DEFAULT NULL,
    team_b_id   INT UNSIGNED DEFAULT NULL,
    label       VARCHAR(255) DEFAULT NULL,
    detail      VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_fixture_season_date (season_id, match_date, slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
