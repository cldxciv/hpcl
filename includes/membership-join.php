<?php
declare(strict_types=1);

// Membership join form (/membership/join): a business chooses a tier and applies. Modelled on
// the committee flow (includes/committee.php) and reusing its Razorpay payment pieces and the
// generic assets/js/committee-payment.js. Applications land in membership_applications and are
// reviewed in the admin; an approved application becomes a public member profile (members).
//
// Payment is optional: when Razorpay is set up (Admin → Integrations) and
// membership_payment_enabled is on, the applicant pays the tier fee with Razorpay Checkout and
// the payment is confirmed at /membership/join/payment. Otherwise the application is simply saved
// and the team follows up — so the form works before Razorpay is configured.
require_once __DIR__ . '/membership.php';
require_once __DIR__ . '/committee.php'; // reuse committeePhone(), committeeCleanText(), COMMITTEE_NAME_PATTERN
require_once __DIR__ . '/razorpay.php';

const MEMBERSHIP_JOIN_FIELDS = ['business_name', 'contact_name', 'email', 'phone', 'tier', 'website', 'city', 'message'];
const MEMBERSHIP_BUSINESS_LENGTH = [2, 150];
const MEMBERSHIP_CONTACT_LENGTH = [3, 100];
const MEMBERSHIP_MESSAGE_LENGTH = [10, 1500];
const MEMBERSHIP_PAYMENT_SETTING = 'membership_payment_enabled';
const MEMBERSHIP_MAX_PER_HOUR = 6;
const MEMBERSHIP_SENT_MESSAGE = 'Thank you! Your membership request has been received. Our team will contact you shortly to complete the next steps.';

/** Whether applicants pay their tier fee online: the setting is on and Razorpay is ready. */
function membershipPaymentRequired(): bool {
    return (query('SELECT value FROM site_settings WHERE name = ?', [MEMBERSHIP_PAYMENT_SETTING])[0]['value'] ?? '') === '1' && razorpayReady();
}

/** GET shows the form (?tier= preselects, ?sent=1 the thanks message); POST saves an application. */
function handleMembershipJoin(): never {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) errorPage(405);
    if ($method !== 'POST') {
        header('Content-Type: text/html; charset=UTF-8');
        $input = array_fill_keys(MEMBERSHIP_JOIN_FIELDS, '');
        $preselect = strtolower((string) ($_GET['tier'] ?? ''));
        if (isset(MEMBERSHIP_TIERS[$preselect])) $input['tier'] = $preselect;
        echo render(membershipJoinForm($input, [], ($_GET['sent'] ?? '') === '1'));
        exit;
    }
    [$input, $errors, $payment] = saveMembershipApplication();
    formResponse($errors, $errors ? 'Please correct the highlighted fields.' : ($payment ? 'Your request is saved. Complete the payment to confirm.' : MEMBERSHIP_SENT_MESSAGE),
        '/membership/join?sent=1', fn () => membershipJoinForm($input, $errors, false), $payment ? ['payment' => $payment] : []);
}

/** Checks the posted fields: cleaned values (phone as 10 digits) and errors by field. */
function validateMembershipApplication(): array {
    $input = [
        'business_name' => committeeCleanText(postedText('business_name')),
        'contact_name' => committeeCleanText(postedText('contact_name')),
        'email' => strtolower(postedText('email')),
        'phone' => postedText('phone'),
        'tier' => strtolower(postedText('tier')),
        'website' => committeeCleanText(postedText('website')),
        'city' => committeeCleanText(postedText('city')),
        'message' => committeeCleanText(postedText('message'), true),
    ];
    $errors = [];
    [$bMin, $bMax] = MEMBERSHIP_BUSINESS_LENGTH;
    $bLen = mb_strlen($input['business_name']);
    if ($bLen === 0) $errors['business_name'] = 'Enter your business or brand name.';
    elseif ($bLen < $bMin || $bLen > $bMax) $errors['business_name'] = "The business name must be $bMin to $bMax characters long.";

    [$cMin, $cMax] = MEMBERSHIP_CONTACT_LENGTH;
    $cLen = mb_strlen($input['contact_name']);
    if ($cLen === 0) $errors['contact_name'] = 'Enter the contact person’s name.';
    elseif ($cLen < $cMin || $cLen > $cMax) $errors['contact_name'] = "The contact name must be $cMin to $cMax characters long.";
    elseif (!preg_match(COMMITTEE_NAME_PATTERN, $input['contact_name'])) $errors['contact_name'] = 'Use only letters, spaces, dots, hyphens and apostrophes in the name.';

    if ($input['email'] === '') $errors['email'] = 'Enter your email address.';
    elseif (strlen($input['email']) > 254 || !filter_var($input['email'], FILTER_VALIDATE_EMAIL) || !preg_match('~@[^@]+\.[a-z]{2,}$~i', $input['email'])) $errors['email'] = 'Enter a valid email address, like name@example.com.';

    $phone = committeePhone($input['phone']);
    if ($input['phone'] === '') $errors['phone'] = 'Enter your mobile number.';
    elseif ($phone === null) $errors['phone'] = 'Enter a valid 10-digit Indian mobile number, like 98160 12345.';
    else $input['phone'] = $phone;

    if (!isset(MEMBERSHIP_TIERS[$input['tier']])) $errors['tier'] = 'Choose a membership tier.';

    if ($input['website'] !== '' && (mb_strlen($input['website']) > 255 || !preg_match('~^(https?://)?[\w.-]+\.[a-z]{2,}(/\S*)?$~i', $input['website']))) $errors['website'] = 'Enter a valid website address, or leave it blank.';
    if (mb_strlen($input['city']) > 120) $errors['city'] = 'The city name is too long.';

    [$mMin, $mMax] = MEMBERSHIP_MESSAGE_LENGTH;
    $mLen = mb_strlen($input['message']);
    if ($mLen === 0) $errors['message'] = 'Tell us a little about your business and goals.';
    elseif ($mLen < $mMin || $mLen > $mMax) $errors['message'] = "Your message must be $mMin to $mMax characters long.";

    return [$input, $errors];
}

/** Validates and stores the application; returns [input, errors, payment-options-or-null]. */
function saveMembershipApplication(): array {
    // Honeypot: a hidden field real people leave empty.
    if (postedText('company_url') !== '') return [array_fill_keys(MEMBERSHIP_JOIN_FIELDS, ''), [], null];
    [$input, $errors] = validateMembershipApplication();
    if ($errors) return [$input, $errors, null];

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $recent = (int) (query('SELECT COUNT(*) AS n FROM membership_applications WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$ip])[0]['n'] ?? 0);
    if ($recent >= MEMBERSHIP_MAX_PER_HOUR) return [$input, ['form' => 'Too many requests from this network. Please try again later.'], null];

    $payable = membershipPaymentRequired();
    $price = MEMBERSHIP_TIERS[$input['tier']]['price'];
    db()->prepare('INSERT INTO membership_applications (business_name, contact_name, email, phone, tier, website, city, message, payment_status, amount, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$input['business_name'], $input['contact_name'], $input['email'], $input['phone'], $input['tier'],
            $input['website'] ?: null, $input['city'] ?: null, $input['message'], $payable ? 'unpaid' : 'not_required', $payable ? $price : null, $ip]);
    $id = (int) db()->lastInsertId();

    if (!$payable) return [$input, [], null];
    try {
        $order = razorpayCreateOrder($price, 'membership-' . $id, ['application_id' => (string) $id, 'tier' => $input['tier']]);
    } catch (RuntimeException $error) {
        db()->prepare('DELETE FROM membership_applications WHERE id = ?')->execute([$id]);
        return [$input, ['form' => 'Online payment isn’t available right now, so your request wasn’t sent. Please try again in a few minutes.'], null];
    }
    db()->prepare('UPDATE membership_applications SET razorpay_order_id = ? WHERE id = ?')->execute([$order['id'], $id]);
    return [$input, [], membershipCheckoutOptions($order, $input)];
}

function membershipCheckoutOptions(array $order, array $input): array {
    $tier = MEMBERSHIP_TIERS[$input['tier']];
    $label = membershipPriceLabel($tier['price']);
    return ['key' => razorpayConfig()['key_id'], 'order_id' => $order['id'], 'amount' => (int) $order['amount'], 'currency' => 'INR', 'name' => 'Pro HPCL',
        'description' => $tier['name'] . ' membership · ' . $label, 'prefill' => ['name' => $input['contact_name'], 'email' => $input['email'], 'contact' => '+91' . $input['phone']],
        'theme' => ['color' => '#0b50a7'], 'amount_label' => $label];
}

/** Records the payment of a membership order (from Checkout or the webhook). */
function membershipPaymentReceived(string $orderId, string $paymentId, ?int $paise = null): bool {
    $app = query('SELECT id, amount, payment_status FROM membership_applications WHERE razorpay_order_id = ?', [$orderId])[0] ?? null;
    if (!$app) return false;
    if ($paise !== null && $paise !== (int) $app['amount'] * 100) {
        error_log("Razorpay payment $paymentId for membership application {$app['id']} was $paise paise, not {$app['amount']} rupees: not recorded");
        return false;
    }
    if ($app['payment_status'] !== 'paid') {
        db()->prepare("UPDATE membership_applications SET payment_status = 'paid', razorpay_payment_id = ?, paid_at = NOW() WHERE id = ? AND payment_status <> 'paid'")->execute([$paymentId, $app['id']]);
    }
    return true;
}

/** /membership/join/payment: Checkout's answer after paying, checked with its signature (JSON). */
function handleMembershipPayment(): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') errorPage(405);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $input = json_decode((string) file_get_contents('php://input'), true);
    $orderId = (string) ($input['razorpay_order_id'] ?? '');
    $paymentId = (string) ($input['razorpay_payment_id'] ?? '');
    $signature = (string) ($input['razorpay_signature'] ?? '');
    if (!razorpayValidSignature($orderId, $paymentId, $signature) || !membershipPaymentReceived($orderId, $paymentId)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'We couldn’t confirm your payment. If money was taken, don’t pay again: contact us with the payment ID ' . $paymentId . '.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => MEMBERSHIP_SENT_MESSAGE, 'payment_id' => $paymentId], JSON_UNESCAPED_UNICODE);
    exit;
}

function membershipJoinForm(array $input, array $errors, bool $sent): string {
    $error = fn (string $field) => '<div class="invalid-feedback" id="' . $field . '-error">' . (isset($errors[$field]) ? escape($errors[$field]) : '') . '</div>';
    $tiers = '';
    foreach (MEMBERSHIP_TIERS as $key => $tier) {
        $checked = ($input['tier'] ?? '') === $key ? ' checked' : '';
        $tiers .= '<label class="mshipjoin-tier"><input type="radio" name="tier" value="' . $key . '" data-msg-required="Choose a membership tier."' . $checked . ' required>'
            . '<span class="mshipjoin-tier__card"><span class="mshipjoin-tier__name">' . escape($tier['name']) . '</span>'
            . '<span class="mshipjoin-tier__price">' . membershipPriceLabel($tier['price']) . '<small>/yr</small></span>'
            . '<span class="mshipjoin-tier__disc">' . $tier['discount'] . '% off ground fees</span></span></label>' . "\n";
    }
    $summary = '';
    if (isset($errors['form'])) $summary = escape($errors['form']);
    elseif ($errors) $summary = 'Please correct the highlighted fields.';
    [$bMin, $bMax] = MEMBERSHIP_BUSINESS_LENGTH;
    [$cMin, $cMax] = MEMBERSHIP_CONTACT_LENGTH;
    [$mMin, $mMax] = MEMBERSHIP_MESSAGE_LENGTH;
    $payable = membershipPaymentRequired();
    $data = [
        'thanks_hidden' => $sent ? '' : ' hidden', 'form_hidden' => $sent ? ' hidden' : '',
        'summary' => $summary, 'summary_hidden' => $summary === '' ? ' hidden' : '',
        'tiers' => $tiers, 'business_min' => $bMin, 'business_max' => $bMax, 'contact_min' => $cMin, 'contact_max' => $cMax,
        'message_min' => $mMin, 'message_max' => $mMax, 'sent_message' => escape(MEMBERSHIP_SENT_MESSAGE),
        'payment_note' => $payable ? '<p class="mshipjoin-hint">After you submit, you’ll pay the tier’s annual fee securely with Razorpay (UPI, cards, net banking or wallets). Your membership is confirmed once payment is complete.</p>' : '',
        'submit_label' => $payable ? 'Submit and pay' : 'Submit membership request',
        'payment_attr' => $payable ? ' data-payment' : '',
        'payment_scripts' => $payable ? '<script src="https://checkout.razorpay.com/v1/checkout.js" defer></script><script src="{{BASE}}/assets/js/committee-payment.js?v=20261024" defer></script>' : '',
    ];
    foreach (MEMBERSHIP_JOIN_FIELDS as $field) {
        if ($field === 'tier') continue;
        $data[$field] = escape($input[$field] ?? '');
        $data[$field . '_invalid'] = isset($errors[$field]) ? ' is-invalid' : '';
        $data[$field . '_aria'] = isset($errors[$field]) ? ' aria-invalid="true"' : '';
        $data[$field . '_error'] = $error($field);
    }
    $data['tier_invalid'] = isset($errors['tier']) ? ' is-invalid' : '';
    $data['tier_error'] = $error('tier');
    return view('templates.join-membership', $data);
}
