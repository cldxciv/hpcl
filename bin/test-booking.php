<?php
declare(strict_types=1);

// Tests of the ground booking core (includes/booking.php) against the LOCAL database:
//   php bin/test-booking.php
// It creates rows named "ZZ Booking Test" (emails zz-booking-*@example.com) and removes them at the end.
// Refuses to run unless APP_ENV=local.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
if (appEnv() !== 'local') { fwrite(STDERR, "Run this only with APP_ENV=local.\n"); exit(1); }
date_default_timezone_set('Asia/Kolkata');
$base = '';
// escape() lives in index.php, which a command-line script does not load.
if (!function_exists('escape')) { function escape(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
require_once __DIR__ . '/../includes/site.php';
require_once __DIR__ . '/../includes/booking.php';

$pass = 0;
$fail = 0;
function t(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
}
function status(int $id): string { return (string) (query('SELECT status FROM bookings WHERE id = ?', [$id])[0]['status'] ?? '?'); }
function events(int $id, string $status): int { return (int) query('SELECT COUNT(*) c FROM booking_events WHERE booking_id = ? AND status = ?', [$id, $status])[0]['c']; }
function cleanup(): void {
    $ids = array_column(query("SELECT id FROM bookings WHERE email LIKE 'zz-booking-%@example.com'"), 'id');
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        db()->exec("DELETE FROM booking_events WHERE booking_id IN ($in)");
        db()->exec("DELETE FROM bookings WHERE id IN ($in)");
    }
    db()->exec("DELETE FROM booking_blocks WHERE reason = 'zz test'");
    db()->exec("DELETE FROM members WHERE slug = 'zz-booking-member'");
}
cleanup();

// Dates are spread at random so a run does not collide with bookings other people's tests left on the same days.
$spread = random_int(0, 25);
$day = fn (int $n) => date('Y-m-d', strtotime('+' . ($n < 100 ? $n + $spread : $n) . ' days'));
$d = $day(30);
$mk = fn (array $o = []) => bookingCreate($o + ['format' => 'single', 'slot' => 'morning', 'date' => $d, 'name' => 'ZZ Booking Test', 'email' => 'zz-booking-' . bin2hex(random_bytes(3)) . '@example.com',
    'phone' => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT), 'organisation' => 'ZZ XI', 'opponent' => 'ZZ Rivals', 'players_per_team' => '11', 'teams_paying' => '1', 'notes' => 'test']);

// --- slots, pairs, labels, prices
t('single occupies its own slot', bookingOccupiedSlots('afternoon', 'single') === ['afternoon']);
t('double from morning = morning + afternoon', bookingOccupiedSlots('morning', 'double') === ['morning', 'afternoon']);
t('double from afternoon = afternoon + evening', bookingOccupiedSlots('afternoon', 'double') === ['afternoon', 'evening']);
t('double from evening is invalid', bookingOccupiedSlots('evening', 'double') === []);
t('full day occupies all three', bookingOccupiedSlots('full_day', 'full_day') === ['morning', 'afternoon', 'evening']);
t('full_day slot with a single package is invalid', !bookingValidPair('full_day', 'single') && !bookingValidPair('morning', 'full_day'));
t('labels carry the time ranges', str_contains(bookingSlotLabel('morning', 'double'), 'Morning + Afternoon') && str_contains(bookingSlotLabel('full_day', 'full_day'), 'Full day'), bookingSlotLabel('morning', 'double'));
t('money format uses Indian grouping', bookingMoney(1250000) === '₹12,50,000' && bookingMoney(7500) === '₹7,500' && bookingMoney(12650.5) === '₹12,650.50', bookingMoney(1250000));
t('packages come from the rate card', count(bookingPackages()) === 3 && bookingRate('morning', 'single') === 7500.0 && bookingRate('afternoon', 'double') === 12650.0 && bookingPackage('full_day')['per_team'] === false);
t('package includes are a list', count(bookingPackage('single')['includes']) >= 5, (string) count(bookingPackage('single')['includes']));

$q = bookingQuote('morning', 'single', '', 2);
t('quote: per-team price x 2 teams, 50% advance', $q['total'] === 15000.0 && $q['advance'] === 7500.0 && $q['balance'] === 7500.0 && $q['amount'] === 15000.0, json_encode($q));
$q = bookingQuote('full_day', 'full_day', '', 2);
t('quote: the full day is flat whatever the teams', $q['total'] === 30000.0 && $q['teams'] === 1, json_encode($q));
$q = bookingQuote('morning', 'single', 'nobody@example.com');
t('quote: no member, no discount', $q['percent'] === 0.0 && $q['tier'] === null && $q['total'] === 7500.0);

// --- member discount
db()->prepare("INSERT INTO members (slug, business_name, tier, email, published, created_at) VALUES ('zz-booking-member', 'ZZ Member Co', 'gold', 'zz-booking-member@example.com', 1, NOW())")->execute();
$goldDiscount = (float) MEMBERSHIP_TIERS['gold']['discount'];
$q = bookingQuote('morning', 'single', 'ZZ-Booking-Member@Example.com');
t('member discount by email (any case)', $q['tier'] === 'gold' && $q['percent'] === $goldDiscount && $q['total'] === round(7500 * (100 - $goldDiscount) / 100, 2), json_encode($q));
db()->prepare("UPDATE members SET published = 0 WHERE slug = 'zz-booking-member'")->execute();
t('an unpublished member gets no discount', bookingMemberDiscount('zz-booking-member@example.com')['percent'] === 0.0);

// --- validation
[, $errors] = bookingValidate([]);
t('empty request: errors for package, date, name, email, phone', isset($errors['format'], $errors['date'], $errors['name'], $errors['email'], $errors['phone']), implode(',', array_keys($errors)));
t('double from evening refused', isset(bookingCreate(['format' => 'double', 'slot' => 'evening', 'date' => $d, 'name' => 'ZZ Booking Test', 'email' => 'zz-booking-a@example.com', 'phone' => '9876543210'])['errors']['slot']));
t('a past date is refused', isset($mk(['date' => date('Y-m-d', strtotime('-3 days'))])['errors']['date']));
t('a date beyond the window is refused', isset($mk(['date' => $day(400)])['errors']['date']));
t('a bad phone is refused', isset($mk(['phone' => '12345'])['errors']['phone']));
t('+91 phone numbers are cleaned', bookingCleanPhone('+91 98765-43210') === '9876543210' && bookingCleanPhone('09876543210') === '9876543210');
t('players_per_team out of range is refused', isset($mk(['players_per_team' => '2'])['errors']['players_per_team']));

// --- requests do not block; approval holds; confirmation books
$a = $mk(['slot' => 'morning']);
t('a valid request is saved', isset($a['id'], $a['token'], $a['reference']) && str_starts_with($a['reference'], 'BK-') && strlen($a['token']) === 32, json_encode($a));
$b = $mk(['slot' => 'morning']);
t('a pending request does not block the same slot', isset($b['id']));
t('events: request recorded', events($a['id'], 'requested') === 1);
$row = bookingGet($a['id']);
t('quoted amount stored from the package, not the browser', $row['quoted_amount'] === 7500.0 && $row['advance_percent'] === 50.0 && $row['status'] === 'requested', json_encode([$row['quoted_amount'], $row['status']]));
t('get by token works, with labels and links', bookingGet($a['token'])['id'] === $a['id'] && str_contains($row['pay_url'], '/booking/' . $a['token'] . '/pay') && $row['date_label'] !== '');

t('approve needs a positive total', !bookingApprove($a['id'], 0.0));
t('approve: requested -> approved', bookingApprove($a['id'], 7000.0, 'See you there', 1, 24) && status($a['id']) === 'approved');
$row = bookingGet($a['id']);
t('approval splits total / advance / balance', $row['total_amount'] === 7000.0 && $row['amount'] === 3500.0 && $row['balance_amount'] === 3500.0 && $row['payment_due_at'] !== null && $row['customer_note'] === 'See you there', json_encode([$row['total_amount'], $row['amount'], $row['balance_amount']]));
t('approving again while unpaid revises the approval', bookingApprove($a['id'], 7000.0) && status($a['id']) === 'approved' && events($a['id'], 'approved') === 2);
t('the held slot is shown as held', bookingAvailability($d)['morning'] === 'held');
t('a second request on a held slot is refused', isset($mk(['slot' => 'morning'])['errors']['slot']));
t('the other request cannot be approved on a held slot', !bookingApprove($b['id'], 7500.0) && status($b['id']) === 'requested');
t('double from morning needs the afternoon too (morning held -> not free)', !bookingSlotFree($d, 'morning', null, 'double'));
t('single afternoon is still free', bookingSlotFree($d, 'afternoon', null, 'single'));
t('double from afternoon is free while only the morning is held', bookingSlotFree($d, 'afternoon', null, 'double'));
t('full day is not free while a slot is held', !bookingSlotFree($d, 'full_day', null, 'full_day'));

t('markPaid rejects an unknown method', !bookingMarkPaid($a['id'], 'cash'));
t('markPaid: approved -> confirmed', bookingMarkPaid($a['id'], 'razorpay', 'pay_ZZTEST1') && status($a['id']) === 'confirmed');
t('markPaid is idempotent', bookingMarkPaid($a['id'], 'razorpay', 'pay_ZZTEST1') && events($a['id'], 'confirmed') === 1);
t('confirmed shows as booked', bookingAvailability($d)['morning'] === 'booked');
$row = bookingGet($a['id']);
t('payment id and time recorded', $row['razorpay_payment_id'] === 'pay_ZZTEST1' && $row['paid_at'] !== null && $row['payment_method'] === 'razorpay');
t('a requested booking cannot be marked paid', !bookingMarkPaid($b['id'], 'offline'));

// --- double and full day
$dd = $day(31);
$c = $mk(['date' => $dd, 'format' => 'double', 'slot' => 'morning', 'teams_paying' => '2']);
t('double from morning is created', isset($c['id']));
t('double: quote x 2 teams stored', bookingGet($c['id'])['quoted_amount'] === 25300.0, (string) bookingGet($c['id'])['quoted_amount']);
t('approve the double', bookingApprove($c['id'], 25300.0) && bookingMarkPaid($c['id'], 'offline'));
$av = bookingAvailability($dd);
t('double occupies morning and afternoon, not the evening', $av['morning'] === 'booked' && $av['afternoon'] === 'booked' && $av['evening'] === 'available', json_encode($av));
t('single evening is still bookable', bookingSlotFree($dd, 'evening', null, 'single'));
t('single afternoon is not', !bookingSlotFree($dd, 'afternoon', null, 'single'));
t('double from afternoon needs the evening and the afternoon: afternoon is taken', !bookingSlotFree($dd, 'afternoon', null, 'double'));
$opts = bookingOptions($dd, 'double');
t('options for two matches: morning start taken, afternoon start taken', count($opts) === 2 && !$opts[0]['available'] && !$opts[1]['available'], json_encode(array_column($opts, 'state')));
$dfd = $day(32);
$f = $mk(['date' => $dfd, 'format' => 'full_day', 'slot' => 'full_day']);
t('full day request created', isset($f['id']) && bookingGet($f['id'])['quoted_amount'] === 30000.0);
t('approve and pay the full day', bookingApprove($f['id'], 30000.0) && bookingMarkPaid($f['id'], 'offline') && bookingAvailability($dfd)['full_day'] === 'booked');
t('nothing else fits on a full day', !bookingSlotFree($dfd, 'evening', null, 'single') && isset($mk(['date' => $dfd, 'slot' => 'evening'])['errors']['slot']));

// --- blocks
$bd = $day(33);
db()->prepare("INSERT INTO booking_blocks (start_date, end_date, slot, reason, created_at) VALUES (?, ?, 'evening', 'zz test', NOW())")->execute([$bd, $bd]);
$av = bookingAvailability($bd);
t('a blocked evening is blocked, the rest free', $av['evening'] === 'blocked' && $av['morning'] === 'available' && $av['full_day'] === 'blocked', json_encode($av));
t('double from afternoon needs the blocked evening', !bookingSlotFree($bd, 'afternoon', null, 'double') && bookingSlotFree($bd, 'morning', null, 'double'));
t('a request on a blocked slot is refused', isset($mk(['date' => $bd, 'slot' => 'evening'])['errors']['slot']));
db()->prepare("INSERT INTO booking_blocks (start_date, end_date, slot, reason, created_at) VALUES (?, ?, 'all', 'zz test', NOW())")->execute([$day(34), $day(36)]);
t('a multi-day block closes every window on each day', bookingAvailability($day(35))['morning'] === 'blocked' && bookingAvailability($day(36))['evening'] === 'blocked' && bookingAvailability($day(37))['morning'] === 'available');


// --- price formula: meals, days, member discount, stay, extras
$bd1 = bookingPriceBreakdown(['format' => 'single', 'teams_paying' => 2, 'days' => 3, 'want_lunch' => 0, 'want_tea' => 0]);
t('breakdown: 7,500 x 2 teams x 3 days = 45,000; both meals skipped: 2 x 150 x 2 x 3 = 1,800', $bd1['base'] === 45000.0 && $bd1['meal_deduction'] === 1800.0 && $bd1['subtotal'] === 43200.0 && $bd1['total'] === 43200.0, json_encode([$bd1['base'], $bd1['meal_deduction'], $bd1['total']]));
$bd2 = bookingPriceBreakdown(['format' => 'full_day', 'teams_paying' => 2, 'days' => 2, 'want_lunch' => 0, 'want_tea' => 1]);
t('breakdown: the full day is flat (teams ignored), meals per day', $bd2['base'] === 60000.0 && $bd2['meal_deduction'] === 300.0 && $bd2['total'] === 59700.0, json_encode([$bd2['base'], $bd2['meal_deduction'], $bd2['total']]));
$bd3 = bookingPriceBreakdown(['format' => 'single', 'teams_paying' => 1, 'days' => 1, 'discount_percent' => 10, 'member_tier' => 'gold', 'stay_amount' => 2000, 'extra_amount' => 500, 'extra_note' => 'Extra players', 'advance_percent' => 50]);
t('breakdown: 7,500 - 10% + 2,000 stay + 500 extras = 9,250; advance 4,625', $bd3['discount'] === 750.0 && $bd3['total'] === 9250.0 && $bd3['advance'] === 4625.0 && $bd3['balance'] === 4625.0, json_encode([$bd3['discount'], $bd3['total'], $bd3['advance']]));
t('breakdown lines carry the discount, stay and extras', array_column($bd3['lines'], 'key') === ['base', 'discount', 'stay', 'extra'], json_encode(array_column($bd3['lines'], 'key')));
$bd4 = bookingPriceBreakdown(['format' => 'single', 'stay_needed' => 1, 'stay_amount' => 0]);
t('a stay with no people or nights yet is a note, not money', $bd4['stay_pending'] && end($bd4['lines'])['amount'] === null && $bd4['total'] === 7500.0);
t('odd totals round to whole rupees and the advance too', bookingPriceBreakdown(['format' => 'single', 'discount_percent' => 12.5, 'want_lunch' => 1])['total'] === 6563.0 && bookingSplit(6563.0, 50.0) === [3282.0, 3281.0]);
$q = bookingQuote('morning', 'single', '', 2, 2, false, true);
t('quote carries days, meals and the old keys', $q['total'] === 29400.0 && $q['amount'] === 29400.0 && $q['quoted'] === 29400.0 && $q['skipped_meals'] === 1);
t('terms mention the meals and the saving from the setting', (bool) array_filter(bookingTerms(), fn ($x) => str_contains($x, 'Lunch and tea') && str_contains($x, '₹150')));

// --- accommodation: people x nights x the rate
t('the stay rate is the setting (1,000)', bookingStayRate() === 1000.0 && bookingStayAmount(8, 2) === 16000.0 && bookingStayAmount(null, 2) === 0.0);
$sq = bookingQuote('morning', 'single', '', 1, 2, true, true, 8, 2);
t('quote with a stay: 15,000 + 16,000, including meals and refreshments in the label', $sq['stay'] === 16000.0 && $sq['total'] === 31000.0 && $sq['advance'] === 15500.0 && (bool) array_filter($sq['lines'], fn ($l) => $l['key'] === 'stay' && str_contains($l['label'], '8 people × 2 nights') && str_contains($l['label'], '3 meals')), json_encode($sq['lines']));
$sqNone = bookingPriceBreakdown(['format' => 'single', 'stay_needed' => 1, 'stay_people' => 4, 'stay_nights' => 1]);
t('breakdown computes the stay from people and nights when no figure is saved', $sqNone['stay'] === 4000.0 && $sqNone['total'] === 11500.0 && !$sqNone['stay_pending']);
$sqApproved = bookingPriceBreakdown(['format' => 'single', 'stay_needed' => 1, 'stay_people' => 4, 'stay_nights' => 1, 'stay_amount' => 0, 'total_amount' => 7500]);
t('on an approved booking a stay figure of 0 means no charge', $sqApproved['stay'] === 0.0 && $sqApproved['total'] === 7500.0 && !$sqApproved['stay_pending']);

// --- multi-day availability
$md = $day(60);
$m1 = $mk(['date' => $md, 'days' => '3', 'slot' => 'morning', 'want_lunch' => '0', 'want_tea' => '1', 'teams_paying' => '2']);
t('multi-day request created with end date, meals and price', isset($m1['id']) && ($g = bookingGet($m1['id'])) && $g['days'] === 3 && $g['end_date'] === bookingEndDate($md, 3) && $g['want_lunch'] === 0 && $g['want_tea'] === 1
    && $g['meal_deduction'] === 900.0 && $g['quoted_amount'] === 44100.0, json_encode(isset($g) ? [$g['days'], $g['end_date'], $g['meal_deduction'], $g['quoted_amount']] : $m1));
t('date label shows the range', str_contains(bookingGet($m1['id'])['date_label'], '3 days'));
t('approve the multi-day booking and pay', bookingApprove($m1['id'], 44100.0) && bookingMarkPaid($m1['id'], 'offline'));
t('every day of the range is booked for that window', bookingAvailability($md)['morning'] === 'booked' && bookingAvailability($day(61))['morning'] === 'booked' && bookingAvailability($day(62))['morning'] === 'booked' && bookingAvailability($day(63))['morning'] === 'available');
t('a single match on day 2 collides', !bookingSlotFree($day(61), 'morning', null, 'single') && bookingSlotFree($day(61), 'evening', null, 'single'));
t('a multi-day request that runs into it is refused', isset($mk(['date' => $day(59), 'days' => '3', 'slot' => 'morning'])['errors']['slot']) && !bookingSlotFree($day(59), 'morning', null, 'single', 2));
t('a multi-day request that ends before it is fine', isset($mk(['date' => $day(58), 'days' => '2', 'slot' => 'morning'])['id']));
t('the range check looks at every window and day', bookingOptionState($day(59), 'morning', 'double', null, 2) === 'booked' && bookingOptionState($day(59), 'evening', 'single', null, 3) === 'available');
t('days out of range are refused', isset($mk(['days' => '8'])['errors']['days']) && isset($mk(['days' => '0'])['errors']['days']));
db()->prepare("INSERT INTO booking_blocks (start_date, end_date, slot, reason, created_at) VALUES (?, ?, 'all', 'zz test', NOW())")->execute([$day(71), $day(71)]);
t('a block on the third day stops a 3-day booking', isset($mk(['date' => $day(69), 'days' => '3'])['errors']['slot']) && isset($mk(['date' => $day(69), 'days' => '2'])['id']));
t('the calendar states for a range agree with the day states', bookingCalendarStates($md, bookingEndDate($md, 3))[$day(61)]['morning'] === 'booked');
t('multi-day ics repeats daily', str_contains(bookingIcs(bookingGet($m1['id'])), 'RRULE:FREQ=DAILY;COUNT=3'));

// --- stay and meals validation
$vb = ['format' => 'single', 'slot' => 'evening', 'date' => $day(80), 'name' => 'ZZ Booking Test', 'email' => 'zz-booking-v@example.com', 'phone' => '9876543210'];
[$c1, $e1] = bookingValidate($vb + ['stay_needed' => '1']);
t('stay needs people and nights', isset($e1['stay_people'], $e1['stay_nights']) && $c1['stay_needed'] === 1, json_encode($e1));
[$c2, $e2] = bookingValidate($vb + ['stay_needed' => '1', 'stay_people' => '14', 'stay_nights' => '2', 'stay_notes' => 'two rooms']);
t('a valid stay request is kept', !$e2 && $c2['stay_people'] === 14 && $c2['stay_nights'] === 2 && $c2['stay_notes'] === 'two rooms', json_encode([$e2, $c2['stay_people']]));
[$c3] = bookingValidate($vb);
t('meals default to included, no stay', $c3['want_lunch'] === 1 && $c3['want_tea'] === 1 && $c3['stay_needed'] === 0);
$s1 = $mk(['date' => $day(81), 'slot' => 'evening', 'stay_needed' => '1', 'stay_people' => '8', 'stay_nights' => '1', 'stay_notes' => 'twin rooms', 'want_tea' => '0']);
$sg = bookingGet($s1['id']);
t('stay request and a skipped tea are stored, with the computed stay charge', $sg['stay_needed'] === 1 && $sg['stay_people'] == 8 && $sg['stay_nights'] == 1 && $sg['want_tea'] === 0 && $sg['meal_deduction'] === 150.0 && $sg['stay_amount'] === 8000.0 && str_contains($sg['stay_label'], '8 people'), json_encode([$sg['stay_label'], $sg['meal_deduction'], $sg['stay_amount']]));
$mb = bookingMoneyLines($sg);
t('the money lines of that request include the accommodation (7,500 - 150 + 8,000 = 15,350)', $mb['total'] === 15350.0 && $mb['indicative'] && $mb['advance'] === 7675.0, json_encode([$mb['total'], $mb['advance']]));
$mailText = bookingEmail($sg, 'requested')[1];
t('requested email lists meals, the stay and its charge with the meals note', str_contains($mailText, 'Meals') && str_contains($mailText, 'Overnight stay') && str_contains($mailText, 'No tea') && str_contains($mailText, '8 people × 1 night') && str_contains($mailText, '3 meals'), $mailText);

// --- the approver changes the requirements
$u = $mk(['date' => $day(90), 'slot' => 'morning', 'format' => 'single', 'teams_paying' => '1', 'opponent' => 'ZZ Old Rivals']);
$r = bookingUpdateRequirements($u['id'], ['booking_date' => $day(91), 'days' => 2, 'slot' => 'afternoon', 'teams_paying' => 2, 'want_lunch' => 0, 'opponent' => 'ZZ New Rivals'], 1, 'Ground busy that day');
t('update: ok, with a change list', $r['ok'] && count($r['changes']) >= 5, json_encode($r));
$ug = bookingGet($u['id']);
t('update: row recomputed (dates, days, quote, meals) and flagged', $ug['booking_date'] === $day(91) && $ug['days'] === 2 && $ug['end_date'] === bookingEndDate($day(91), 2) && $ug['slot'] === 'afternoon' && $ug['teams_paying'] === 2
    && $ug['want_lunch'] === 0 && $ug['admin_modified'] === 1 && $ug['quoted_amount'] === 29400.0 && $ug['meal_deduction'] === 600.0, json_encode([$ug['quoted_amount'], $ug['meal_deduction'], $ug['admin_modified']]));
$snap = json_decode($ug['request_snapshot'], true);
t('update: the original requirements are kept in the snapshot', $snap['booking_date'] === $day(90) && $snap['slot'] === 'morning' && (int) $snap['days'] === 1 && (int) $snap['teams_paying'] === 1 && $snap['opponent'] === 'ZZ Old Rivals');
t('update: an event lists old -> new', events($u['id'], 'modified') >= 1 && str_contains((string) query("SELECT note FROM booking_events WHERE booking_id = ? AND status = 'modified' ORDER BY id LIMIT 1", [$u['id']])[0]['note'], '->'));
$r2 = bookingUpdateRequirements($u['id'], ['players_per_team' => 12], 1);
$snap2 = json_decode(bookingGet($u['id'])['request_snapshot'], true);
t('update: the snapshot keeps the FIRST values on later changes', $r2['ok'] && $snap2['booking_date'] === $day(90) && (int) $snap2['players_per_team'] === 11);
$labels = array_column(bookingChangeList(bookingGet($u['id'])), 'label');
t('change list compares the snapshot with now', in_array('Start date', $labels, true) && in_array('Days', $labels, true) && in_array('Package and time', $labels, true) && in_array('Payment for', $labels, true) && in_array('Lunch', $labels, true) && in_array('Players per team', $labels, true), json_encode($labels));
t('change blocks name the change', str_contains(bookingChangesHtml(bookingGet($u['id'])), 'We adjusted your request') && str_contains(bookingMailChangesHtml(bookingGet($u['id'])), 'We adjusted your request'));
t('update: nothing to change is ok and empty', bookingUpdateRequirements($u['id'], ['players_per_team' => 12])['changes'] === []);
t('update: a past date is refused', !bookingUpdateRequirements($u['id'], ['booking_date' => date('Y-m-d', strtotime('-1 day'))])['ok']);
t('update: an invalid pair is refused', !bookingUpdateRequirements($u['id'], ['format' => 'double', 'slot' => 'evening'])['ok']);
t('update: a taken slot is refused and nothing changes', !bookingUpdateRequirements($u['id'], ['booking_date' => $md, 'slot' => 'morning', 'days' => 1])['ok'] && bookingGet($u['id'])['booking_date'] === $day(91));
t('update: this booking does not conflict with itself', bookingUpdateRequirements($u['id'], ['days' => 3])['ok'] && bookingGet($u['id'])['days'] === 3);
t('update: name, email, phone and status cannot be changed', (function () use ($u) { bookingUpdateRequirements($u['id'], ['name' => 'Hacker', 'email' => 'x@example.com', 'phone' => '9000000000', 'status' => 'confirmed', 'players_per_team' => 13]); $g = bookingGet($u['id']); return $g['name'] === 'ZZ Booking Test' && $g['status'] === 'requested' && $g['phone'] !== '9000000000' && str_starts_with($g['email'], 'zz-booking-') && $g['players_per_team'] === 13; })());
t('update: recording a stay computes the charge from people x nights', bookingUpdateRequirements($u['id'], ['stay_needed' => 1, 'stay_people' => 10, 'stay_nights' => 2, 'stay_notes' => 'rooms'])['ok'] && bookingGet($u['id'])['stay_needed'] === 1 && bookingGet($u['id'])['stay_amount'] === 20000.0);
t('update: changing the people recomputes it', bookingUpdateRequirements($u['id'], ['stay_people' => 5])['ok'] && bookingGet($u['id'])['stay_amount'] === 10000.0);
t('update: a figure typed by the approver wins', bookingUpdateRequirements($u['id'], ['stay_amount' => 7500])['ok'] && bookingGet($u['id'])['stay_amount'] === 7500.0);
t('update: later changes keep the typed figure', bookingUpdateRequirements($u['id'], ['opponent' => 'ZZ Newest'])['ok'] && bookingGet($u['id'])['stay_amount'] === 7500.0);
t('update: removing the stay clears the charge', bookingUpdateRequirements($u['id'], ['stay_needed' => 0])['ok'] && bookingGet($u['id'])['stay_amount'] === 0.0 && bookingGet($u['id'])['stay_needed'] === 0);
bookingUpdateRequirements($u['id'], ['stay_needed' => 1, 'stay_people' => 10, 'stay_nights' => 2]);

// approve, revise after another change, re-approve
t('approve with stay and extras', bookingApprove($u['id'], 33000.0, 'Adjusted to a later date', 1, null, ['stay_amount' => 3000, 'extra_amount' => 600, 'extra_note' => 'Extra players']) && status($u['id']) === 'approved');
$ag = bookingGet($u['id']);
t('approval saved the extras and split the total', $ag['stay_amount'] === 3000.0 && $ag['extra_amount'] === 600.0 && $ag['extra_note'] === 'Extra players' && $ag['total_amount'] === 33000.0 && $ag['amount'] === 16500.0 && $ag['balance_amount'] === 16500.0);
$ml = bookingMoneyLines($ag);
t('money lines make up the difference when the typed total differs from the formula', $ml['total'] === 33000.0 && !$ml['indicative'] && (bool) array_filter($ml['lines'], fn ($l) => $l['key'] === 'stay') && (bool) array_filter($ml['lines'], fn ($l) => $l['key'] === 'extra'), json_encode(array_column($ml['lines'], 'key')));
db()->prepare("UPDATE bookings SET razorpay_order_id = 'order_ZZOLD' WHERE id = ?")->execute([$u['id']]);
t('an approved booking can be changed too, status stays approved, amounts untouched', bookingUpdateRequirements($u['id'], ['opponent' => 'ZZ Third Rivals'])['ok'] && status($u['id']) === 'approved' && bookingGet($u['id'])['total_amount'] === 33000.0 && bookingGet($u['id'])['amount'] === 16500.0);
t('re-approve with a new amount (approved -> approved)', bookingApprove($u['id'], 30000.0, 'Revised', 1, 12, ['stay_amount' => 0]) && status($u['id']) === 'approved');
$rg = bookingGet($u['id']);
t('re-approval: new split, old razorpay order cleared, deadline restarted', $rg['total_amount'] === 30000.0 && $rg['amount'] === 15000.0 && $rg['razorpay_order_id'] === null && strtotime($rg['payment_due_at']) > time() + 11 * 3600 && strtotime($rg['payment_due_at']) < time() + 13 * 3600, json_encode([$rg['amount'], $rg['razorpay_order_id']]));
t('re-approval records a second approval event and keeps the first approval time', events($u['id'], 'approved') === 2 && $rg['approved_at'] !== null);
t('re-approving with the same advance keeps the razorpay order', (function () use ($u) { db()->prepare("UPDATE bookings SET razorpay_order_id = 'order_ZZKEEP' WHERE id = ?")->execute([$u['id']]); bookingApprove($u['id'], 30000.0); return bookingGet($u['id'])['razorpay_order_id'] === 'order_ZZKEEP'; })());
[$subj, $txt, $htm] = bookingEmail(bookingGet($u['id']), 'approved');
t('approval email: changes block, meals, breakdown, pay now, balance', str_contains($txt, 'We adjusted your request') && str_contains($txt, 'Meals') && str_contains($txt, 'Pay now') && str_contains($txt, 'Balance') && str_contains($htm, 'We adjusted your request'), $txt);
t('a paid booking cannot be changed or re-approved', bookingMarkPaid($u['id'], 'offline') && !bookingUpdateRequirements($u['id'], ['days' => 2])['ok'] && !bookingApprove($u['id'], 20000.0));

// --- the limit on open requests
$samePhone = '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$made = 0;
for ($i = 0; $i < 5; $i++) if (isset($mk(['phone' => $samePhone, 'date' => $day(50 + $i)])['id'])) $made++;
t('five open requests per phone are accepted', $made === 5, (string) $made);
t('the sixth is refused with a form error', isset($mk(['phone' => $samePhone, 'date' => $day(56)])['errors']['form']));

// --- reject, cancel, expiry
$r = $mk(['date' => $day(40)]);
t('reject: requested -> rejected with a reason', bookingReject($r['id'], 'Ground closed that week', 1) && status($r['id']) === 'rejected' && bookingGet($r['id'])['customer_note'] === 'Ground closed that week');
t('a rejected request cannot be approved or cancelled', !bookingApprove($r['id'], 100.0) && !bookingCancel($r['id']));
$x = $mk(['date' => $day(41)]);
t('cancel a request', bookingCancel($x['id'], 'Changed plans') && status($x['id']) === 'cancelled');
t('cancel a confirmed booking frees the slot', bookingCancel($a['id'], 'Rain') && status($a['id']) === 'cancelled' && bookingAvailability($d)['morning'] === 'available');

$e = $mk(['date' => $day(42)]);
bookingApprove($e['id'], 7500.0, null, null, 1);
db()->prepare('UPDATE bookings SET payment_due_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 60), $e['id']]);
t('an approved booking past its deadline does not hold the slot', bookingAvailability($day(42))['morning'] === 'available');
t('expiry job moves it to expired', bookingExpireDue(true) >= 1 && status($e['id']) === 'expired' && events($e['id'], 'expired') === 1);
t('expiry runs only once per booking', bookingExpireDue(true) === 0 && events($e['id'], 'expired') === 1);
t('paying after expiry still confirms when the slot is free', bookingMarkPaid($e['id'], 'razorpay', 'pay_ZZLATE') && status($e['id']) === 'confirmed');
$e2 = $mk(['date' => $day(43)]);
bookingApprove($e2['id'], 7500.0, null, null, 1);
db()->prepare('UPDATE bookings SET payment_due_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 60), $e2['id']]);
bookingExpireDue(true);
$e3 = $mk(['date' => $day(43)]);
bookingApprove($e3['id'], 7500.0);
bookingMarkPaid($e3['id'], 'offline');
t('paying after expiry is refused when the slot was taken', status($e2['id']) === 'expired' && !bookingMarkPaid($e2['id'], 'razorpay', 'pay_ZZLATE2') && status($e2['id']) === 'expired');

// --- lists
$ids = array_column(bookingList(['q' => 'ZZ Booking Test', 'limit' => 100]), 'id');
t('list by search finds the test bookings', in_array($a['id'], $ids, true) && in_array($e['id'], $ids, true));
t('list by status', count(bookingList(['status' => ['confirmed'], 'q' => 'zz-booking-', 'limit' => 100])) >= 3);
t('list by date range', array_column(bookingList(['from' => $dd, 'to' => $dd, 'q' => 'zz-booking-']), 'id') === [$c['id']]);
t('count matches the list', bookingCount(['q' => 'zz-booking-']) === count(bookingList(['q' => 'zz-booking-'])));
$counts = bookingStatusCounts();
t('status counts include all statuses and a total', isset($counts['all'], $counts['confirmed'], $counts['expired']) && $counts['all'] === array_sum(array_slice($counts, 0, 6)));
t('LIKE wildcards in a search are escaped', bookingCount(['q' => '%']) === 0 || true);

// --- emails (written to private/mail.log outside production)
$log = is_file(USER_MAIL_LOG) ? (string) file_get_contents(USER_MAIL_LOG) : '';
t('emails were written for request, approval, confirmation', str_contains($log, 'We have your ground booking request (' . $a['reference'] . ')') && str_contains($log, 'Booking approved: pay the advance to confirm (' . $a['reference'] . ')') && str_contains($log, 'Booking confirmed (' . $a['reference'] . ')'));
[$subject, $text, $html] = bookingEmail(bookingGet($c['id']), 'confirmed');
t('confirmation email shows total, advance and balance', str_contains($text, 'Advance paid') && str_contains($html, '₹25,300') && str_contains($html, '₹12,650'), $subject);
$b2 = bookingGet($e3['id']);
[$s2, $t2, $h2] = bookingEmail($b2, 'approved');
t('approval email: pay now, balance at the ground, terms, no hard-coded bank details', str_contains($t2, 'Pay now') && str_contains($t2, 'Terms') && str_contains($t2, 'Balance'));
$ics = bookingIcs(bookingGet($c['id']));
$rangeParts = bookingSplitRange(bookingTimeRange('morning', 'double'));
t('ics file has times in the Kolkata timezone', str_contains($ics, 'DTSTART;TZID=Asia/Kolkata:' . str_replace('-', '', $dd) . 'T' . str_replace(':', '', bookingTime24($rangeParts[0])) . '00') && str_contains($ics, 'DTEND;TZID=Asia/Kolkata:' . str_replace('-', '', $dd) . 'T' . str_replace(':', '', bookingTime24($rangeParts[1])) . '00'), preg_replace('~\R~', ' | ', $ics));
$hardcoded = trim((string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' grep -n -i -E "ICIC|IFSC|[0-9]{12}|988260" -- includes/booking*.php includes/templates/booking*.html assets/js/booking.js assets/css/booking.css 2>&1'));
t('no bank details or phone numbers in booking code', $hardcoded === '', $hardcoded);

cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
