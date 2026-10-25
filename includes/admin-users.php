<?php
declare(strict_types=1);

// Admin users and roles (/admin/users, /admin/roles), for the root user only. The root user can't
// be deleted, deactivated or given another role here, so there is always exactly one; it is
// moved to another user with bin/create-admin.php --root. A role in use can't be deleted.
require_once __DIR__ . '/admin-forms.php';

const ROLE_SLUG_PATTERN = '~^[a-z][a-z0-9-]{1,31}$~';

function adminRoles(): array {
    return query('SELECT r.slug, r.name, r.description, r.permissions, (SELECT COUNT(*) FROM admins a WHERE a.role = r.slug) AS users FROM admin_roles r ORDER BY r.name');
}
/** The Users, Roles and Trash tabs at the top of the root user's pages. */
function usersTabs(string $active): string {
    $counts = query('SELECT (SELECT COUNT(*) FROM admins) AS users, (SELECT COUNT(*) FROM admin_roles) + 1 AS roles,
        (SELECT COUNT(*) FROM teams WHERE deleted_at IS NOT NULL) + (SELECT COUNT(*) FROM players WHERE deleted_at IS NOT NULL) + (SELECT COUNT(*) FROM owners WHERE deleted_at IS NOT NULL) AS trash')[0];
    return '<nav class="admin-tabs" aria-label="Users and roles">'
        . '<a href="{{BASE}}/admin/users"' . ($active === 'users' ? ' aria-current="page"' : '') . '>Users <span class="admin-muted">· ' . (int) $counts['users'] . '</span></a>'
        . '<a href="{{BASE}}/admin/roles"' . ($active === 'roles' ? ' aria-current="page"' : '') . '>Roles <span class="admin-muted">· ' . (int) $counts['roles'] . '</span></a>'
        . '<a href="{{BASE}}/admin/trash"' . ($active === 'trash' ? ' aria-current="page"' : '') . '>Trash <span class="admin-muted">· ' . (int) $counts['trash'] . '</span></a></nav>';
}
function roleBadge(string $slug, string $name): string {
    return '<span class="admin-badge' . ($slug === ADMIN_ROOT_ROLE ? ' admin-badge--root' : '') . '">' . escape($name) . '</span>';
}
function permissionChips(array $permissions): string {
    return $permissions ? implode(' ', array_map(fn ($permission) => '<span class="admin-chip">' . escape(ADMIN_PERMISSIONS[$permission][0]) . '</span>', $permissions))
        : '<span class="admin-muted">Dashboard only</span>';
}
/** The valid permissions of a stored, comma-separated list. */
function rolePermissions(string $list): array {
    return array_values(array_intersect(array_keys(ADMIN_PERMISSIONS), explode(',', $list)));
}
function avatarHtml(string $name, string $class = 'admin-avatar'): string {
    return '<span class="' . $class . '" aria-hidden="true">' . escape(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
}

// Users

function renderAdminUsers(array $admin): string {
    $users = query('SELECT a.id, a.email, a.name, a.role, a.is_active, a.last_login_at, r.name AS role_name
        FROM admins a LEFT JOIN admin_roles r ON r.slug = a.role ORDER BY a.role = ? DESC, a.is_active DESC, a.name', [ADMIN_ROOT_ROLE]);
    $rows = implode('', array_map(function ($user) use ($admin) {
        $id = (int) $user['id'];
        $isRoot = $user['role'] === ADMIN_ROOT_ROLE;
        $actions = '<a href="{{BASE}}/admin/users/' . $id . '">Edit</a>'
            . (!$isRoot && $id !== (int) $admin['id'] ? '<a class="admin-danger-link" href="{{BASE}}/admin/users/' . $id . '/delete">Delete</a>' : '');
        return '<tr' . ($user['is_active'] ? '' : ' class="is-inactive"') . '><td class="admin-cell-team">' . avatarHtml($user['name'])
            . '<span><a href="{{BASE}}/admin/users/' . $id . '">' . escape($user['name']) . '</a>' . ($id === (int) $admin['id'] ? ' <span class="admin-muted admin-small">(you)</span>' : '')
            . '<br><span class="admin-muted admin-small">' . escape($user['email']) . '</span></span></td>'
            . '<td>' . roleBadge($user['role'], $isRoot ? 'Root' : ($user['role_name'] ?? 'No role (' . $user['role'] . ')')) . '</td>'
            . '<td class="admin-wide-only">' . ($user['is_active'] ? '<span class="admin-status admin-status--ok">Active</span>' : '<span class="admin-status">Deactivated</span>') . '</td>'
            . '<td class="admin-wide-only">' . ($user['last_login_at'] ? date('j M Y, g:i A', strtotime($user['last_login_at'])) : '<span class="admin-muted">Never</span>') . '</td>'
            . '<td class="admin-actions">' . $actions . '</td></tr>';
    }, $users));
    $table = '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Role</th><th class="admin-wide-only">Status</th><th class="admin-wide-only">Last sign-in</th>'
        . '<th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    return adminView('templates.admin.users', $admin, '/admin/users', ['tabs' => usersTabs('users'), 'table' => $table]);
}

function renderUserForm(array $admin, ?int $id): string {
    $user = $id === null ? ['id' => null, 'name' => '', 'email' => '', 'role' => 'manager', 'is_active' => 1]
        : query('SELECT id, name, email, role, is_active FROM admins WHERE id = ?', [$id])[0] ?? notFound();
    $roles = adminRoles();
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') [$user, $errors] = saveUser($admin, $user, $roles);
    return userForm($admin, $user, $roles, $errors);
}

/** Validates and saves the posted form; redirects on success, else returns [user, errors] to show again. */
function saveUser(array $admin, array $user, array $roles): array {
    $isNew = $user['id'] === null;
    $isRoot = $user['role'] === ADMIN_ROOT_ROLE;
    $isSelf = !$isNew && (int) $user['id'] === (int) $admin['id'];
    $input = ['id' => $user['id'], 'name' => postedText('name'), 'email' => strtolower(postedText('email')),
        // The root user keeps the root role and stays active; you can't deactivate yourself.
        'role' => $isRoot ? ADMIN_ROOT_ROLE : postedText('role'),
        'is_active' => $isRoot || $isSelf ? 1 : (($_POST['is_active'] ?? null) === '1' ? 1 : 0)];
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
    $errors = [];
    if ($input['name'] === '') $errors['name'] = 'Enter the user’s name.';
    elseif (mb_strlen($input['name']) > 255) $errors['name'] = 'The name must be 255 characters or fewer.';
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL) || strlen($input['email']) > 255) $errors['email'] = 'Enter an email address, such as name@prohpcl.com.';
    elseif (query('SELECT id FROM admins WHERE email = ? AND id <> ?', [$input['email'], (int) $user['id']])) $errors['email'] = 'Another user already has this email address.';
    if (!$isRoot && !in_array($input['role'], array_column($roles, 'slug'), true)) $errors['role'] = 'Choose a role.';
    if ($isNew || $password !== '' || $confirm !== '') {
        if (!preg_match(ADMIN_PASSWORD_PATTERN, $password)) $errors['password'] = 'The password must be 6 to 8 characters.';
        elseif ($password !== $confirm) $errors['password_confirm'] = 'The passwords don’t match.';
    }
    if ($errors) return [$input + ['role' => $user['role']], $errors];

    $hash = $password === '' ? null : adminPasswordHash($password);
    if ($isNew) {
        adminExecute('INSERT INTO admins (email, name, role, is_active, password_hash) VALUES (?, ?, ?, ?, ?)', [$input['email'], $input['name'], $input['role'], $input['is_active'], $hash]);
    } else {
        adminExecute('UPDATE admins SET email = ?, name = ?, role = ?, is_active = ?' . ($hash ? ', password_hash = ?' : '') . ' WHERE id = ?',
            [$input['email'], $input['name'], $input['role'], $input['is_active'], ...($hash ? [$hash] : []), $user['id']]);
        // A new password signs the user out everywhere, except your own session when it's your password.
        if ($hash && $isSelf) $_SESSION['hash'] = adminHashFingerprint($hash);
    }
    adminFlash(($isNew ? 'Added ' : 'Saved ') . $input['name'] . '.' . ($hash && !$isNew && !$isSelf ? ' Their new password signs them out of any open sessions.' : ''));
    adminRedirect('/admin/users');
}

function userForm(array $admin, array $user, array $roles, array $errors): string {
    $isNew = $user['id'] === null;
    $isRoot = $user['role'] === ADMIN_ROOT_ROLE;
    $isSelf = !$isNew && (int) $user['id'] === (int) $admin['id'];
    $roleField = $isRoot
        ? '<p class="admin-static">' . roleBadge(ADMIN_ROOT_ROLE, 'Root') . ' Full access, including users and roles. Move it to another user with <code>php bin/create-admin.php --root</code>.</p>'
        : '<div class="admin-choices">' . implode('', array_map(fn ($role) => '<label class="admin-choice"><input type="radio" name="role" value="' . escape($role['slug']) . '"'
            . ($role['slug'] === $user['role'] ? ' checked' : '') . '><span><strong>' . escape($role['name']) . '</strong><span class="admin-muted admin-small">'
            . escape((string) $role['description']) . '</span><span class="admin-chips">' . permissionChips(rolePermissions($role['permissions'])) . '</span></span></label>', $roles)) . '</div>';
    $status = $isRoot || $isSelf
        ? '<p class="admin-static">Active' . ($isSelf && !$isRoot ? ' (you can’t deactivate your own account)' : '') . '</p>'
        : '<label class="admin-check"><input type="checkbox" name="is_active" value="1"' . ($user['is_active'] ? ' checked' : '') . '>Active: can sign in</label>';
    return adminView('templates.admin.user-form', $admin, '/admin/users', [
        'title' => $isNew ? 'Add a user' : 'Edit ' . escape($user['name']),
        'action' => '{{BASE}}/admin/users/' . ($isNew ? 'new' : (int) $user['id']),
        'summary' => formErrorSummary($errors, 'none'),
        'name' => escape($user['name']), 'name_error' => fieldError($errors, 'name'), 'name_invalid' => fieldInvalid($errors, 'name'),
        'email' => escape($user['email']), 'email_error' => fieldError($errors, 'email'), 'email_invalid' => fieldInvalid($errors, 'email'),
        'role_field' => $roleField, 'role_error' => fieldError($errors, 'role'), 'status_field' => $status,
        'password_label' => $isNew ? 'Password' : 'New password <span class="admin-optional">optional</span>',
        'password_hint' => $isNew ? '6 to 8 characters. Share it with the user privately.' : 'Leave empty to keep the current password. A new one signs the user out of any open sessions.',
        'password_required' => $isNew ? ' required' : '',
        'password_error' => fieldError($errors, 'password'), 'password_invalid' => fieldInvalid($errors, 'password'),
        'password_confirm_error' => fieldError($errors, 'password_confirm'), 'password_confirm_invalid' => fieldInvalid($errors, 'password_confirm'),
        'submit' => $isNew ? 'Add user' : 'Save changes',
        'delete' => $isNew || $isRoot || $isSelf ? '' : '<a class="admin-danger-link" href="{{BASE}}/admin/users/' . (int) $user['id'] . '/delete">Delete this user</a>',
    ]);
}

function renderUserDelete(array $admin, int $id): string {
    $user = query('SELECT id, name, email, role FROM admins WHERE id = ?', [$id])[0] ?? notFound();
    if ($user['role'] === ADMIN_ROOT_ROLE || $id === (int) $admin['id']) {
        $body = '<p>' . ($user['role'] === ADMIN_ROOT_ROLE ? 'The root user can’t be deleted.' : 'You can’t delete your own account.') . '</p>'
            . '<p class="admin-form-actions"><a class="admin-button-secondary" href="{{BASE}}/admin/users">Back to users</a></p>';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        adminExecute('DELETE FROM admins WHERE id = ?', [$id]);
        adminFlash('Deleted ' . $user['name'] . '.');
        adminRedirect('/admin/users');
    } else {
        $body = '<p>Delete <strong>' . escape($user['name']) . '</strong> (' . escape($user['email']) . ')? They are signed out and can’t sign in again. This can’t be undone.</p>'
            . '<p class="admin-hint">To stop them signing in for a while instead, edit the user and untick Active.</p>'
            . '<form method="post" action="{{BASE}}/admin/users/' . $id . '/delete" class="admin-form-actions"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">'
            . '<button type="submit" class="admin-button-danger">Delete user</button><a class="admin-button-secondary" href="{{BASE}}/admin/users/' . $id . '">Cancel</a></form>';
    }
    return adminView('templates.admin.delete', $admin, '/admin/users', ['title' => 'Delete ' . escape($user['name']), 'image' => avatarHtml($user['name'], 'admin-avatar admin-avatar--large'),
        'back' => '<a href="{{BASE}}/admin/users">&larr; All users</a>', 'body' => $body]);
}

// Roles

function renderAdminRoles(array $admin): string {
    $root = (int) query('SELECT COUNT(*) AS n FROM admins WHERE role = ?', [ADMIN_ROOT_ROLE])[0]['n'];
    $rows = '<tr><td><strong>Root</strong><br><span class="admin-muted admin-small">The one user who can do everything. Not editable.</span></td>'
        . '<td class="admin-cell-wrap">' . permissionChips(array_keys(ADMIN_PERMISSIONS)) . ' <span class="admin-chip admin-chip--root">Users and roles</span></td>'
        . '<td class="admin-num">' . $root . '</td><td></td></tr>';
    foreach (adminRoles() as $role) {
        $slug = escape($role['slug']);
        $rows .= '<tr><td><a href="{{BASE}}/admin/roles/' . $slug . '"><strong>' . escape($role['name']) . '</strong></a><br><span class="admin-muted admin-small">' . escape((string) $role['description']) . '</span></td>'
            . '<td class="admin-cell-wrap">' . permissionChips(rolePermissions($role['permissions'])) . '</td><td class="admin-num">' . (int) $role['users'] . '</td>'
            . '<td class="admin-actions"><a href="{{BASE}}/admin/roles/' . $slug . '">Edit</a>' . ((int) $role['users'] === 0 ? '<a class="admin-danger-link" href="{{BASE}}/admin/roles/' . $slug . '/delete">Delete</a>' : '') . '</td></tr>';
    }
    $table = '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Role</th><th>Can use</th><th class="admin-num">Users</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>'
        . $rows . '</tbody></table></div>';
    return adminView('templates.admin.roles', $admin, '/admin/users', ['tabs' => usersTabs('roles'), 'table' => $table]);
}

function renderRoleForm(array $admin, ?string $slug): string {
    $role = $slug === null ? ['slug' => '', 'name' => '', 'description' => '', 'permissions' => '']
        : query('SELECT slug, name, description, permissions FROM admin_roles WHERE slug = ?', [$slug])[0] ?? notFound();
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') [$role, $errors] = saveRole($slug, $role);
    $isNew = $slug === null;
    $checked = rolePermissions($role['permissions']);
    $permissions = implode('', array_map(fn ($key, $permission) => '<label class="admin-choice"><input type="checkbox" name="permissions[]" value="' . $key . '"'
        . (in_array($key, $checked, true) ? ' checked' : '') . '><span><strong>' . escape($permission[0]) . '</strong><span class="admin-muted admin-small">' . escape($permission[1]) . '</span></span></label>',
        array_keys(ADMIN_PERMISSIONS), ADMIN_PERMISSIONS));
    $users = $isNew ? 0 : (int) query('SELECT COUNT(*) AS n FROM admins WHERE role = ?', [$slug])[0]['n'];
    return adminView('templates.admin.role-form', $admin, '/admin/users', [
        'title' => $isNew ? 'Add a role' : 'Edit ' . escape($role['name']),
        'action' => '{{BASE}}/admin/roles/' . ($isNew ? 'new' : escape($slug)),
        'summary' => formErrorSummary($errors, 'none'),
        'name' => escape($role['name']), 'name_error' => fieldError($errors, 'name'), 'name_invalid' => fieldInvalid($errors, 'name'),
        'slug_field' => $isNew
            ? '<input id="slug" name="slug" type="text" value="' . escape($role['slug']) . '" maxlength="32" pattern="[a-z][a-z0-9-]{1,31}" spellcheck="false" autocapitalize="off"' . fieldInvalid($errors, 'slug') . '>'
                . '<p class="admin-hint">A short id, such as editor. Leave empty to make it from the name. It can’t be changed later.</p>' . fieldError($errors, 'slug')
            : '<p class="admin-static"><code>' . escape($slug) . '</code></p>',
        'description' => escape((string) $role['description']), 'description_error' => fieldError($errors, 'description'), 'description_invalid' => fieldInvalid($errors, 'description'),
        'permissions' => $permissions,
        'users_note' => $users ? '<p class="admin-hint">' . $users . ' user' . ($users === 1 ? ' has' : 's have') . ' this role. Changes apply to them straight away.</p>' : '',
        'submit' => $isNew ? 'Add role' : 'Save changes',
        'delete' => $isNew || $users ? '' : '<a class="admin-danger-link" href="{{BASE}}/admin/roles/' . escape($slug) . '/delete">Delete this role</a>',
    ]);
}

/** Validates and saves the posted role; redirects on success, else returns [role, errors] to show again. */
function saveRole(?string $slug, array $role): array {
    $input = ['slug' => $slug ?? strtolower(postedText('slug')), 'name' => postedText('name'), 'description' => postedText('description'),
        'permissions' => implode(',', rolePermissions(implode(',', array_filter(is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [], 'is_string'))))];
    $errors = [];
    if ($input['name'] === '') $errors['name'] = 'Enter the role’s name.';
    elseif (mb_strlen($input['name']) > 64) $errors['name'] = 'The name must be 64 characters or fewer.';
    if ($slug === null) {
        if ($input['slug'] === '') $input['slug'] = substr(trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($input['name'])), '-'), 0, 32);
        if (!preg_match(ROLE_SLUG_PATTERN, $input['slug'])) $errors['slug'] = 'Use 2 to 32 lowercase letters, numbers and hyphens, starting with a letter.';
        elseif ($input['slug'] === ADMIN_ROOT_ROLE || in_array($input['slug'], ['new'], true) || query('SELECT slug FROM admin_roles WHERE slug = ?', [$input['slug']])) $errors['slug'] = 'This id is already used. Choose another.';
    }
    if (mb_strlen($input['description']) > 255) $errors['description'] = 'The description must be 255 characters or fewer.';
    if ($errors) return [$input, $errors];
    if ($slug === null) {
        adminExecute('INSERT INTO admin_roles (slug, name, description, permissions) VALUES (?, ?, ?, ?)', [$input['slug'], $input['name'], $input['description'] ?: null, $input['permissions']]);
    } else {
        adminExecute('UPDATE admin_roles SET name = ?, description = ?, permissions = ? WHERE slug = ?', [$input['name'], $input['description'] ?: null, $input['permissions'], $slug]);
    }
    adminFlash(($slug === null ? 'Added the role ' : 'Saved the role ') . $input['name'] . '.');
    adminRedirect('/admin/roles');
}

function renderRoleDelete(array $admin, string $slug): string {
    $role = query('SELECT slug, name FROM admin_roles WHERE slug = ?', [$slug])[0] ?? notFound();
    $users = query('SELECT name FROM admins WHERE role = ? ORDER BY name', [$slug]);
    if ($users) {
        $body = '<p>The ' . escape($role['name']) . ' role can’t be deleted while ' . count($users) . ' user' . (count($users) === 1 ? ' has' : 's have') . ' it: '
            . escape(implode(', ', array_column($users, 'name'))) . '. Give them another role first.</p>'
            . '<p class="admin-form-actions"><a class="admin-button-secondary" href="{{BASE}}/admin/users">Go to users</a></p>';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        adminExecute('DELETE FROM admin_roles WHERE slug = ?', [$slug]);
        adminFlash('Deleted the role ' . $role['name'] . '.');
        adminRedirect('/admin/roles');
    } else {
        $body = '<p>Delete the <strong>' . escape($role['name']) . '</strong> role? No users have it. This can’t be undone.</p>'
            . '<form method="post" action="{{BASE}}/admin/roles/' . escape($slug) . '/delete" class="admin-form-actions"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">'
            . '<button type="submit" class="admin-button-danger">Delete role</button><a class="admin-button-secondary" href="{{BASE}}/admin/roles/' . escape($slug) . '">Cancel</a></form>';
    }
    return adminView('templates.admin.delete', $admin, '/admin/users', ['title' => 'Delete the ' . escape($role['name']) . ' role', 'image' => '',
        'back' => '<a href="{{BASE}}/admin/roles">&larr; All roles</a>', 'body' => $body]);
}
