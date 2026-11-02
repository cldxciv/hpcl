-- When a site user was first asked to pick a favourite team (and picked one or skipped), so the
-- prompt after their first login appears once. NULL = not asked yet. Run once, or use
-- bin/migrate-users-team-prompt.php (safe to repeat). The site works without the column: the
-- prompt is simply not shown until it exists.
ALTER TABLE users ADD COLUMN team_prompted_at DATETIME NULL AFTER favourite_team_id;
