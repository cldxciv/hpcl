<?php
declare(strict_types=1);

// Creates an admin user, or changes an existing one's name and password (which signs them out):
//   php bin/create-admin.php           a new user is an admin, or the root user if there is none yet
//   php bin/create-admin.php --root    the user becomes the root user (the previous root becomes an admin)
// Other users are usually added by the root user on the Users page; this script is for the first
// user and for getting back in (a forgotten root password).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/admin.php';

// Checked before the first read: stream_isatty() discards input STDIN has already buffered.
define('HIDE_INPUT', DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN));

function ask(string $label): string {
    echo $label;
    $line = fgets(STDIN);
    if ($line === false) exit(1);
    return trim($line);
}
/** Reads a line without showing it, where the terminal allows it. */
function askHidden(string $label): string {
    echo $label;
    if (HIDE_INPUT) shell_exec('stty -echo');
    $line = fgets(STDIN);
    if (HIDE_INPUT) {
        shell_exec('stty echo');
        echo "\n";
    }
    if ($line === false) exit(1);
    return rtrim($line, "\r\n");
}
function fail(string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$email = strtolower(ask('Email: '));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) fail('That is not a valid email address.');
$name = ask('Name: ');
if ($name === '' || mb_strlen($name) > 255) fail('Enter a name of up to 255 characters.');
if (!HIDE_INPUT && stream_isatty(STDOUT)) echo "Note: the password will be visible as you type it.\n";
$password = askHidden('Password (6 to 8 characters): ');
if (!preg_match(ADMIN_PASSWORD_PATTERN, $password)) fail('The password must be 6 to 8 characters.');
if (askHidden('Repeat the password: ') !== $password) fail('The passwords do not match.');

$makeRoot = in_array('--root', $argv, true);
$existing = query('SELECT role FROM admins WHERE email = ?', [$email])[0] ?? null;
$role = $makeRoot || !query("SELECT id FROM admins WHERE role = ?", [ADMIN_ROOT_ROLE]) ? ADMIN_ROOT_ROLE : ($existing['role'] ?? 'admin');
$pdo = db();
$pdo->beginTransaction();
// There is only ever one root user.
if ($role === ADMIN_ROOT_ROLE) adminExecute("UPDATE admins SET role = 'admin' WHERE role = ? AND email <> ?", [ADMIN_ROOT_ROLE, $email]);
adminExecute('INSERT INTO admins (email, name, role, is_active, password_hash) VALUES (?, ?, ?, 1, ?)
    ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role), is_active = 1, password_hash = VALUES(password_hash)', [$email, $name, $role, adminPasswordHash($password)]);
$pdo->commit();
echo ($existing ? 'Updated' : 'Created') . " $email, role: $role.\n";
