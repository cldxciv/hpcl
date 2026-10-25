<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/db.php';
if (!db()->query("SHOW COLUMNS FROM home_slides LIKE 'options'")->fetch()) {
    db()->exec('ALTER TABLE home_slides ADD COLUMN options JSON NULL');
}
echo "Hero slider schema ready.\n";
