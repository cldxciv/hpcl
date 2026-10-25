<?php
declare(strict_types=1);

// Admin squad pages (/admin/teams/{id}/squad/{season id}): the players of a team in one season
// (season_players). Players are added from a search, and marked retained, ordered or removed.
// A player is in at most one team per season, and one with stats for the team that season
// (player_season_stats) can't be removed.
require_once __DIR__ . '/admin-forms.php';

// Players listed per search on the squad page.
const SQUAD_SEARCH_LIMIT = 20;

function squadUrl(int $teamId, int $seasonId, string $search = ''): string {
    return '/admin/teams/' . $teamId . '/squad/' . $seasonId . ($search === '' ? '' : '?q=' . rawurlencode($search));
}
/** The squad of a team in a season, in squad order, with whether each player has stats. */
function squadPlayers(int $teamId, int $seasonId): array {
    return query('SELECT p.id, p.name, p.slug, p.photo, p.type, p.city, sp.is_retained, sp.position,
        EXISTS (SELECT 1 FROM player_season_stats ps WHERE ps.player_id = p.id AND ps.season_id = sp.season_id AND ps.team_id = sp.team_id) AS has_stats
        FROM season_players sp JOIN players p ON p.id = sp.player_id WHERE sp.season_id = ? AND sp.team_id = ? AND p.deleted_at IS NULL ORDER BY sp.position, p.name', [$seasonId, $teamId]);
}

function renderSquad(array $admin, int $teamId, int $seasonId): string {
    $team = query('SELECT id, name, slug, logo FROM teams WHERE id = ? AND deleted_at IS NULL', [$teamId])[0] ?? notFound();
    // Only seasons the team plays in have a squad page.
    $seasons = query('SELECT s.id, s.route_key, s.name, (SELECT COUNT(*) FROM season_players sp JOIN players p ON p.id = sp.player_id WHERE sp.season_id = s.id AND sp.team_id = st.team_id AND p.deleted_at IS NULL) AS players
        FROM season_teams st JOIN seasons s ON s.id = st.season_id WHERE st.team_id = ? ORDER BY s.route_key DESC', [$teamId]);
    $season = array_values(array_filter($seasons, fn ($row) => (int) $row['id'] === $seasonId))[0] ?? notFound();
    $search = adminSearch();
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $errors = postedText('action') === 'add' ? addToSquad($team, $season, $search) : saveSquad($team, $season, $search);
    }
    return squadPage($admin, $team, $season, $seasons, squadPlayers($teamId, $seasonId), $search, $errors);
}

/** Adds the posted player to the squad, at the end; redirects, or returns the errors. */
function addToSquad(array $team, array $season, string $search): array {
    $playerId = ctype_digit(postedText('player_id')) ? (int) postedText('player_id') : 0;
    $player = query('SELECT id, name FROM players WHERE id = ? AND deleted_at IS NULL', [$playerId])[0] ?? null;
    if (!$player) return ['add' => 'That player no longer exists.'];
    $current = query('SELECT t.name FROM season_players sp JOIN teams t ON t.id = sp.team_id WHERE sp.season_id = ? AND sp.player_id = ?', [$season['id'], $playerId])[0] ?? null;
    if ($current) return ['add' => $player['name'] . ' is already in ' . $current['name'] . ' in ' . $season['name'] . '.'];
    adminTransaction(function () use ($team, $season, $playerId) {
        $position = (int) query('SELECT COALESCE(MAX(position) + 1, 0) AS position FROM season_players WHERE season_id = ? AND team_id = ? FOR UPDATE', [$season['id'], $team['id']])[0]['position'];
        adminExecute('INSERT INTO season_players (season_id, player_id, team_id, is_retained, position) VALUES (?, ?, ?, 0, ?)', [$season['id'], $playerId, $team['id'], $position]);
    });
    adminFlash('Added ' . $player['name'] . ' to the squad.');
    adminRedirect(squadUrl((int) $team['id'], (int) $season['id'], $search));
}

/** Saves the squad table: retained, order and removals; redirects, or returns the errors. */
function saveSquad(array $team, array $season, string $search): array {
    $squad = array_column(squadPlayers((int) $team['id'], (int) $season['id']), null, 'id');
    $posted = is_array($_POST['players'] ?? null) ? $_POST['players'] : [];
    $changes = [];
    $errors = [];
    foreach ($squad as $id => $player) {
        $row = is_array($posted[$id] ?? null) ? $posted[$id] : [];
        $order = trim(is_string($row['order'] ?? null) ? $row['order'] : '');
        if (($row['remove'] ?? null) === '1') {
            if ($player['has_stats']) $errors['squad'][] = $player['name'] . ' has stats for this team in ' . $season['name'] . ', so they can’t be removed.';
            $changes[$id] = null;
        } elseif ($order === '' || !ctype_digit($order) || (int) $order > 999) {
            $errors['squad'][] = 'The order for ' . $player['name'] . ' must be a whole number from 0 to 999.';
        } else {
            $changes[$id] = [(int) $order, ($row['retained'] ?? null) === '1' ? 1 : 0];
        }
    }
    if ($errors) return $errors;
    adminTransaction(function () use ($team, $season, $changes) {
        foreach ($changes as $playerId => $change) {
            $key = [$season['id'], $playerId, $team['id']];
            if ($change === null) adminExecute('DELETE FROM season_players WHERE season_id = ? AND player_id = ? AND team_id = ?', $key);
            else adminExecute('UPDATE season_players SET position = ?, is_retained = ? WHERE season_id = ? AND player_id = ? AND team_id = ?', [...$change, ...$key]);
        }
    });
    $removed = count(array_filter($changes, fn ($change) => $change === null));
    adminFlash('Saved the squad' . ($removed ? ' and removed ' . $removed . ' player' . ($removed === 1 ? '' : 's') : '') . '.');
    adminRedirect(squadUrl((int) $team['id'], (int) $season['id'], $search));
}

function squadPage(array $admin, array $team, array $season, array $seasons, array $squad, string $search, array $errors): string {
    $teamId = (int) $team['id'];
    $seasonId = (int) $season['id'];
    $posted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && is_array($_POST['players'] ?? null) ? $_POST['players'] : null;
    $tabs = implode('', array_map(fn ($row) => '<a href="{{BASE}}' . squadUrl($teamId, (int) $row['id']) . '"' . ((int) $row['id'] === $seasonId ? ' aria-current="page"' : '') . '>'
        . escape($row['name']) . ' <span class="admin-muted">· ' . (int) $row['players'] . '</span></a>', $seasons));

    $rows = '';
    foreach ($squad as $player) {
        $id = (int) $player['id'];
        // After an error the table shows what was posted.
        $row = is_array($posted[$id] ?? null) ? $posted[$id] : null;
        $order = $row ? (is_string($row['order'] ?? null) ? $row['order'] : '') : (string) $player['position'];
        $retained = $row ? ($row['retained'] ?? null) === '1' : (bool) $player['is_retained'];
        $remove = $row && ($row['remove'] ?? null) === '1';
        $name = 'players[' . $id . ']';
        $rows .= '<tr><td class="admin-cell-team">' . adminImageHtml($player['photo']) . '<span><a href="{{BASE}}/player/' . rawurlencode((string) $player['slug']) . '" target="_blank" rel="noopener">'
            . escape($player['name']) . '</a><br><span class="admin-muted admin-small">' . escape(implode(' · ', array_filter([(string) $player['type'], (string) $player['city']]))) . '</span></span></td>'
            . '<td><label class="admin-check"><input type="checkbox" name="' . $name . '[retained]" value="1"' . ($retained ? ' checked' : '') . ' aria-label="' . escape($player['name']) . ' retained">Retained</label></td>'
            . '<td><input class="admin-input-small" type="text" inputmode="numeric" name="' . $name . '[order]" value="' . escape($order) . '" maxlength="3" aria-label="Order of ' . escape($player['name']) . '"></td>'
            . '<td>' . ($player['has_stats'] ? '<span class="admin-muted admin-small">Has stats</span>'
                : '<label class="admin-check admin-danger-link"><input type="checkbox" name="' . $name . '[remove]" value="1"' . ($remove ? ' checked' : '') . ' aria-label="Remove ' . escape($player['name']) . '">Remove</label>') . '</td></tr>';
    }
    $squadTable = $squad
        ? '<form method="post" action="{{BASE}}' . squadUrl($teamId, $seasonId, $search) . '"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '"><input type="hidden" name="action" value="save">'
            . '<div class="admin-table-wrap"><table class="admin-table admin-table--squad"><thead><tr><th>Player</th><th>Retained</th><th>Order</th><th><span class="admin-visually-hidden">Remove</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<div class="admin-form-actions admin-section"><button type="submit">Save squad</button><span class="admin-hint">Order sorts the squad on the team page, lowest first.</span></div></form>'
        : '<p class="admin-empty">No players in this squad yet. Find them below to add them.</p>';

    $results = '';
    if ($search !== '') {
        $found = query('SELECT p.id, p.name, p.photo, p.type, p.city,
            (SELECT t.name FROM season_players sp JOIN teams t ON t.id = sp.team_id WHERE sp.season_id = ? AND sp.player_id = p.id LIMIT 1) AS team_name,
            (SELECT sp.team_id FROM season_players sp WHERE sp.season_id = ? AND sp.player_id = p.id LIMIT 1) AS team_id
            FROM players p WHERE p.name LIKE ? AND p.deleted_at IS NULL ORDER BY p.name, p.id LIMIT ' . (SQUAD_SEARCH_LIMIT + 1), [$seasonId, $seasonId, likePattern($search)]);
        $more = count($found) > SQUAD_SEARCH_LIMIT;
        $items = implode('', array_map(fn ($player) => '<li>' . adminImageHtml($player['photo'])
            . '<span class="admin-result__name">' . escape($player['name']) . '<br><span class="admin-muted admin-small">' . escape(implode(' · ', array_filter(['#' . $player['id'], (string) $player['type'], (string) $player['city']]))) . '</span></span>'
            . ($player['team_id'] === null
                ? '<form method="post" action="{{BASE}}' . squadUrl($teamId, $seasonId, $search) . '"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '"><input type="hidden" name="action" value="add">'
                    . '<input type="hidden" name="player_id" value="' . (int) $player['id'] . '"><button type="submit" class="admin-button-secondary">Add</button></form>'
                : '<span class="admin-muted admin-small">' . ((int) $player['team_id'] === $teamId ? 'In this squad' : 'In ' . escape($player['team_name'])) . '</span>') . '</li>', array_slice($found, 0, SQUAD_SEARCH_LIMIT)));
        $results = $found
            ? '<ul class="admin-results">' . $items . '</ul>' . ($more ? '<p class="admin-hint">Showing the first ' . SQUAD_SEARCH_LIMIT . ' matches. Type more of the name to narrow them down.</p>' : '')
            : '<p class="admin-empty">No players match “' . escape($search) . '”.</p>';
    }

    $messages = array_merge($errors['squad'] ?? [], isset($errors['add']) ? [$errors['add']] : []);
    return adminView('templates.admin.squad', $admin, '/admin/teams', [
        'title' => escape($team['name']) . ' squad', 'season_name' => escape($season['name']), 'team_id' => (string) $teamId,
        'team_link' => '{{BASE}}/teams/season/' . (int) $season['route_key'] . '/' . rawurlencode((string) $team['slug']),
        'logo' => adminImageHtml($team['logo'], 'admin-logo-preview'), 'tabs' => $tabs, 'count' => count($squad) . ' player' . (count($squad) === 1 ? '' : 's'),
        'errors' => $messages ? '<div class="admin-alert" role="alert">' . implode('<br>', array_map('escape', $messages)) . '</div>' : '',
        'new_player' => adminCan('players') ? ' Someone who isn’t listed yet? <a href="{{BASE}}/admin/players/new">Add a new player</a> first.' : ' Someone who isn’t listed yet has to be added on the Players page first.',
        'squad' => $squadTable, 'search' => escape($search), 'search_action' => '{{BASE}}' . squadUrl($teamId, $seasonId), 'results' => $results,
    ]);
}
