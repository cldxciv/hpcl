<?php
declare(strict_types=1);

// Site user accounts (public sign-up, sign-in and profile), separate from the admin users in
// admin.php. This file is the shared core: the session, CSRF, the signed-in user, mail and the
// routing of the account pages. The pages themselves are in account-auth.php (sign up, log in,
// log out, forgot and reset password) and account-profile.php (/account and its pages).
//
// The session is started only on these routes, so other public pages still never set a cookie;
// the header's MY ACCOUNT link goes to /account, which sends signed-out visitors to /login. User
// sessions have their own cookie and their own folder (private/sessions), so the admin session's
// shorter lifetime never garbage-collects them.
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/admin-forms.php'; // postedText()

const USER_SESSION_LIFETIME = 30 * 24 * 60 * 60;
const USER_PASSWORD_LENGTH = [8, 128];
const USER_NAME_LENGTH = [2, 100];
const USER_SESSION_DIR = __DIR__ . '/../private/sessions';
const USER_MAIL_LOG = __DIR__ . '/../private/mail.log';
const USER_FORM_EXPIRED = 'This form expired. Reload the page and try again.';
// Failed sign-ins and wrong current passwords allowed within USER_FAILURE_WINDOW minutes, per
// email and per IP address, before further attempts are refused (as on the admin).
const USER_MAX_EMAIL_FAILURES = 5;
const USER_MAX_IP_FAILURES = 20;
const USER_FAILURE_WINDOW = 15;

// path => [file in includes/, handler function]. Each handler checks its own methods and exits.
const ACCOUNT_ROUTES = [
    '/signup' => ['account-auth.php', 'handleSignup'],
    '/login' => ['account-auth.php', 'handleLogin'],
    '/logout' => ['account-auth.php', 'handleLogout'],
    '/forgot-password' => ['account-auth.php', 'handleForgotPassword'],
    '/reset-password' => ['account-auth.php', 'handleResetPassword'],
    '/account' => ['account-profile.php', 'handleAccountHome'],
    '/account/edit' => ['account-profile.php', 'handleAccountEdit'],
    '/account/password' => ['account-profile.php', 'handleAccountPassword'],
];

function handleAccountRequest(string $path): never {
    [$file, $handler] = ACCOUNT_ROUTES[$path] ?? [null, null];
    if ($file === null) errorPage(404);
    // Passwords and session cookies never travel over plain HTTP on the live site (as on the admin).
    if (appEnv() === 'production' && !accountHttps()) {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        header('Location: ' . preg_replace('~^http:~i', 'https:', absoluteUrl('{{BASE}}' . $path)) . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }
    accountStartSession();
    require_once __DIR__ . '/' . $file;
    $handler();
    exit;
}

/** Hashes passwords with Argon2id when PHP has it, otherwise bcrypt (as adminHashAlgorithm()). */
function userHashAlgorithm(): array {
    return defined('PASSWORD_ARGON2ID') ? [PASSWORD_ARGON2ID, []] : [PASSWORD_BCRYPT, ['cost' => 12]];
}
function userPasswordHash(string $password): string {
    [$algorithm, $options] = userHashAlgorithm();
    return password_hash($password, $algorithm, $options);
}
/** A fingerprint of the password hash, so changing the password signs out other sessions. */
function userHashFingerprint(string $hash): string {
    return hash('sha256', $hash);
}
function accountExecute(string $sql, array $params = []): void {
    db()->prepare($sql)->execute($params);
}

function accountHttps(): bool {
    return !in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['', 'off'], true)
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}
function accountStartSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = accountHttps();
    if (!is_dir(USER_SESSION_DIR)) mkdir(USER_SESSION_DIR, 0700, true);
    session_save_path(USER_SESSION_DIR);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) USER_SESSION_LIFETIME);
    // Hosts that clean the default session folder with cron set gc_probability to 0, which would
    // leave this folder growing for ever: PHP clears it itself on 1 request in 100.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_name($https ? '__Host-user' : 'prohpcl_user');
    // Lax, not Strict: someone opening /account from an email or WhatsApp link stays signed in.
    // Every form still carries a CSRF token.
    session_set_cookie_params(['lifetime' => USER_SESSION_LIFETIME, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
/** Deletes the session's data and file, and expires its cookie. */
function accountEndSession(): void {
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookie['path'], 'domain' => $cookie['domain'],
        'secure' => $cookie['secure'], 'httponly' => $cookie['httponly'], 'samesite' => $cookie['samesite']]);
    session_destroy();
}
/** Signs the user in on this session (after sign-up, sign-in or a password reset). */
function accountSignIn(array $user): void {
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int) $user['id'], 'hash' => userHashFingerprint($user['password_hash']), 'login_at' => time(),
        'csrf' => bin2hex(random_bytes(32))];
    accountExecute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
}

function accountCsrfToken(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
/** Whether the posted csrf_token matches the session's. Forms answer USER_FORM_EXPIRED when not. */
function accountCsrfValid(): bool {
    $token = $_POST['csrf_token'] ?? null;
    return is_string($token) && is_string($_SESSION['csrf'] ?? null) && hash_equals($_SESSION['csrf'], $token);
}

/**
 * The signed-in user (all columns of `users`), or null. Expired sessions, and sessions of
 * deactivated users or of a changed password, are discarded and given a new id.
 */
function currentUser(): ?array {
    static $checked = false, $user = null;
    if ($checked) return $user;
    $checked = true;
    if (!is_int($_SESSION['user_id'] ?? null)) return null;
    if (time() - (int) ($_SESSION['login_at'] ?? 0) <= USER_SESSION_LIFETIME) {
        $user = query('SELECT * FROM users WHERE id = ?', [$_SESSION['user_id']])[0] ?? null;
        if ($user && (!$user['is_active'] || !hash_equals(userHashFingerprint($user['password_hash']), (string) ($_SESSION['hash'] ?? '')))) $user = null;
    }
    if (!$user) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    return $user;
}
/** The signed-in user; signed-out visitors are sent to /login and brought back here afterwards. */
function requireUser(): array {
    global $path;
    $user = currentUser();
    if ($user) return $user;
    // A form sent by forms.js after the session ended (logged out elsewhere, deactivated): say so,
    // rather than answering with the login page, which forms.js can only report as an error.
    if (wantsJson()) accountFormResponse(['form' => 'You’re logged out. Reload the page to log in again.'], '', '/login', fn () => '');
    accountRedirect('/login' . ($path !== '/account' ? '?next=' . rawurlencode($path) : ''));
}
/** A ?next= path to return to after signing in: only an account page, else /account. */
function accountNextPath(mixed $next): string {
    return is_string($next) && isset(ACCOUNT_ROUTES[$next]) && str_starts_with($next, '/account') ? $next : '/account';
}

/** Redirects to a fixed site path (303, so a POST isn't repeated). */
function accountRedirect(string $path): never {
    global $base;
    header('Location: ' . $base . $path, true, 303);
    exit;
}
/** The headers of every account response: personal, so never cached or indexed, and never shown inside another site's frame. */
function accountHeaders(): void {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Referrer-Policy: same-origin');
}
/** Sends an account page. */
function accountPage(string $html, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    accountHeaders();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo render($html);
    exit;
}
/** Answers a posted account form: formResponse() with the account headers, which the form shown again with its errors needs too. */
function accountFormResponse(array $errors, string $message, string $redirect, callable $form): never {
    accountHeaders();
    formResponse($errors, $errors ? 'Please correct the highlighted fields.' : $message, $redirect, $form);
}
/**
 * A form's view data: the CSRF token, the summary alert, and each field's value ($<field>_value:
 * $email is the footer's contact email), invalid class, aria-invalid and error.
 */
function accountFormData(array $input, array $errors, array $fields): array {
    $summary = isset($errors['form']) ? escape($errors['form']) : ($errors ? 'Please correct the highlighted fields.' : '');
    $data = ['csrf' => accountCsrfToken(), 'summary' => $summary, 'summary_hidden' => $summary === '' ? ' hidden' : ''];
    foreach ($fields as $field) {
        $data[$field . '_value'] = escape((string) ($input[$field] ?? ''));
        $data[$field . '_invalid'] = isset($errors[$field]) ? ' is-invalid' : '';
        $data[$field . '_aria'] = isset($errors[$field]) ? ' aria-invalid="true"' : '';
        $data[$field . '_error'] = '<div class="invalid-feedback" id="' . $field . '-error">' . (isset($errors[$field]) ? escape($errors[$field]) : '') . '</div>';
    }
    return $data;
}

/** A posted password as typed: not trimmed, since spaces are part of it. */
function postedPassword(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}
/** The errors of a new password and its confirmation, under the given field names. */
function accountNewPasswordErrors(string $password, string $confirm, string $field = 'password', string $confirmField = 'password_confirm'): array {
    [$min, $max] = USER_PASSWORD_LENGTH;
    $length = mb_strlen($password);
    $errors = [];
    if ($password === '') $errors[$field] = 'Choose a password.';
    elseif ($length < $min) $errors[$field] = "Your password must be at least $min characters long.";
    elseif ($length > $max) $errors[$field] = "Your password must be $max characters or fewer.";
    if ($confirm === '') $errors[$confirmField] = 'Enter your password again.';
    elseif (!isset($errors[$field]) && $confirm !== $password) $errors[$confirmField] = 'The passwords don’t match.';
    return $errors;
}
/** Whether the email or the IP address has too many recent failed sign-ins (or wrong current passwords). */
function accountLoginBlocked(string $email, string $ip): bool {
    $failures = query('SELECT COALESCE(SUM(email = ?), 0) AS email, COALESCE(SUM(ip = ?), 0) AS ip FROM user_login_attempts
        WHERE (email = ? OR ip = ?) AND attempted_at > NOW() - INTERVAL ' . USER_FAILURE_WINDOW . ' MINUTE', [$email, $ip, $email, $ip])[0];
    return (int) $failures['email'] >= USER_MAX_EMAIL_FAILURES || (int) $failures['ip'] >= USER_MAX_IP_FAILURES;
}
/** A message shown once on the next account page, such as "Your profile is saved." */
function accountFlash(string $message): void {
    $_SESSION['flash'] = $message;
}
/** The flash message as an alert (or ''), removed from the session. */
function accountTakeFlash(): string {
    $message = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    return is_string($message) && $message !== '' ? '<div class="alert alert-success acct-flash" role="status">' . escape($message) . '</div>' : '';
}

/**
 * Sends an account email (password reset, welcome…). Outside production it is written to
 * private/mail.log instead, since the SMTP settings are the live mailbox's. Returns false (and
 * logs why) when sending fails, so a mail problem never shows the visitor an error page.
 */
function accountSendMail(string $to, string $subject, string $text): bool {
    if (appEnv() !== 'production') {
        file_put_contents(USER_MAIL_LOG, '[' . date('c') . "] To: $to\nSubject: $subject\n\n$text\n" . str_repeat('-', 60) . "\n", FILE_APPEND | LOCK_EX);
        return true;
    }
    require_once __DIR__ . '/mailer.php';
    try {
        sendMail($to, $subject, $text);
        return true;
    } catch (RuntimeException $error) {
        error_log('Account email to ' . json_encode($to) . ' failed: ' . $error->getMessage());
        return false;
    }
}

/** The teams a user can pick as their favourite, by name: id => name. */
function accountTeams(): array {
    return array_column(query('SELECT id, name FROM teams WHERE deleted_at IS NULL ORDER BY name'), 'name', 'id');
}
