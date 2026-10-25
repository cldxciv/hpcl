<?php
declare(strict_types=1);

// Admin pages for teams (/admin/teams): list, add, edit and delete teams (to the trash), and choose
// the seasons they play in (season_teams). Squads, stats and owners are edited elsewhere, so a team
// can't be deleted, or removed from a season, while it has any of them.
require_once __DIR__ . '/admin-forms.php';

const TEAM_LOGO_DIR = 'storage/uploads/teams';
const TEAM_SLUG_PATTERN = '~^[a-z0-9]+(?:-[a-z0-9]+)*$~';
// What is attached to a team in a season and stops it being removed: key => [count per season, label].
// Players and owners in the trash don't count: they can't be seen or removed.
const TEAM_USAGE = [
    'players' => ['SELECT sp.season_id, COUNT(*) AS n FROM season_players sp JOIN players p ON p.id = sp.player_id WHERE sp.team_id = ? AND p.deleted_at IS NULL GROUP BY sp.season_id', 'squad player'],
    'stats' => ['SELECT season_id, COUNT(*) AS n FROM player_season_stats WHERE team_id = ? GROUP BY season_id', 'stats row'],
    'owners' => ['SELECT sto.season_id, COUNT(*) AS n FROM season_team_owners sto JOIN owners o ON o.id = sto.owner_id WHERE sto.team_id = ? AND o.deleted_at IS NULL GROUP BY sto.season_id', 'owner'],
];

function teamSlug(string $text): string {
    return trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($text)), '-');
}
/** Squad players, stats rows and owners of a team, per season: [season_id => [key => count]]. */
function teamUsage(int $teamId): array {
    $usage = [];
    foreach (TEAM_USAGE as $key => [$sql]) {
        foreach (query($sql, [$teamId]) as $row) $usage[$row['season_id']][$key] = (int) $row['n'];
    }
    return $usage;
}
/** "3 squad players, 1 owner" */
function usageText(array $counts): string {
    $parts = [];
    foreach (TEAM_USAGE as $key => [, $label]) {
        $count = $counts[$key] ?? 0;
        if ($count) $parts[] = number_format($count) . ' ' . $label . ($count === 1 ? '' : 's');
    }
    return implode(', ', $parts);
}
function renderAdminTeams(array $admin): string {
    $search = adminSearch();
    $where = 'WHERE t.deleted_at IS NULL';
    $params = [];
    if ($search !== '') {
        $like = likePattern($search);
        $where .= ' AND (t.name LIKE ? OR t.short_name LIKE ? OR t.slug LIKE ?)';
        $params = [$like, $like, $like];
    }
    $teams = query("SELECT t.id, t.name, t.short_name, t.slug, t.logo,
        (SELECT GROUP_CONCAT(s.route_key ORDER BY s.route_key SEPARATOR ', ') FROM season_teams st JOIN seasons s ON s.id = st.season_id WHERE st.team_id = t.id) AS seasons,
        (SELECT COUNT(DISTINCT sp.player_id) FROM season_players sp JOIN players p ON p.id = sp.player_id WHERE sp.team_id = t.id AND p.deleted_at IS NULL) AS players
        FROM teams t $where ORDER BY t.name", $params);
    $rows = implode('', array_map(fn ($team) => '<tr><td class="admin-cell-team">' . adminImageHtml($team['logo'])
        . '<a href="{{BASE}}/admin/teams/' . (int) $team['id'] . '">' . escape($team['name']) . '</a></td>'
        . '<td class="admin-wide-only">' . escape((string) $team['short_name']) . '</td><td class="admin-wide-only"><code>' . escape((string) $team['slug']) . '</code></td>'
        . '<td class="admin-wide-only">' . ($team['seasons'] ? escape($team['seasons']) : '<span class="admin-muted">None</span>') . '</td>'
        . '<td class="admin-num">' . number_format((int) $team['players']) . '</td>'
        . '<td class="admin-actions"><a href="{{BASE}}/admin/teams/' . (int) $team['id'] . '">Edit</a>'
        . (adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/teams/' . (int) $team['id'] . '/delete">Delete</a>' : '') . '</td></tr>', $teams));
    $total = (int) query('SELECT COUNT(*) AS n FROM teams WHERE deleted_at IS NULL')[0]['n'];
    $summary = $search === '' ? number_format($total) . ' teams' : number_format(count($teams)) . ' of ' . number_format($total) . ' teams match “' . escape($search) . '”';
    $table = $teams
        ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Team</th><th class="admin-wide-only">Short name</th><th class="admin-wide-only">Slug</th><th class="admin-wide-only">Seasons</th><th class="admin-num">Players</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>'
        : '<p class="admin-empty">' . ($search === '' ? 'No teams yet.' : 'No teams match your search.') . '</p>';
    return adminView('templates.admin.teams', $admin, '/admin/teams', ['search' => escape($search), 'summary' => $summary, 'table' => $table]);
}

function renderTeamForm(array $admin, ?int $id): string {
    $team = $id === null ? ['id' => null, 'name' => '', 'short_name' => '', 'slug' => '', 'logo' => null, 'description' => '']
        : query('SELECT * FROM teams WHERE id = ? AND deleted_at IS NULL', [$id])[0] ?? notFound();
    $seasons = adminSeasons();
    $memberships = $id === null ? [] : array_column(query('SELECT season_id, pool FROM season_teams WHERE team_id = ?', [$id]), 'pool', 'season_id');
    // Seasons saved for the team, which have a squad page.
    $saved = array_keys($memberships);
    $usage = $id === null ? [] : teamUsage($id);
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') [$team, $memberships, $errors] = saveTeam($team, $seasons, $usage);
    return teamForm($admin, $team, $seasons, $memberships, $usage, $errors, $saved);
}

/** Validates and saves the posted form; redirects on success, else returns [team, memberships, errors] to show again. */
function saveTeam(array $team, array $seasons, array $usage): array {
    $input = ['id' => $team['id'], 'logo' => $team['logo'], 'name' => postedText('name'), 'short_name' => postedText('short_name'),
        'slug' => strtolower(postedText('slug')), 'description' => postedText('description')];
    if ($input['slug'] === '') $input['slug'] = teamSlug($input['name']);
    $errors = [];
    if ($input['name'] === '') $errors['name'] = 'Enter the team’s name.';
    elseif (mb_strlen($input['name']) > 255) $errors['name'] = 'The name must be 255 characters or fewer.';
    if (mb_strlen($input['short_name']) > 255) $errors['short_name'] = 'The short name must be 255 characters or fewer.';
    if ($input['slug'] === '') $errors['slug'] = 'Enter a slug, such as shimla-titans.';
    elseif (strlen($input['slug']) > 255 || !preg_match(TEAM_SLUG_PATTERN, $input['slug'])) $errors['slug'] = 'Use only lowercase letters, numbers and single hyphens, such as shimla-titans.';
    elseif ($taken = query('SELECT deleted_at FROM teams WHERE slug = ? AND id <> ?', [$input['slug'], (int) $team['id']])[0] ?? null) {
        // Only the root user is told the other team is in the trash.
        $errors['slug'] = $taken['deleted_at'] && adminCan('root') ? 'A team in your Trash uses this slug. Choose another, or delete that team permanently first.' : 'Another team already uses this slug.';
    }
    if (mb_strlen($input['description']) > 10000) $errors['description'] = 'The description must be 10,000 characters or fewer.';

    $posted = is_array($_POST['seasons'] ?? null) ? $_POST['seasons'] : [];
    $memberships = [];
    foreach ($seasons as $season) {
        $row = $posted[$season['id']] ?? null;
        if (!is_array($row) || ($row['in'] ?? null) !== '1') {
            if (isset($usage[$season['id']])) $errors['seasons'][] = $season['name'] . ' has ' . usageText($usage[$season['id']]) . ' for this team, so the team can’t be removed from it.';
            continue;
        }
        $pool = trim(is_string($row['pool'] ?? null) ? $row['pool'] : '');
        if (mb_strlen($pool) > 16) $errors['seasons'][] = 'The pool for ' . $season['name'] . ' must be 16 characters or fewer.';
        $memberships[$season['id']] = $pool === '' ? null : $pool;
    }

    $upload = uploadedImage('logo', 'logo');
    if (is_string($upload)) $errors['logo'] = $upload;
    if ($errors) return [$input, $memberships, $errors];

    $newLogo = null;
    if (is_array($upload)) {
        $newLogo = saveUploadedImage($upload, TEAM_LOGO_DIR);
        if (!$newLogo) return [$input, $memberships, ['logo' => 'The logo could not be saved: the folder ' . TEAM_LOGO_DIR . ' isn’t writable by the web server.']];
        $input['logo'] = $newLogo;
    } elseif (($_POST['remove_logo'] ?? null) === '1') {
        $input['logo'] = null;
    }

    // Old logo files are left in place: they may still be linked from elsewhere.
    try {
        adminTransaction(function () use (&$input, $memberships) {
            $values = [$input['name'], $input['short_name'] === '' ? null : $input['short_name'], $input['slug'], $input['logo'], $input['description'] === '' ? null : $input['description']];
            if ($input['id'] === null) {
                $input['id'] = nextId('teams');
                adminExecute('INSERT INTO teams (name, short_name, slug, logo, description, id) VALUES (?, ?, ?, ?, ?, ?)', [...$values, $input['id']]);
            } else {
                adminExecute('UPDATE teams SET name = ?, short_name = ?, slug = ?, logo = ?, description = ? WHERE id = ?', [...$values, $input['id']]);
            }
            $kept = array_keys($memberships);
            adminExecute('DELETE FROM season_teams WHERE team_id = ?' . ($kept ? ' AND season_id NOT IN (' . implode(', ', array_fill(0, count($kept), '?')) . ')' : ''), [$input['id'], ...$kept]);
            foreach ($memberships as $seasonId => $pool) {
                adminExecute('INSERT INTO season_teams (season_id, team_id, pool) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pool = VALUES(pool)', [$seasonId, $input['id'], $pool]);
            }
        });
    } catch (Throwable $error) {
        if ($newLogo) @unlink(dirname(__DIR__) . '/' . $newLogo);
        throw $error;
    }
    adminFlash(($team['id'] === null ? 'Added ' : 'Saved ') . $input['name'] . '.');
    adminRedirect('/admin/teams');
}

function teamForm(array $admin, array $team, array $seasons, array $memberships, array $usage, array $errors, array $saved): string {
    $error = fn (string $field) => fieldError($errors, $field);
    $invalid = fn (string $field) => fieldInvalid($errors, $field);
    $seasonRows = implode('', array_map(function ($season) use ($memberships, $usage, $saved, $team) {
        $id = (int) $season['id'];
        $in = array_key_exists($id, $memberships);
        $locked = isset($usage[$id]);
        $name = 'seasons[' . $id . ']';
        // A season with squad players, stats or owners stays ticked: the box is disabled, so a hidden field sends it.
        return '<tr><td><label class="admin-check"><input type="checkbox" name="' . $name . '[in]" value="1"' . ($in || $locked ? ' checked' : '') . ($locked ? ' disabled' : '') . '>'
            . escape($season['name']) . '</label>' . ($locked ? '<input type="hidden" name="' . $name . '[in]" value="1">' : '') . '</td>'
            . '<td><input class="admin-input-small" type="text" name="' . $name . '[pool]" value="' . escape((string) ($memberships[$id] ?? '')) . '" maxlength="16" aria-label="Pool in ' . escape($season['name']) . '"></td>'
            . '<td class="admin-muted">' . ($locked ? escape(usageText($usage[$id])) : '')
            . (in_array($id, $saved, true) && adminCan('squads') ? ($locked ? '<br>' : '') . '<a href="{{BASE}}/admin/teams/' . (int) $team['id'] . '/squad/' . $id . '">Manage squad</a>' : '') . '</td></tr>';
    }, $seasons));
    $isNew = $team['id'] === null;
    return adminView('templates.admin.team-form', $admin, '/admin/teams', [
        'title' => $isNew ? 'Add a team' : 'Edit ' . escape($team['name']),
        'action' => '{{BASE}}/admin/teams/' . ($isNew ? 'new' : (int) $team['id']),
        'summary' => formErrorSummary($errors, 'logo'),
        'name' => escape($team['name']), 'name_error' => $error('name'), 'name_invalid' => $invalid('name'),
        'short_name' => escape((string) $team['short_name']), 'short_name_error' => $error('short_name'), 'short_name_invalid' => $invalid('short_name'),
        'slug' => escape((string) $team['slug']), 'slug_error' => $error('slug'), 'slug_invalid' => $invalid('slug'),
        'slug_hint' => $isNew ? 'Used in the team’s address. Leave empty to make it from the name.' : 'Used in the team’s address, /teams/season/{n}/<b>' . escape((string) $team['slug']) . '</b>. Changing it breaks existing links to the team.',
        'logo_current' => $team['logo'] ? '<div class="admin-logo-current">' . adminImageHtml($team['logo'], 'admin-logo-preview') . '<label class="admin-check"><input type="checkbox" name="remove_logo" value="1">Remove the logo</label></div>' : '',
        'logo_error' => $error('logo'), 'logo_invalid' => $invalid('logo'),
        'description' => escape((string) $team['description']), 'description_error' => $error('description'), 'description_invalid' => $invalid('description'),
        'seasons' => $seasons ? '<div class="admin-table-wrap"><table class="admin-table admin-table--form"><thead><tr><th>Plays in</th><th>Pool</th><th>Squad, stats and owners</th></tr></thead><tbody>' . $seasonRows . '</tbody></table></div>'
            : '<p class="admin-empty">No seasons yet.</p>',
        'seasons_error' => isset($errors['seasons']) ? implode('', array_map(fn ($message) => '<p class="admin-field-error">' . escape($message) . '</p>', $errors['seasons'])) : '',
        'submit' => $isNew ? 'Add team' : 'Save changes',
        'delete' => $isNew || !adminCan('delete') ? '' : '<a class="admin-danger-link" href="{{BASE}}/admin/teams/' . (int) $team['id'] . '/delete">Delete this team</a>',
    ]);
}

function renderTeamDelete(array $admin, int $id): string {
    $team = query('SELECT * FROM teams WHERE id = ? AND deleted_at IS NULL', [$id])[0] ?? notFound();
    $usage = teamUsage($id);
    $seasonNames = array_column(query('SELECT s.id, s.name FROM season_teams st JOIN seasons s ON s.id = st.season_id WHERE st.team_id = ? ORDER BY s.route_key', [$id]), 'name', 'id');
    if ($usage) {
        $items = implode('', array_map(fn ($seasonId, $counts) => '<li>' . escape($seasonNames[$seasonId] ?? 'Season #' . $seasonId) . ': ' . escape(usageText($counts)) . '</li>', array_keys($usage), $usage));
        $body = '<p>' . escape($team['name']) . ' can’t be deleted while it still has:</p><ul class="admin-list">' . $items . '</ul>'
            . '<p>Remove them first: owners on the <a href="{{BASE}}/admin/owners">Owners</a> page, squad players and stats in phpMyAdmin (tables season_players and player_season_stats).</p>'
            . '<p class="admin-form-actions"><a class="admin-button-secondary" href="{{BASE}}/admin/teams/' . $id . '">Back to the team</a></p>';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // Its seasons are kept, so restoring the team puts it back where it was.
        moveToTrash('teams', $id);
        adminFlash('Deleted ' . $team['name'] . '.');
        adminRedirect('/admin/teams');
    } else {
        $body = deleteConfirmation('team', $team['name'], '/admin/teams/' . $id . '/delete', '/admin/teams/' . $id,
            $seasonNames ? '<p>It will also be removed from ' . escape(implode(', ', $seasonNames)) . '.</p>' : '');
    }
    return adminView('templates.admin.delete', $admin, '/admin/teams', ['title' => 'Delete ' . escape($team['name']), 'image' => adminImageHtml($team['logo'], 'admin-logo-preview'),
        'back' => '<a href="{{BASE}}/admin/teams">&larr; All teams</a>', 'body' => $body]);
}
