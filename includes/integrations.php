<?php
declare(strict_types=1);

// Settings of the services the site connects to, edited by the root user at /admin/integrations
// (admin-integrations.php): outgoing mail over SMTP (mailer.php) and Razorpay payments
// (razorpay.php). They are rows of site_settings named smtp_* and razorpay_*.
//
// Passwords and secret keys are stored encrypted with APP_KEY from .env (AES-256-GCM), so a
// database export alone doesn't reveal them, and they are never shown again in the admin. Without
// APP_KEY they can't be saved: create one with php bin/generate-key.php.
require_once __DIR__ . '/site.php';

// name => [group, label, kind (text, host, port, choice, email, secret, toggle, razorpay_key), hint, choices].
const INTEGRATION_SETTINGS = [
    'smtp_enabled' => ['smtp', 'Send email', 'toggle', 'Turn on to send email from the site with the server below.'],
    'smtp_host' => ['smtp', 'SMTP server', 'host', 'For example smtp.hostinger.com or smtp.gmail.com.'],
    'smtp_port' => ['smtp', 'Port', 'port', 'Usually 465 for SSL or 587 for STARTTLS.'],
    'smtp_encryption' => ['smtp', 'Encryption', 'choice', 'Use SSL with port 465 and STARTTLS with port 587.', ['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => 'None (not recommended)']],
    'smtp_username' => ['smtp', 'Username', 'text', 'Usually the full mailbox address. Leave empty if the server needs no sign-in.'],
    'smtp_password' => ['smtp', 'Password', 'secret', 'The mailbox password, or an app password for Gmail.'],
    'smtp_from_address' => ['smtp', 'From address', 'email', 'The address email is sent from, such as contact@prohpcl.com. Many servers require the mailbox you sign in with.'],
    'smtp_from_name' => ['smtp', 'From name', 'text', 'The sender name people see, such as Pro HPCL.'],
    'razorpay_enabled' => ['razorpay', 'Accept payments', 'toggle', 'Turn on to take payments with Razorpay.'],
    'razorpay_mode' => ['razorpay', 'Mode', 'choice', 'Test mode takes no real money. Switch to live with your live keys when you are ready.', ['test' => 'Test', 'live' => 'Live']],
    'razorpay_key_id' => ['razorpay', 'Key ID', 'razorpay_key', 'From the Razorpay Dashboard → Account & Settings → API Keys. Starts with rzp_test_ or rzp_live_.'],
    'razorpay_key_secret' => ['razorpay', 'Key secret', 'secret', 'Shown once by Razorpay when you generate the key.'],
    'razorpay_webhook_secret' => ['razorpay', 'Webhook secret', 'secret', 'Optional. The secret you choose when adding a webhook in Razorpay, used to check that payment notifications are genuine.'],
];
const INTEGRATION_GROUPS = [
    'smtp' => ['Email (SMTP)', 'The mail server the site sends email through. Values left empty here fall back to the MAIL_ settings in .env.'],
    'razorpay' => ['Razorpay', 'Your Razorpay account’s API keys, for taking payments on the site.'],
];
// The MAIL_ variable of .env each SMTP setting falls back to.
const SMTP_ENV_FALLBACK = ['smtp_host' => 'MAIL_HOST', 'smtp_port' => 'MAIL_PORT', 'smtp_encryption' => 'MAIL_ENCRYPTION', 'smtp_username' => 'MAIL_USERNAME',
    'smtp_password' => 'MAIL_PASSWORD', 'smtp_from_address' => 'MAIL_FROM_ADDRESS', 'smtp_from_name' => 'MAIL_FROM_NAME'];

/** The 32-byte key from APP_KEY (base64, as bin/generate-key.php writes it), or null when it isn't set. */
function appKey(): ?string {
    $key = (string) getenv('APP_KEY');
    if ($key === '') return null;
    $decoded = base64_decode(preg_replace('~^base64:~', '', $key), true);
    return $decoded !== false && strlen($decoded) === 32 ? $decoded : hash('sha256', $key, true);
}
function encryptSecret(string $value): string {
    $key = appKey() ?? throw new RuntimeException('APP_KEY is not set in .env');
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
}
/** The plain value of a stored secret; '' when it can't be decrypted (APP_KEY missing or changed). */
function decryptSecret(string $stored): string {
    if (!str_starts_with($stored, 'enc:v1:')) return $stored;
    $raw = base64_decode(substr($stored, 7), true);
    $key = appKey();
    if ($raw === false || strlen($raw) < 29 || !$key) return '';
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

/** The stored integration settings, with secrets still encrypted. */
function integrationRows(): array {
    return array_column(query("SELECT name, value FROM site_settings WHERE name LIKE 'smtp\\_%' OR name LIKE 'razorpay\\_%'"), 'value', 'name');
}
/** One integration setting as plain text (secrets decrypted), or $default when it isn't set. */
function integrationSetting(string $name, string $default = ''): string {
    static $rows = null;
    $rows ??= integrationRows();
    $value = (string) ($rows[$name] ?? '');
    if ($value !== '' && (INTEGRATION_SETTINGS[$name][2] ?? '') === 'secret') $value = decryptSecret($value);
    return $value !== '' ? $value : $default;
}

/** The SMTP connection: host, port, encryption, username, password, from_address, from_name, enabled. */
function smtpConfig(): array {
    $config = ['enabled' => integrationSetting('smtp_enabled') === '1'];
    foreach (SMTP_ENV_FALLBACK as $name => $env) $config[substr($name, 5)] = integrationSetting($name, (string) getenv($env));
    $config['port'] = (int) ($config['port'] ?: ($config['encryption'] === 'ssl' ? 465 : 587));
    $config['encryption'] = in_array($config['encryption'], ['ssl', 'tls', 'none'], true) ? $config['encryption'] : 'ssl';
    $config['from_name'] = $config['from_name'] ?: 'Pro HPCL';
    return $config;
}
/** The Razorpay account: enabled, mode, key_id, key_secret, webhook_secret. */
function razorpayConfig(): array {
    return ['enabled' => integrationSetting('razorpay_enabled') === '1', 'mode' => integrationSetting('razorpay_mode', 'test'),
        'key_id' => integrationSetting('razorpay_key_id'), 'key_secret' => integrationSetting('razorpay_key_secret'),
        'webhook_secret' => integrationSetting('razorpay_webhook_secret')];
}
