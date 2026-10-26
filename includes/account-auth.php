<?php
declare(strict_types=1);

// The sign-in pages of site user accounts (includes/account.php): sign up (/signup), log in
// (/login), log out (/logout), and forgot (/forgot-password) and reset (/reset-password) password.
// The forms are sent with AJAX by assets/js/forms.js and answered with formResponse(), as the
// membership join form (membership-join.php); without JavaScript they are ordinary forms. Every
// form carries the session's CSRF token: a missing or old one only asks the visitor to reload.
//
// Sign-in is slowed down as on the admin (adminLogin()): after too many failures for an email or
// from an IP address, attempts are refused with the same message as a wrong password. Reset links
// are emailed, work once for USER_RESET_MINUTES, and only their SHA-256 is stored (user_tokens).
require_once __DIR__ . '/committee.php'; // reuse committeePhone(), committeeCleanText(), COMMITTEE_NAME_PATTERN

const USER_SIGNUP_FIELDS = ['name', 'email', 'phone', 'password', 'password_confirm', 'terms'];
// Accounts that can be created from one IP address per hour, against spam.
const USER_SIGNUPS_PER_HOUR = 5;
const USER_EMAIL_TAKEN = 'An account with this email already exists. Log in or reset your password.';
const USER_LOGIN_ERROR = 'Email or password is incorrect.';
// How long a reset link works, and how many are sent per account and per IP address in an hour.
const USER_RESET_MINUTES = 60;
const USER_RESETS_PER_USER = 3;
const USER_RESETS_PER_IP = 10;
const USER_RESET_SENT = 'If an account exists for that email, we’ve sent a link to reset your password. It works for ' . USER_RESET_MINUTES . ' minutes.';
const USER_RESET_EXPIRED = 'This link has expired or was already used. Ask for a new one.';

/** The request method of a form page: GET, HEAD or POST (others get 405). */
function accountAuthMethod(): string {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) errorPage(405);
    return $method;
}

/** The error of a typed email address, or null (the rules of the membership join form). */
function accountEmailError(string $email): ?string {
    if ($email === '') return 'Enter your email address.';
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('~@[^@]+\.[a-z]{2,}$~i', $email)) return 'Enter a valid email address, like name@example.com.';
    return null;
}
/** The first word of a name, to greet someone ("Welcome, Rohit!"). */
function accountFirstName(string $name): string {
    return explode(' ', trim($name))[0];
}

/** GET shows the sign-up form; POST creates the account and signs it in. Signed-in users go to /account. */
function handleSignup(): never {
    $method = accountAuthMethod();
    if (currentUser()) $method === 'POST' ? accountFormResponse([], 'You’re already logged in.', '/account', fn () => '') : accountRedirect('/account');
    if ($method !== 'POST') accountPage(signupForm(array_fill_keys(USER_SIGNUP_FIELDS, ''), []));
    [$input, $errors] = createAccount();
    accountFormResponse($errors, 'Your account is created.', '/account', fn () => signupForm($input, $errors));
}

/** Checks the posted sign-up and creates the account, signed in on this session: [input, errors]. */
function createAccount(): array {
    $input = ['name' => committeeCleanText(postedText('name')), 'email' => strtolower(postedText('email')), 'phone' => postedText('phone'), 'terms' => postedText('terms')];
    if (!accountCsrfValid()) return [$input, ['form' => USER_FORM_EXPIRED]];
    // Honeypot: a hidden field real people leave empty. Bots are told it worked.
    if (postedText('website') !== '') return [$input, []];

    $errors = [];
    [$min, $max] = USER_NAME_LENGTH;
    $nameLength = mb_strlen($input['name']);
    if ($nameLength === 0) $errors['name'] = 'Enter your full name.';
    elseif ($nameLength < $min || $nameLength > $max) $errors['name'] = "Your name must be $min to $max characters long.";
    elseif (!preg_match(COMMITTEE_NAME_PATTERN, $input['name'])) $errors['name'] = 'Use only letters, spaces, dots, hyphens and apostrophes in your name.';

    if ($error = accountEmailError($input['email'])) $errors['email'] = $error;

    // The phone is optional: blank, or an Indian mobile number stored as 10 digits.
    $phone = committeePhone($input['phone']);
    if ($input['phone'] !== '' && $phone === null) $errors['phone'] = 'Enter a valid 10-digit Indian mobile number, like 98160 12345.';
    elseif ($phone !== null) $input['phone'] = $phone;

    $password = postedPassword('password');
    $errors += accountNewPasswordErrors($password, postedPassword('password_confirm'));
    if ($input['terms'] !== '1') $errors['terms'] = 'Agree to the Terms and Privacy Policy to create your account.';
    if ($errors) return [$input, $errors];

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $recent = (int) (query('SELECT COUNT(*) AS n FROM users WHERE signup_ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$ip])[0]['n'] ?? 0);
    if ($recent >= USER_SIGNUPS_PER_HOUR) return [$input, ['form' => 'Too many accounts were created from this network. Please try again later.']];
    if (query('SELECT id FROM users WHERE email = ?', [$input['email']])) return [$input, ['email' => USER_EMAIL_TAKEN]];

    $hash = userPasswordHash($password);
    try {
        accountExecute('INSERT INTO users (name, email, phone, password_hash, signup_ip, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$input['name'], $input['email'], $input['phone'] ?: null, $hash, $ip]);
    } catch (PDOException $error) {
        // The same email signed up at the same moment: the unique key refused the second one.
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) return [$input, ['email' => USER_EMAIL_TAKEN]];
        throw $error;
    }
    accountSignIn(['id' => (int) db()->lastInsertId(), 'password_hash' => $hash]);

    $first = accountFirstName($input['name']);
    accountFlash("Welcome to Pro HPCL, $first!");
    accountSendMail($input['email'], 'Welcome to Pro HPCL', "Hi $first,\n\nWelcome to Pro HPCL! Your account is ready.\n\n"
        . 'Log in at any time to update your details and pick your favourite team: ' . absoluteUrl('{{BASE}}/login') . "\n\n"
        . "If you didn’t create this account, reply to this email and we’ll sort it out.\n\nThe Pro HPCL team\n");
    return [$input, []];
}

function signupForm(array $input, array $errors): string {
    [$nameMin, $nameMax] = USER_NAME_LENGTH;
    [$passwordMin, $passwordMax] = USER_PASSWORD_LENGTH;
    return view('templates.account.signup', accountFormData($input, $errors, USER_SIGNUP_FIELDS) + [
        'name_min' => $nameMin, 'name_max' => $nameMax, 'password_min' => $passwordMin, 'password_max' => $passwordMax,
        'terms_checked' => ($input['terms'] ?? '') === '1' ? ' checked' : '']);
}

/** GET shows the log-in form (?next= the account page to go to afterwards, ?out=1 after logging out); POST signs in. */
function handleLogin(): never {
    $method = accountAuthMethod();
    $next = accountNextPath($method === 'POST' ? ($_POST['next'] ?? null) : ($_GET['next'] ?? null));
    if (currentUser()) $method === 'POST' ? accountFormResponse([], 'You’re already logged in.', $next, fn () => '') : accountRedirect($next);
    if ($method !== 'POST') accountPage(loginForm(['email' => '', 'next' => $next], [], ($_GET['out'] ?? '') === '1'));
    [$input, $errors] = accountLogin($next);
    accountFormResponse($errors, 'You’re logged in.', $next, fn () => loginForm($input, $errors, false));
}

/** Checks the posted email and password and signs the user in: [input, errors]. */
function accountLogin(string $next): array {
    $input = ['email' => strtolower(postedText('email')), 'next' => $next];
    if (!accountCsrfValid()) return [$input, ['form' => USER_FORM_EXPIRED]];
    $password = postedPassword('password');
    $errors = [];
    if ($error = accountEmailError($input['email'])) $errors['email'] = $error;
    if ($password === '') $errors['password'] = 'Enter your password.';
    if ($errors) return [$input, $errors];

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    accountExecute('DELETE FROM user_login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    $blocked = accountLoginBlocked($input['email'], $ip);
    $user = query('SELECT * FROM users WHERE email = ?', [$input['email']])[0] ?? null;
    // Hash the password even for an unknown email, so the response time doesn't reveal which emails
    // have an account. A deactivated account gets the same message as a wrong password.
    $valid = $user ? password_verify($password, $user['password_hash']) && $user['is_active'] : userPasswordHash($password) === '';
    if ($blocked || !$valid) {
        accountExecute('INSERT INTO user_login_attempts (ip, email, attempted_at) VALUES (?, ?, NOW())', [$ip, $input['email']]);
        if ($blocked) error_log('User sign-in refused for ' . json_encode($input['email']) . ' from ' . $ip . ' (too many attempts)');
        return [$input, ['form' => USER_LOGIN_ERROR]];
    }

    accountExecute('DELETE FROM user_login_attempts WHERE email = ?', [$input['email']]);
    [$algorithm, $options] = userHashAlgorithm();
    if (password_needs_rehash($user['password_hash'], $algorithm, $options)) {
        $user['password_hash'] = userPasswordHash($password);
        accountExecute('UPDATE users SET password_hash = ? WHERE id = ?', [$user['password_hash'], $user['id']]);
    }
    accountSignIn($user);
    return [$input, []];
}

function loginForm(array $input, array $errors, bool $loggedOut): string {
    return view('templates.account.login', accountFormData($input, $errors, ['email', 'password']) + ['next' => escape($input['next']),
        'notice' => $loggedOut ? '<div class="alert alert-success" role="status">You’re logged out.</div>' : '']);
}

/** POST only: ends the session and goes to /login?out=1. Without the form's token (a link or form on another site) nothing happens. */
function handleLogout(): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') errorPage(405);
    if (!accountCsrfValid()) formResponse([], '', '/account', fn () => '');
    accountEndSession();
    formResponse([], 'You’re logged out.', '/login?out=1', fn () => '');
}

/** GET shows the form (?sent=1 the message after sending); POST emails a reset link when the account exists. */
function handleForgotPassword(): never {
    $method = accountAuthMethod();
    if ($method !== 'POST') accountPage(forgotPasswordForm('', [], ($_GET['sent'] ?? '') === '1'));
    [$email, $errors] = sendPasswordReset();
    accountFormResponse($errors, USER_RESET_SENT, '/forgot-password?sent=1', fn () => forgotPasswordForm($email, $errors, false));
}

/**
 * Emails a reset link to the posted address: [email, errors]. The answer is the same whether or not
 * the account exists; nothing is sent for an unknown or deactivated account, or over the limits.
 */
function sendPasswordReset(): array {
    $email = strtolower(postedText('email'));
    if (!accountCsrfValid()) return [$email, ['form' => USER_FORM_EXPIRED]];
    if ($error = accountEmailError($email)) return [$email, ['email' => $error]];

    // Links that expired over a day ago are of no more use, not even for the limits.
    accountExecute('DELETE FROM user_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY');
    $user = query('SELECT id, name, email, is_active FROM users WHERE email = ?', [$email])[0] ?? null;
    if (!$user || !$user['is_active']) return [$email, []];
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $recent = query("SELECT COALESCE(SUM(user_id = ?), 0) AS user_count, COALESCE(SUM(ip = ?), 0) AS ip_count FROM user_tokens
        WHERE purpose = 'reset' AND (user_id = ? OR ip = ?) AND created_at > NOW() - INTERVAL 1 HOUR", [$user['id'], $ip, $user['id'], $ip])[0];
    if ((int) $recent['user_count'] >= USER_RESETS_PER_USER || (int) $recent['ip_count'] >= USER_RESETS_PER_IP) return [$email, []];

    $token = bin2hex(random_bytes(32));
    accountExecute("INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at, ip, created_at) VALUES (?, 'reset', ?, NOW() + INTERVAL " . USER_RESET_MINUTES . ' MINUTE, ?, NOW())',
        [$user['id'], hash('sha256', $token), $ip]);
    accountSendMail($user['email'], 'Reset your Pro HPCL password', 'Hi ' . accountFirstName($user['name']) . ",\n\n"
        . "We got a request to reset the password of your Pro HPCL account. Open this link to choose a new one:\n\n"
        . absoluteUrl('{{BASE}}/reset-password?token=' . $token) . "\n\n"
        . 'The link works once, for ' . USER_RESET_MINUTES . " minutes. If you didn’t ask for it, ignore this email: your password stays the same.\n\nThe Pro HPCL team\n");
    return [$email, []];
}

function forgotPasswordForm(string $email, array $errors, bool $sent): string {
    return view('templates.account.forgot-password', accountFormData(['email' => $email], $errors, ['email']) + [
        'sent_hidden' => $sent ? '' : ' hidden', 'form_hidden' => $sent ? ' hidden' : '', 'sent_message' => escape(USER_RESET_SENT)]);
}

/** GET ?token= shows the new-password form, or that the link no longer works; POST sets the password and signs the user in. */
function handleResetPassword(): never {
    $method = accountAuthMethod();
    if ($method !== 'POST') accountPage(resetPasswordForm(is_string($_GET['token'] ?? null) ? $_GET['token'] : '', []));
    [$token, $errors] = resetPassword();
    accountFormResponse($errors, 'Your password is changed.', '/account', fn () => resetPasswordForm($token, $errors));
}

/** The user (all columns of `users`, plus token_id) of a reset link that still works: unused, not expired, of an active account. */
function passwordResetToken(string $token): ?array {
    if (!preg_match('~^[0-9a-f]{64}$~', $token)) return null;
    return query("SELECT u.*, t.id AS token_id FROM user_tokens t JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.purpose = 'reset' AND t.used_at IS NULL AND t.expires_at > NOW() AND u.is_active = 1", [hash('sha256', $token)])[0] ?? null;
}

/**
 * Sets the posted new password of a reset link: [token, errors]. The link and the account's other
 * reset links stop working, and signing in again here signs out the account's other sessions.
 */
function resetPassword(): array {
    $token = postedText('token');
    if (!accountCsrfValid()) return [$token, ['form' => USER_FORM_EXPIRED]];
    $user = passwordResetToken($token);
    if (!$user) return [$token, ['form' => USER_RESET_EXPIRED]];
    $password = postedPassword('password');
    if ($errors = accountNewPasswordErrors($password, postedPassword('password_confirm'))) return [$token, $errors];

    // The link is marked used first, so two submissions at once can't both use it.
    $claim = db()->prepare('UPDATE user_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
    $claim->execute([$user['token_id']]);
    if ($claim->rowCount() !== 1) return [$token, ['form' => USER_RESET_EXPIRED]];
    $user['password_hash'] = userPasswordHash($password);
    accountExecute('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?', [$user['password_hash'], $user['id']]);
    accountExecute("UPDATE user_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = 'reset' AND used_at IS NULL", [$user['id']]);
    // Proving the email lifts a lockout from wrong passwords.
    accountExecute('DELETE FROM user_login_attempts WHERE email = ?', [$user['email']]);
    accountSignIn($user);
    accountFlash('Your password is changed.');
    return [$token, []];
}

function resetPasswordForm(string $token, array $errors): string {
    $user = passwordResetToken($token);
    [$min, $max] = USER_PASSWORD_LENGTH;
    return view('templates.account.reset-password', accountFormData([], $errors, ['password', 'password_confirm']) + [
        'token' => $user ? escape($token) : '', 'account_email' => $user ? escape($user['email']) : '',
        'form_hidden' => $user ? '' : ' hidden', 'expired_hidden' => $user ? ' hidden' : '',
        'password_min' => $min, 'password_max' => $max, 'reset_minutes' => USER_RESET_MINUTES]);
}
