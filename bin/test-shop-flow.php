<?php
declare(strict_types=1);

// LOCAL-ONLY helper for testing the shop checkout without a Razorpay account (refuses to run unless APP_ENV=local):
//   php bin/test-shop-flow.php setup      turns the shop on, fakes Razorpay (shop_fake_razorpay) with throw-away keys and adds two test products
//   php bin/test-shop-flow.php sig ORDER_ID PAYMENT_ID     prints the razorpay_signature Checkout would return, for the fake key secret
//   php bin/test-shop-flow.php webhook-sig FILE            prints the X-Razorpay-Signature of a webhook body file, for the fake webhook secret
//   php bin/test-shop-flow.php dbtests    exercises stock, the paid transition, idempotency, cancel/restock and amounts directly
//   php bin/test-shop-flow.php cleanup    removes the test products and orders (slug zzco-, email *@shoptest.local) and the fake settings
// The browser flow is driven by bin/test-shop-flow.py (Playwright). None of this exists on the live site: the seam in
// includes/shop-checkout.php (shopFakeRazorpay) only works when appEnv() === 'local'.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
if (appEnv() !== 'local') { fwrite(STDERR, "Refusing to run: APP_ENV is not local.\n"); exit(1); }
require_once __DIR__ . '/../includes/site.php';
if (!function_exists('escape')) { function escape(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } } // defined by index.php on the site
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/shop-orders.php';
require_once __DIR__ . '/../includes/integrations.php';

const TEST_KEY_ID = 'rzp_test_LocalFake12345';
const TEST_KEY_SECRET = 'localfakesecret0123456789';
const TEST_WEBHOOK_SECRET = 'localfakewebhooksecret';

function setSetting(string $name, ?string $value): void {
    if ($value === null) { db()->prepare('DELETE FROM site_settings WHERE name = ?')->execute([$name]); return; }
    db()->prepare('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$name, $value]);
}
function check(string $what, bool $ok, string $detail = ''): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . ($detail !== '' && !$ok ? " ($detail)" : '') . "\n";
    if (!$ok) $GLOBALS['failed'] = true;
}

$command = $argv[1] ?? '';
$failed = false;
if ($command === 'setup') {
    setSetting('shop_enabled', '1');
    setSetting('shop_fake_razorpay', '1');
    setSetting('razorpay_enabled', '1');
    setSetting('razorpay_mode', 'test');
    setSetting('razorpay_key_id', TEST_KEY_ID);
    setSetting('razorpay_key_secret', encryptSecret(TEST_KEY_SECRET));
    setSetting('razorpay_webhook_secret', encryptSecret(TEST_WEBHOOK_SECRET));
    setSetting('shop_shipping_fee', '60');
    setSetting('shop_free_shipping_over', '2000');
    setSetting('shop_support_phone', '+91 98765 43210');
    setSetting('shop_notify_email', 'team@shoptest.local');
    $category = query("SELECT id FROM shop_categories WHERE slug = 'apparel'")[0]['id'] ?? null;
    foreach (query("SELECT id FROM shop_products WHERE slug LIKE 'zzco-%'") as $row) {
        foreach (['shop_product_variants', 'shop_product_colours', 'shop_product_images'] as $table) db()->prepare("DELETE FROM $table WHERE product_id = ?")->execute([$row['id']]);
        db()->prepare('DELETE FROM shop_products WHERE id = ?')->execute([$row['id']]);
    }
    $insert = db()->prepare('INSERT INTO shop_products (slug, name, brand, category_id, short_description, description, price, compare_price, gst_rate, stock, image, position, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 5, ?, ?, 1, 1, NOW())');
    $insert->execute(['zzco-jersey', 'TEST Pro HPCL Jersey', 'Pro HPCL', $category, 'Test jersey', 'A jersey for tests.', 799, 999, 0, 'assets/media/logo.png']);
    $jersey = (int) db()->lastInsertId();
    $insert->execute(['zzco-cap', 'TEST Pro HPCL Cap', 'Pro HPCL', $category, 'Test cap', 'A cap for tests.', 299, null, 3, 'assets/media/logo.png']);
    $cap = (int) db()->lastInsertId();
    $variant = db()->prepare('INSERT INTO shop_product_variants (product_id, colour_id, size, stock) VALUES (?, NULL, ?, ?)');
    foreach ([['M', 5], ['L', 2]] as [$size, $stock]) $variant->execute([$jersey, $size, $stock]);
    echo "setup done: jersey #$jersey (M 5, L 2), cap #$cap (stock 3)\n";
} elseif ($command === 'sig') {
    echo hash_hmac('sha256', ($argv[2] ?? '') . '|' . ($argv[3] ?? ''), TEST_KEY_SECRET), "\n";
} elseif ($command === 'webhook-sig') {
    echo hash_hmac('sha256', (string) file_get_contents($argv[2] ?? ''), TEST_WEBHOOK_SECRET), "\n";
} elseif ($command === 'cleanup') {
    foreach (query("SELECT id FROM shop_orders WHERE email LIKE '%@shoptest.local'") as $order) {
        foreach (['shop_order_items', 'shop_order_events'] as $table) db()->prepare("DELETE FROM $table WHERE order_id = ?")->execute([$order['id']]);
        db()->prepare('DELETE FROM shop_orders WHERE id = ?')->execute([$order['id']]);
    }
    foreach (query("SELECT id FROM shop_products WHERE slug LIKE 'zzco-%'") as $row) {
        foreach (['shop_product_variants', 'shop_product_colours', 'shop_product_images'] as $table) db()->prepare("DELETE FROM $table WHERE product_id = ?")->execute([$row['id']]);
        db()->prepare('DELETE FROM shop_products WHERE id = ?')->execute([$row['id']]);
    }
    foreach (['shop_fake_razorpay', 'razorpay_enabled', 'razorpay_mode', 'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret', 'shop_notify_email', 'shop_support_phone', 'shop_free_shipping_over', 'shop_shipping_fee'] as $name) setSetting($name, null);
    echo "cleanup done (shop_enabled left as it is)\n";
} elseif ($command === 'dbtests') {
    $product = query("SELECT id FROM shop_products WHERE slug = 'zzco-jersey'")[0]['id'] ?? null;
    $cap = query("SELECT id FROM shop_products WHERE slug = 'zzco-cap'")[0]['id'] ?? null;
    if (!$product || !$cap) { fwrite(STDERR, "Run setup first.\n"); exit(1); }
    foreach (query("SELECT id FROM shop_orders WHERE email = 'dbtest@shoptest.local'") as $old) {
        foreach (['shop_order_items', 'shop_order_events'] as $table) db()->prepare("DELETE FROM $table WHERE order_id = ?")->execute([$old['id']]);
        db()->prepare('DELETE FROM shop_orders WHERE id = ?')->execute([$old['id']]);
    }
    $variantM = (int) query('SELECT id FROM shop_product_variants WHERE product_id = ? AND size = ?', [$product, 'M'])[0]['id'];
    $stock = fn () => [(int) query('SELECT stock FROM shop_product_variants WHERE id = ?', [$variantM])[0]['stock'], (int) query('SELECT stock FROM shop_products WHERE id = ?', [$cap])[0]['stock']];
    db()->prepare('UPDATE shop_product_variants SET stock = 5 WHERE id = ?')->execute([$variantM]);
    db()->prepare('UPDATE shop_products SET stock = 3 WHERE id = ?')->execute([$cap]);
    $make = function (string $rzpId, array $items) {
        db()->prepare("INSERT INTO shop_orders (order_number, token, name, email, phone, address_line1, city, state, pincode, subtotal, shipping, total, status, razorpay_order_id, created_at)
            VALUES (?, ?, 'DB Tester', 'dbtest@shoptest.local', '9876543210', '12 Test Street', 'Shimla', 'Himachal Pradesh', '171001', 1000, 60, 1060, 'pending', ?, NOW())")
            ->execute(['T-' . bin2hex(random_bytes(4)), bin2hex(random_bytes(16)), $rzpId]);
        $id = (int) db()->lastInsertId();
        foreach ($items as [$pid, $vid, $qty]) {
            db()->prepare('INSERT INTO shop_order_items (order_id, product_id, variant_id, product_name, variant_label, unit_price, qty, line_total) VALUES (?, ?, ?, ?, ?, 500, ?, ?)')
                ->execute([$id, $pid, $vid, 'Test item', $vid ? 'M' : null, $qty, 500 * $qty]);
        }
        return $id;
    };
    // 1. Paid once: stock goes down, status paid, event written.
    $a = $make('order_DBTEST000001', [[$product, $variantM, 2], [$cap, null, 1]]);
    check('wrong amount is refused', shopMarkOrderPaid('order_DBTEST000001', 'pay_DBTEST000001', 100) === false && shopOrderGet($a)['status'] === 'pending');
    check('paid with the right amount', shopMarkOrderPaid('order_DBTEST000001', 'pay_DBTEST000001', 106000) === true);
    check('status is paid', shopOrderGet($a)['status'] === 'paid');
    check('stock taken (variant 5->3, cap 3->2)', $stock() === [3, 2], json_encode($stock()));
    // 2. Same payment again (webhook + browser): nothing changes.
    check('second confirmation is accepted', shopMarkOrderPaid('order_DBTEST000001', 'pay_DBTEST000001', 106000) === true);
    check('stock not taken twice', $stock() === [3, 2], json_encode($stock()));
    check('only one paid event', count(array_filter(shopOrderGet($a)['events'], fn ($e) => $e['status'] === 'paid')) === 1);
    check('webhook entry point knows the order', shopPaymentReceived('order_DBTEST000001', 'pay_DBTEST000001', 106000) === true);
    check('webhook entry point ignores others', shopPaymentReceived('order_NOTSHOP', 'pay_X', 100) === false);
    // 3. Shipping with tracking, then cancelling gives the stock back.
    check('shipped with tracking', shopOrderSetStatus($a, 'shipped', 'Handed to courier', ['carrier' => 'DTDC', 'number' => 'D123456']));
    $o = shopOrderGet($a);
    check('tracking saved', $o['tracking_number'] === 'D123456' && $o['tracking_carrier'] === 'DTDC');
    check('same status again changes nothing', shopOrderSetStatus($a, 'shipped') === false);
    check('cancel works', shopOrderSetStatus($a, 'cancelled', 'Customer asked'));
    check('stock restored (5, 3)', $stock() === [5, 3], json_encode($stock()));
    check('stock_deducted cleared', (int) shopOrderGet($a)['stock_deducted'] === 0);
    check('cancel again does not restock twice', shopOrderSetStatus($a, 'cancelled') === false && $stock() === [5, 3]);
    $late = shopMarkOrderPaid('order_DBTEST000001', 'pay_LATE', 106000);
    check('a late payment on a cancelled order is flagged, not resurrected', $late === false && shopOrderGet($a)['status'] === 'cancelled'
        && (bool) array_filter(array_column(shopOrderGet($a)['events'], 'note'), fn ($n) => str_contains((string) $n, 'NEEDS ATTENTION')));
    // 4. Not enough stock: paid, flagged, no stock change.
    db()->prepare('UPDATE shop_products SET stock = 1 WHERE id = ?')->execute([$cap]);
    $b = $make('order_DBTEST000002', [[$cap, null, 2]]);
    check('paid even when stock is short', shopMarkOrderPaid('order_DBTEST000002', 'pay_DBTEST000002', 106000) === true && shopOrderGet($b)['status'] === 'paid');
    $events = array_column(shopOrderGet($b)['events'], 'note');
    check('stock-short order is flagged NEEDS ATTENTION', (bool) array_filter($events, fn ($n) => str_contains((string) $n, 'NEEDS ATTENTION')));
    check('no stock taken for a short order', (int) query('SELECT stock FROM shop_products WHERE id = ?', [$cap])[0]['stock'] === 1 && (int) shopOrderGet($b)['stock_deducted'] === 0);
    // 5. Manual paid from the admin, then refund.
    db()->prepare('UPDATE shop_products SET stock = 3 WHERE id = ?')->execute([$cap]);
    $c = $make('order_DBTEST000003', [[$cap, null, 1]]);
    check('admin can mark pending as paid', shopOrderSetStatus($c, 'paid', 'Bank transfer') === true && shopOrderGet($c)['status'] === 'paid');
    check('stock taken for the manual paid order', (int) query('SELECT stock FROM shop_products WHERE id = ?', [$cap])[0]['stock'] === 2);
    check('refund restocks', shopOrderSetStatus($c, 'refunded', 'Refunded in Razorpay') && (int) query('SELECT stock FROM shop_products WHERE id = ?', [$cap])[0]['stock'] === 3);
    // 6. Expiry of old pending orders.
    $d = $make('order_DBTEST000004', [[$cap, null, 1]]);
    db()->prepare('UPDATE shop_orders SET created_at = (NOW() - INTERVAL 30 HOUR) WHERE id = ?')->execute([$d]);
    check('old pending orders are closed', shopExpirePendingOrders() >= 1 && shopOrderGet($d)['status'] === 'failed');
    check('listing and counting work', shopOrderCount(['q' => 'DB Tester']) >= 4 && count(shopOrderList(['status' => 'cancelled', 'q' => 'dbtest@shoptest.local'])) === 1);
    check('lookup by token and number', shopOrderGet(shopOrderGet($a)['token'])['id'] == $a && shopOrderGet(shopOrderGet($a)['order_number'])['id'] == $a);
    echo "mail log: private/mail.log, html in private/mail-html/\n";
    exit($failed ? 1 : 0);
} else {
    fwrite(STDERR, "Usage: php bin/test-shop-flow.php setup|sig|webhook-sig|dbtests|cleanup\n");
    exit(2);
}
