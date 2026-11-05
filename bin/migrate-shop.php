<?php
declare(strict_types=1);

// Applies database/shop.sql safely (each column/table only when missing), so it can be re-run:
//   php bin/migrate-shop.php
// The demo products that shipped with the old catalogue are hidden (is_active = 0) the first time
// shop_products.is_active is added, so they do not appear on the public shop.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/site.php';
echo implode("\n", shopMigrate()), "\n";

function shopMigrate(): array {
    $out = [];
    $has = fn (string $t, string $c) => (bool) query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
    $add = function (string $t, string $c, string $sql, ?string $after = null) use ($has, &$out) {
        if ($has($t, $c)) { $out[] = "$t.$c exists"; return false; }
        db()->exec($sql); $out[] = "added $t.$c"; return true;
    };
    if ($add('shop_products', 'is_active', 'ALTER TABLE shop_products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER position')) {
        db()->exec('UPDATE shop_products SET is_active = 0'); $out[] = 'hid the existing demo products (is_active = 0)';
    }
    $add('shop_products', 'created_at', 'ALTER TABLE shop_products ADD COLUMN created_at DATETIME DEFAULT NULL AFTER is_active');
    $add('shop_products', 'updated_at', 'ALTER TABLE shop_products ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at');
    $add('shop_categories', 'image', 'ALTER TABLE shop_categories ADD COLUMN image VARCHAR(512) DEFAULT NULL AFTER name');
    $add('shop_categories', 'is_active', 'ALTER TABLE shop_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER position');
    $add('shop_product_variants', 'id', 'ALTER TABLE shop_product_variants ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
    $sql = file_get_contents(__DIR__ . '/../database/shop.sql');
    preg_match_all('~CREATE TABLE IF NOT EXISTS .*?DEFAULT CHARSET=utf8mb4;~s', $sql, $tables);
    foreach ($tables[0] as $create) { db()->exec(preg_replace('~--[^\n]*~', '', $create)); }
    $out[] = 'tables shop_orders, shop_order_items, shop_order_events ensured';
    return $out;
}
