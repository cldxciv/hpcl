<?php
declare(strict_types=1);

// The admin dashboard (/admin): site totals, the seasons and sign-in activity.
require_once __DIR__ . '/site.php';

const SEASON_STATUS_BADGES = ['upcoming' => 'upcoming', 'ongoing' => 'ongoing', 'completed' => 'completed'];

function dashboardNumber(int|string|null $value): string {
    return number_format((int) $value);
}
function dashboardDate(?string $value, string $format = 'j M Y'): string {
    return $value ? date($format, strtotime($value)) : '—';
}
function dashboardStatus(?string $status): string {
    $class = SEASON_STATUS_BADGES[$status] ?? 'unknown';
    return '<span class="admin-badge admin-badge--' . $class . '">' . escape(ucfirst($status ?: 'Unknown')) . '</span>';
}
/** "Open until …", "Opens …" or "Closed …" for a season's registration window. */
function registrationState(array $season): string {
    if (!$season['registration_starts_at'] || !$season['registration_ends_at']) return 'Not scheduled';
    $now = time();
    $start = strtotime($season['registration_starts_at']);
    $end = strtotime($season['registration_ends_at']);
    if ($now < $start) return 'Opens ' . date('j M Y, g:i A', $start);
    if ($now <= $end) return 'Open until ' . date('j M Y, g:i A', $end);
    return 'Closed ' . date('j M Y', $end);
}
/** A stat tile; $hint is a short line under the number. */
function dashboardTile(string $label, int|string $value, string $hint = ''): string {
    return '<div class="admin-tile"><p class="admin-tile__label">' . escape($label) . '</p><p class="admin-tile__value">' . dashboardNumber($value) . '</p>'
        . ($hint !== '' ? '<p class="admin-tile__hint">' . escape($hint) . '</p>' : '') . '</div>';
}

function renderDashboard(array $admin, ?string $previousLogin): string {
    // Records in the trash are left out of every count.
    $totals = query('SELECT (SELECT COUNT(*) FROM players WHERE deleted_at IS NULL) AS players, (SELECT COUNT(*) FROM teams WHERE deleted_at IS NULL) AS teams,
        (SELECT COUNT(*) FROM seasons) AS seasons, (SELECT COUNT(*) FROM owners WHERE deleted_at IS NULL) AS owners, (SELECT COUNT(*) FROM videos) AS videos,
        (SELECT COUNT(*) FROM blog_posts) AS blog_posts, (SELECT COUNT(*) FROM sponsors) AS sponsors, (SELECT COUNT(*) FROM shop_products) AS products,
        (SELECT COUNT(*) FROM teams WHERE deleted_at IS NOT NULL) + (SELECT COUNT(*) FROM players WHERE deleted_at IS NOT NULL)
            + (SELECT COUNT(*) FROM owners WHERE deleted_at IS NOT NULL) AS trash')[0];
    $tiles = dashboardTile('Players', $totals['players']) . dashboardTile('Teams', $totals['teams']) . dashboardTile('Seasons', $totals['seasons'])
        . dashboardTile('Owners', $totals['owners']) . dashboardTile('Videos', $totals['videos']) . dashboardTile('Blog posts', $totals['blog_posts'])
        . dashboardTile('Sponsors', $totals['sponsors'])
        . (shopEnabled() ? dashboardTile('Shop products', $totals['products']) : dashboardTile('Shop products', $totals['products'], 'Shop is hidden'))
        // The root user sees what is waiting in the trash.
        . (adminCan('root') ? '<a class="admin-tile admin-tile--link" href="{{BASE}}/admin/trash"><p class="admin-tile__label">In the trash</p><p class="admin-tile__value">'
            . dashboardNumber($totals['trash']) . '</p><p class="admin-tile__hint">Restore or delete for good</p></a>' : '');

    $seasons = query('SELECT s.id, s.route_key, s.name, s.status, s.start_date, s.registration_starts_at, s.registration_ends_at,
        (SELECT COUNT(*) FROM season_teams st JOIN teams t ON t.id = st.team_id WHERE st.season_id = s.id AND t.deleted_at IS NULL) AS teams,
        (SELECT COUNT(DISTINCT sp.player_id) FROM season_players sp JOIN players p ON p.id = sp.player_id WHERE sp.season_id = s.id AND p.deleted_at IS NULL) AS players,
        (SELECT COUNT(DISTINCT sp.player_id) FROM season_players sp JOIN players p ON p.id = sp.player_id WHERE sp.season_id = s.id AND sp.is_retained = 1 AND p.deleted_at IS NULL) AS retained,
        (SELECT COALESCE(SUM(ps.runs), 0) FROM player_season_stats ps WHERE ps.season_id = s.id) AS runs,
        (SELECT COALESCE(SUM(ps.wickets), 0) FROM player_season_stats ps WHERE ps.season_id = s.id) AS wickets
        FROM seasons s ORDER BY s.route_key DESC');
    $latest = $seasons[0] ?? null;
    $season = $latest
        ? '<div class="admin-panel__head"><h2>' . escape($latest['name']) . '</h2>' . dashboardStatus($latest['status']) . '</div>'
            . '<dl class="admin-facts"><div><dt>Starts</dt><dd>' . dashboardDate($latest['start_date']) . '</dd></div>'
            . '<div><dt>Registration</dt><dd>' . escape(registrationState($latest)) . '</dd></div>'
            . '<div><dt>Teams</dt><dd>' . dashboardNumber($latest['teams']) . '</dd></div>'
            . '<div><dt>Squad players</dt><dd>' . dashboardNumber($latest['players']) . '</dd></div>'
            . '<div><dt>Retained players</dt><dd>' . dashboardNumber($latest['retained']) . '</dd></div></dl>'
            . '<p class="admin-links"><a href="{{BASE}}/teams/season/' . (int) $latest['route_key'] . '">Teams page</a> · <a href="{{BASE}}/stats/season/' . (int) $latest['route_key'] . '">Stats page</a></p>'
        : '<h2>Latest season</h2><p class="admin-empty">No seasons yet.' . (adminCan('seasons') ? ' <a href="{{BASE}}/admin/seasons/new">Add a season</a>.' : '') . '</p>';
    $rows = implode('', array_map(fn ($row) => '<tr><td><a href="{{BASE}}/' . (adminCan('seasons') ? 'admin/seasons/' . (int) $row['id'] : 'teams/season/' . (int) $row['route_key']) . '">' . escape($row['name']) . '</a></td>'
        . '<td>' . dashboardStatus($row['status']) . '</td><td>' . dashboardDate($row['start_date']) . '</td>'
        . '<td class="admin-num">' . dashboardNumber($row['teams']) . '</td><td class="admin-num">' . dashboardNumber($row['players']) . '</td>'
        . '<td class="admin-num">' . dashboardNumber($row['runs']) . '</td><td class="admin-num">' . dashboardNumber($row['wickets']) . '</td></tr>', $seasons));
    $seasonTable = $seasons
        ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Season</th><th>Status</th><th>Starts</th>'
            . '<th class="admin-num">Teams</th><th class="admin-num">Players</th><th class="admin-num">Runs</th><th class="admin-num">Wickets</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        : '<p class="admin-empty">No seasons yet.</p>';

    $security = query('SELECT (SELECT COUNT(*) FROM admin_login_attempts WHERE attempted_at > NOW() - INTERVAL 1 DAY) AS failed,
        (SELECT COUNT(DISTINCT ip) FROM admin_login_attempts WHERE attempted_at > NOW() - INTERVAL 1 DAY) AS ips,
        (SELECT COUNT(*) FROM admins) AS admins')[0];
    $activity = '<ul class="admin-checklist"><li class="' . ((int) $security['failed'] ? 'is-warning' : 'is-ok') . '"><span>Failed sign-ins in the last 24 hours</span><strong>'
        . dashboardNumber($security['failed']) . ((int) $security['failed'] ? ' <small>from ' . dashboardNumber($security['ips']) . ' IP' . ((int) $security['ips'] === 1 ? '' : 's') . '</small>' : '') . '</strong></li>'
        . '<li><span>Admin accounts</span><strong>' . dashboardNumber($security['admins']) . '</strong></li>'
        . '<li><span>Your previous sign-in</span><strong>' . ($previousLogin ? dashboardDate($previousLogin, 'j M Y, g:i A') : 'First sign-in') . '</strong></li></ul>';

    return adminView('templates.admin.dashboard', $admin, '/admin', [
        'tiles' => $tiles, 'season' => $season, 'seasons' => $seasonTable, 'activity' => $activity]);
}
