<?php
declare(strict_types=1);

// Admin pages for seasons (/admin/seasons): list, add, edit and delete empty seasons.
require_once __DIR__ . '/admin-forms.php';

const SEASON_IMAGE_DIR = 'storage/uploads/seasons';
const SEASON_STATUSES = ['upcoming' => 'Upcoming', 'ongoing' => 'Ongoing', 'completed' => 'Completed'];

function seasonUsage(int $id): array {
    $tables = [
        'teams' => ['season_teams', 'team'],
        'players' => ['season_players', 'squad player'],
        'owners' => ['season_team_owners', 'owner assignment'],
        'stats' => ['player_season_stats', 'stats row'],
    ];
    $usage = [];
    foreach ($tables as $key => [$table, $label]) {
        $count = (int) query("SELECT COUNT(*) AS n FROM $table WHERE season_id = ?", [$id])[0]['n'];
        if ($count) $usage[$key] = [$count, $label];
    }
    return $usage;
}

function seasonUsageText(array $usage): string {
    return implode(', ', array_map(fn ($item) => number_format($item[0]) . ' ' . $item[1] . ($item[0] === 1 ? '' : 's'), $usage));
}

function renderAdminSeasons(array $admin): string {
    $search = adminSearch();
    $where = '';
    $params = [];
    if ($search !== '') {
        $like = likePattern($search);
        $where = 'WHERE s.name LIKE ? OR CAST(s.route_key AS CHAR) LIKE ? OR s.status LIKE ?';
        $params = [$like, $like, $like];
    }
    $seasons = query("SELECT s.*,
        (SELECT COUNT(*) FROM season_teams st WHERE st.season_id = s.id) AS teams,
        (SELECT COUNT(DISTINCT sp.player_id) FROM season_players sp WHERE sp.season_id = s.id) AS players
        FROM seasons s $where ORDER BY s.route_key DESC", $params);
    $rows = implode('', array_map(fn ($season) => '<tr><td class="admin-cell-team">' . adminImageHtml($season['image'])
        . '<a href="{{BASE}}/admin/seasons/' . (int) $season['id'] . '">' . escape($season['name']) . '</a></td>'
        . '<td>' . escape(SEASON_STATUSES[$season['status']] ?? ucfirst((string) ($season['status'] ?: 'Unknown'))) . '</td>'
        . '<td class="admin-wide-only">' . escape($season['start_date'] ?: '—') . '</td><td class="admin-num">' . number_format((int) $season['teams']) . '</td>'
        . '<td class="admin-num">' . number_format((int) $season['players']) . '</td><td class="admin-actions"><a href="{{BASE}}/admin/seasons/' . (int) $season['id'] . '">Edit</a>'
        . (adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/seasons/' . (int) $season['id'] . '/delete">Delete</a>' : '') . '</td></tr>', $seasons));
    $total = (int) query('SELECT COUNT(*) AS n FROM seasons')[0]['n'];
    $summary = $search === '' ? number_format($total) . ' seasons' : number_format(count($seasons)) . ' of ' . number_format($total) . ' seasons match “' . escape($search) . '”';
    $table = $seasons ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Season</th><th>Status</th><th class="admin-wide-only">Starts</th><th class="admin-num">Teams</th><th class="admin-num">Players</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        : '<p class="admin-empty">' . ($search === '' ? 'No seasons yet.' : 'No seasons match your search.') . '</p>';
    return adminView('templates.admin.seasons', $admin, '/admin/seasons', ['search' => escape($search), 'summary' => $summary, 'table' => $table]);
}

function renderSeasonForm(array $admin, ?int $id): string {
    $season = $id === null ? ['id' => null, 'route_key' => '', 'name' => '', 'status' => 'upcoming', 'start_date' => '', 'end_date' => '', 'image' => null,
        'registration_starts_at' => '', 'registration_ends_at' => '', 'registration_fee' => '']
        : query('SELECT * FROM seasons WHERE id = ?', [$id])[0] ?? notFound();
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') [$season, $errors] = saveSeason($season);
    return seasonForm($admin, $season, $errors);
}

function validSeasonDate(string $value, string $format): bool {
    if ($value === '') return true;
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
    return $date !== false && $date->format($format) === $value;
}

/** Validates and saves a season; returns the posted season and errors when validation fails. */
function saveSeason(array $season): array {
    $input = ['id' => $season['id'], 'image' => $season['image'], 'route_key' => postedText('route_key'), 'name' => postedText('name'),
        'status' => postedText('status'), 'start_date' => postedText('start_date'), 'end_date' => postedText('end_date'),
        'registration_starts_at' => postedText('registration_starts_at'), 'registration_ends_at' => postedText('registration_ends_at'),
        'registration_fee' => postedText('registration_fee')];
    $errors = [];
    if ($input['route_key'] === '' || !ctype_digit($input['route_key']) || (int) $input['route_key'] < 1 || (float) $input['route_key'] > 4294967295) $errors['route_key'] = 'Enter a whole season number from 1 to 4,294,967,295.';
    elseif (query('SELECT id FROM seasons WHERE route_key = ? AND id <> ?', [(int) $input['route_key'], (int) $season['id']])) $errors['route_key'] = 'Another season already uses this number.';
    if ($input['name'] === '') $errors['name'] = 'Enter the season’s name.';
    elseif (mb_strlen($input['name']) > 255) $errors['name'] = 'The name must be 255 characters or fewer.';
    if (!isset(SEASON_STATUSES[$input['status']])) $errors['status'] = 'Choose upcoming, ongoing or completed.';
    foreach (['start_date' => 'Start date', 'end_date' => 'End date'] as $field => $label) if (!validSeasonDate($input[$field], 'Y-m-d')) $errors[$field] = "$label is not a valid date.";
    foreach (['registration_starts_at' => 'Registration start', 'registration_ends_at' => 'Registration end'] as $field => $label) if (!validSeasonDate($input[$field], 'Y-m-d\\TH:i')) $errors[$field] = "$label is not a valid date and time.";
    if (!isset($errors['start_date'], $errors['end_date']) && $input['start_date'] && $input['end_date'] && $input['end_date'] < $input['start_date']) $errors['end_date'] = 'The end date must be on or after the start date.';
    if (!isset($errors['registration_starts_at'], $errors['registration_ends_at']) && $input['registration_starts_at'] && $input['registration_ends_at'] && $input['registration_ends_at'] < $input['registration_starts_at']) $errors['registration_ends_at'] = 'Registration must end after it starts.';
    if ($input['registration_fee'] !== '' && (!preg_match('~^\d{1,8}(?:\.\d{1,2})?$~', $input['registration_fee']) || (float) $input['registration_fee'] > 99999999.99)) $errors['registration_fee'] = 'Enter an amount from 0 to 99,999,999.99 with up to two decimal places.';

    $upload = uploadedImage('image', 'image');
    if (is_string($upload)) $errors['image'] = $upload;
    if ($errors) return [$input, $errors];
    $newImage = null;
    if (is_array($upload)) {
        $newImage = saveUploadedImage($upload, SEASON_IMAGE_DIR);
        if (!$newImage) return [$input, ['image' => 'The image could not be saved: the folder ' . SEASON_IMAGE_DIR . ' isn’t writable by the web server.']];
        $input['image'] = $newImage;
    } elseif (($_POST['remove_image'] ?? null) === '1') $input['image'] = null;

    $values = [(int) $input['route_key'], $input['name'], $input['status'], $input['start_date'] ?: null, $input['image'], $input['end_date'] ?: null,
        $input['registration_starts_at'] ? str_replace('T', ' ', $input['registration_starts_at']) . ':00' : null,
        $input['registration_ends_at'] ? str_replace('T', ' ', $input['registration_ends_at']) . ':00' : null, $input['registration_fee'] === '' ? null : $input['registration_fee']];
    try {
        adminTransaction(function () use (&$input, $values) {
            if ($input['id'] === null) {
                $input['id'] = nextId('seasons');
                adminExecute('INSERT INTO seasons (route_key, name, status, start_date, image, end_date, registration_starts_at, registration_ends_at, registration_fee, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $input['id']]);
            } else adminExecute('UPDATE seasons SET route_key = ?, name = ?, status = ?, start_date = ?, image = ?, end_date = ?, registration_starts_at = ?, registration_ends_at = ?, registration_fee = ? WHERE id = ?', [...$values, $input['id']]);
        });
    } catch (Throwable $error) {
        if ($newImage) @unlink(dirname(__DIR__) . '/' . $newImage);
        throw $error;
    }
    adminFlash(($season['id'] === null ? 'Added ' : 'Saved ') . $input['name'] . '.');
    adminRedirect('/admin/seasons');
}

function seasonForm(array $admin, array $season, array $errors): string {
    $isNew = $season['id'] === null;
    $datetime = fn ($value) => $value ? substr(str_replace(' ', 'T', (string) $value), 0, 16) : '';
    $statuses = implode('', array_map(fn ($key, $label) => '<option value="' . $key . '"' . ($season['status'] === $key ? ' selected' : '') . '>' . $label . '</option>', array_keys(SEASON_STATUSES), SEASON_STATUSES));
    $data = ['title' => $isNew ? 'Add a season' : 'Edit ' . escape($season['name']), 'action' => '{{BASE}}/admin/seasons/' . ($isNew ? 'new' : (int) $season['id']),
        'summary' => formErrorSummary($errors, 'image'), 'statuses' => $statuses, 'submit' => $isNew ? 'Add season' : 'Save changes',
        'image_current' => $season['image'] ? '<div class="admin-logo-current">' . adminImageHtml($season['image'], 'admin-logo-preview') . '<label class="admin-check"><input type="checkbox" name="remove_image" value="1">Remove the image</label></div>' : '',
        'delete' => $isNew || !adminCan('delete') ? '' : '<a class="admin-danger-link" href="{{BASE}}/admin/seasons/' . (int) $season['id'] . '/delete">Delete this season</a>'];
    foreach (['route_key', 'name', 'status', 'start_date', 'end_date', 'registration_fee', 'image'] as $field) {
        $data[$field] = escape((string) ($season[$field] ?? ''));
        $data[$field . '_error'] = fieldError($errors, $field);
        $data[$field . '_invalid'] = fieldInvalid($errors, $field);
    }
    foreach (['registration_starts_at', 'registration_ends_at'] as $field) {
        $data[$field] = escape($datetime($season[$field] ?? ''));
        $data[$field . '_error'] = fieldError($errors, $field);
        $data[$field . '_invalid'] = fieldInvalid($errors, $field);
    }
    return adminView('templates.admin.season-form', $admin, '/admin/seasons', $data);
}

function renderSeasonDelete(array $admin, int $id): string {
    $season = query('SELECT * FROM seasons WHERE id = ?', [$id])[0] ?? notFound();
    $usage = seasonUsage($id);
    if ($usage) {
        $body = '<p>' . escape($season['name']) . ' can’t be deleted while it has ' . escape(seasonUsageText($usage)) . '.</p><p>Remove those records first, then try again.</p>'
            . '<p class="admin-form-actions"><a class="admin-button-secondary" href="{{BASE}}/admin/seasons/' . $id . '">Back to the season</a></p>';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        adminExecute('DELETE FROM seasons WHERE id = ?', [$id]);
        adminFlash('Deleted ' . $season['name'] . '.');
        adminRedirect('/admin/seasons');
    } else $body = deleteConfirmation('season', $season['name'], '/admin/seasons/' . $id . '/delete', '/admin/seasons/' . $id);
    return adminView('templates.admin.delete', $admin, '/admin/seasons', ['title' => 'Delete ' . escape($season['name']), 'image' => adminImageHtml($season['image'], 'admin-logo-preview'),
        'back' => '<a href="{{BASE}}/admin/seasons">&larr; All seasons</a>', 'body' => $body]);
}
