<?php
declare(strict_types=1);

// Admin pages for site users (/admin/site-users): the public website accounts made at /signup
// (account.php), not the admin users at /admin/users. List and search them, read one, and
// deactivate or reactivate it; currentUser() signs a deactivated user out on their next request.
// The password hash is never read here.
require_once __DIR__ . '/admin-forms.php';

const SITE_USERS_PER_PAGE = 50;
const SITE_USER_STATUSES = ['active' => 'Active', 'deactivated' => 'Deactivated'];
// The columns these pages read: every column of users except password_hash, and the favourite team's name.
const SITE_USER_COLUMNS = 'u.id, u.name, u.email, u.phone, u.city, u.favourite_team_id, u.email_verified_at, u.is_active, u.last_login_at, u.signup_ip, u.created_at, u.updated_at, t.name AS team_name';

function siteUserStatusBadge(int|string $active): string {
    return (int) $active ? '<span class="admin-badge admin-badge--ongoing">Active</span>' : '<span class="admin-badge">Deactivated</span>';
}
/** A value of an optional column, or a muted dash when it is empty. */
function siteUserValue(?string $value): string {
    return $value === null || $value === '' ? '<span class="admin-muted">—</span>' : escape($value);
}
/** A stored date and time, or $none in muted text when there is none. */
function siteUserDate(?string $value, string $format = 'j M Y, g:i A', string $none = '—'): string {
    return $value ? escape(date($format, strtotime($value))) : '<span class="admin-muted">' . $none . '</span>';
}
function siteUserTile(string $label, int|string $value, string $hint = ''): string {
    return '<div class="admin-tile"><p class="admin-tile__label">' . escape($label) . '</p><p class="admin-tile__value">' . number_format((int) $value) . '</p>'
        . ($hint !== '' ? '<p class="admin-tile__hint">' . escape($hint) . '</p>' : '') . '</div>';
}
function siteUser(int $id): array {
    return query('SELECT ' . SITE_USER_COLUMNS . ' FROM users u LEFT JOIN teams t ON t.id = u.favourite_team_id WHERE u.id = ?', [$id])[0] ?? notFound();
}

function renderAdminSiteUsers(array $admin): string {
    $search = adminSearch();
    $status = is_string($_GET['status'] ?? null) && isset(SITE_USER_STATUSES[$_GET['status']]) ? $_GET['status'] : '';
    $conditions = [];
    $params = [];
    if ($status !== '') {
        $conditions[] = 'u.is_active = ?';
        $params[] = $status === 'active' ? 1 : 0;
    }
    if ($search !== '') {
        $conditions[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
        array_push($params, ...array_fill(0, 3, likePattern($search)));
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $total = (int) query("SELECT COUNT(*) AS n FROM users u $where", $params)[0]['n'];
    $lastPage = max(1, (int) ceil($total / SITE_USERS_PER_PAGE));
    $page = min($lastPage, max(1, ctype_digit((string) ($_GET['page'] ?? '')) ? (int) $_GET['page'] : 1));
    $users = query('SELECT ' . SITE_USER_COLUMNS . " FROM users u LEFT JOIN teams t ON t.id = u.favourite_team_id $where ORDER BY u.created_at DESC, u.id DESC LIMIT "
        . SITE_USERS_PER_PAGE . ' OFFSET ' . (($page - 1) * SITE_USERS_PER_PAGE), $params);
    $rows = implode('', array_map(fn ($u) => '<tr' . ($u['is_active'] ? '' : ' class="is-inactive"') . '><td><a href="{{BASE}}/admin/site-users/' . (int) $u['id'] . '">' . escape($u['name']) . '</a></td>'
        . '<td>' . escape($u['email']) . '</td><td class="admin-wide-only">' . siteUserValue($u['phone']) . '</td><td class="admin-wide-only">' . siteUserValue($u['city']) . '</td>'
        . '<td class="admin-wide-only">' . siteUserValue($u['team_name']) . '</td><td class="admin-wide-only">' . siteUserDate($u['created_at'], 'j M Y') . '</td>'
        . '<td class="admin-wide-only">' . siteUserDate($u['last_login_at'], 'j M Y', 'Never') . '</td><td>' . siteUserStatusBadge($u['is_active']) . '</td>'
        . '<td class="admin-actions"><a href="{{BASE}}/admin/site-users/' . (int) $u['id'] . '">View</a></td></tr>', $users));

    $counts = query('SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 0), 0) AS deactivated, COALESCE(SUM(created_at > NOW() - INTERVAL 7 DAY), 0) AS recent FROM users')[0];
    $tiles = siteUserTile('Accounts', $counts['total']) . siteUserTile('Joined in the last 7 days', $counts['recent']) . siteUserTile('Deactivated', $counts['deactivated'], 'Can’t sign in');
    $tabCounts = ['' => (int) $counts['total'], 'active' => (int) $counts['total'] - (int) $counts['deactivated'], 'deactivated' => (int) $counts['deactivated']];
    $tabUrl = fn (string $key) => '{{BASE}}/admin/site-users' . ($key !== '' || $search !== '' ? '?' . escape(http_build_query(array_filter(['status' => $key, 'q' => $search]))) : '');
    $tabs = '<nav class="admin-tabs" aria-label="Status">' . implode('', array_map(fn ($key, $label) => '<a href="' . $tabUrl($key) . '"' . ($key === $status ? ' aria-current="page"' : '') . '>'
        . $label . ' <span class="admin-muted">' . number_format($tabCounts[$key]) . '</span></a>', ['', ...array_keys(SITE_USER_STATUSES)], ['All', ...SITE_USER_STATUSES])) . '</nav>';
    $pageUrl = fn (int $number) => '{{BASE}}/admin/site-users?' . escape(http_build_query(array_filter(['status' => $status, 'q' => $search, 'page' => $number > 1 ? $number : null])));
    $pager = $lastPage > 1 ? '<nav class="admin-pager" aria-label="Pages">'
        . ($page > 1 ? '<a href="' . $pageUrl($page - 1) . '" rel="prev">&larr; Previous</a>' : '<span></span>')
        . '<span class="admin-muted">Page ' . $page . ' of ' . $lastPage . '</span>'
        . ($page < $lastPage ? '<a href="' . $pageUrl($page + 1) . '" rel="next">Next &rarr;</a>' : '<span></span>') . '</nav>' : '';
    $table = $users ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Name</th><th>Email</th><th class="admin-wide-only">Mobile</th><th class="admin-wide-only">City</th>'
        . '<th class="admin-wide-only">Favourite team</th><th class="admin-wide-only">Joined</th><th class="admin-wide-only">Last login</th><th>Status</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>'
        . $rows . '</tbody></table></div>' . $pager
        : '<p class="admin-empty">' . ($search === '' && $status === '' ? 'Nobody has signed up yet. Visitors make an account at <a href="{{BASE}}/signup">/signup</a>.' : 'No site users match.') . '</p>';
    $summary = number_format($total) . ' account' . ($total === 1 ? '' : 's') . ($search !== '' ? ($total === 1 ? ' matches' : ' match') . ' “' . escape($search) . '”' : '');
    return adminView('templates.admin.site-users', $admin, '/admin/site-users', ['search' => escape($search), 'status' => escape($status), 'summary' => $summary, 'tiles' => $tiles, 'tabs' => $tabs, 'table' => $table]);
}

/** One site user: their profile and sign-in details, and the deactivate or reactivate button. */
function renderSiteUser(array $admin, int $id): string {
    $user = siteUser($id);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $active = $_POST['is_active'] ?? null;
        if ($active !== '0' && $active !== '1') errorPage(400);
        adminExecute('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ? AND is_active <> ?', [(int) $active, $id, (int) $active]);
        adminFlash($active === '1' ? 'Account reactivated.' : 'Account deactivated.');
        adminRedirect('/admin/site-users/' . $id);
    }
    $active = (bool) $user['is_active'];
    $phone = $user['phone'] ? '<a href="tel:+91' . escape($user['phone']) . '">' . escape($user['phone']) . '</a>' : siteUserValue(null);
    return adminView('templates.admin.site-user', $admin, '/admin/site-users', [
        'title' => escape($user['name']), 'id' => $id, 'badge' => siteUserStatusBadge($user['is_active']),
        'email' => escape($user['email']), 'phone' => $phone, 'city' => siteUserValue($user['city']), 'team' => siteUserValue($user['team_name']),
        'verified' => $user['email_verified_at'] ? 'Yes <span class="admin-muted admin-small">' . siteUserDate($user['email_verified_at']) . '</span>' : 'No',
        'joined' => siteUserDate($user['created_at']), 'last_login' => siteUserDate($user['last_login_at'], 'j M Y, g:i A', 'Never'),
        'updated' => siteUserDate($user['updated_at'], 'j M Y, g:i A', 'Never'), 'signup_ip' => siteUserValue($user['signup_ip']),
        'status_hint' => $active ? 'Deactivating signs ' . escape($user['name']) . ' out at once, and they can’t sign in again until you reactivate the account. Their details are kept.'
            : 'This account is deactivated: ' . escape($user['name']) . ' can’t sign in. Reactivating lets them sign in again with their current password.',
        'next_active' => $active ? '0' : '1',
        'submit' => $active ? '<button type="submit" class="admin-button-danger">Deactivate account</button>' : '<button type="submit">Reactivate account</button>']);
}
