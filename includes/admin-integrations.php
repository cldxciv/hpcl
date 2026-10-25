<?php
declare(strict_types=1);

// Admin integrations page (/admin/integrations; root user only): the SMTP server for outgoing
// email and the Razorpay keys (integrations.php), with a test email and a check of the keys.
// Secrets are write-only: the form never shows them, an empty field keeps the saved one.
require_once __DIR__ . '/admin-forms.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/razorpay.php';

/** The error for an integration setting's value, or null when it is fine. An empty value is always fine. */
function integrationError(string $name, string $value): ?string {
    [, $label, $kind] = INTEGRATION_SETTINGS[$name];
    if ($value === '' || $kind === 'toggle') return null;
    if (str_contains($value, "\n")) return "$label must be a single line.";
    if (mb_strlen($value) > 255) return "$label must be 255 characters or fewer.";
    return match ($kind) {
        'host' => preg_match('~^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$~i', $value) ? null : 'Enter a server name such as smtp.hostinger.com, without https:// or a port.',
        'port' => ctype_digit($value) && (int) $value >= 1 && (int) $value <= 65535 ? null : 'Enter a port number from 1 to 65535, usually 465 or 587.',
        'choice' => isset(INTEGRATION_SETTINGS[$name][4][$value]) ? null : 'Choose one of the options.',
        'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Enter an email address, such as contact@prohpcl.com.',
        'razorpay_key' => preg_match(RAZORPAY_KEY_PATTERN, $value) ? null : 'A key ID looks like rzp_test_AbC123xYz45678 or rzp_live_…',
        'secret' => preg_match('~^\S+$~', $value) ? null : "$label can’t contain spaces.",
        default => null,
    };
}

/** Saves the posted settings; returns [the values to show again, errors] when they aren't valid. */
function saveIntegrations(array $stored): array {
    $values = [];
    $errors = [];
    $changes = [];
    foreach (INTEGRATION_SETTINGS as $name => [, $label, $kind]) {
        if ($kind === 'toggle') $values[$name] = ($_POST[$name] ?? '') === '1' ? '1' : '';
        elseif ($kind === 'secret') {
            $posted = postedText($name);
            if (($_POST['remove_' . $name] ?? '') === '1') $changes[$name] = '';
            elseif ($posted !== '') {
                if (!appKey()) $errors[$name] = 'Secrets can’t be saved until APP_KEY is set in .env. Run php bin/generate-key.php on the server.';
                $changes[$name] = $posted;
            }
            // The secret in effect after saving, for the checks below; it is never shown again.
            $values[$name] = array_key_exists($name, $changes) ? $changes[$name] : (($stored[$name] ?? '') !== '' ? '(saved)' : '');
            if (($changes[$name] ?? '') !== '' && $error = integrationError($name, $changes[$name])) $errors[$name] = $error;
            continue;
        } else $values[$name] = postedText($name);
        // A choice always has a value: the first option when none was sent.
        if ($kind === 'choice' && $values[$name] === '') $values[$name] = (string) array_key_first(INTEGRATION_SETTINGS[$name][4]);
        if ($error = integrationError($name, $values[$name])) $errors[$name] = $error;
        $changes[$name] = $values[$name];
    }

    // Values left empty fall back to .env, so a field is only missing when both are empty.
    $effective = fn (string $name) => $values[$name] !== '' ? $values[$name] : (string) getenv(SMTP_ENV_FALLBACK[$name] ?? '');
    if ($values['smtp_enabled'] === '1') {
        if ($effective('smtp_host') === '') $errors['smtp_host'] ??= 'Enter the SMTP server to send email.';
        if ($effective('smtp_from_address') === '') $errors['smtp_from_address'] ??= 'Enter the address email is sent from.';
        if ($effective('smtp_username') !== '' && $effective('smtp_password') === '') $errors['smtp_password'] ??= 'Enter the password for this username.';
    }
    if ($values['razorpay_key_id'] !== '' && !isset($errors['razorpay_key_id']) && !str_starts_with($values['razorpay_key_id'], 'rzp_' . $values['razorpay_mode'] . '_')) {
        $errors['razorpay_key_id'] = 'This is a ' . (str_starts_with($values['razorpay_key_id'], 'rzp_live_') ? 'live' : 'test') . ' key, but the mode is ' . $values['razorpay_mode'] . '.';
    }
    if ($values['razorpay_enabled'] === '1') {
        if ($values['razorpay_key_id'] === '') $errors['razorpay_key_id'] ??= 'Enter the key ID to take payments.';
        if ($values['razorpay_key_secret'] === '') $errors['razorpay_key_secret'] ??= 'Enter the key secret to take payments.';
    }
    if ($errors) return [$values, $errors];

    adminTransaction(function () use ($changes) {
        foreach ($changes as $name => $value) {
            if ($value === '') adminExecute('DELETE FROM site_settings WHERE name = ?', [$name]);
            else adminExecute('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$name, INTEGRATION_SETTINGS[$name][2] === 'secret' ? encryptSecret($value) : $value]);
        }
    });
    adminFlash('Saved the integrations.');
    adminRedirect('/admin/integrations');
}

function renderIntegrations(array $admin): string {
    $stored = integrationRows();
    $values = array_map(fn ($name) => INTEGRATION_SETTINGS[$name][2] === 'secret' ? '' : (string) ($stored[$name] ?? ''), array_combine(array_keys(INTEGRATION_SETTINGS), array_keys(INTEGRATION_SETTINGS)));
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') [$values, $errors] = saveIntegrations($stored);

    $groups = [];
    foreach (INTEGRATION_SETTINGS as $name => [$group, $label, $kind, $hint]) {
        $value = $kind === 'secret' ? '' : escape($values[$name]);
        $attributes = ' id="' . $name . '" name="' . $name . '"' . fieldInvalid($errors, $name);
        $fallback = isset(SMTP_ENV_FALLBACK[$name]) && $kind !== 'secret' && getenv(SMTP_ENV_FALLBACK[$name]) ? ' placeholder="' . escape((string) getenv(SMTP_ENV_FALLBACK[$name])) . '"' : '';
        $saved = $kind === 'secret' && ($stored[$name] ?? '') !== '';
        $input = match ($kind) {
            'toggle' => '<label class="admin-check"><input' . $attributes . ' type="checkbox" value="1"' . ($values[$name] === '1' ? ' checked' : '') . '>' . escape($hint) . '</label>',
            'choice' => '<select' . $attributes . '>' . implode('', array_map(fn ($key, $text) => '<option value="' . $key . '"' . ($values[$name] === (string) $key || ($values[$name] === '' && $key === array_key_first(INTEGRATION_SETTINGS[$name][4])) ? ' selected' : '') . '>' . escape($text) . '</option>',
                array_keys(INTEGRATION_SETTINGS[$name][4]), INTEGRATION_SETTINGS[$name][4])) . '</select>',
            'secret' => '<input' . $attributes . ' type="password" value="" maxlength="255" autocomplete="new-password" spellcheck="false"' . ($saved ? ' placeholder="•••••••• saved"' : '') . '>',
            'port' => '<input' . $attributes . ' type="number" min="1" max="65535" value="' . $value . '"' . $fallback . '>',
            'email' => '<input' . $attributes . ' type="email" value="' . $value . '" maxlength="255" autocomplete="off"' . $fallback . '>',
            default => '<input' . $attributes . ' type="text" value="' . $value . '" maxlength="255" autocomplete="off" spellcheck="false"' . $fallback . '>',
        };
        $note = match (true) {
            $kind === 'toggle' => '',
            $saved => '<p class="admin-hint">' . escape($hint) . ' A value is saved; leave the field empty to keep it.</p>'
                . '<label class="admin-check"><input type="checkbox" name="remove_' . $name . '" value="1">Remove the saved ' . escape(strtolower($label)) . '</label>',
            $kind === 'secret' && isset(SMTP_ENV_FALLBACK[$name]) && getenv(SMTP_ENV_FALLBACK[$name]) => '<p class="admin-hint">' . escape($hint) . ' Not set here: the password in .env (' . SMTP_ENV_FALLBACK[$name] . ') is used.</p>',
            default => '<p class="admin-hint">' . escape($hint) . '</p>',
        };
        $groups[$group] = ($groups[$group] ?? '') . '<div class="admin-field">' . ($kind === 'toggle' ? '<p class="admin-field-label">' . escape($label) . '</p>' : '<label for="' . $name . '">' . escape($label) . '</label>')
            . $input . $note . fieldError($errors, $name) . '</div>';
    }
    $sections = implode('', array_map(fn ($group, $fields) => '<section class="admin-settings-section" aria-labelledby="integrations-' . $group . '"><div class="admin-settings-section__intro">'
        . '<h2 id="integrations-' . $group . '">' . escape(INTEGRATION_GROUPS[$group][0]) . '</h2><p>' . escape(INTEGRATION_GROUPS[$group][1]) . '</p></div>'
        . '<div class="admin-panel admin-form">' . $fields . '</div></section>', array_keys($groups), $groups));

    $test = is_array($_SESSION['integration_test'] ?? null) ? $_SESSION['integration_test'] : null;
    unset($_SESSION['integration_test']);
    return adminView('templates.admin.integrations', $admin, '/admin/integrations', ['summary' => formErrorSummary($errors, 'none'), 'sections' => $sections,
        'test_result' => $test ? '<p class="' . ($test[0] ? 'admin-flash' : 'admin-alert') . '" role="' . ($test[0] ? 'status' : 'alert') . '">' . escape($test[1]) . '</p>' : '',
        'app_key_warning' => appKey() ? '' : '<p class="admin-alert" role="alert">APP_KEY isn’t set in .env, so passwords and secret keys can’t be saved. Run <code>php bin/generate-key.php</code> on the server, then reload this page.</p>',
        'test_email' => escape($admin['email']), 'razorpay_mode' => escape(razorpayConfig()['mode'])]);
}

/** Sends a test email with the saved SMTP settings (even before sending is turned on). */
function sendIntegrationTestEmail(array $admin): never {
    $to = strtolower(postedText('to'));
    try {
        sendMail($to, 'Test email from Pro HPCL', "This is a test email from the Pro HPCL website.\n\nIf you can read it, the SMTP settings work.\n\nSent by " . $admin['name'] . ' at ' . date('j M Y, g:i A') . '.',
            null, ['enabled' => true] + smtpConfig());
        $_SESSION['integration_test'] = [true, "Test email sent to $to. Check the inbox (and the spam folder)."];
    } catch (RuntimeException $error) {
        $_SESSION['integration_test'] = [false, 'The test email wasn’t sent. ' . $error->getMessage()];
    }
    adminRedirect('/admin/integrations');
}
function checkIntegrationRazorpay(array $admin): never {
    $error = razorpayCheckKeys();
    $_SESSION['integration_test'] = $error === null ? [true, 'Razorpay accepted the ' . razorpayConfig()['mode'] . ' keys.'] : [false, $error];
    adminRedirect('/admin/integrations');
}
