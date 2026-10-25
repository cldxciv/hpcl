<?php
declare(strict_types=1);

// WhatsApp membership agent (phase 2). Receives messages from the WhatsApp Business Cloud API
// (Meta) at /whatsapp/webhook and answers them with the same sales brain as the on-site agent
// (includes/agent.php), then replies over WhatsApp. Conversations are stored on the 'whatsapp'
// channel so leads from both agents sit together in agent_conversations.
//
// This cannot run purely local: Meta must reach a PUBLIC https webhook, and you need a WhatsApp
// Business account + phone number. Fill these in .env when ready:
//   WHATSAPP_TOKEN            permanent access token (Bearer)
//   WHATSAPP_PHONE_NUMBER_ID  the sending number's phone_number_id
//   WHATSAPP_VERIFY_TOKEN     any secret string; set the same value in Meta's webhook config
//   WHATSAPP_APP_SECRET       (optional) app secret, to verify request signatures
//   WHATSAPP_API_VERSION      (optional) Graph API version, default v21.0
require_once __DIR__ . '/agent.php';

function whatsappConfig(): array {
    return [
        'token' => trim((string) getenv('WHATSAPP_TOKEN')),
        'phone_number_id' => trim((string) getenv('WHATSAPP_PHONE_NUMBER_ID')),
        'verify_token' => trim((string) getenv('WHATSAPP_VERIFY_TOKEN')),
        'app_secret' => trim((string) getenv('WHATSAPP_APP_SECRET')),
        'version' => getenv('WHATSAPP_API_VERSION') ?: 'v21.0',
    ];
}
function whatsappReady(): bool {
    $c = whatsappConfig();
    return $c['token'] !== '' && $c['phone_number_id'] !== '';
}

/** Sends a plain text WhatsApp message. Returns true on success. */
function whatsappSend(string $to, string $text): bool {
    $c = whatsappConfig();
    if (!whatsappReady()) { error_log('WhatsApp not configured; cannot send.'); return false; }
    $url = "https://graph.facebook.com/{$c['version']}/{$c['phone_number_id']}/messages";
    $body = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to,
        'type' => 'text', 'text' => ['preview_url' => true, 'body' => $text]];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['token']],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($status < 200 || $status >= 300) { error_log("WhatsApp send $status: " . substr((string) $response, 0, 300)); return false; }
    return true;
}

/** Verifies Meta's X-Hub-Signature-256 against the raw body, when an app secret is configured. */
function whatsappSignatureValid(string $rawBody): bool {
    $secret = whatsappConfig()['app_secret'];
    if ($secret === '') return true; // not enforced unless a secret is set
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (!str_starts_with($sig, 'sha256=')) return false;
    return hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $secret), $sig);
}

/** Extracts [from => text] pairs for incoming text messages from a webhook payload. */
function whatsappIncomingTexts(array $payload): array {
    $out = [];
    foreach ($payload['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            foreach ($change['value']['messages'] ?? [] as $msg) {
                if (($msg['type'] ?? '') === 'text' && isset($msg['from'], $msg['text']['body'])) {
                    $out[] = ['from' => (string) $msg['from'], 'text' => (string) $msg['text']['body']];
                }
            }
        }
    }
    return $out;
}

/** /whatsapp/webhook: GET verifies the webhook, POST handles incoming messages. */
function handleWhatsappWebhook(): never {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Webhook verification handshake (Meta → GET with hub.* query params).
    if ($method === 'GET') {
        $mode = $_GET['hub_mode'] ?? ($_GET['hub.mode'] ?? '');
        $token = $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? '');
        $challenge = $_GET['hub_challenge'] ?? ($_GET['hub.challenge'] ?? '');
        $expected = whatsappConfig()['verify_token'];
        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, (string) $token)) {
            header('Content-Type: text/plain'); echo $challenge; exit;
        }
        http_response_code(403); echo 'Forbidden'; exit;
    }

    if ($method !== 'POST') { http_response_code(405); exit; }

    $raw = (string) file_get_contents('php://input');
    if (!whatsappSignatureValid($raw)) { http_response_code(401); echo 'Bad signature'; exit; }
    $payload = json_decode($raw, true);

    // Acknowledge immediately so Meta doesn't retry; then process.
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'OK';
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    if (!is_array($payload)) exit;
    foreach (whatsappIncomingTexts($payload) as $m) {
        try {
            $conversation = agentConversation($m['from'], null, 'whatsapp', $m['from']);
            $reply = agentReply($conversation, trim($m['text']), 'whatsapp');
            whatsappSend($m['from'], $reply);
        } catch (Throwable $e) {
            error_log('WhatsApp handling error: ' . $e->getMessage());
        }
    }
    exit;
}
