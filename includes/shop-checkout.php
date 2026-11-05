<?php
declare(strict_types=1);

// Shop checkout and orders for customers:
//   GET  /shop/checkout              the delivery form and order summary (renderShopCheckout)
//   POST /shop/checkout/place        saves a pending order and starts the Razorpay payment (JSON)
//   POST /shop/checkout/verify       confirms Razorpay Checkout's answer with its signature (JSON)
//   GET  /shop/order/<token>         the order's page, reached by the private link (renderShopOrder)
//   GET  /account/orders[?order=NO]  a signed-in customer's orders (handleAccountOrders)
// Razorpay's webhook also lands here through shopPaymentReceived() (shop-orders.php), for payers who
// close the page before the confirmation. The cart is shop-cart.php (storefront); orders, stock and
// statuses are shop-orders.php; emails are shop-mail.php.
//
// LOCAL TEST SEAM: with APP_ENV=local and site_settings.shop_fake_razorpay = '1', the Razorpay order is
// not created over the network (a fake order id is used). Signatures are still checked with the saved
// key secret, so a test can compute a valid one (bin/test-shop-flow.py). shopFakeRazorpay() is false
// whenever appEnv() is not exactly 'local', so it cannot act on the live site.
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/shop-cart.php';
require_once __DIR__ . '/shop-orders.php';
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/razorpay.php';
require_once __DIR__ . '/committee.php'; // committeePhone(), committeeCleanText(), COMMITTEE_NAME_PATTERN

const SHOP_CHECKOUT_FIELDS = ['name', 'email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'pincode', 'notes'];
const SHOP_CHECKOUT_MAX_PER_HOUR = 10;
const SHOP_STATES = ['Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh', 'Chhattisgarh',
    'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka',
    'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab',
    'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'];

/** True only on a local copy with the test setting on: Razorpay is not called and a fake order id is used. */
function shopFakeRazorpay(): bool {
    return appEnv() === 'local' && setting('shop_fake_razorpay') === '1';
}
/** Whether the shop can take payment now: Razorpay is set up (or the local test seam is on). */
function shopPaymentReady(): bool {
    return razorpayReady() || shopFakeRazorpay();
}

/** Starts the session the cart lives in (the same one as the account, so a signed-in customer is recognised). */
function shopCheckoutSession(): void {
    if (function_exists('shopCartSessionStart')) shopCartSessionStart();
    else accountStartSession();
}
function shopCheckoutCsrfToken(): string {
    return function_exists('shopCsrfToken') ? shopCsrfToken() : accountCsrfToken();
}
function shopCheckoutCsrfValid(): bool {
    return function_exists('shopCsrfValid') ? shopCsrfValid() : accountCsrfValid();
}
function shopCheckoutHeaders(): void {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');
}
function shopJson(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    shopCheckoutHeaders();
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** What to put in the form for a signed-in customer (name, email, phone, city from the account). */
function shopCheckoutPrefill(): array {
    $user = currentUser();
    $input = array_fill_keys(SHOP_CHECKOUT_FIELDS, '');
    if ($user) {
        $input['name'] = (string) $user['name'];
        $input['email'] = (string) $user['email'];
        $input['phone'] = (string) ($user['phone'] ?? '');
        $input['city'] = (string) ($user['city'] ?? '');
    }
    return $input;
}

/** The posted delivery details, cleaned, and their errors by field. */
function shopCheckoutValidate(): array {
    $input = [
        'name' => committeeCleanText(postedText('name')),
        'email' => strtolower(trim(postedText('email'))),
        'phone' => postedText('phone'),
        'address_line1' => committeeCleanText(postedText('address_line1')),
        'address_line2' => committeeCleanText(postedText('address_line2')),
        'city' => committeeCleanText(postedText('city')),
        'state' => postedText('state'),
        'pincode' => preg_replace('~\s+~', '', postedText('pincode')),
        'notes' => committeeCleanText(postedText('notes'), true),
    ];
    $errors = [];
    $length = mb_strlen($input['name']);
    if ($length === 0) $errors['name'] = 'Enter the name the parcel should go to.';
    elseif ($length < 2 || $length > 100) $errors['name'] = 'The name must be 2 to 100 characters long.';
    elseif (!preg_match(COMMITTEE_NAME_PATTERN, $input['name'])) $errors['name'] = 'Use only letters, spaces, dots, hyphens and apostrophes in the name.';
    if ($input['email'] === '') $errors['email'] = 'Enter your email address, so we can send your order confirmation.';
    elseif (mb_strlen($input['email']) > 190 || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($input['phone'] === '') $errors['phone'] = 'Enter a mobile number we can reach you on.';
    elseif (($phone = committeePhone($input['phone'])) === null) $errors['phone'] = 'Enter a valid 10-digit Indian mobile number.';
    else $input['phone'] = $phone;
    $length = mb_strlen($input['address_line1']);
    if ($length === 0) $errors['address_line1'] = 'Enter the house number and street.';
    elseif ($length < 5 || $length > 200) $errors['address_line1'] = 'The address must be 5 to 200 characters long.';
    if (mb_strlen($input['address_line2']) > 200) $errors['address_line2'] = 'Keep this line to 200 characters.';
    $length = mb_strlen($input['city']);
    if ($length === 0) $errors['city'] = 'Enter your city or town.';
    elseif ($length < 2 || $length > 100) $errors['city'] = 'The city must be 2 to 100 characters long.';
    if ($input['state'] === '') $errors['state'] = 'Choose your state.';
    elseif (!in_array($input['state'], SHOP_STATES, true)) $errors['state'] = 'Choose a state from the list.';
    if ($input['pincode'] === '') $errors['pincode'] = 'Enter your 6-digit PIN code.';
    elseif (!preg_match('~^[1-9]\d{5}$~', $input['pincode'])) $errors['pincode'] = 'A PIN code has 6 digits.';
    if (mb_strlen($input['notes']) > 300) $errors['notes'] = 'Keep the note to 300 characters.';
    return [$input, $errors];
}

/** The cart's lines as an HTML list for the order summary. */
function shopCheckoutSummaryItems(array $lines): string {
    $html = '';
    foreach ($lines as $line) {
        $html .= '<li class="sco-line">'
            . '<img class="sco-line__img" src="' . escape(linkUrl((string) ($line['image'] ?? ''))) . '" alt="" width="56" height="56" loading="lazy">'
            . '<div class="sco-line__body"><span class="sco-line__name">' . escape((string) $line['product']['name']) . '</span>'
            . (($line['variant_label'] ?? '') !== '' ? '<span class="sco-line__meta">' . escape($line['variant_label']) . '</span>' : '')
            . '<span class="sco-line__meta">Qty ' . (int) $line['qty'] . ' × ' . rupees($line['unit_price']) . '</span></div>'
            . '<span class="sco-line__total">' . rupees($line['line_total']) . '</span></li>';
    }
    return $html;
}
function shopStateOptions(string $selected): string {
    $html = '<option value="">Choose your state</option>';
    foreach (SHOP_STATES as $state) $html .= '<option value="' . escape($state) . '"' . ($state === $selected ? ' selected' : '') . '>' . escape($state) . '</option>';
    return $html;
}
/** "Questions? Call or WhatsApp …" as a paragraph, or '' when no support phone is set. */
function shopSupportLine(): string {
    $phone = trim(shopSetting('shop_support_phone'));
    return $phone !== '' ? '<p class="sco-small sco-muted">Questions? Call or WhatsApp ' . phoneLink($phone) . '.</p>' : '';
}

/** GET /shop/checkout: the form and summary (null = 404 is not used: an empty cart gets a friendly page). */
function renderShopCheckout(?array $input = null, array $errors = []): string {
    shopCheckoutSession();
    shopCheckoutHeaders();
    $lines = shopCartLines();
    $ready = shopPaymentReady();
    $totals = $lines ? shopCartTotals($lines) : ['subtotal' => 0, 'shipping' => 0, 'total' => 0];
    $input ??= shopCheckoutPrefill();
    $data = accountFormData($input, $errors, SHOP_CHECKOUT_FIELDS);
    $data['csrf'] = shopCheckoutCsrfToken();
    return view('templates.shop-checkout', $data + [
        'empty_hidden' => $lines ? ' hidden' : '',
        'form_hidden' => $lines ? '' : ' hidden',
        'items' => shopCheckoutSummaryItems($lines),
        'subtotal' => rupees($totals['subtotal']),
        'shipping' => (float) $totals['shipping'] > 0 ? rupees($totals['shipping']) : 'Free',
        'total' => rupees($totals['total']),
        'count' => (string) (int) array_sum(array_column($lines, 'qty')),
        'items_word' => (int) array_sum(array_column($lines, 'qty')) === 1 ? 'item' : 'items',
        'state_options' => shopStateOptions((string) $input['state']),
        'pay_ready' => $ready ? '1' : '',
        'pay_disabled' => $ready ? '' : ' disabled aria-disabled="true"',
        'pay_note' => $ready ? '' : '<p class="sco-note sco-note--wait" role="status">Online payment is being set up, so orders can’t be placed just yet. Please check back soon.</p>',
        'support' => shopSupportLine(),
        'razorpay_scripts' => $ready ? '<script src="https://checkout.razorpay.com/v1/checkout.js" defer></script>' : '',
        'js_version' => '20261105',
    ]);
}

/** POST /shop/checkout/place|verify. */
function handleShopCheckoutStep(string $step): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') errorPage(405);
    shopCheckoutSession();
    if (!shopCheckoutCsrfValid()) shopJson(['ok' => false, 'message' => USER_FORM_EXPIRED], 403);
    $step === 'place' ? shopCheckoutPlace() : shopCheckoutVerify();
}

/** Creates the Razorpay order for $paise (rupees × 100): the order as Razorpay returns it. Fake on a local copy with the seam on. */
function shopCreateRazorpayOrder(int $paise, string $receipt, array $notes): array {
    if (shopFakeRazorpay()) return ['id' => 'order_FAKE' . bin2hex(random_bytes(7)), 'amount' => $paise];
    [$status, $order] = razorpayRequest('POST', '/orders', ['amount' => $paise, 'currency' => 'INR', 'receipt' => substr($receipt, 0, 40), 'notes' => $notes]);
    if ($status !== 200 || !is_string($order['id'] ?? null)) {
        error_log('Razorpay shop order failed (' . $status . '): ' . json_encode($order));
        throw new RuntimeException('The payment could not be started.');
    }
    return $order;
}

function shopCheckoutPlace(): never {
    if (!wantsJson()) { header('Location: ' . $GLOBALS['base'] . '/shop/checkout', true, 303); exit; }
    // Honeypot: a hidden field real people leave empty.
    if (postedText('company_url') !== '') shopJson(['ok' => false, 'message' => 'We couldn’t place this order. Please try again.'], 422);
    if (!shopPaymentReady()) shopJson(['ok' => false, 'message' => 'Online payment is being set up, so orders can’t be placed just yet. Please check back soon.'], 503);

    $lines = shopCartLines();
    if (!$lines) shopJson(['ok' => false, 'message' => 'Your cart is empty.', 'redirect' => $GLOBALS['base'] . '/shop'], 422);
    // The cart is checked again against the database on every attempt: stock, current prices and the shipping rule come from the server, never from the browser.
    foreach ($lines as $line) {
        if ((int) $line['max_qty'] < (int) $line['qty']) {
            shopJson(['ok' => false, 'message' => $line['product']['name'] . (((int) $line['max_qty'] > 0) ? ' has only ' . (int) $line['max_qty'] . ' left. Please update your cart.' : ' is out of stock. Please remove it from your cart.'),
                'redirect' => $GLOBALS['base'] . '/shop/cart'], 422);
        }
    }
    [$input, $errors] = shopCheckoutValidate();
    if ($errors) shopJson(['ok' => false, 'message' => 'Please correct the highlighted fields.', 'errors' => $errors], 422);

    // A modest limit against order spam: per browser session, and per email address.
    $now = time();
    $times = array_values(array_filter((array) ($_SESSION['shop_order_times'] ?? []), fn ($t) => is_int($t) && $t > $now - 3600));
    $recent = (int) (query("SELECT COUNT(*) AS n FROM shop_orders WHERE email = ? AND created_at > (NOW() - INTERVAL 1 HOUR)", [$input['email']])[0]['n'] ?? 0);
    if (count($times) >= SHOP_CHECKOUT_MAX_PER_HOUR || $recent >= SHOP_CHECKOUT_MAX_PER_HOUR) shopJson(['ok' => false, 'message' => 'Too many orders were started from here in the last hour. Please try again later.'], 429);
    $times[] = $now;
    $_SESSION['shop_order_times'] = $times;
    if (random_int(1, 20) === 1) shopExpirePendingOrders();

    $totals = shopCartTotals($lines);
    $subtotal = round(array_sum(array_map(fn ($line) => (float) $line['unit_price'] * (int) $line['qty'], $lines)), 2);
    $shipping = round((float) $totals['shipping'], 2);
    $total = round($subtotal + $shipping, 2);
    $paise = (int) round($total * 100);
    if ($paise < 100) shopJson(['ok' => false, 'message' => 'The order total is too small to pay online.'], 422);

    $user = currentUser();
    $pdo = db();
    // A checkout opened again for the same browser replaces the one still waiting for payment.
    $previous = (int) ($_SESSION['shop_pending_order'] ?? 0);
    if ($previous > 0) {
        $closed = $pdo->prepare("UPDATE shop_orders SET status = 'failed', updated_at = NOW() WHERE id = ? AND status = 'pending'");
        $closed->execute([$previous]);
        if ($closed->rowCount()) shopOrderEvent($previous, 'failed', 'Replaced by a new checkout.');
    }
    $token = bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO shop_orders (order_number, token, user_id, name, email, phone, address_line1, address_line2, city, state, pincode, notes, subtotal, shipping, total, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', NOW())')
            ->execute([substr($token, 0, 20), $token, $user ? (int) $user['id'] : null, $input['name'], $input['email'], $input['phone'], $input['address_line1'], $input['address_line2'] ?: null,
                $input['city'], $input['state'], $input['pincode'], $input['notes'] ?: null, $subtotal, $shipping, $total]);
        $orderId = (int) $pdo->lastInsertId();
        $number = 'HPCL-' . (10000 + $orderId);
        $pdo->prepare('UPDATE shop_orders SET order_number = ? WHERE id = ?')->execute([$number, $orderId]);
        $insert = $pdo->prepare('INSERT INTO shop_order_items (order_id, product_id, variant_id, product_name, variant_label, image, unit_price, qty, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lines as $line) {
            $insert->execute([$orderId, (int) $line['product']['id'], !empty($line['variant']['id']) ? (int) $line['variant']['id'] : null, $line['product']['name'],
                ($line['variant_label'] ?? '') !== '' ? $line['variant_label'] : null, $line['image'] ?? null, round((float) $line['unit_price'], 2), (int) $line['qty'], round((float) $line['unit_price'] * (int) $line['qty'], 2)]);
        }
        shopOrderEvent($orderId, 'pending', 'Order placed, waiting for payment.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Shop order could not be saved: ' . $error->getMessage());
        shopJson(['ok' => false, 'message' => 'We couldn’t save your order. Please try again.'], 500);
    }

    try {
        $rzp = shopCreateRazorpayOrder($paise, $number, ['shop_order' => $number]);
    } catch (RuntimeException $error) {
        $pdo->prepare("UPDATE shop_orders SET status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
        shopOrderEvent($orderId, 'failed', 'Razorpay could not start the payment.');
        shopJson(['ok' => false, 'message' => 'Online payment isn’t available right now, so your order wasn’t placed. Please try again in a few minutes.'], 502);
    }
    $pdo->prepare('UPDATE shop_orders SET razorpay_order_id = ? WHERE id = ?')->execute([$rzp['id'], $orderId]);
    $_SESSION['shop_pending_order'] = $orderId;

    shopJson(['ok' => true, 'message' => 'Order saved. Opening the payment window…', 'token' => $token, 'order_number' => $number,
        'redirect' => $GLOBALS['base'] . '/shop/order/' . $token,
        'payment' => ['key' => shopFakeRazorpay() ? 'rzp_test_LOCALFAKEKEY' : razorpayConfig()['key_id'], 'order_id' => $rzp['id'], 'amount' => $paise, 'currency' => 'INR', 'name' => 'Pro HPCL Shop',
            'description' => 'Order ' . $number, 'prefill' => ['name' => $input['name'], 'email' => $input['email'], 'contact' => '+91' . $input['phone']], 'theme' => ['color' => '#0b50a7']]]);
}

function shopCheckoutVerify(): never {
    $orderId = (string) ($_POST['razorpay_order_id'] ?? '');
    $paymentId = (string) ($_POST['razorpay_payment_id'] ?? '');
    $signature = (string) ($_POST['razorpay_signature'] ?? '');
    $failed = fn (string $message = '') => shopJson(['ok' => false, 'message' => $message !== '' ? $message
        : 'We couldn’t confirm your payment. If money was taken, don’t pay again: contact us with the payment ID ' . preg_replace('~[^A-Za-z0-9_]~', '', $paymentId) . '.'], 422);
    if (!preg_match('~^order_\w{6,40}$~', $orderId) || !preg_match('~^pay_\w{6,40}$~', $paymentId) || !razorpayValidSignature($orderId, $paymentId, $signature)) $failed();
    $order = query('SELECT id, token FROM shop_orders WHERE razorpay_order_id = ?', [$orderId])[0] ?? null;
    if (!$order) $failed();
    // The amount was fixed when the Razorpay order was created for this order, so the signature is enough here.
    if (!shopMarkOrderPaid($orderId, $paymentId, null)) $failed();
    shopCartClear();
    unset($_SESSION['shop_pending_order']);
    shopJson(['ok' => true, 'message' => 'Payment received. Thank you!', 'redirect' => $GLOBALS['base'] . '/shop/order/' . $order['token']]);
}

/** A status badge. */
function shopOrderBadge(string $status): string {
    $kind = in_array($status, ['cancelled', 'failed', 'refunded'], true) ? 'bad' : ($status === 'pending' ? 'wait' : 'ok');
    return '<span class="sco-badge sco-badge--' . $kind . '">' . escape(shopOrderStatusLabel($status)) . '</span>';
}

/** The order in full as HTML (shared by the order page and the account): status or timeline, tracking, items, totals, address. */
function shopOrderDetailHtml(array $order): string {
    $status = $order['status'];
    $when = [];
    foreach ($order['events'] as $event) $when[$event['status']] ??= $event['created_at'];
    if ($order['paid_at']) $when['paid'] = $order['paid_at'];
    $date = fn (?string $value) => $value ? date('j M Y, g:i a', strtotime($value)) : '';
    $html = '<header class="sco-order__head"><div><p class="sco-eyebrow">Order</p><h1 class="sco-order__number">' . escape($order['order_number']) . '</h1>'
        . '<p class="sco-muted">Placed ' . escape($date($order['created_at'])) . '</p></div>' . shopOrderBadge($status) . '</header>';
    if (in_array($status, ['cancelled', 'failed', 'refunded'], true)) {
        $text = ['cancelled' => 'This order was cancelled. If you had paid, the amount is refunded to your original payment method within 5 to 7 working days.',
            'failed' => 'This order was never paid, so nothing was charged. You can start again from your cart.',
            'refunded' => 'This order was refunded. The money returns to your original payment method within 5 to 7 working days.'][$status];
        $html .= '<p class="sco-note sco-note--bad" role="status">' . escape($text) . '</p>';
    } elseif ($status === 'pending') {
        $html .= '<p class="sco-note sco-note--wait" role="status">We are waiting for your payment to be confirmed. If you were charged, this page updates within a few minutes; refresh it to check. If the payment window was closed, your cart is still saved so you can try again.</p>';
    } else {
        $reached = array_search($status, SHOP_ORDER_TIMELINE, true);
        $html .= '<ol class="sco-timeline" aria-label="Order progress">';
        foreach (SHOP_ORDER_TIMELINE as $i => $step) {
            $done = $reached !== false && $i <= $reached;
            $html .= '<li class="sco-timeline__step' . ($done ? ' is-done' : '') . ($reached === $i ? ' is-current' : '') . '"' . ($reached === $i ? ' aria-current="step"' : '') . '>'
                . '<span class="sco-timeline__dot" aria-hidden="true"></span><span class="sco-timeline__label">' . escape(shopOrderStatusLabel($step)) . '</span>'
                . (isset($when[$step]) && $done ? '<span class="sco-timeline__time">' . escape($date($when[$step])) . '</span>' : '') . '</li>';
        }
        $html .= '</ol>';
    }
    if (($order['tracking_number'] ?? '') !== '' && in_array($status, ['shipped', 'delivered'], true)) {
        $html .= '<p class="sco-tracking"><strong>Tracking</strong> ' . (trim((string) $order['tracking_carrier']) !== '' ? escape($order['tracking_carrier']) . ': ' : '') . '<span class="sco-tracking__no">' . escape($order['tracking_number']) . '</span></p>';
    }
    $html .= '<section aria-labelledby="sco-items"><h2 id="sco-items">Items</h2><ul class="sco-lines">';
    foreach ($order['items'] as $item) {
        $html .= '<li class="sco-line"><img class="sco-line__img" src="' . escape(linkUrl((string) ($item['image'] ?? ''))) . '" alt="" width="56" height="56" loading="lazy">'
            . '<div class="sco-line__body"><span class="sco-line__name">' . escape($item['product_name']) . '</span>'
            . ($item['variant_label'] ? '<span class="sco-line__meta">' . escape($item['variant_label']) . '</span>' : '')
            . '<span class="sco-line__meta">Qty ' . (int) $item['qty'] . ' × ' . rupees($item['unit_price']) . '</span></div><span class="sco-line__total">' . rupees($item['line_total']) . '</span></li>';
    }
    $html .= '</ul><dl class="sco-totals"><dt>Subtotal</dt><dd>' . rupees($order['subtotal']) . '</dd><dt>Shipping</dt><dd>' . ((float) $order['shipping'] > 0 ? rupees($order['shipping']) : 'Free')
        . '</dd><dt class="sco-totals__grand">Total (incl. GST)</dt><dd class="sco-totals__grand">' . rupees($order['total']) . '</dd></dl></section>';
    $html .= '<section aria-labelledby="sco-address"><h2 id="sco-address">Delivering to</h2><address class="sco-address">' . shopMailAddressHtml($order) . '</address>'
        . (trim((string) $order['notes']) !== '' ? '<h3 class="sco-sub">Your note</h3><p class="sco-notes">' . nl2br(escape($order['notes'])) . '</p>' : '') . '</section>';
    return $html;
}

/** GET /shop/order/<token>: null (404) when there is no such order. */
function renderShopOrder(string $token): ?string {
    shopCheckoutSession();
    shopCheckoutHeaders();
    $order = shopOrderGet($token);
    if (!$order) return null;
    return view('templates.shop-order', ['detail' => shopOrderDetailHtml($order), 'order_number' => escape($order['order_number']), 'support' => shopSupportLine(),
        'js_version' => '20261105']);
}

/** The customer's orders (/account/orders): a list, or one order with ?order=HPCL-10001. */
function handleAccountOrders(): never {
    $user = requireUser();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) errorPage(405);
    $own = ['user_id' => (int) $user['id'], 'email' => (string) $user['email']];
    $number = strtoupper(trim((string) ($_GET['order'] ?? '')));
    if ($number !== '') {
        $order = shopOrderGet($number);
        // Only the customer's own order: placed while signed in, or with the account's email address.
        if (!$order || !((int) $order['user_id'] === (int) $user['id'] || strcasecmp((string) $order['email'], (string) $user['email']) === 0)) errorPage(404);
        $body = '<p class="sco-back"><a href="{{BASE}}/account/orders">← All my orders</a></p>' . shopOrderDetailHtml($order)
            . '<p class="sco-actions"><a class="sco-btn sco-btn--ghost" href="{{BASE}}/shop/order/' . escape($order['token']) . '">Printable order page</a></p>';
        $title = 'Order ' . $order['order_number'];
    } else {
        $orders = shopOrderList($own + ['limit' => 100]);
        $title = 'My orders';
        $body = '<h1>My orders</h1>';
        if (!$orders) $body .= '<p class="sco-muted">You haven’t placed an order yet.' . (shopEnabled() ? ' <a href="{{BASE}}/shop">Visit the shop</a>.' : '') . '</p>';
        else {
            $body .= '<ul class="sco-orders">';
            foreach ($orders as $order) {
                $body .= '<li class="sco-orders__row"><a class="sco-orders__link" href="{{BASE}}/account/orders?order=' . escape($order['order_number']) . '"><span class="sco-orders__no">' . escape($order['order_number']) . '</span>'
                    . '<span class="sco-muted">' . escape(date('j M Y', strtotime($order['created_at']))) . ' · ' . (int) $order['items_count'] . ' item' . ((int) $order['items_count'] === 1 ? '' : 's') . '</span></a>'
                    . '<span class="sco-orders__side">' . shopOrderBadge($order['status']) . '<strong>' . rupees($order['total']) . '</strong></span></li>';
            }
            $body .= '</ul>';
        }
    }
    accountPage(view('templates.account.orders', ['title' => escape($title), 'body' => $body, 'js_version' => '20261105']));
}
