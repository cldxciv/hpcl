<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-forms.php';

/** Each signed-in user may edit only their own profile, without changing permissions. */
function renderAdminProfile(array $admin): string {
    $input = ['name' => $admin['name'], 'email' => $admin['email']];
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = postedText('action');
        if ($action === 'profile') {
            $input = ['name' => postedText('name'), 'email' => strtolower(postedText('email'))];
            if ($input['name'] === '') $errors['name'] = 'Enter your name.';
            elseif (mb_strlen($input['name']) > 255) $errors['name'] = 'The name must be 255 characters or fewer.';
            if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL) || strlen($input['email']) > 255) $errors['email'] = 'Enter a valid email address.';
            elseif (query('SELECT id FROM admins WHERE email = ? AND id <> ?', [$input['email'], $admin['id']])) $errors['email'] = 'Another user already has this email address.';
            if (!$errors) {
                adminExecute('UPDATE admins SET name = ?, email = ? WHERE id = ?', [$input['name'], $input['email'], $admin['id']]);
                adminFlash('Your profile details have been saved.');
                adminRedirect('/admin/profile');
            }
        } elseif ($action === 'password') {
            $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
            if (!password_verify($current, $admin['password_hash'])) $errors['current_password'] = 'Your current password is incorrect.';
            if (!preg_match(ADMIN_PASSWORD_PATTERN, $password)) $errors['password'] = 'The password must be 6 to 8 characters.';
            elseif ($password !== $confirm) $errors['password_confirm'] = 'The passwords don’t match.';
            if (!$errors) {
                $hash = adminPasswordHash($password);
                adminExecute('UPDATE admins SET password_hash = ? WHERE id = ?', [$hash, $admin['id']]);
                session_regenerate_id(true);
                $_SESSION['hash'] = adminHashFingerprint($hash);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                adminFlash('Your password has been changed. Other sessions have been signed out.');
                adminRedirect('/admin/profile');
            }
        } else {
            errorPage(400);
        }
    }
    $data = ['summary' => formErrorSummary($errors, 'none'), 'name' => escape($input['name']), 'email' => escape($input['email'])];
    foreach (['name', 'email', 'current_password', 'password', 'password_confirm'] as $field) {
        $data[$field . '_error'] = fieldError($errors, $field);
        $data[$field . '_invalid'] = fieldInvalid($errors, $field);
    }
    return adminView('templates.admin.profile', $admin, '/admin/profile', $data);
}
