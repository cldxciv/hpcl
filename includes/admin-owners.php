<?php
declare(strict_types=1);

// Admin pages for team owners (/admin/owners): list, add, edit and delete owners, and choose the
// teams they own in each season (season_team_owners). Deleting moves an owner to the trash; their
// links are kept for a restore.
require_once __DIR__ . '/admin-forms.php';

const OWNER_PHOTO_DIR = 'storage/uploads/owners';
// Empty "Owns" rows offered on the form for adding teams.
const OWNER_BLANK_ROWS = 2;

/** The teams an owner owns: [['pair' => "season-team", 'order' => n, ...]], newest season first. */
function ownerTeams(int $ownerId): array {
    return array_map(fn ($row) => ['pair' => $row['season_id'] . '-' . $row['team_id'], 'order' => (string) $row['display_order'], 'season' => $row['season'], 'team' => $row['team']],
        query('SELECT sto.season_id, sto.team_id, sto.display_order, s.name AS season, t.name AS team FROM season_team_owners sto
            JOIN seasons s ON s.id = sto.season_id JOIN teams t ON t.id = sto.team_id WHERE sto.owner_id = ? ORDER BY s.route_key DESC, t.name', [$ownerId]));
}
/** Every team of every season, for the "Owns" pickers: [season name => ["season-team" => team name]]. */
function seasonTeamChoices(): array {
    $choices = [];
    foreach (query('SELECT s.id AS season_id, s.name AS season, t.id AS team_id, t.name AS team FROM season_teams st
        JOIN seasons s ON s.id = st.season_id JOIN teams t ON t.id = st.team_id WHERE t.deleted_at IS NULL ORDER BY s.route_key DESC, t.name') as $row) {
        $choices[$row['season']][$row['season_id'] . '-' . $row['team_id']] = $row['team'];
    }
    return $choices;
}

function renderAdminOwners(array $admin): string {
    $search = adminSearch();
    $where = 'WHERE o.deleted_at IS NULL' . ($search === '' ? '' : ' AND o.name LIKE ?');
    $owners = query("SELECT o.id, o.name, o.photo,
        (SELECT GROUP_CONCAT(CONCAT(t.name, ' (', s.route_key, ')') ORDER BY s.route_key DESC, t.name SEPARATOR ', ')
            FROM season_team_owners sto JOIN teams t ON t.id = sto.team_id JOIN seasons s ON s.id = sto.season_id WHERE sto.owner_id = o.id) AS teams
        FROM owners o $where ORDER BY o.name", $search === '' ? [] : [likePattern($search)]);
    $rows = implode('', array_map(fn ($owner) => '<tr><td class="admin-cell-team">' . adminImageHtml($owner['photo'])
        . '<a href="{{BASE}}/admin/owners/' . (int) $owner['id'] . '">' . escape($owner['name']) . '</a></td>'
        . '<td class="admin-wide-only admin-cell-wrap">' . ($owner['teams'] ? escape($owner['teams']) : '<span class="admin-muted">None</span>') . '</td>'
        . '<td class="admin-actions"><a href="{{BASE}}/admin/owners/' . (int) $owner['id'] . '">Edit</a>'
        . (adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/owners/' . (int) $owner['id'] . '/delete">Delete</a>' : '') . '</td></tr>', $owners));
    $total = (int) query('SELECT COUNT(*) AS n FROM owners WHERE deleted_at IS NULL')[0]['n'];
    $summary = $search === '' ? number_format($total) . ' owners' : number_format(count($owners)) . ' of ' . number_format($total) . ' owners match “' . escape($search) . '”';
    $table = $owners
        ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Owner</th><th class="admin-wide-only">Owns (season)</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>'
        : '<p class="admin-empty">' . ($search === '' ? 'No owners yet.' : 'No owners match your search.') . '</p>';
    return adminView('templates.admin.owners', $admin, '/admin/owners', ['search' => escape($search), 'summary' => $summary, 'table' => $table]);
}

function renderOwnerForm(array $admin, ?int $id): string {
    $owner = $id === null ? ['id' => null, 'name' => '', 'photo' => null] : query('SELECT * FROM owners WHERE id = ? AND deleted_at IS NULL', [$id])[0] ?? notFound();
    $choices = seasonTeamChoices();
    $teams = $id === null ? [] : ownerTeams($id);
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') [$owner, $teams, $errors] = saveOwner($owner, $choices);
    return ownerForm($admin, $owner, $teams, $choices, $errors);
}

/** Validates and saves the posted form; redirects on success, else returns [owner, teams, errors] to show again. */
function saveOwner(array $owner, array $choices): array {
    $input = ['id' => $owner['id'], 'photo' => $owner['photo'], 'name' => postedText('name')];
    $errors = [];
    if ($input['name'] === '') $errors['name'] = 'Enter the owner’s name.';
    elseif (mb_strlen($input['name']) > 255) $errors['name'] = 'The name must be 255 characters or fewer.';

    $valid = [];
    foreach ($choices as $seasonTeams) $valid += array_fill_keys(array_keys($seasonTeams), true);
    $teams = [];
    $seen = [];
    foreach (is_array($_POST['teams'] ?? null) ? $_POST['teams'] : [] as $row) {
        if (!is_array($row)) continue;
        $pair = is_string($row['pair'] ?? null) ? $row['pair'] : '';
        $order = trim(is_string($row['order'] ?? null) ? $row['order'] : '');
        if ($pair === '') continue;
        $teams[] = ['pair' => $pair, 'order' => $order];
        if (!isset($valid[$pair])) $errors['teams'][] = 'One of the chosen teams doesn’t play in that season any more. Choose again.';
        elseif (isset($seen[$pair])) $errors['teams'][] = 'The same team and season is chosen twice.';
        if ($order !== '' && (!ctype_digit($order) || (int) $order > 999)) $errors['teams'][] = 'Display order must be a whole number from 0 to 999.';
        $seen[$pair] = true;
    }
    if (isset($errors['teams'])) $errors['teams'] = array_values(array_unique($errors['teams']));

    $upload = uploadedImage('photo', 'photo');
    if (is_string($upload)) $errors['photo'] = $upload;
    if ($errors) return [$input, $teams, $errors];

    $newPhoto = null;
    if (is_array($upload)) {
        $newPhoto = saveUploadedImage($upload, OWNER_PHOTO_DIR);
        if (!$newPhoto) return [$input, $teams, ['photo' => 'The photo could not be saved: the folder ' . OWNER_PHOTO_DIR . ' isn’t writable by the web server.']];
        $input['photo'] = $newPhoto;
    } elseif (($_POST['remove_photo'] ?? null) === '1') {
        $input['photo'] = null;
    }

    // Old photo files are left in place: they may still be linked from elsewhere.
    try {
        adminTransaction(function () use (&$input, $teams) {
            if ($input['id'] === null) {
                $input['id'] = nextId('owners');
                adminExecute('INSERT INTO owners (id, name, photo) VALUES (?, ?, ?)', [$input['id'], $input['name'], $input['photo']]);
            } else {
                adminExecute('UPDATE owners SET name = ?, photo = ? WHERE id = ?', [$input['name'], $input['photo'], $input['id']]);
            }
            adminExecute('DELETE FROM season_team_owners WHERE owner_id = ?', [$input['id']]);
            foreach ($teams as $team) {
                [$seasonId, $teamId] = array_map('intval', explode('-', $team['pair']));
                adminExecute('INSERT INTO season_team_owners (season_id, team_id, owner_id, display_order) VALUES (?, ?, ?, ?)', [$seasonId, $teamId, $input['id'], (int) $team['order']]);
            }
        });
    } catch (Throwable $error) {
        if ($newPhoto) @unlink(dirname(__DIR__) . '/' . $newPhoto);
        throw $error;
    }
    adminFlash(($owner['id'] === null ? 'Added ' : 'Saved ') . $input['name'] . '.');
    adminRedirect('/admin/owners');
}

function ownerForm(array $admin, array $owner, array $teams, array $choices, array $errors): string {
    $teams = array_merge($teams, array_fill(0, OWNER_BLANK_ROWS, ['pair' => '', 'order' => '']));
    $rows = '';
    foreach ($teams as $i => $team) {
        $options = '<option value="">— None —</option>';
        foreach ($choices as $season => $seasonTeams) {
            $options .= '<optgroup label="' . escape($season) . '">';
            foreach ($seasonTeams as $pair => $name) $options .= '<option value="' . $pair . '"' . ($pair === $team['pair'] ? ' selected' : '') . '>' . escape($name . ' · ' . $season) . '</option>';
            $options .= '</optgroup>';
        }
        $rows .= '<tr><td><select name="teams[' . $i . '][pair]" aria-label="Team and season, row ' . ($i + 1) . '">' . $options . '</select></td>'
            . '<td><input class="admin-input-small" type="text" inputmode="numeric" name="teams[' . $i . '][order]" value="' . escape($team['order']) . '" maxlength="3" placeholder="0" aria-label="Display order, row ' . ($i + 1) . '"></td></tr>';
    }
    $isNew = $owner['id'] === null;
    return adminView('templates.admin.owner-form', $admin, '/admin/owners', [
        'title' => $isNew ? 'Add an owner' : 'Edit ' . escape($owner['name']),
        'action' => '{{BASE}}/admin/owners/' . ($isNew ? 'new' : (int) $owner['id']),
        'summary' => formErrorSummary($errors, 'photo'),
        'name' => escape($owner['name']), 'name_error' => fieldError($errors, 'name'), 'name_invalid' => fieldInvalid($errors, 'name'),
        'photo_current' => $owner['photo'] ? '<div class="admin-logo-current">' . adminImageHtml($owner['photo'], 'admin-logo-preview') . '<label class="admin-check"><input type="checkbox" name="remove_photo" value="1">Remove the photo</label></div>' : '',
        'photo_error' => fieldError($errors, 'photo'), 'photo_invalid' => fieldInvalid($errors, 'photo'),
        'teams' => $choices ? '<div class="admin-table-wrap"><table class="admin-table admin-table--form admin-table--stack"><thead><tr><th>Team · season</th><th>Display order</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            : '<p class="admin-empty">No teams are in a season yet. Add teams to seasons on the <a href="{{BASE}}/admin/teams">Teams</a> page first.</p>',
        'teams_error' => isset($errors['teams']) ? implode('', array_map(fn ($message) => '<p class="admin-field-error">' . escape($message) . '</p>', $errors['teams'])) : '',
        'submit' => $isNew ? 'Add owner' : 'Save changes',
        'delete' => $isNew || !adminCan('delete') ? '' : '<a class="admin-danger-link" href="{{BASE}}/admin/owners/' . (int) $owner['id'] . '/delete">Delete this owner</a>',
    ]);
}

function renderOwnerDelete(array $admin, int $id): string {
    $owner = query('SELECT * FROM owners WHERE id = ? AND deleted_at IS NULL', [$id])[0] ?? notFound();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        moveToTrash('owners', $id);
        adminFlash('Deleted ' . $owner['name'] . '.');
        adminRedirect('/admin/owners');
    }
    $teams = ownerTeams($id);
    $body = deleteConfirmation('owner', $owner['name'], '/admin/owners/' . $id . '/delete', '/admin/owners/' . $id,
        $teams ? '<p>They will also be removed as owner of:</p><ul class="admin-list">' . implode('', array_map(fn ($team) => '<li>' . escape($team['team'] . ', ' . $team['season']) . '</li>', $teams)) . '</ul>' : '');
    return adminView('templates.admin.delete', $admin, '/admin/owners', ['title' => 'Delete ' . escape($owner['name']), 'image' => adminImageHtml($owner['photo'], 'admin-logo-preview'),
        'back' => '<a href="{{BASE}}/admin/owners">&larr; All owners</a>', 'body' => $body]);
}
