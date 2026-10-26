<?php
declare(strict_types=1);

// Admin sign-in (/admin/login, /admin/logout), roles and routing of the signed-in pages under
// /admin. Users are created by the root user (Users page) or with bin/create-admin.php; there is
// no sign-up page. A session is started only on these routes, so public pages never set a cookie.
//
// Roles: one user has the role "root" and can do everything, including managing users and roles.
// Every other user has a role from admin_roles (admin, manager…), whose permissions name the
// areas they may use (ADMIN_PERMISSIONS).
require_once __DIR__ . '/site.php';

const ADMIN_IDLE_TIMEOUT = 30 * 60;
const ADMIN_ABSOLUTE_TIMEOUT = 8 * 60 * 60;
// Failed sign-ins allowed within ADMIN_FAILURE_WINDOW minutes before further attempts are refused.
const ADMIN_MAX_EMAIL_FAILURES = 5;
const ADMIN_MAX_IP_FAILURES = 20;
const ADMIN_FAILURE_WINDOW = 15;
const ADMIN_LOGIN_ERROR = 'Email or password is incorrect.';
// Admin passwords are 6 to 8 characters (bin/create-admin.php).
const ADMIN_PASSWORD_PATTERN = '~^.{6,8}$~su';
// Methods the sign-in routes answer; the signed-in pages are listed in adminPages().
const ADMIN_AUTH_ROUTES = ['/admin/login' => ['GET', 'HEAD', 'POST'], '/admin/logout' => ['POST']];
// The role of the one user who can do everything; it is not a row of admin_roles.
const ADMIN_ROOT_ROLE = 'root';
// What a role can be allowed to do: permission => [label, description]. "root" (users and
// roles) is not on the list: only the root user has it.
const ADMIN_PERMISSIONS = [
    'seasons' => ['Seasons', 'Add and edit season details and registration dates.'],
    'teams' => ['Teams', 'Add and edit teams, and choose the seasons they play in.'],
    'squads' => ['Squads', 'Add players to team squads, mark them retained, reorder or remove them.'],
    'players' => ['Players', 'Add and edit players.'],
    'owners' => ['Owners', 'Add and edit owners and the teams they own.'],
    'committee' => ['Committee applications', 'Read committee member applications with their Aadhaar card, and approve or reject them.'],
    'membership' => ['Membership', 'Review membership requests and manage public member business profiles.'],
    'site_users' => ['Site users', 'See who has signed up for a website account, and deactivate or reactivate accounts.'],
    'settings' => ['Site settings', 'Manage the hero slider, contact details, social links and home page highlights season.'],
    'delete' => ['Delete records', 'Delete teams, players and owners, in the areas the role can use. (Deleted records go to your Trash, where you can restore them.)'],
];
// The admin menu: path => [label, icon (the inside of a 24×24 outline SVG), permission needed or null,
// group]. Entries with a group are listed under it in a dropdown (ADMIN_MENU_GROUPS), so the
// header fits on one row.
const ADMIN_MENU = [
    '/admin' => ['Dashboard', '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>', null],
    '/admin/seasons' => ['Seasons', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2M8 3v3M16 3v3"/>', 'seasons'],
    '/admin/teams' => ['Teams', '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6l8-3z"/>', 'teams'],
    '/admin/players' => ['Players', '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>', 'players'],
    '/admin/owners' => ['Owners', '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14.2c2.1.6 3.5 2.6 3.5 5.8"/>', 'owners'],
    '/admin/committee' => ['Committee', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>', 'committee', 'people'],
    '/admin/membership' => ['Membership', '<circle cx="12" cy="8" r="5"/><path d="M8.5 12.5 7 21l5-3 5 3-1.5-8.5"/>', 'membership', 'people'],
    '/admin/members' => ['Members', '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 12h18"/>', 'membership', 'people'],
    '/admin/site-users' => ['Site users', '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10.5" r="2.5"/><path d="M5.5 16.5c.5-1.7 1.9-2.6 3.5-2.6s3 .9 3.5 2.6M15 9.5h4M15 13.5h3"/>', 'site_users', 'people'],
    '/admin/hero' => ['Hero slider', '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m3 16 5-5 4 4 4-6 5 7"/>', 'settings', 'settings'],
    '/admin/settings' => ['Site settings', '<path d="M4 6h9M19 6h1M4 12h3M13 12h7M4 18h11M21 18h-1"/><circle cx="16" cy="6" r="2.5"/><circle cx="10" cy="12" r="2.5"/><circle cx="18" cy="18" r="2.5"/>', 'settings', 'settings'],
    '/admin/integrations' => ['Integrations', '<path d="M9 7V3M15 7V3M7 7h10v4a5 5 0 0 1-10 0V7zM12 16v5"/>', 'root', 'settings'],
    '/admin/users' => ['Users', '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.5"/>', 'root', 'settings'],
];
// Dropdowns of the admin menu: key => [label, icon].
const ADMIN_MENU_GROUPS = [
    'people' => ['People', '<circle cx="12" cy="7" r="3"/><circle cx="5" cy="9" r="2.2"/><circle cx="19" cy="9" r="2.2"/><path d="M7 20c0-2.8 2.2-5 5-5s5 2.2 5 5"/><path d="M1.5 18c0-2 1.6-3.6 3.5-3.6M22.5 18c0-2-1.6-3.6-3.5-3.6"/>'],
    'settings' => ['Settings', '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'],
];

/** Hashes passwords with Argon2id when PHP has it, otherwise bcrypt. */
function adminHashAlgorithm(): array {
    return defined('PASSWORD_ARGON2ID') ? [PASSWORD_ARGON2ID, []] : [PASSWORD_BCRYPT, ['cost' => 12]];
}
function adminPasswordHash(string $password): string {
    [$algorithm, $options] = adminHashAlgorithm();
    return password_hash($password, $algorithm, $options);
}
function adminExecute(string $sql, array $params = []): void {
    db()->prepare($sql)->execute($params);
}

function adminHttps(): bool {
    return !in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['', 'off'], true)
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function adminStartSession(): void {
    $https = adminHttps();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) ADMIN_ABSOLUTE_TIMEOUT);
    // The __Host- prefix makes browsers accept the cookie only over HTTPS, for the whole host.
    session_name($https ? '__Host-admin' : 'prohpcl_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}
/** Deletes the session's data and file, and expires its cookie. */
function adminEndSession(): void {
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookie['path'], 'domain' => $cookie['domain'],
        'secure' => $cookie['secure'], 'httponly' => $cookie['httponly'], 'samesite' => $cookie['samesite']]);
    session_destroy();
}

function adminCsrfToken(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function adminCheckCsrf(): void {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !is_string($_SESSION['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $token)) errorPage(400);
}

/** A fingerprint of the password hash, so changing the password signs out existing sessions. */
function adminHashFingerprint(string $hash): string {
    return hash('sha256', $hash);
}
/**
 * The signed-in admin, or null. Timed-out sessions, and sessions of deleted or deactivated admins
 * or of a changed password, are discarded and given a new id. The admin's role and permissions are
 * read on every request, so changes to them apply at once.
 */
function currentAdmin(): ?array {
    if (!is_int($_SESSION['admin_id'] ?? null)) return null;
    $now = time();
    $admin = null;
    if ($now - (int) ($_SESSION['seen_at'] ?? 0) <= ADMIN_IDLE_TIMEOUT && $now - (int) ($_SESSION['login_at'] ?? 0) <= ADMIN_ABSOLUTE_TIMEOUT) {
        $admin = query('SELECT a.id, a.email, a.name, a.password_hash, a.role, a.is_active, r.name AS role_name, r.permissions
            FROM admins a LEFT JOIN admin_roles r ON r.slug = a.role WHERE a.id = ?', [$_SESSION['admin_id']])[0] ?? null;
        if ($admin && (!$admin['is_active'] || !hash_equals(adminHashFingerprint($admin['password_hash']), (string) ($_SESSION['hash'] ?? '')))) $admin = null;
    }
    if (!$admin) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $_SESSION['seen_at'] = $now;
    // A role that no longer exists gives no permissions: the dashboard only.
    $admin['permissions'] = $admin['role'] === ADMIN_ROOT_ROLE ? [...array_keys(ADMIN_PERMISSIONS), 'root']
        : array_values(array_intersect(explode(',', (string) $admin['permissions']), array_keys(ADMIN_PERMISSIONS)));
    $admin['role_name'] = $admin['role'] === ADMIN_ROOT_ROLE ? 'Root' : ($admin['role_name'] ?? 'No role');
    return signedInAdmin($admin);
}
/** The admin signed in for this request; set once by currentAdmin(). */
function signedInAdmin(?array $admin = null): ?array {
    static $current = null;
    if ($admin !== null) $current = $admin;
    return $current;
}
/** Whether the signed-in admin may use an area ("teams", "delete"…, or "root" for users and roles). */
function adminCan(string $permission): bool {
    return in_array($permission, signedInAdmin()['permissions'] ?? [], true);
}

/** Whether the email or the IP address has too many recent failed sign-ins. */
function adminLoginBlocked(string $email, string $ip): bool {
    $failures = query('SELECT COALESCE(SUM(email = ?), 0) AS email, COALESCE(SUM(ip = ?), 0) AS ip FROM admin_login_attempts
        WHERE (email = ? OR ip = ?) AND attempted_at > NOW() - INTERVAL ' . ADMIN_FAILURE_WINDOW . ' MINUTE', [$email, $ip, $email, $ip])[0];
    return (int) $failures['email'] >= ADMIN_MAX_EMAIL_FAILURES || (int) $failures['ip'] >= ADMIN_MAX_IP_FAILURES;
}

function adminLoginForm(string $email = '', string $error = ''): string {
    return view('templates.admin.login', ['csrf' => adminCsrfToken(), 'email' => escape($email),
        'error' => $error === '' ? '' : '<p class="admin-alert" role="alert">' . escape($error) . '</p>']);
}
/** Handles the sign-in form: redirects to /admin on success, or returns the form with the error. */
function adminLogin(): string {
    adminCheckCsrf();
    $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    adminExecute('DELETE FROM admin_login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');

    $blocked = adminLoginBlocked($email, $ip);
    $admin = $email !== '' && strlen($email) <= 255 ? query('SELECT id, password_hash, last_login_at, is_active FROM admins WHERE email = ?', [$email])[0] ?? null : null;
    // Hash the password even for an unknown email, so the response time doesn't reveal which emails
    // exist. A deactivated account gets the same message as a wrong password.
    $valid = $admin ? password_verify($password, $admin['password_hash']) && $admin['is_active'] : adminPasswordHash($password) === '';
    if ($blocked || !$valid) {
        adminExecute('INSERT INTO admin_login_attempts (ip, email, attempted_at) VALUES (?, ?, NOW())', [$ip, mb_substr($email, 0, 255)]);
        error_log('Admin sign-in failed for ' . json_encode($email) . ' from ' . $ip . ($blocked ? ' (too many attempts)' : ''));
        return adminLoginForm($email, ADMIN_LOGIN_ERROR);
    }

    adminExecute('DELETE FROM admin_login_attempts WHERE email = ?', [$email]);
    $hash = $admin['password_hash'];
    [$algorithm, $options] = adminHashAlgorithm();
    if (password_needs_rehash($hash, $algorithm, $options)) {
        $hash = adminPasswordHash($password);
        adminExecute('UPDATE admins SET password_hash = ? WHERE id = ?', [$hash, $admin['id']]);
    }
    adminExecute('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [$admin['id']]);
    session_regenerate_id(true);
    $_SESSION = ['admin_id' => (int) $admin['id'], 'hash' => adminHashFingerprint($hash), 'login_at' => time(), 'seen_at' => time(),
        'previous_login' => $admin['last_login_at'], 'csrf' => bin2hex(random_bytes(32))];
    adminRedirect('/admin');
}

/** Redirects to a fixed admin path after a form or a sign-in check. */
function adminRedirect(string $path): never {
    global $base;
    header('Location: ' . $base . $path, true, 303);
    exit;
}
function adminPage(string $html): never {
    header('Content-Type: text/html; charset=UTF-8');
    // Images may come from other https sites: a team logo can be stored as a full URL.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo render($html);
    exit;
}

/** A message shown once on the next admin page, such as "Team saved." */
function adminFlash(string $message): void {
    $_SESSION['flash'] = $message;
}
/** Renders a signed-in page: the view gets the top bar's data, the menu and the flash message. */
function adminView(string $name, array $admin, string $active, array $data = []): string {
    // Only the areas the admin may use are in the menu.
    $menu = array_filter(ADMIN_MENU, fn ($item) => $item[2] === null || adminCan($item[2]));
    $link = fn (string $path, array $item) => '<a href="{{BASE}}' . $path . '"' . ($path === $active ? ' aria-current="page"' : '') . '>'
        . '<svg class="admin-icon" viewBox="0 0 24 24" aria-hidden="true">' . $item[1] . '</svg>' . escape($item[0]) . '</a>';
    // Entries in menu order, each group in the place of its first entry: group key => its paths, or path => null.
    $entries = [];
    foreach ($menu as $path => $item) {
        if (isset($item[3])) $entries['group:' . $item[3]][] = $path;
        else $entries[$path] = null;
    }
    $nav = '';
    foreach ($entries as $key => $paths) {
        // A group with a single entry the admin may use is shown as a plain link.
        if ($paths === null || count($paths) === 1) {
            $path = $paths[0] ?? $key;
            $nav .= $link($path, $menu[$path]);
            continue;
        }
        $group = ADMIN_MENU_GROUPS[substr($key, 6)];
        $nav .= '<details class="admin-nav__group"><summary' . (in_array($active, $paths, true) ? ' class="is-current"' : '') . '>'
            . '<svg class="admin-icon" viewBox="0 0 24 24" aria-hidden="true">' . $group[1] . '</svg>' . escape($group[0])
            . '<svg class="admin-icon admin-nav__chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>'
            . '<div class="admin-nav__dropdown">' . implode('', array_map(fn ($path) => $link($path, $menu[$path]), $paths)) . '</div></details>';
    }
    $flash = is_string($_SESSION['flash'] ?? null) ? $_SESSION['flash'] : '';
    unset($_SESSION['flash']);
    return view($name, $data + ['admin_name' => escape($admin['name']), 'admin_email' => escape($admin['email']), 'csrf' => adminCsrfToken(), 'nav' => $nav,
        'admin_initial' => escape(mb_strtoupper(mb_substr($admin['name'], 0, 1))), 'admin_role' => escape($admin['role_name']), 'year' => date('Y'),
        'flash' => $flash === '' ? '' : '<p class="admin-flash" role="status">' . escape($flash) . '</p>']);
}

/**
 * Signed-in pages: pattern => [methods, file in includes/, renderer, permissions needed]. A
 * renderer gets the admin and the pattern's captures, and returns the page or redirects. The
 * admin must have every listed permission, and POST requests are checked for the CSRF token,
 * before the renderer runs.
 */
function adminPages(): array {
    $read = ['GET', 'HEAD'];
    $write = ['GET', 'HEAD', 'POST'];
    return [
        '~^/admin/profile$~' => [$write, 'admin-profile.php', fn ($admin) => renderAdminProfile($admin), []],
        '~^/admin$~' => [$read, 'dashboard.php', fn ($admin) => renderDashboard($admin, $_SESSION['previous_login'] ?? null), []],
        '~^/admin/seasons$~' => [$read, 'admin-seasons.php', fn ($admin) => renderAdminSeasons($admin), ['seasons']],
        '~^/admin/seasons/new$~' => [$write, 'admin-seasons.php', fn ($admin) => renderSeasonForm($admin, null), ['seasons']],
        '~^/admin/seasons/(\d+)$~' => [$write, 'admin-seasons.php', fn ($admin, $id) => renderSeasonForm($admin, (int) $id), ['seasons']],
        '~^/admin/seasons/(\d+)/delete$~' => [$write, 'admin-seasons.php', fn ($admin, $id) => renderSeasonDelete($admin, (int) $id), ['seasons', 'delete']],
        '~^/admin/teams$~' => [$read, 'admin-teams.php', fn ($admin) => renderAdminTeams($admin), ['teams']],
        '~^/admin/teams/new$~' => [$write, 'admin-teams.php', fn ($admin) => renderTeamForm($admin, null), ['teams']],
        '~^/admin/teams/(\d+)$~' => [$write, 'admin-teams.php', fn ($admin, $id) => renderTeamForm($admin, (int) $id), ['teams']],
        '~^/admin/teams/(\d+)/delete$~' => [$write, 'admin-teams.php', fn ($admin, $id) => renderTeamDelete($admin, (int) $id), ['teams', 'delete']],
        '~^/admin/teams/(\d+)/squad/(\d+)$~' => [$write, 'admin-squads.php', fn ($admin, $team, $season) => renderSquad($admin, (int) $team, (int) $season), ['squads']],
        '~^/admin/players$~' => [$read, 'admin-players.php', fn ($admin) => renderAdminPlayers($admin), ['players']],
        '~^/admin/players/new$~' => [$write, 'admin-players.php', fn ($admin) => renderPlayerForm($admin, null), ['players']],
        '~^/admin/players/(\d+)$~' => [$write, 'admin-players.php', fn ($admin, $id) => renderPlayerForm($admin, (int) $id), ['players']],
        '~^/admin/players/(\d+)/delete$~' => [$write, 'admin-players.php', fn ($admin, $id) => renderPlayerDelete($admin, (int) $id), ['players', 'delete']],
        '~^/admin/committee$~' => [$read, 'admin-committee.php', fn ($admin) => renderAdminCommittee($admin), ['committee']],
        '~^/admin/committee/payment$~' => [['POST'], 'admin-committee.php', fn ($admin) => saveCommitteePaymentSetting($admin), ['committee']],
        '~^/admin/committee/(\d+)$~' => [$write, 'admin-committee.php', fn ($admin, $id) => renderCommitteeApplication($admin, (int) $id), ['committee']],
        '~^/admin/committee/(\d+)/aadhaar/(front|back)$~' => [$read, 'admin-committee.php', fn ($admin, $id, $side) => sendCommitteeDocument($admin, (int) $id, $side), ['committee']],
        '~^/admin/committee/(\d+)/delete$~' => [$write, 'admin-committee.php', fn ($admin, $id) => renderCommitteeDelete($admin, (int) $id), ['committee', 'delete']],
        '~^/admin/membership$~' => [$read, 'admin-membership.php', fn ($admin) => renderAdminMembership($admin), ['membership']],
        '~^/admin/membership/payment$~' => [['POST'], 'admin-membership.php', fn ($admin) => saveMembershipPaymentSetting($admin), ['membership']],
        '~^/admin/membership/(\d+)$~' => [$write, 'admin-membership.php', fn ($admin, $id) => renderMembershipApplication($admin, (int) $id), ['membership']],
        '~^/admin/membership/(\d+)/profile$~' => [['POST'], 'admin-membership.php', fn ($admin, $id) => createMemberFromApplication($admin, (int) $id), ['membership']],
        '~^/admin/membership/(\d+)/verify$~' => [['POST'], 'admin-membership.php', fn ($admin, $id) => verifyMembershipPayment($admin, (int) $id), ['membership']],
        '~^/admin/membership/(\d+)/delete$~' => [$write, 'admin-membership.php', fn ($admin, $id) => renderMembershipDelete($admin, (int) $id), ['membership', 'delete']],
        '~^/admin/members$~' => [$read, 'admin-membership.php', fn ($admin) => renderAdminMembers($admin), ['membership']],
        '~^/admin/members/new$~' => [$write, 'admin-membership.php', fn ($admin) => renderMemberForm($admin, null), ['membership']],
        '~^/admin/members/(\d+)$~' => [$write, 'admin-membership.php', fn ($admin, $id) => renderMemberForm($admin, (int) $id), ['membership']],
        '~^/admin/members/(\d+)/delete$~' => [$write, 'admin-membership.php', fn ($admin, $id) => renderMemberDelete($admin, (int) $id), ['membership', 'delete']],
        '~^/admin/site-users$~' => [$read, 'admin-site-users.php', fn ($admin) => renderAdminSiteUsers($admin), ['site_users']],
        '~^/admin/site-users/(\d+)$~' => [$write, 'admin-site-users.php', fn ($admin, $id) => renderSiteUser($admin, (int) $id), ['site_users']],
        '~^/admin/hero$~' => [$write, 'admin-hero.php', fn ($admin) => renderAdminHero($admin), ['settings']],
        '~^/admin/hero/new$~' => [$write, 'admin-hero.php', fn ($admin) => renderHeroForm($admin, null), ['settings']],
        '~^/admin/hero/(\d+)$~' => [$write, 'admin-hero.php', fn ($admin, $id) => renderHeroForm($admin, (int) $id), ['settings']],
        '~^/admin/hero/(\d+)/delete$~' => [$write, 'admin-hero.php', fn ($admin, $id) => renderHeroDelete($admin, (int) $id), ['settings', 'delete']],
        '~^/admin/settings$~' => [$write, 'admin-settings.php', fn ($admin) => renderSettings($admin), ['settings']],
        '~^/admin/owners$~' => [$read, 'admin-owners.php', fn ($admin) => renderAdminOwners($admin), ['owners']],
        '~^/admin/owners/new$~' => [$write, 'admin-owners.php', fn ($admin) => renderOwnerForm($admin, null), ['owners']],
        '~^/admin/owners/(\d+)$~' => [$write, 'admin-owners.php', fn ($admin, $id) => renderOwnerForm($admin, (int) $id), ['owners']],
        '~^/admin/owners/(\d+)/delete$~' => [$write, 'admin-owners.php', fn ($admin, $id) => renderOwnerDelete($admin, (int) $id), ['owners', 'delete']],
        '~^/admin/integrations$~' => [$write, 'admin-integrations.php', fn ($admin) => renderIntegrations($admin), ['root']],
        '~^/admin/integrations/test-email$~' => [['POST'], 'admin-integrations.php', fn ($admin) => sendIntegrationTestEmail($admin), ['root']],
        '~^/admin/integrations/check-razorpay$~' => [['POST'], 'admin-integrations.php', fn ($admin) => checkIntegrationRazorpay($admin), ['root']],
        '~^/admin/users$~' => [$read, 'admin-users.php', fn ($admin) => renderAdminUsers($admin), ['root']],
        '~^/admin/users/new$~' => [$write, 'admin-users.php', fn ($admin) => renderUserForm($admin, null), ['root']],
        '~^/admin/users/(\d+)$~' => [$write, 'admin-users.php', fn ($admin, $id) => renderUserForm($admin, (int) $id), ['root']],
        '~^/admin/users/(\d+)/delete$~' => [$write, 'admin-users.php', fn ($admin, $id) => renderUserDelete($admin, (int) $id), ['root']],
        '~^/admin/trash$~' => [$read, 'admin-trash.php', fn ($admin) => renderTrash($admin), ['root']],
        '~^/admin/trash/(teams|players|owners)/(\d+)/restore$~' => [['POST'], 'admin-trash.php', fn ($admin, $kind, $id) => restoreFromTrash($admin, $kind, (int) $id), ['root']],
        '~^/admin/trash/(teams|players|owners)/(\d+)/delete$~' => [$write, 'admin-trash.php', fn ($admin, $kind, $id) => renderTrashDelete($admin, $kind, (int) $id), ['root']],
        '~^/admin/roles$~' => [$read, 'admin-users.php', fn ($admin) => renderAdminRoles($admin), ['root']],
        '~^/admin/roles/new$~' => [$write, 'admin-users.php', fn ($admin) => renderRoleForm($admin, null), ['root']],
        '~^/admin/roles/([a-z][a-z0-9-]*)$~' => [$write, 'admin-users.php', fn ($admin, $slug) => renderRoleForm($admin, $slug), ['root']],
        '~^/admin/roles/([a-z][a-z0-9-]*)/delete$~' => [$write, 'admin-users.php', fn ($admin, $slug) => renderRoleDelete($admin, $slug), ['root']],
    ];
}

/** The page for an admin who isn't allowed to use the one they asked for. */
function adminForbidden(array $admin): never {
    http_response_code(403);
    adminPage(adminView('templates.admin.message', $admin, '', ['title' => 'Not allowed',
        'message' => 'Your role (' . escape($admin['role_name']) . ') doesn’t include this page. If you need it, ask the root user to change your role.',
        'action' => '<a class="admin-button" href="{{BASE}}/admin">Go to the dashboard</a>']));
}

function handleAdminRequest(string $path): never {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $page = null;
    $captures = [];
    foreach (adminPages() as $pattern => $candidate) {
        if (!preg_match($pattern, $path, $match)) continue;
        [$page, $captures] = [$candidate, array_slice($match, 1)];
        break;
    }
    $methods = $page[0] ?? ADMIN_AUTH_ROUTES[$path] ?? notFound();
    if (!in_array($method, $methods, true)) errorPage(405);
    // Passwords and session cookies never travel over plain HTTP on the live site.
    if (appEnv() === 'production' && !adminHttps()) {
        header('Location: ' . preg_replace('~^http:~i', 'https:', absoluteUrl('{{BASE}}' . $path)), true, 301);
        exit;
    }

    adminStartSession();
    $admin = currentAdmin();
    if ($path === '/admin/logout') {
        if ($admin) {
            adminCheckCsrf();
            adminEndSession();
        }
        adminRedirect('/admin/login');
    }
    if ($page) {
        if (!$admin) adminRedirect('/admin/login');
        foreach ($page[3] as $permission) if (!adminCan($permission)) adminForbidden($admin);
        if ($method === 'POST') adminCheckCsrf();
        require_once __DIR__ . '/' . $page[1];
        adminPage($page[2]($admin, ...$captures));
    }
    if ($admin) adminRedirect('/admin');
    adminPage($method === 'POST' ? adminLogin() : adminLoginForm());
}
