<?php
declare(strict_types=1);

// Admin pages for players (/admin/players): list, add, edit and delete players (to the trash).
// Squads are edited on the squad pages (admin-squads.php); a player with stats
// (player_season_stats) can't be deleted.
require_once __DIR__ . '/admin-forms.php';

const PLAYER_PHOTO_DIR = 'storage/uploads/players';
const PLAYERS_PER_PAGE = 50;
const PLAYER_SLUG_PATTERN = '~^[a-z0-9]+(?:-[a-z0-9]+)*$~';
// Short text fields of the form: column => [label, suggest the values already used].
const PLAYER_TEXT_FIELDS = ['city' => ['City', false], 'state' => ['State', false], 'type' => ['Role', true], 'batting_style' => ['Batting style', true], 'bowling_style' => ['Bowling style', true]];

/** A slug made from the name that no other player uses: rahul-sharma, else rahul-sharma-2, -3… */
function uniquePlayerSlug(string $name): string {
    $base = trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($name)), '-') ?: 'player';
    $taken = array_column(query('SELECT slug FROM players WHERE slug = ? OR slug LIKE ?', [$base, likePattern($base . '-')]), 'slug', 'slug');
    for ($n = 1, $slug = $base; isset($taken[$slug]); $slug = $base . '-' . ++$n);
    return $slug;
}
/** The values already used in a column, most used first, for the field's suggestions. */
function playerSuggestions(string $column): array {
    return array_column(query("SELECT $column AS value FROM players WHERE $column IS NOT NULL AND $column NOT IN ('', '-') GROUP BY $column ORDER BY COUNT(*) DESC, $column LIMIT 30"), 'value');
}
/** The squads a player is in, newest season first, with whether they have stats there. */
function playerSquads(int $playerId): array {
    return query('SELECT s.id AS season_id, s.name AS season, t.id AS team_id, t.name AS team,
        EXISTS (SELECT 1 FROM player_season_stats ps WHERE ps.player_id = sp.player_id AND ps.season_id = sp.season_id AND ps.team_id = sp.team_id) AS has_stats
        FROM season_players sp JOIN seasons s ON s.id = sp.season_id JOIN teams t ON t.id = sp.team_id WHERE sp.player_id = ? ORDER BY s.route_key DESC', [$playerId]);
}

function renderAdminPlayers(array $admin): string {
    $search = adminSearch();
    $where = 'WHERE p.deleted_at IS NULL';
    $params = [];
    if (preg_match('~^#?(\d+)$~', $search, $id)) {
        $where .= ' AND p.id = ?';
        $params = [(int) $id[1]];
    } elseif ($search !== '') {
        $where .= ' AND (p.name LIKE ? OR p.city LIKE ? OR p.slug LIKE ?)';
        $params = array_fill(0, 3, likePattern($search));
    }
    $total = (int) query("SELECT COUNT(*) AS n FROM players p $where", $params)[0]['n'];
    $lastPage = max(1, (int) ceil($total / PLAYERS_PER_PAGE));
    $page = min($lastPage, max(1, ctype_digit((string) ($_GET['page'] ?? '')) ? (int) $_GET['page'] : 1));
    $players = query("SELECT p.id, p.name, p.photo, p.type, p.city,
        (SELECT CONCAT(t.name, ' · ', s.name) FROM season_players sp JOIN teams t ON t.id = sp.team_id JOIN seasons s ON s.id = sp.season_id
            WHERE sp.player_id = p.id ORDER BY s.route_key DESC LIMIT 1) AS latest
        FROM players p $where ORDER BY p.name, p.id LIMIT " . PLAYERS_PER_PAGE . ' OFFSET ' . (($page - 1) * PLAYERS_PER_PAGE), $params);
    $rows = implode('', array_map(fn ($player) => '<tr><td class="admin-cell-team">' . adminImageHtml($player['photo'])
        . '<span><a href="{{BASE}}/admin/players/' . (int) $player['id'] . '">' . escape($player['name']) . '</a><br><span class="admin-muted admin-small">#' . (int) $player['id']
        . ($player['city'] ? ' · ' . escape($player['city']) : '') . '</span></span></td>'
        . '<td class="admin-wide-only">' . escape((string) $player['type']) . '</td>'
        . '<td class="admin-wide-only">' . ($player['latest'] ? escape($player['latest']) : '<span class="admin-muted">None</span>') . '</td>'
        . '<td class="admin-actions"><a href="{{BASE}}/admin/players/' . (int) $player['id'] . '">Edit</a>'
        . (adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/players/' . (int) $player['id'] . '/delete">Delete</a>' : '') . '</td></tr>', $players));
    $pageUrl = fn (int $number) => '{{BASE}}/admin/players?' . escape(http_build_query(array_filter(['q' => $search, 'page' => $number > 1 ? $number : null])));
    $pager = $lastPage > 1 ? '<nav class="admin-pager" aria-label="Pages">'
        . ($page > 1 ? '<a href="' . $pageUrl($page - 1) . '" rel="prev">&larr; Previous</a>' : '<span></span>')
        . '<span class="admin-muted">Page ' . $page . ' of ' . $lastPage . '</span>'
        . ($page < $lastPage ? '<a href="' . $pageUrl($page + 1) . '" rel="next">Next &rarr;</a>' : '<span></span>') . '</nav>' : '';
    $all = (int) query('SELECT COUNT(*) AS n FROM players WHERE deleted_at IS NULL')[0]['n'];
    $summary = $search === '' ? number_format($all) . ' players' : number_format($total) . ' of ' . number_format($all) . ' players match “' . escape($search) . '”';
    $table = $players
        ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Player</th><th class="admin-wide-only">Role</th><th class="admin-wide-only">Latest squad</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>' . $pager
        : '<p class="admin-empty">' . ($search === '' ? 'No players yet.' : 'No players match your search.') . '</p>';
    return adminView('templates.admin.players', $admin, '/admin/players', ['search' => escape($search), 'summary' => $summary, 'table' => $table]);
}

function renderPlayerForm(array $admin, ?int $id): string {
    $player = $id === null ? ['id' => null, 'name' => '', 'slug' => '', 'age' => null, 'photo' => null, 'achievements' => ''] + array_fill_keys(array_keys(PLAYER_TEXT_FIELDS), '')
        : query('SELECT * FROM players WHERE id = ? AND deleted_at IS NULL', [$id])[0] ?? notFound();
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') [$player, $errors] = savePlayer($player);
    return playerForm($admin, $player, $errors);
}

/** Validates and saves the posted form; redirects on success, else returns [player, errors] to show again. */
function savePlayer(array $player): array {
    $input = ['id' => $player['id'], 'photo' => $player['photo'], 'name' => postedText('name'), 'slug' => strtolower(postedText('slug')),
        'age' => postedText('age'), 'achievements' => postedText('achievements')];
    foreach (PLAYER_TEXT_FIELDS as $field => $_) $input[$field] = postedText($field);
    $errors = [];
    if ($input['name'] === '') $errors['name'] = 'Enter the player’s name.';
    elseif (mb_strlen($input['name']) > 255) $errors['name'] = 'The name must be 255 characters or fewer.';
    if ($input['slug'] === '') {
        // New players get a free slug made from the name; an existing player's slug can't be emptied.
        if ($player['id'] === null && $input['name'] !== '') $input['slug'] = uniquePlayerSlug($input['name']);
        elseif ($player['id'] !== null) $errors['slug'] = 'Enter a slug, such as rahul-sharma.';
    } elseif (strlen($input['slug']) > 255 || !preg_match(PLAYER_SLUG_PATTERN, $input['slug'])) {
        $errors['slug'] = 'Use only lowercase letters, numbers and single hyphens, such as rahul-sharma.';
    } elseif ($taken = query('SELECT deleted_at FROM players WHERE slug = ? AND id <> ?', [$input['slug'], (int) $player['id']])[0] ?? null) {
        // Only the root user is told the other player is in the trash.
        $errors['slug'] = ($taken['deleted_at'] && adminCan('root') ? 'A player in your Trash uses this slug.' : 'Another player already uses this slug.') . ' Try ' . uniquePlayerSlug($input['slug']) . '.';
    }
    foreach (PLAYER_TEXT_FIELDS as $field => [$label]) {
        if (mb_strlen($input[$field]) > 255) $errors[$field] = $label . ' must be 255 characters or fewer.';
    }
    if ($input['age'] !== '' && (!ctype_digit($input['age']) || (int) $input['age'] < 5 || (int) $input['age'] > 99)) $errors['age'] = 'Enter an age from 5 to 99, or leave it empty.';
    if (mb_strlen($input['achievements']) > 10000) $errors['achievements'] = 'Achievements must be 10,000 characters or fewer.';

    $upload = uploadedImage('photo', 'photo');
    if (is_string($upload)) $errors['photo'] = $upload;
    if ($errors) return [$input, $errors];

    $newPhoto = null;
    if (is_array($upload)) {
        $newPhoto = saveUploadedImage($upload, PLAYER_PHOTO_DIR);
        if (!$newPhoto) return [$input, ['photo' => 'The photo could not be saved: the folder ' . PLAYER_PHOTO_DIR . ' isn’t writable by the web server.']];
        $input['photo'] = $newPhoto;
    } elseif (($_POST['remove_photo'] ?? null) === '1') {
        $input['photo'] = null;
    }

    $columns = ['name', 'slug', 'city', 'state', 'age', 'type', 'batting_style', 'bowling_style', 'photo', 'achievements'];
    $values = array_map(fn ($column) => $input[$column] === '' ? null : ($column === 'age' ? (int) $input[$column] : $input[$column]), $columns);
    // Old photo files are left in place: they may still be linked from elsewhere.
    try {
        adminTransaction(function () use (&$input, $columns, $values) {
            if ($input['id'] === null) {
                $input['id'] = nextId('players');
                adminExecute('INSERT INTO players (id, ' . implode(', ', $columns) . ') VALUES (?' . str_repeat(', ?', count($columns)) . ')', [$input['id'], ...$values]);
            } else {
                adminExecute('UPDATE players SET ' . implode(' = ?, ', $columns) . ' = ? WHERE id = ?', [...$values, $input['id']]);
            }
        });
    } catch (Throwable $error) {
        if ($newPhoto) @unlink(dirname(__DIR__) . '/' . $newPhoto);
        throw $error;
    }
    adminFlash($player['id'] === null ? 'Added ' . $input['name'] . '. Add them to a squad from the team’s page.' : 'Saved ' . $input['name'] . '.');
    adminRedirect($player['id'] === null ? '/admin/players/' . $input['id'] : '/admin/players');
}

function playerForm(array $admin, array $player, array $errors): string {
    $isNew = $player['id'] === null;
    $fields = '';
    foreach (PLAYER_TEXT_FIELDS as $field => [$label, $suggest]) {
        $list = $suggest ? '<datalist id="' . $field . '-options">' . implode('', array_map(fn ($value) => '<option value="' . escape($value) . '">', playerSuggestions($field))) . '</datalist>' : '';
        $fields .= '<div class="admin-field"><label for="' . $field . '">' . $label . ' <span class="admin-optional">optional</span></label>'
            . '<input id="' . $field . '" name="' . $field . '" type="text" value="' . escape((string) $player[$field]) . '" maxlength="255"' . ($suggest ? ' list="' . $field . '-options" autocomplete="off"' : '') . fieldInvalid($errors, $field) . '>'
            . $list . fieldError($errors, $field) . '</div>';
    }
    $squads = $isNew ? [] : playerSquads((int) $player['id']);
    // Squad names link to their squad pages for admins who may edit squads.
    $squadName = fn ($squad) => adminCan('squads') ? '<a href="{{BASE}}/admin/teams/' . (int) $squad['team_id'] . '/squad/' . (int) $squad['season_id'] . '">'
        . escape($squad['team'] . ' · ' . $squad['season']) . '</a>' : escape($squad['team'] . ' · ' . $squad['season']);
    $squadList = $isNew ? '<p class="admin-hint">After saving, add the player to a team from the team’s page (Teams → the team → Manage squad).</p>'
        : ($squads ? '<ul class="admin-list">' . implode('', array_map(fn ($squad) => '<li>' . $squadName($squad) . ($squad['has_stats'] ? ' <span class="admin-muted admin-small">has stats</span>' : '') . '</li>', $squads)) . '</ul>'
            : '<p class="admin-empty">Not in any squad yet. Add the player from a team’s page (Teams → the team → Manage squad).</p>');
    return adminView('templates.admin.player-form', $admin, '/admin/players', [
        'title' => $isNew ? 'Add a player' : 'Edit ' . escape($player['name']),
        'action' => '{{BASE}}/admin/players/' . ($isNew ? 'new' : (int) $player['id']),
        'summary' => formErrorSummary($errors, 'photo'),
        'name' => escape($player['name']), 'name_error' => fieldError($errors, 'name'), 'name_invalid' => fieldInvalid($errors, 'name'),
        'slug' => escape((string) $player['slug']), 'slug_error' => fieldError($errors, 'slug'), 'slug_invalid' => fieldInvalid($errors, 'slug'),
        'slug_hint' => $isNew ? 'Used in the player’s address, /player/{slug}. Leave empty to make it from the name.'
            : 'Used in the player’s address, /player/<b>' . escape((string) $player['slug']) . '</b>. Changing it breaks existing links to the player.',
        'age' => escape((string) $player['age']), 'age_error' => fieldError($errors, 'age'), 'age_invalid' => fieldInvalid($errors, 'age'),
        'fields' => $fields,
        'photo_current' => $player['photo'] ? '<div class="admin-logo-current">' . adminImageHtml($player['photo'], 'admin-logo-preview') . '<label class="admin-check"><input type="checkbox" name="remove_photo" value="1">Remove the photo</label></div>' : '',
        'photo_error' => fieldError($errors, 'photo'), 'photo_invalid' => fieldInvalid($errors, 'photo'),
        'achievements' => escape((string) $player['achievements']), 'achievements_error' => fieldError($errors, 'achievements'), 'achievements_invalid' => fieldInvalid($errors, 'achievements'),
        'squads' => $squadList,
        'view' => $isNew ? '' : '<a href="{{BASE}}/player/' . rawurlencode((string) $player['slug']) . '" target="_blank" rel="noopener">View the player’s page</a>',
        'submit' => $isNew ? 'Add player' : 'Save changes',
        'delete' => $isNew || !adminCan('delete') ? '' : '<a class="admin-danger-link" href="{{BASE}}/admin/players/' . (int) $player['id'] . '/delete">Delete this player</a>',
    ]);
}

function renderPlayerDelete(array $admin, int $id): string {
    $player = query('SELECT * FROM players WHERE id = ? AND deleted_at IS NULL', [$id])[0] ?? notFound();
    $squads = playerSquads($id);
    $withStats = array_filter($squads, fn ($squad) => $squad['has_stats']);
    $stats = (int) query('SELECT COUNT(*) AS n FROM player_season_stats WHERE player_id = ?', [$id])[0]['n'];
    if ($stats) {
        $body = '<p>' . escape($player['name']) . ' can’t be deleted: they have stats for ' . number_format($stats) . ' season' . ($stats === 1 ? '' : 's')
            . ($withStats ? ' (' . escape(implode(', ', array_map(fn ($squad) => $squad['team'] . ' · ' . $squad['season'], $withStats))) . ')' : '') . '.</p>'
            . '<p>Stats are kept as the league’s record. Remove them in phpMyAdmin (table player_season_stats) only if they were entered by mistake.</p>'
            . '<p class="admin-form-actions"><a class="admin-button-secondary" href="{{BASE}}/admin/players/' . $id . '">Back to the player</a></p>';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // Their squads are kept, so restoring the player puts them back where they were.
        moveToTrash('players', $id);
        adminFlash('Deleted ' . $player['name'] . '.');
        adminRedirect('/admin/players');
    } else {
        $body = deleteConfirmation('player', $player['name'] . ' (#' . $id . ')', '/admin/players/' . $id . '/delete', '/admin/players/' . $id,
            '<p>Their page /player/' . escape((string) $player['slug']) . ' will stop working.</p>'
            . ($squads ? '<p>They will also be removed from:</p><ul class="admin-list">' . implode('', array_map(fn ($squad) => '<li>' . escape($squad['team'] . ' · ' . $squad['season']) . '</li>', $squads)) . '</ul>' : ''));
    }
    return adminView('templates.admin.delete', $admin, '/admin/players', ['title' => 'Delete ' . escape($player['name']), 'image' => adminImageHtml($player['photo'], 'admin-logo-preview'),
        'back' => '<a href="{{BASE}}/admin/players">&larr; All players</a>', 'body' => $body]);
}
