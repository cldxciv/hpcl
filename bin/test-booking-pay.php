<?php
declare(strict_types=1);

// LOCAL-ONLY helper for testing the booking payment without a Razorpay account (refuses to run unless APP_ENV=local):
//   php bin/test-booking-pay.php setup        turns booking on, fakes Razorpay (shop_fake_razorpay) with throw-away keys
//   php bin/test-booking-pay.php offline      switches Razorpay off (to see the offline-payment page); `online` switches it back on
//   php bin/test-booking-pay.php make STATUS [ADVANCE] [DUE_IN_HOURS] [EMAIL] [USER_ID] [DAYS_AHEAD] [EXTRA_JSON]   inserts a test booking, prints {"id","token","reference"}
//        EXTRA_JSON sets other columns (days, teams_paying, want_lunch, want_tea, stay_needed, stay_people, stay_nights, stay_amount, extra_amount, extra_note,
//        slot, format, admin_modified, request_snapshot ...); with "price": true the total/advance/balance are worked out by the core (bookingPriceBreakdown)
//   php bin/test-booking-pay.php approve ID TOTAL   bookingApprove() on a booking (a revision of an approved one clears its Razorpay order when the advance changes)
//   php bin/test-booking-pay.php expire ID          makes an approved booking overdue and runs bookingExpireDue()
//   php bin/test-booking-pay.php takeslot ID        confirms another booking on the same date, slot and package (the slot is gone)
//   php bin/test-booking-pay.php get ID       prints the booking's status, payment ids and last history rows
//   php bin/test-booking-pay.php sig ORDER_ID PAYMENT_ID    prints the razorpay_signature Checkout would return, for the fake key secret
//   php bin/test-booking-pay.php webhook-sig FILE           prints the X-Razorpay-Signature of a webhook body file, for the fake webhook secret
//   php bin/test-booking-pay.php dbtests      exercises the paid transition, idempotency, wrong amounts and late payments directly
//   php bin/test-booking-pay.php cleanup      removes the test bookings (reference BKT-*, email *@bookingtest.local) and the fake settings
// The browser flow is driven by bin/test-booking-pay.py (Playwright). None of this exists on the live site: the seam in
// includes/booking-pay.php (bookingFakeRazorpay) only works when appEnv() === 'local'.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
if (appEnv() !== 'local') { fwrite(STDERR, "Refusing to run: APP_ENV is not local.\n"); exit(1); }
require_once __DIR__ . '/../includes/site.php';
if (!function_exists('escape')) { function escape(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } } // defined by index.php on the site
require_once __DIR__ . '/../includes/content.php';
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
function makeBooking(string $status, float $advance = 3750, float $dueHours = 24, string $email = 'tester@bookingtest.local', ?int $userId = null, int $daysAhead = 20, array $extra = []): array {
    $token = bin2hex(random_bytes(16));
    $reference = 'BKT-' . random_int(100000, 999999);
    $date = date('Y-m-d', strtotime("+$daysAhead days"));
    $total = $advance * 2;
    $due = $status === 'approved' ? date('Y-m-d H:i:s', time() + (int) round($dueHours * 3600)) : null;
    db()->prepare('INSERT INTO bookings (reference, token, user_id, name, email, phone, organisation, booking_date, slot, format, status, quoted_amount, total_amount, advance_percent,
        amount, balance_amount, payment_due_at, approved_at, customer_note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 50, ?, ?, ?, ?, ?, NOW())')
        ->execute([$reference, $token, $userId, 'Test Captain', $email, '9876543210', 'Test XI', $date, 'morning', 'single', $status, $total, $total, $advance, $total - $advance, $due,
            $status === 'approved' ? date('Y-m-d H:i:s') : null, $status === 'approved' ? 'Approved for the test.' : null]);
    $id = (int) db()->lastInsertId();
    $allowed = ['days', 'teams_paying', 'players_per_team', 'want_lunch', 'want_tea', 'stay_needed', 'stay_people', 'stay_nights', 'stay_notes', 'stay_amount', 'extra_amount', 'extra_note',
        'slot', 'format', 'opponent', 'admin_modified', 'request_snapshot', 'discount_percent', 'member_tier'];
    $set = array_intersect_key($extra, array_flip($allowed));
    if (isset($set['days'])) $set['end_date'] = date('Y-m-d', strtotime($date . ' +' . ((int) $set['days'] - 1) . ' days'));
    if ($set) {
        db()->prepare('UPDATE bookings SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $id]);
    }
    if (!empty($extra['price'])) {
        require_once __DIR__ . '/../includes/booking.php';
        $row = query('SELECT * FROM bookings WHERE id = ?', [$id])[0];
        $row['total_amount'] = null;   // priced from the requirements, as before approval
        $bd = bookingPriceBreakdown($row);
        db()->prepare('UPDATE bookings SET quoted_amount = ?, meal_deduction = ?, total_amount = ?, amount = ?, balance_amount = ? WHERE id = ?')
            ->execute([$bd['base'], $bd['meal_deduction'], $bd['total'], $bd['advance'], $bd['balance'], $id]);
    }
    return ['id' => $id, 'token' => $token, 'reference' => $reference, 'date' => $date];
}

$command = $argv[1] ?? '';
$failed = false;
if ($command === 'setup') {
    setSetting('booking_enabled', '1');
    setSetting('shop_fake_razorpay', '1');
    setSetting('razorpay_enabled', '1');
    setSetting('razorpay_mode', 'test');
    setSetting('razorpay_key_id', TEST_KEY_ID);
    setSetting('razorpay_key_secret', encryptSecret(TEST_KEY_SECRET));
    setSetting('razorpay_webhook_secret', encryptSecret(TEST_WEBHOOK_SECRET));
    echo "setup done\n";
} elseif ($command === 'offline') {
    setSetting('razorpay_enabled', '0');
    setSetting('shop_fake_razorpay', null);
    echo "razorpay off\n";
} elseif ($command === 'online') {
    setSetting('razorpay_enabled', '1');
    setSetting('shop_fake_razorpay', '1');
    echo "razorpay on (fake)\n";
} elseif ($command === 'make') {
    echo json_encode(makeBooking((string) ($argv[2] ?? 'approved'), (float) ($argv[3] ?? 3750), (float) ($argv[4] ?? 24), (string) ($argv[5] ?? 'tester@bookingtest.local'),
        isset($argv[6]) && $argv[6] !== '' ? (int) $argv[6] : null, (int) ($argv[7] ?? 20), isset($argv[8]) && $argv[8] !== '' ? (array) json_decode($argv[8], true) : [])), "\n";
} elseif ($command === 'approve') {
    require_once __DIR__ . '/../includes/booking.php';
    echo bookingApprove((int) $argv[2], (float) $argv[3], 'Revised in the test.') ? "approved\n" : "refused\n";
} elseif ($command === 'expire') {
    require_once __DIR__ . '/../includes/booking.php';
    db()->prepare("UPDATE bookings SET payment_due_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = ? AND status = 'approved'")->execute([(int) $argv[2]]);
    echo 'expired ' . bookingExpireDue(true) . "\n";
} elseif ($command === 'takeslot') {
    $row = query('SELECT booking_date, slot, format, days FROM bookings WHERE id = ?', [(int) $argv[2]])[0];
    $other = makeBooking('confirmed', 3750, 24, 'other@bookingtest.local', null, (int) round((strtotime($row['booking_date']) - strtotime(date('Y-m-d'))) / 86400),
        ['slot' => $row['slot'], 'format' => $row['format'], 'days' => $row['days']]);
    echo json_encode($other), "\n";
} elseif ($command === 'get') {
    $row = query('SELECT id, reference, status, amount, razorpay_order_id, razorpay_payment_id, payment_method, paid_at FROM bookings WHERE id = ?', [(int) ($argv[2] ?? 0)])[0] ?? null;
    $events = query('SELECT status, note FROM booking_events WHERE booking_id = ? ORDER BY id DESC LIMIT 8', [(int) ($argv[2] ?? 0)]);
    echo json_encode(['row' => $row, 'events' => $events], JSON_UNESCAPED_SLASHES), "\n";
} elseif ($command === 'sig') {
    echo hash_hmac('sha256', ($argv[2] ?? '') . '|' . ($argv[3] ?? ''), TEST_KEY_SECRET), "\n";
} elseif ($command === 'webhook-sig') {
    echo hash_hmac('sha256', (string) file_get_contents($argv[2] ?? ''), TEST_WEBHOOK_SECRET), "\n";
} elseif ($command === 'cleanup') {
    foreach (query("SELECT id FROM bookings WHERE reference LIKE 'BKT-%' OR email LIKE '%@bookingtest.local'") as $row) {
        db()->prepare('DELETE FROM booking_events WHERE booking_id = ?')->execute([$row['id']]);
        db()->prepare('DELETE FROM bookings WHERE id = ?')->execute([$row['id']]);
    }
    foreach (['shop_fake_razorpay', 'razorpay_enabled', 'razorpay_mode', 'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret'] as $name) setSetting($name, null);
    echo "cleanup done (booking_enabled left as it is)\n";
} elseif ($command === 'dbtests') {
    require_once __DIR__ . '/../includes/account.php';
    require_once __DIR__ . '/../includes/booking-pay.php';
    $status = fn (int $id) => (string) query('SELECT status FROM bookings WHERE id = ?', [$id])[0]['status'];
    $events = fn (int $id, string $kind) => (int) query('SELECT COUNT(*) c FROM booking_events WHERE booking_id = ? AND status = ?', [$id, $kind])[0]['c'];
    $startOrder = function (array $b): string {
        $order = 'order_TESTBK' . bin2hex(random_bytes(5));
        db()->prepare('UPDATE bookings SET razorpay_order_id = ? WHERE id = ?')->execute([$order, $b['id']]);
        return $order;
    };

    $b = makeBooking('approved');
    $order = $startOrder($b);
    check('an unknown Razorpay order is not a booking', bookingPaymentReceived('order_NOSUCHORDER1', 'pay_X000000001', 100) === false);
    check('a wrong amount is refused and flagged', bookingPaymentReceived($order, 'pay_TESTBK00001', 12300) === true && $status($b['id']) === 'approved' && $events($b['id'], 'payment_issue') === 1);
    check('the right amount confirms the booking', bookingPaymentReceived($order, 'pay_TESTBK00002', 375000) === true && $status($b['id']) === 'confirmed');
    $row = query('SELECT razorpay_payment_id, payment_method, paid_at FROM bookings WHERE id = ?', [$b['id']])[0];
    check('payment id, method and time are saved', $row['razorpay_payment_id'] === 'pay_TESTBK00002' && $row['payment_method'] === 'razorpay' && $row['paid_at'] !== null);
    check('the same payment again changes nothing (idempotent)', bookingPaymentReceived($order, 'pay_TESTBK00002', 375000) === true && $status($b['id']) === 'confirmed');
    check('a second payment id on a confirmed booking is ignored', bookingPaymentReceived($order, 'pay_TESTBK00009', 375000) === true && (string) query('SELECT razorpay_payment_id FROM bookings WHERE id = ?', [$b['id']])[0]['razorpay_payment_id'] === 'pay_TESTBK00002');

    // Expired but the slot is still free (test bookings are 20 days ahead, nothing else holds it): the late advance confirms it.
    $late = makeBooking('expired', 3750, 24, 'tester@bookingtest.local', null, 41);
    $lateOrder = $startOrder($late);
    check('a late payment on an expired booking whose slot is still free confirms it', bookingPaymentReceived($lateOrder, 'pay_TESTBK00003', 375000) === true && $status($late['id']) === 'confirmed');
    // Expired and the slot was taken meanwhile: flagged for the team, nothing is applied.
    $taken = makeBooking('confirmed', 3750, 24, 'tester@bookingtest.local', null, 42);
    $lateTaken = makeBooking('expired', 3750, 24, 'tester@bookingtest.local', null, 42);
    $lateTakenOrder = $startOrder($lateTaken);
    check('a late payment whose slot was taken is flagged, not applied', bookingPaymentReceived($lateTakenOrder, 'pay_TESTBK00006', 375000) === true && $status($lateTaken['id']) === 'expired' && $events($lateTaken['id'], 'payment_issue') === 1);

    $old = makeBooking('approved');
    db()->prepare("INSERT INTO booking_events (booking_id, status, note, created_at) VALUES (?, 'payment_started', 'order_OLDORDER0001', NOW())")->execute([$old['id']]);
    db()->prepare('UPDATE bookings SET razorpay_order_id = ? WHERE id = ?')->execute(['order_NEWORDER0001', $old['id']]);
    check('a payment on an earlier order of the booking still finds it', bookingPaymentReceived('order_OLDORDER0001', 'pay_TESTBK00004', 375000) === true && $status($old['id']) === 'confirmed');

    $cancelled = makeBooking('cancelled');
    $cancelledOrder = $startOrder($cancelled);
    check('a payment on a cancelled booking is flagged', bookingPaymentReceived($cancelledOrder, 'pay_TESTBK00005', 375000) === true && $status($cancelled['id']) === 'cancelled');
    foreach (query("SELECT id FROM bookings WHERE reference LIKE 'BKT-%' AND email = 'tester@bookingtest.local'") as $r) {
        db()->prepare('DELETE FROM booking_events WHERE booking_id = ?')->execute([$r['id']]);
        db()->prepare('DELETE FROM bookings WHERE id = ?')->execute([$r['id']]);
    }
    echo $failed ? "SOME CHECKS FAILED\n" : "all db checks passed\n";
    exit($failed ? 1 : 0);
} else {
    fwrite(STDERR, "unknown command\n");
    exit(1);
}
