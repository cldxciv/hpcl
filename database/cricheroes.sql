-- A player's CricHeroes profile link, shown as a button on the player page and editable at
-- /admin/players. Optional. Run once; filled in by hand or with bin/import-cricheroes.php.
ALTER TABLE players ADD COLUMN cricheroes_url VARCHAR(512) DEFAULT NULL AFTER photo;
