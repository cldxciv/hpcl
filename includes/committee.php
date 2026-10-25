<?php
declare(strict_types=1);

// The committee member application form (/join-committee): name, email, phone, the Aadhaar card's
// front and back, a plan and why they want to join. Applications are stored in
// committee_applications and reviewed in the admin (admin-committee.php).
//
// The form is sent with AJAX by assets/js/forms.js, which checks the fields in the browser first
// and gets JSON back (formResponse()); without JavaScript it is an ordinary form. The rules below
// are the ones that count: the browser's checks are only a convenience.
//
// Aadhaar cards are identity documents: they are saved to private/committee/, which the web
// server never serves (.htaccess, router.php), and are shown only to signed-in admins.
//
// Payment is turned on and off at Admin → Committee (committee_payment_enabled) and needs
// Razorpay (Admin → Integrations). When it is on, the application is saved as unpaid with a
// Razorpay order for the plan, the browser opens Razorpay Checkout, and the payment is confirmed
// at /join-committee/payment (or by the webhook, razorpay.php).
require_once __DIR__ . '/admin-forms.php';
require_once __DIR__ . '/razorpay.php';

const COMMITTEE_PLANS = [5000, 10000, 15000];
const COMMITTEE_STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
const COMMITTEE_DOCUMENT_DIR = 'private/committee';
const COMMITTEE_DOCUMENT_MAX_BYTES = 1024 * 1024;
const COMMITTEE_DOCUMENT_MAX_LABEL = '1 MB';
const COMMITTEE_DOCUMENT_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
// A photo of the card smaller than this can't be read.
const COMMITTEE_DOCUMENT_MIN_PIXELS = [300, 180];
const COMMITTEE_NAME_LENGTH = [3, 100];
// Letters of any script, spaces, and . ' - between them (as in "K. Singh", "D'Souza").
const COMMITTEE_NAME_PATTERN = "~^\\p{L}[\\p{L}\\p{M} .'-]*$~u";
const COMMITTEE_MESSAGE_LENGTH = [20, 2000];
// Applications allowed from one IP address per hour, against spam.
const COMMITTEE_MAX_PER_HOUR = 5;
const COMMITTEE_FIELDS = ['name', 'email', 'phone', 'plan', 'message', 'aadhaar_front', 'aadhaar_back'];
const COMMITTEE_SENT_MESSAGE = 'Thank you! We have received your application and will contact you soon.';
const COMMITTEE_PAYMENT_SETTING = 'committee_payment_enabled';
const COMMITTEE_PAYMENT_STATUSES = ['not_required' => 'No payment', 'unpaid' => 'Awaiting payment', 'paid' => 'Paid'];

/** Whether payment is turned on for the form (Admin → Committee), whether or not Razorpay is ready. */
function committeePaymentSwitchedOn(): bool {
    return (query('SELECT value FROM site_settings WHERE name = ?', [COMMITTEE_PAYMENT_SETTING])[0]['value'] ?? '') === '1';
}
/** Whether applicants pay their plan: turned on, and Razorpay is set up. */
function committeePaymentRequired(): bool {
    return committeePaymentSwitchedOn() && razorpayReady();
}

function committeePlanLabel(int|string $plan): string {
    return '₹' . number_format((int) $plan);
}
/** A phone number as 10 digits (spaces, dashes and a +91 or 0 prefix removed), or null if it isn't an Indian mobile number. */
function committeePhone(string $phone): ?string {
    $digits = preg_replace('~[\s().-]+~', '', $phone);
    return preg_match('~^(?:\+?91|0)?([6-9]\d{9})$~', $digits, $match) ? $match[1] : null;
}
/** Text with runs of spaces made one and control characters removed; $multiline keeps line breaks. */
function committeeCleanText(string $text, bool $multiline = false): string {
    $text = preg_replace($multiline ? '~[^\P{C}\n]+~u' : '~\p{C}+~u', '', $text) ?? '';
    if (!$multiline) return trim(preg_replace('~\s+~u', ' ', $text));
    // Spaces around line breaks are dropped, and more than one empty line in a row becomes one.
    return trim(preg_replace(["~[ \t]+~", "~ ?\n ?~", "~\n{3,}~"], [' ', "\n", "\n\n"], $text));
}

/**
 * Checks the Aadhaar side uploaded in $field: [temporary file, extension], or an error message.
 * Like uploadedImage(), the type is read from the content; PDFs are accepted too.
 */
function uploadedAadhaar(string $field, string $label): array|string {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) return "Choose a photo or scan of the $label.";
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > COMMITTEE_DOCUMENT_MAX_BYTES) return "The $label must be " . COMMITTEE_DOCUMENT_MAX_LABEL . " or smaller.";
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return "The $label could not be uploaded. Please try again.";
    if ($file['size'] === 0) return "The $label file is empty. Choose another one.";
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(COMMITTEE_DOCUMENT_TYPES[$type])) return "The $label must be a JPEG, PNG or WebP photo, or a PDF.";
    if ($type === 'application/pdf') {
        if (!str_contains((string) file_get_contents($file['tmp_name'], false, null, 0, 1024), '%PDF-')) return "The $label isn’t a readable PDF.";
    } else {
        $size = @getimagesize($file['tmp_name']);
        if (!$size) return "The $label isn’t a readable image.";
        [$minLong, $minShort] = COMMITTEE_DOCUMENT_MIN_PIXELS;
        if (max($size[0], $size[1]) < $minLong || min($size[0], $size[1]) < $minShort) return "The photo of the $label is too small to read. Upload a clearer photo, at least $minLong × $minShort pixels.";
    }
    return [$file['tmp_name'], COMMITTEE_DOCUMENT_TYPES[$type]];
}
function committeeDocumentPath(string $stored): string {
    return dirname(__DIR__) . '/' . $stored;
}

/** GET shows the form (or the thank-you message after ?sent=1), POST saves an application. */
function handleCommitteeRequest(): never {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) errorPage(405);
    if ($method !== 'POST') {
        header('Content-Type: text/html; charset=UTF-8');
        if ($method === 'GET') echo render(committeeForm(array_fill_keys(COMMITTEE_FIELDS, ''), [], ($_GET['sent'] ?? '') === '1'));
        exit;
    }
    [$input, $errors, $payment] = saveCommitteeApplication();
    formResponse($errors, $errors ? 'Please correct the highlighted fields.' : ($payment ? 'Your application is saved. Complete the payment to send it.' : COMMITTEE_SENT_MESSAGE),
        '/join-committee?sent=1', fn () => committeeForm($input, $errors, false), $payment ? ['payment' => $payment] : []);
}

/** Checks the posted fields: the cleaned values (phone as 10 digits), the checked uploads and the errors by field. */
function validateCommitteeApplication(): array {
    $input = ['name' => committeeCleanText(postedText('name')), 'email' => strtolower(postedText('email')), 'phone' => postedText('phone'),
        'plan' => postedText('plan'), 'message' => committeeCleanText(postedText('message'), true)];
    $errors = [];
    [$min, $max] = COMMITTEE_NAME_LENGTH;
    $nameLength = mb_strlen($input['name']);
    if ($nameLength === 0) $errors['name'] = 'Enter your full name.';
    elseif ($nameLength < $min || $nameLength > $max) $errors['name'] = "Your name must be $min to $max characters long.";
    elseif (!preg_match(COMMITTEE_NAME_PATTERN, $input['name'])) $errors['name'] = 'Use only letters, spaces, dots, hyphens and apostrophes in your name.';

    if ($input['email'] === '') $errors['email'] = 'Enter your email address.';
    elseif (strlen($input['email']) > 254 || !filter_var($input['email'], FILTER_VALIDATE_EMAIL) || !preg_match('~@[^@]+\.[a-z]{2,}$~i', $input['email'])) $errors['email'] = 'Enter a valid email address, like name@example.com.';

    $phone = committeePhone($input['phone']);
    if ($input['phone'] === '') $errors['phone'] = 'Enter your mobile number.';
    elseif ($phone === null) $errors['phone'] = 'Enter a valid 10-digit Indian mobile number, like 98160 12345.';
    else $input['phone'] = $phone;

    if (!ctype_digit($input['plan']) || !in_array((int) $input['plan'], COMMITTEE_PLANS, true)) $errors['plan'] = 'Choose a membership plan.';

    [$min, $max] = COMMITTEE_MESSAGE_LENGTH;
    $messageLength = mb_strlen($input['message']);
    if ($messageLength === 0) $errors['message'] = 'Tell us why you want to become a member.';
    elseif ($messageLength < $min) $errors['message'] = "Write at least $min characters (you have $messageLength).";
    elseif ($messageLength > $max) $errors['message'] = 'Keep your message to ' . number_format($max) . ' characters or fewer.';

    $uploads = [];
    foreach (['aadhaar_front' => 'front of your Aadhaar card', 'aadhaar_back' => 'back of your Aadhaar card'] as $field => $label) {
        $uploads[$field] = uploadedAadhaar($field, $label);
        if (is_string($uploads[$field])) $errors[$field] = $uploads[$field];
    }
    if (!isset($errors['aadhaar_front'], $errors['aadhaar_back']) && is_array($uploads['aadhaar_front']) && is_array($uploads['aadhaar_back'])
        && hash_file('sha256', $uploads['aadhaar_front'][0]) === hash_file('sha256', $uploads['aadhaar_back'][0])) {
        $errors['aadhaar_back'] = 'You chose the same file for the front and the back. Upload a photo of the back of the card.';
    }
    return [$input, $uploads, $errors];
}

/**
 * Validates and stores an application: [the posted values, the errors (none when it was saved),
 * the Razorpay Checkout options when it must be paid, or null].
 */
function saveCommitteeApplication(): array {
    // A request larger than post_max_size arrives with no fields at all.
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        return [array_fill_keys(COMMITTEE_FIELDS, ''), ['form' => 'Your files are too large to send. Upload photos of ' . COMMITTEE_DOCUMENT_MAX_LABEL . ' or smaller each.'], null];
    }
    // Bots fill in the hidden "website" field; they are told it was sent, and nothing is saved.
    if (postedText('website') !== '') return [[], [], null];
    [$input, $uploads, $errors] = validateCommitteeApplication();
    if ($errors) return [$input, $errors, null];
    $payable = committeePaymentRequired();
    // Razorpay Checkout runs in the browser, so paying needs the AJAX form.
    if ($payable && !wantsJson()) return [$input, ['form' => 'Paying for your membership needs JavaScript. Turn it on in your browser and try again.'], null];

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ((int) query('SELECT COUNT(*) AS n FROM committee_applications WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$ip])[0]['n'] >= COMMITTEE_MAX_PER_HOUR) {
        return [$input, ['form' => 'Too many applications have been sent from your connection. Please try again in an hour.'], null];
    }
    // An application that was never paid doesn't count: its sender may apply again.
    $pending = query("SELECT email, phone FROM committee_applications WHERE status = 'pending' AND payment_status <> 'unpaid' AND (email = ? OR phone = ?) LIMIT 1", [$input['email'], $input['phone']])[0] ?? null;
    if ($pending) {
        $field = $pending['email'] === $input['email'] ? 'email' : 'phone';
        return [$input, [$field => 'An application with this ' . ($field === 'email' ? 'email address' : 'mobile number') . ' is already waiting for review. We will contact you soon.'], null];
    }

    $saved = [];
    foreach ($uploads as $field => $upload) {
        $path = saveUploadedImage($upload, COMMITTEE_DOCUMENT_DIR);
        if (!$path) {
            foreach ($saved as $file) @unlink(committeeDocumentPath($file));
            return [$input, ['form' => 'Your documents could not be saved. Please try again later.'], null];
        }
        $saved[$field] = $path;
    }
    $plan = (int) $input['plan'];
    try {
        db()->prepare('INSERT INTO committee_applications (name, email, phone, plan, message, aadhaar_front, aadhaar_back, payment_status, amount, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$input['name'], $input['email'], $input['phone'], $plan, $input['message'], $saved['aadhaar_front'], $saved['aadhaar_back'],
                $payable ? 'unpaid' : 'not_required', $payable ? $plan : null, $ip]);
        $id = (int) db()->lastInsertId();
    } catch (Throwable $error) {
        foreach ($saved as $file) @unlink(committeeDocumentPath($file));
        throw $error;
    }
    if (!$payable) return [$input, [], null];

    try {
        $order = razorpayCreateOrder($plan, 'committee-' . $id, ['application_id' => (string) $id, 'plan' => (string) $plan]);
    } catch (RuntimeException $error) {
        // Without an order nothing can be paid: the application is removed, so it can be sent again.
        db()->prepare('DELETE FROM committee_applications WHERE id = ?')->execute([$id]);
        foreach ($saved as $file) @unlink(committeeDocumentPath($file));
        return [$input, ['form' => 'Online payment isn’t available right now, so your application wasn’t sent. Please try again in a few minutes.'], null];
    }
    db()->prepare('UPDATE committee_applications SET razorpay_order_id = ? WHERE id = ?')->execute([$order['id'], $id]);
    return [$input, [], committeeCheckoutOptions($order, $input, $plan)];
}

/** The options of Razorpay Checkout (checkout.js) for an application's order. */
function committeeCheckoutOptions(array $order, array $input, int $plan): array {
    return ['key' => razorpayConfig()['key_id'], 'order_id' => $order['id'], 'amount' => (int) $order['amount'], 'currency' => 'INR', 'name' => 'Pro HPCL',
        'description' => 'Committee membership · ' . committeePlanLabel($plan), 'prefill' => ['name' => $input['name'], 'email' => $input['email'], 'contact' => '+91' . $input['phone']],
        'theme' => ['color' => '#0b50a7'], 'amount_label' => committeePlanLabel($plan)];
}

/**
 * Records the payment of an application's order, from Checkout or the webhook. Returns whether
 * the order belongs to an application (paid now or before). An amount that doesn't match the
 * plan is logged and not recorded.
 */
function committeePaymentReceived(string $orderId, string $paymentId, ?int $paise = null): bool {
    $application = query('SELECT id, amount, payment_status FROM committee_applications WHERE razorpay_order_id = ?', [$orderId])[0] ?? null;
    if (!$application) return false;
    if ($paise !== null && $paise !== (int) $application['amount'] * 100) {
        error_log("Razorpay payment $paymentId for committee application {$application['id']} was $paise paise, not {$application['amount']} rupees: not recorded");
        return false;
    }
    if ($application['payment_status'] !== 'paid') {
        db()->prepare("UPDATE committee_applications SET payment_status = 'paid', razorpay_payment_id = ?, paid_at = NOW() WHERE id = ? AND payment_status <> 'paid'")->execute([$paymentId, $application['id']]);
    }
    return true;
}

/** /join-committee/payment: Checkout's answer after paying, checked with its signature (JSON). */
function handleCommitteePayment(): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') errorPage(405);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    [$orderId, $paymentId, $signature] = [postedText('razorpay_order_id'), postedText('razorpay_payment_id'), postedText('razorpay_signature')];
    $valid = preg_match('~^order_\w{6,40}$~', $orderId) && preg_match('~^pay_\w{6,40}$~', $paymentId) && razorpayValidSignature($orderId, $paymentId, $signature);
    if (!$valid || !committeePaymentReceived($orderId, $paymentId)) {
        error_log("Committee payment refused: order $orderId, payment $paymentId (" . ($valid ? 'unknown order' : 'invalid signature') . ')');
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'We couldn’t confirm your payment. If money was taken, don’t pay again: contact us with the payment ID ' . $paymentId . '.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => COMMITTEE_SENT_MESSAGE, 'payment_id' => $paymentId], JSON_UNESCAPED_UNICODE);
    exit;
}

function committeeForm(array $input, array $errors, bool $sent): string {
    $error = fn (string $field) => '<div class="invalid-feedback" id="' . $field . '-error">' . (isset($errors[$field]) ? escape($errors[$field]) : '') . '</div>';
    $plans = implode("\n", array_map(fn ($plan) => '<label class="committee-plan"><input type="radio" name="plan" aria-describedby="plan-error" data-msg-required="Choose a membership plan." value="' . $plan . '"' . (($input['plan'] ?? '') === (string) $plan ? ' checked' : '') . ' required>'
        . '<span class="committee-plan__card"><span class="committee-plan__amount">' . committeePlanLabel($plan) . '</span><span class="committee-plan__label">Membership</span></span></label>', COMMITTEE_PLANS));
    $summary = '';
    if (isset($errors['form'])) $summary = escape($errors['form']);
    elseif ($errors) $summary = 'Please correct the highlighted fields'
        // Chosen files are not kept when the form is shown again.
        . (array_diff(['aadhaar_front', 'aadhaar_back'], array_keys($errors)) ? ', and choose your Aadhaar card photos again' : '') . '.';
    [$nameMin, $nameMax] = COMMITTEE_NAME_LENGTH;
    [$messageMin, $messageMax] = COMMITTEE_MESSAGE_LENGTH;
    $data = ['thanks_hidden' => $sent ? '' : ' hidden', 'form_hidden' => $sent ? ' hidden' : '', 'summary' => $summary, 'summary_hidden' => $summary === '' ? ' hidden' : '',
        'plans' => $plans, 'name_min' => $nameMin, 'name_max' => $nameMax, 'message_min' => $messageMin, 'message_max' => $messageMax,
        'max_bytes' => COMMITTEE_DOCUMENT_MAX_BYTES, 'max_label' => COMMITTEE_DOCUMENT_MAX_LABEL, 'sent_message' => escape(COMMITTEE_SENT_MESSAGE)];
    $payable = committeePaymentRequired();
    $data += ['payment_note' => $payable ? '<p class="committee-hint committee-payment-note">After you submit, you’ll pay the plan’s amount securely with Razorpay (UPI, cards, net banking or wallets). Your application is sent once the payment is complete.</p>' : '',
        'submit_label' => $payable ? 'Submit and pay' : 'Submit application',
        'payment_attr' => $payable ? ' data-payment' : '',
        'payment_scripts' => $payable ? '<script src="https://checkout.razorpay.com/v1/checkout.js" defer></script><script src="{{BASE}}/assets/js/committee-payment.js?v=20261024" defer></script>' : ''];
    foreach (COMMITTEE_FIELDS as $field) {
        $data[$field] = escape($input[$field] ?? '');
        $data[$field . '_invalid'] = isset($errors[$field]) ? ' is-invalid' : '';
        $data[$field . '_aria'] = isset($errors[$field]) ? ' aria-invalid="true"' : '';
        $data[$field . '_error'] = $error($field);
    }
    return view('templates.join-committee', $data);
}
