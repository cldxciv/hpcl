<?php
declare(strict_types=1);

// Razorpay's API with the keys set at /admin/integrations (razorpayConfig() in integrations.php).
// A payment is an order created here (razorpayCreateOrder()), paid in the browser with Razorpay
// Checkout, then confirmed with the signature Checkout returns (razorpayValidSignature()). The
// webhook (/razorpay/webhook) confirms it too, for payers who close the page before that.
require_once __DIR__ . '/integrations.php';

const RAZORPAY_API = 'https://api.razorpay.com/v1';
const RAZORPAY_KEY_PATTERN = '~^rzp_(test|live)_[A-Za-z0-9]{8,32}$~';

/** Calls the API with the account's keys: [HTTP status, decoded JSON or null]. Throws when Razorpay can't be reached. */
function razorpayRequest(string $method, string $path, ?array $body = null, ?array $config = null): array {
    $config ??= razorpayConfig();
    $curl = curl_init(RAZORPAY_API . $path);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERPWD => $config['key_id'] . ':' . $config['key_secret'], CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json']]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) throw new RuntimeException("Couldn’t reach Razorpay ($error).");
    return [$status, json_decode((string) $response, true)];
}

/** Checks the keys by listing one payment: null when they work, otherwise the reason. */
function razorpayCheckKeys(?array $config = null): ?string {
    $config ??= razorpayConfig();
    if ($config['key_id'] === '' || $config['key_secret'] === '') return 'Enter and save the key ID and key secret first.';
    try {
        [$status, $data] = razorpayRequest('GET', '/payments?count=1', null, $config);
    } catch (RuntimeException $error) {
        return $error->getMessage();
    }
    if ($status === 200) return null;
    if ($status === 401) return 'Razorpay didn’t accept these keys. Check the key ID and secret, and that they belong to the same mode (test or live).';
    return 'Razorpay answered ' . $status . ($data['error']['description'] ?? null ? ': ' . $data['error']['description'] : '.');
}

/** Whether payments can be taken: turned on, with both keys. */
function razorpayReady(): bool {
    $config = razorpayConfig();
    return $config['enabled'] && $config['key_id'] !== '' && $config['key_secret'] !== '';
}
/** Creates an order of $rupees (INR): the order as Razorpay returns it (id, amount in paise…). Throws when it fails. */
function razorpayCreateOrder(int $rupees, string $receipt, array $notes = []): array {
    [$status, $order] = razorpayRequest('POST', '/orders', ['amount' => $rupees * 100, 'currency' => 'INR', 'receipt' => substr($receipt, 0, 40), 'notes' => $notes]);
    if ($status !== 200 || !is_string($order['id'] ?? null)) {
        error_log('Razorpay order failed (' . $status . '): ' . json_encode($order));
        throw new RuntimeException('The payment could not be started.');
    }
    return $order;
}
/** Whether Checkout's razorpay_signature proves that $paymentId paid $orderId. */
function razorpayValidSignature(string $orderId, string $paymentId, string $signature): bool {
    $secret = razorpayConfig()['key_secret'];
    return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
}

/**
 * Razorpay's notifications (Dashboard → Webhooks → URL https://…/razorpay/webhook, events
 * payment.captured and order.paid, with the webhook secret saved at Integrations). A paid order
 * is passed on to what it is for: committee applications (committee.php).
 */
function handleRazorpayWebhook(): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') errorPage(405);
    $body = (string) file_get_contents('php://input');
    $secret = razorpayConfig()['webhook_secret'];
    $signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
    if ($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
        error_log('Razorpay webhook refused: ' . ($secret === '' ? 'no webhook secret is saved' : 'the signature is wrong'));
        http_response_code(400);
        exit;
    }
    $event = json_decode($body, true);
    $payment = $event['payload']['payment']['entity'] ?? null;
    if (in_array($event['event'] ?? '', ['payment.captured', 'order.paid'], true) && is_string($payment['order_id'] ?? null) && is_string($payment['id'] ?? null)) {
        require_once __DIR__ . '/committee.php';
        committeePaymentReceived($payment['order_id'], $payment['id'], (int) ($payment['amount'] ?? 0));
    }
    // Razorpay retries anything but a 2xx answer, so other events are acknowledged too.
    http_response_code(200);
    exit;
}
