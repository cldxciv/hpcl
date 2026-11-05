<?php
declare(strict_types=1);

// Ground booking payment and the customer's bookings:
//   GET  /booking/<token>/pay            the approved booking and its payment (renderBookingPay)
//   POST /booking/<token>/pay/create     starts a Razorpay payment for the advance (JSON)
//   POST /booking/<token>/pay/verify     confirms Razorpay Checkout's answer with its signature (JSON)
//   GET  /account/bookings[?booking=BK-10001]   a signed-in customer's bookings (handleAccountBookings)
// Razorpay's webhook also lands here through bookingPaymentReceived(), for payers who close the page
// before the confirmation. The request form, the status page, prices, statuses and the emails are
// booking.php / booking-mail.php; the payment only ever moves an APPROVED booking to confirmed, through
// bookingMarkPaid(). The amount to pay is bookings.amount (the advance), read from the database.
//
// LOCAL TEST SEAM: with APP_ENV=local and site_settings.shop_fake_razorpay = '1' (the same switch the shop
// tests use), the Razorpay order is not created over the network (a fake order id is used). Signatures are
// still checked with the saved key secret, so a test can compute a valid one (bin/test-booking-pay.php).
// bookingFakeRazorpay() is false whenever appEnv() is not exactly 'local', so it cannot act on the live site.
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/razorpay.php';
require_once __DIR__ . '/booking.php';

const BOOKING_PAY_MAX_PER_HOUR = 12;

/** True only on a local copy with the test setting on: Razorpay is not called and a fake order id is used. */
function bookingFakeRazorpay(): bool {
    return appEnv() === 'local' && setting('shop_fake_razorpay') === '1';
}
/** Whether online payment can be taken now: Razorpay is set up (or the local test seam is on). */
function bookingPaymentReady(): bool {
    return razorpayReady() || bookingFakeRazorpay();
}

function bookingPayHeaders(): void {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');
}
function bookingPayJson(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    bookingPayHeaders();
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function bookingPayMoney(float|string|null $amount): string {
    $amount = (float) $amount;
    return '&#8377;' . number_format($amount, floor($amount) === $amount ? 0 : 2);
}
function bookingPayDate(?string $value, bool $time = false): string {
    $stamp = $value ? strtotime($value) : false;
    return $stamp ? date($time ? 'D, j M Y, g:i a' : 'D, j M Y', $stamp) : '';
}
/** "Morning (8:00 AM - 11:00 AM)", "Morning + Afternoon (8:00 AM - 2:30 PM)" or "Full day (...)": the booking's own windows. */
function bookingPaySlot(array $booking): string {
    return (string) ($booking['slot_label'] ?? bookingSlotLabel((string) $booking['slot'], (string) $booking['format']));
}
function bookingPayFormat(array $booking): string {
    return (string) ($booking['format_label'] ?? bookingFormatLabel((string) $booking['format']));
}
function bookingPayStatus(array $booking): string {
    return (string) ($booking['status_label'] ?? bookingStatusLabel((string) $booking['status']));
}
function bookingPayBadge(string $status, string $label): string {
    $kind = in_array($status, ['rejected', 'cancelled', 'expired'], true) ? 'bad' : (in_array($status, ['requested', 'approved'], true) ? 'wait' : 'ok');
    return '<span class="sco-badge sco-badge--' . $kind . '">' . escape($label) . '</span>';
}
function bookingPaySupportLine(): string {
    $phone = trim(bookingSetting('booking_support_phone', ''));
    return $phone !== '' ? '<p class="sco-small sco-muted">Questions? Call or WhatsApp ' . phoneLink($phone) . '.</p>' : '';
}
/** Where the team hears about trouble with a payment: booking_notify_email, else the contact email, else MAIL_FROM_ADDRESS. */
function bookingPayTeamEmail(): string {
    foreach ([bookingSetting('booking_notify_email', ''), setting('contact_email'), (string) getenv('MAIL_FROM_ADDRESS')] as $address) {
        $address = trim((string) $address);
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) return $address;
    }
    return '';
}
function bookingPaySession(): void {
    accountStartSession();
}

/** The booking row for a Razorpay order id: the latest order saved on the booking, or an earlier one recorded in its history. */
function bookingByRazorpayOrder(string $orderId): ?array {
    $row = query('SELECT id FROM bookings WHERE razorpay_order_id = ?', [$orderId])[0] ?? null;
    $row ??= query("SELECT booking_id AS id FROM booking_events WHERE status = 'payment_started' AND (note = ? OR note LIKE ?) LIMIT 1", [$orderId, $orderId . '|%'])[0] ?? null;
    return $row ? bookingGet((int) $row['id']) : null;
}
/** The amount (paise) a Razorpay order of this booking was created for, from the history; null for an order the history doesn't know. */
function bookingOrderPaise(int $bookingId, string $orderId): ?int {
    $note = query("SELECT note FROM booking_events WHERE booking_id = ? AND status = 'payment_started' AND note LIKE ? ORDER BY id DESC LIMIT 1", [$bookingId, $orderId . '|%'])[0]['note'] ?? null;
    return is_string($note) && preg_match('~\|(\d+)$~', $note, $m) ? (int) $m[1] : null;
}
function bookingPayEvent(int $bookingId, string $status, string $note): void {
    db()->prepare('INSERT INTO booking_events (booking_id, status, note, created_at) VALUES (?, ?, ?, NOW())')->execute([$bookingId, $status, mb_substr($note, 0, 500)]);
}

/**
 * Puts a verified payment on its booking: an approved booking becomes confirmed (bookingMarkPaid), and so does an expired one
 * whose slot is still free. A payment that can't be applied is written to the booking's history and the team is emailed, so
 * the money is never lost silently. Returns 'confirmed', 'amount' (what was paid is not the advance now due, e.g. the price
 * was revised after the payment was started), 'slot' (late payment and the slot has been given away) or 'state' (the
 * booking is cancelled, rejected or not approved).
 */
function bookingPayConfirm(array $booking, string $paymentId, ?int $paise): string {
    if ($booking['status'] === 'confirmed') return 'confirmed';
    $id = (int) $booking['id'];
    if ($paise !== null && $paise !== (int) round((float) $booking['amount'] * 100)) {
        bookingPayEvent($id, 'payment_issue', 'NEEDS ATTENTION: payment ' . $paymentId . ' was for Rs ' . number_format($paise / 100, 2) . ' but ' . number_format((float) $booking['amount'], 2) . ' is due now. Refund it or confirm the booking by hand.');
        bookingPayTellTeam($booking, $paymentId, 'The amount paid (Rs ' . number_format($paise / 100, 2) . ') does not match the advance now due (Rs ' . number_format((float) $booking['amount'], 2) . ').');
        return 'amount';
    }
    // Approved: the normal case. Expired: the advance arrived after the hold ran out; bookingMarkPaid confirms it when the slot is still free.
    if (in_array($booking['status'], ['approved', 'expired'], true) && bookingMarkPaid($id, 'razorpay', $paymentId)) return 'confirmed';
    if ($booking['status'] === 'expired') {
        bookingPayEvent($id, 'payment_issue', 'NEEDS ATTENTION: payment received but the slot is no longer available — refund required. Payment ' . $paymentId . '.');
        bookingPayTellTeam($booking, $paymentId, 'Payment received but the slot is no longer available — refund required.');
        return 'slot';
    }
    bookingPayEvent($id, 'payment_issue', 'NEEDS ATTENTION: payment ' . $paymentId . ' arrived while the booking is ' . $booking['status'] . '. Confirm it by hand or refund it.');
    bookingPayTellTeam($booking, $paymentId, 'The payment arrived while the booking is ' . $booking['status'] . '.');
    return 'state';
}
function bookingPayTellTeam(array $booking, string $paymentId, string $why): void {
    error_log('Booking payment needs attention: ' . $booking['reference'] . ' ' . $paymentId . ' - ' . $why);
    $to = bookingPayTeamEmail();
    if ($to === '') return;
    accountSendMail($to, 'Booking payment needs attention: ' . $booking['reference'],
        "A payment came in for ground booking {$booking['reference']} that could not be applied automatically.\n\n$why\nRazorpay payment ID: $paymentId\nBooking: " . absoluteUrl('{{BASE}}/booking/' . $booking['token']) . "\n\nPlease check the booking in the admin and either confirm it or refund the payment in Razorpay.\n");
}

/** Called by the Razorpay webhook (razorpay.php) for every captured payment that is not a committee, membership or shop one. True when it was a booking payment. */
function bookingPaymentReceived(string $rzpOrderId, string $paymentId, ?int $paise = null): bool {
    try {
        $booking = bookingByRazorpayOrder($rzpOrderId);
    } catch (Throwable) {
        return false; // the booking tables don't exist (yet): not ours
    }
    if (!$booking) return false;
    bookingPayConfirm($booking, $paymentId, $paise);
    return true;
}

// ---------------------------------------------------------------------------------------------------------
// The payment page
// ---------------------------------------------------------------------------------------------------------

/** The booking in full as HTML: reference, status, what and when, the changes the team made, and the price breakdown. Shared by the pay page and the account. */
function bookingPayDetailHtml(array $booking): string {
    $status = (string) $booking['status'];
    $html = '<header class="sco-order__head"><div><p class="sco-eyebrow">Ground booking</p><h1 class="sco-order__number">' . escape((string) $booking['reference']) . '</h1>'
        . '<p class="sco-muted">Requested ' . escape(bookingPayDate($booking['created_at'] ?? null, true)) . '</p></div>' . bookingPayBadge($status, bookingPayStatus($booking)) . '</header>';
    if (in_array($status, ['requested', 'approved', 'confirmed'], true)) $html .= bookingChangesHtml($booking);
    $html .= '<section aria-labelledby="bkp-what"><h2 id="bkp-what">Your booking</h2><dl class="bkp-facts">';
    foreach (bkStatusFacts($booking) as [$label, $value]) {
        if ($label === 'Reference') continue;
        $html .= '<dt>' . escape((string) $label) . '</dt><dd>' . escape((string) $value) . '</dd>';
    }
    $html .= '</dl></section>';

    $money = bookingMoneyLines($booking);
    if ($money['total'] !== null) {
        $percent = bookingPercentText((float) $money['advance_percent']);
        $html .= '<section aria-labelledby="bkp-amounts"><h2 id="bkp-amounts">Price</h2><dl class="sco-totals bkp-lines">';
        foreach ($money['lines'] as $line) $html .= '<dt>' . escape((string) $line['label']) . '</dt><dd>' . escape(bookingMailSigned($line['amount'])) . '</dd>';
        if ($money['indicative']) {
            $html .= '<dt class="bkp-total">Indicative total</dt><dd class="bkp-total">' . escape(bookingMoney($money['total'])) . '</dd>'
                . '<dt>Advance to confirm (' . escape($percent) . '%)</dt><dd>' . escape(bookingMoney($money['advance'])) . '</dd>'
                . '<dt>Balance, payable at the ground</dt><dd>' . escape(bookingMoney($money['balance'])) . '</dd></dl>'
                . '<p class="sco-small sco-muted">The final amount is confirmed when your request is approved.' . ($money['stay_pending'] ? ' Overnight accommodation is quoted then.' : '') . '</p>';
        } else {
            $paid = $status === 'confirmed';
            $html .= '<dt class="bkp-total">Total price</dt><dd class="bkp-total">' . escape(bookingMoney($money['total'])) . '</dd>'
                . '<dt class="sco-totals__grand">' . ($paid ? 'Advance paid (' . escape($percent) . '%)' : 'Advance to pay now (' . escape($percent) . '%)') . '</dt><dd class="sco-totals__grand">' . escape(bookingMoney($money['advance'])) . '</dd>'
                . '<dt>Balance, payable at the ground</dt><dd>' . escape(bookingMoney($money['balance'])) . '</dd></dl>';
        }
        $html .= '</section>';
    }
    if (trim((string) ($booking['customer_note'] ?? '')) !== '' && $status !== 'requested') {
        $html .= '<p class="sco-note sco-note--wait" role="note"><strong>Message from the team:</strong> ' . nl2br(escape((string) $booking['customer_note'])) . '</p>';
    }
    return $html;
}

/** The booking terms (bookingTerms() in booking.php: the standard ones plus any extra lines from the settings) shown under the booking. */
function bookingPayTermsHtml(): string {
    $terms = bookingTerms();
    if (!$terms) return '';
    return '<section class="bkp-terms" aria-labelledby="bkp-terms"><h2 id="bkp-terms">Terms</h2><ul>' . implode('', array_map(fn ($term) => '<li>' . escape((string) $term) . '</li>', $terms)) . '</ul></section>';
}

/** The part of the page that depends on the state: how to pay, or what happens next. */
function bookingPayStateHtml(array $booking, string $csrf): string {
    $status = (string) $booking['status'];
    $token = (string) $booking['token'];
    $ready = bookingPaymentReady();
    $new = '<p class="sco-actions sco-noprint"><a class="sco-btn" href="{{BASE}}/book">Make a new request</a></p>';
    // Money that arrived for a booking it could not be applied to: say so plainly, whatever the booking's state.
    $issue = '';
    foreach ($booking['events'] ?? [] as $event) {
        if ($event['status'] === 'payment_issue') {
            $issue = '<p class="sco-note sco-note--bad" role="status"><strong>We received a payment for this booking that we could not apply.</strong> Our team has been told and will refund it or contact you. Please don’t pay again; if you have questions, quote the reference <strong>' . escape((string) $booking['reference']) . '</strong>.</p>';
            break;
        }
    }
    if ($status === 'requested') {
        return $issue . '<p class="sco-note sco-note--wait" role="status"><strong>Waiting for approval.</strong> The team is checking the ground for ' . escape(bookingPayDate($booking['booking_date'])) . '. When your request is approved you will get an email with the payment details and this page will show the Pay button. You don’t need to do anything else.</p>';
    }
    if ($status === 'confirmed') {
        $when = $booking['paid_at'] ? ' on ' . escape(bookingPayDate($booking['paid_at'], true)) : '';
        return '<p class="sco-note sco-note--ok" role="status"><strong>Your booking is confirmed.</strong> We received your advance' . $when . '. Please bring the balance to the ground'
            . ($booking['payment_method'] === 'offline' ? ' (your advance was recorded by the team)' : '') . '. Keep this page: it is your receipt.'
            . ($booking['razorpay_payment_id'] ? '<br><span class="sco-small">Payment ID: ' . escape((string) $booking['razorpay_payment_id']) . '</span>' : '') . '</p>'
            . '<p class="sco-actions sco-noprint"><button type="button" class="sco-btn sco-btn--ghost" data-print>Print receipt</button></p>';
    }
    if ($status === 'rejected') {
        return $issue . '<p class="sco-note sco-note--bad" role="status"><strong>This request could not be approved.</strong> Nothing was charged. You are welcome to try another date or slot.</p>' . $new;
    }
    if ($status === 'cancelled') {
        return $issue . '<p class="sco-note sco-note--bad" role="status"><strong>This booking was cancelled.</strong> If you had paid, the team will arrange the refund. You can make a new request any time.</p>' . $new;
    }
    if ($status === 'expired') {
        return $issue . '<p class="sco-note sco-note--bad" role="status"><strong>The time to pay has passed,</strong> so the slot was released' . ($issue !== '' ? '.' : ' and nothing was charged.') . ' If it is still free you can request it again.</p>' . $new;
    }
    // approved: pay now
    $due = $booking['payment_due_at'] ? strtotime((string) $booking['payment_due_at']) : false;
    $html = '<p class="sco-note sco-note--wait" role="status"><strong>Approved. Pay the advance to confirm your slot.</strong> The slot is held for you until you pay'
        . ($due ? ' or until <strong>' . escape(date('D, j M Y, g:i a', $due)) . '</strong>' : '') . '.'
        . ($due ? ' <span class="bkp-countdown" data-due="' . $due . '" data-now="' . time() . '" aria-live="off"></span>' : '') . '</p>';
    if ($ready) {
        $html .= '<form id="bkp-form" class="sco-noprint" method="post" action="{{BASE}}/booking/' . escape($token) . '/pay/create" data-verify="{{BASE}}/booking/' . escape($token) . '/pay/verify" novalidate>'
            . '<input type="hidden" name="csrf_token" value="' . escape($csrf) . '">'
            . '<div id="bkp-alert" class="sco-alert" role="alert" tabindex="-1" hidden></div>'
            . '<button type="submit" id="bkp-pay" class="sco-btn sco-btn--block">Pay ' . bookingPayMoney($booking['amount']) . ' securely</button>'
            . '<p class="sco-small sco-muted sco-secure">Secure payment by Razorpay: UPI, cards and net banking.</p></form>';
    } else {
        $note = trim(bookingSetting('booking_payment_note', ''));
        $html .= '<section class="sco-noprint" aria-labelledby="bkp-offline"><h2 id="bkp-offline">How to pay</h2>'
            . '<p class="sco-note sco-note--wait" role="status">Online payment is being set up, so there is no Pay button just yet. Please pay the advance of ' . bookingPayMoney($booking['amount']) . ' as described below and quote <strong>' . escape((string) $booking['reference']) . '</strong>. The team confirms your booking as soon as the payment is received.</p>'
            . ($note !== '' ? '<p class="bkp-instructions">' . nl2br(escape($note)) . '</p>' : '<p class="sco-muted">The team will send you the payment details.</p>') . '</section>';
    }
    return $html;
}

/** GET /booking/<token>/pay: null (404) when there is no such booking. */
function renderBookingPay(string $token): ?string {
    bookingPaySession();
    bookingPayHeaders();
    bookingExpireDue();
    $booking = bookingGet($token);
    if (!$booking) return null;
    return view('templates.booking-pay', [
        'reference' => escape((string) $booking['reference']),
        'detail' => bookingPayDetailHtml($booking),
        'state' => bookingPayStateHtml($booking, accountCsrfToken()),
        'support' => bookingPaySupportLine(),
        'terms' => bookingPayTermsHtml(),
        'razorpay_scripts' => $booking['status'] === 'approved' && bookingPaymentReady() ? '<script src="https://checkout.razorpay.com/v1/checkout.js" defer></script>' : '',
        'js_version' => '20261105',
    ]);
}

/** POST /booking/<token>/pay/create|verify (JSON). */
function handleBookingPayStep(string $token, string $step): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') errorPage(405);
    bookingPaySession();
    if (!accountCsrfValid()) bookingPayJson(['ok' => false, 'message' => USER_FORM_EXPIRED], 403);
    bookingExpireDue();
    $booking = bookingGet($token);
    if (!$booking) bookingPayJson(['ok' => false, 'message' => 'We couldn’t find this booking.'], 404);
    $step === 'create' ? bookingPayCreate($booking) : bookingPayVerify($booking);
}

/** Creates the Razorpay order for $paise (rupees × 100): the order as Razorpay returns it. Fake on a local copy with the seam on. */
function bookingCreateRazorpayOrder(int $paise, string $receipt, array $notes): array {
    if (bookingFakeRazorpay()) return ['id' => 'order_FAKE' . bin2hex(random_bytes(7)), 'amount' => $paise];
    [$status, $order] = razorpayRequest('POST', '/orders', ['amount' => $paise, 'currency' => 'INR', 'receipt' => substr($receipt, 0, 40), 'notes' => $notes]);
    if ($status !== 200 || !is_string($order['id'] ?? null)) {
        error_log('Razorpay booking order failed (' . $status . '): ' . json_encode($order));
        throw new RuntimeException('The payment could not be started.');
    }
    return $order;
}

function bookingPayCreate(array $booking): never {
    if (!wantsJson()) { header('Location: ' . $GLOBALS['base'] . '/booking/' . $booking['token'] . '/pay', true, 303); exit; }
    if (!bookingPaymentReady()) bookingPayJson(['ok' => false, 'message' => 'Online payment is being set up. Please pay as described on this page; the team will confirm your booking.'], 503);
    $status = (string) $booking['status'];
    if ($status !== 'approved') {
        $why = ['confirmed' => 'This booking is already paid and confirmed.', 'requested' => 'This request has not been approved yet.',
            'rejected' => 'This request was not approved.', 'cancelled' => 'This booking was cancelled.', 'expired' => 'The time to pay has passed.'][$status] ?? 'This booking can’t be paid.';
        bookingPayJson(['ok' => false, 'message' => $why, 'redirect' => $GLOBALS['base'] . '/booking/' . $booking['token'] . '/pay'], 409);
    }
    // An approved slot is held until payment_due_at; after that the team has to approve it again.
    if ($booking['payment_due_at'] && strtotime((string) $booking['payment_due_at']) < time()) {
        bookingPayJson(['ok' => false, 'message' => 'The time to pay has passed, so the slot was released. Please make a new request.', 'redirect' => $GLOBALS['base'] . '/booking/' . $booking['token'] . '/pay'], 410);
    }
    // Someone else may have been given the slot since (the team can override a hold): refuse rather than take money for it.
    if (!bookingSlotFree((string) $booking['booking_date'], (string) $booking['slot'], (int) $booking['id'], (string) $booking['format'], (int) $booking['days'])) {
        bookingPayJson(['ok' => false, 'message' => 'Sorry, this slot has just been given to someone else. Please contact us or choose another date.'], 409);
    }
    $rupees = (float) $booking['amount'];
    $paise = (int) round($rupees * 100);
    if ($paise < 100) bookingPayJson(['ok' => false, 'message' => 'There is no amount to pay online for this booking. Please contact the team.'], 422);

    // Modest limit on payment attempts from one visitor.
    $now = time();
    $times = array_values(array_filter((array) ($_SESSION['booking_pay_times'] ?? []), fn ($t) => is_int($t) && $t > $now - 3600));
    if (count($times) >= BOOKING_PAY_MAX_PER_HOUR) bookingPayJson(['ok' => false, 'message' => 'Too many payment attempts from here in the last hour. Please try again later.'], 429);
    $times[] = $now;
    $_SESSION['booking_pay_times'] = $times;

    try {
        $order = bookingCreateRazorpayOrder($paise, 'bk-' . $booking['id'], ['booking' => (string) $booking['reference']]);
    } catch (RuntimeException $error) {
        bookingPayJson(['ok' => false, 'message' => 'We couldn’t start the payment. Please try again in a moment.'], 502);
    }
    db()->prepare('UPDATE bookings SET razorpay_order_id = ?, updated_at = NOW() WHERE id = ? AND status = ?')->execute([$order['id'], $booking['id'], 'approved']);
    // Keep every order id ever created for this booking: a payment on an older one must still find it.
    bookingPayEvent((int) $booking['id'], 'payment_started', $order['id'] . '|' . $paise);

    bookingPayJson(['ok' => true, 'message' => 'Opening the payment window…', 'payment' => [
        'key' => bookingFakeRazorpay() ? 'rzp_test_LOCALFAKEKEY' : razorpayConfig()['key_id'], 'order_id' => $order['id'], 'amount' => $paise, 'currency' => 'INR',
        'name' => 'Pro HPCL Ground Booking', 'description' => 'Booking ' . $booking['reference'] . ' (advance)',
        'prefill' => ['name' => $booking['name'], 'email' => $booking['email'], 'contact' => '+91' . preg_replace('~\D+~', '', (string) $booking['phone'])],
        'theme' => ['color' => '#0b50a7']], 'redirect' => $GLOBALS['base'] . '/booking/' . $booking['token'] . '/pay']);
}

function bookingPayVerify(array $booking): never {
    $orderId = (string) ($_POST['razorpay_order_id'] ?? '');
    $paymentId = (string) ($_POST['razorpay_payment_id'] ?? '');
    $signature = (string) ($_POST['razorpay_signature'] ?? '');
    $failed = fn (string $message = '') => bookingPayJson(['ok' => false, 'message' => $message !== '' ? $message
        : 'We couldn’t confirm your payment. If money was taken, don’t pay again: contact us with the payment ID ' . preg_replace('~[^A-Za-z0-9_]~', '', $paymentId) . '.'], 422);
    if (!preg_match('~^order_\w{6,40}$~', $orderId) || !preg_match('~^pay_\w{6,40}$~', $paymentId) || !razorpayValidSignature($orderId, $paymentId, $signature)) $failed();
    // The order must be one this booking started. Its amount was fixed when it was created, so a payment made on an order
    // from before the price was revised is not the advance now due: bookingPayConfirm flags it instead of confirming.
    $owner = bookingByRazorpayOrder($orderId);
    if (!$owner || (int) $owner['id'] !== (int) $booking['id']) $failed();
    $result = bookingPayConfirm($owner, $paymentId, bookingOrderPaise((int) $owner['id'], $orderId));
    if ($result !== 'confirmed') {
        $failed(match ($result) {
            'slot' => 'We received your payment, but the time to pay had passed and this slot has since been given to someone else. The team has been told and will refund you. Please don’t pay again.',
            'amount' => 'We received your payment, but the price of this booking changed after it was started, so it could not be confirmed. The team has been told and will contact you or refund the payment. Please don’t pay again.',
            default => 'We received your payment, but the booking could not be confirmed automatically. The team has been told; please don’t pay again.',
        });
    }
    bookingPayJson(['ok' => true, 'message' => 'Payment received. Your booking is confirmed!', 'redirect' => $GLOBALS['base'] . '/booking/' . $booking['token'] . '/pay']);
}

// ---------------------------------------------------------------------------------------------------------
// The customer's bookings in the account
// ---------------------------------------------------------------------------------------------------------

/** The signed-in customer's bookings: requested while signed in, or with the account's email address. */
function bookingPayOwnList(array $user): array {
    return query('SELECT id FROM bookings WHERE user_id = ? OR email = ? ORDER BY booking_date DESC, id DESC LIMIT 100', [(int) $user['id'], (string) $user['email']]);
}

/** /account/bookings: a list, or one booking with ?booking=BK-10001. */
function handleAccountBookings(): never {
    $user = requireUser();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) errorPage(405);
    bookingExpireDue();
    $reference = strtoupper(trim((string) ($_GET['booking'] ?? '')));
    if ($reference !== '') {
        $row = query('SELECT id FROM bookings WHERE reference = ?', [$reference])[0] ?? null;
        $booking = $row ? bookingGet((int) $row['id']) : null;
        // Only the customer's own booking: requested while signed in, or with the account's email address.
        if (!$booking || !((int) ($booking['user_id'] ?? 0) === (int) $user['id'] || strcasecmp((string) $booking['email'], (string) $user['email']) === 0)) errorPage(404);
        $body = '<p class="sco-back"><a href="{{BASE}}/account/bookings">← All my bookings</a></p>' . bookingPayDetailHtml($booking);
        if ($booking['status'] === 'approved') $body .= '<p class="sco-actions"><a class="sco-btn" href="{{BASE}}/booking/' . escape((string) $booking['token']) . '/pay">Pay ' . bookingPayMoney($booking['amount']) . ' now</a></p>';
        elseif ($booking['status'] === 'confirmed') $body .= '<p class="sco-actions"><a class="sco-btn sco-btn--ghost" href="{{BASE}}/booking/' . escape((string) $booking['token']) . '/pay">Open receipt</a></p>';
        $title = 'Booking ' . $booking['reference'];
    } else {
        $title = 'My bookings';
        $body = '<h1>My bookings</h1>';
        $rows = bookingPayOwnList($user);
        if (!$rows) {
            $body .= '<p class="sco-muted">You haven’t requested the ground yet.' . (bookingEnabled() ? ' <a href="{{BASE}}/book">Request a booking</a>.' : '') . '</p>';
        } else {
            $body .= (bookingEnabled() ? '<p class="sco-actions"><a class="sco-btn sco-btn--ghost" href="{{BASE}}/book">Request another booking</a></p>' : '') . '<ul class="sco-orders">';
            foreach ($rows as $row) {
                $booking = bookingGet((int) $row['id']);
                if (!$booking) continue;
                $body .= '<li class="sco-orders__row"><a class="sco-orders__link" href="{{BASE}}/account/bookings?booking=' . escape((string) $booking['reference']) . '"><span class="sco-orders__no">' . escape((string) $booking['reference']) . '</span>'
                    . '<span class="sco-muted">' . escape(bookingPayDate($booking['booking_date'])) . ' · ' . escape(bookingPaySlot($booking)) . ' · ' . escape(bookingPayFormat($booking)) . '</span></a>'
                    . '<span class="sco-orders__side">' . bookingPayBadge((string) $booking['status'], bookingPayStatus($booking))
                    . ($booking['amount'] !== null ? '<strong>' . bookingPayMoney($booking['amount']) . '</strong>' : '')
                    . ($booking['status'] === 'approved' ? '<a class="sco-btn bkp-small" href="{{BASE}}/booking/' . escape((string) $booking['token']) . '/pay">Pay now</a>' : '') . '</span></li>';
            }
            $body .= '</ul>';
        }
    }
    accountPage(view('templates.account.bookings', ['title' => escape($title), 'body' => $body, 'js_version' => '20261105']));
}
