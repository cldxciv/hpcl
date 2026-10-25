<?php
declare(strict_types=1);

// The trash (/admin/trash), for the root user only: the teams, players and owners other admins
// deleted (deleted_at set). Restoring puts a record back on the site and in the admin as it was;
// deleting it for good removes the row and its links. Stats are never deleted: a team or player
// with stats stays in the trash. Image files are left on disk.
require_once __DIR__ . '/admin-users.php';

// kind => [one, many, image column, admin page of a restored record].
const TRASH_KINDS = [
    'teams' => ['team', 'Teams', 'logo', '/admin/teams/'],
    'players' => ['player', 'Players', 'photo', '/admin/players/'],
    'owners' => ['owner', 'Owners', 'photo', '/admin/owners/'],
];

/** A record of the trash, with who deleted it, or the 404 page. */
function trashedRecord(string $kind, int $id): array {
    [, , $image] = TRASH_KINDS[$kind];
    return query("SELECT r.id, r.name, r.$image AS image, r.deleted_at, a.name AS deleted_by FROM $kind r LEFT JOIN admins a ON a.id = r.deleted_by
        WHERE r.id = ? AND r.deleted_at IS NOT NULL", [$id])[0] ?? notFound();
}
/** Why a record can't be deleted for good, or null when it can. */
function trashBlocker(string $kind, int $id): ?string {
    if ($kind === 'owners') return null;
    $column = $kind === 'teams' ? 'team_id' : 'player_id';
    $stats = (int) query("SELECT COUNT(*) AS n FROM player_season_stats WHERE $column = ?", [$id])[0]['n'];
    if ($stats) return 'It has ' . number_format($stats) . ' stats row' . ($stats === 1 ? '' : 's') . ', which are kept as the league’s record. Restore it instead, or remove the stats in phpMyAdmin (table player_season_stats) if they were entered by mistake.';
    if ($kind === 'teams') {
        // Players and owners outside the trash would be left pointing at a missing team.
        $live = query('SELECT (SELECT COUNT(*) FROM season_players sp JOIN players p ON p.id = sp.player_id WHERE sp.team_id = ? AND p.deleted_at IS NULL) AS players,
            (SELECT COUNT(*) FROM season_team_owners sto JOIN owners o ON o.id = sto.owner_id WHERE sto.team_id = ? AND o.deleted_at IS NULL) AS owners', [$id, $id])[0];
        if ($live['players'] || $live['owners']) return 'Players or owners outside the trash are still linked to it. Restore the team and remove them from it first.';
    }
    return null;
}

function renderTrash(array $admin): string {
    $sections = '';
    $total = 0;
    foreach (TRASH_KINDS as $kind => [$one, $many, $image]) {
        $rows = query("SELECT r.id, r.name, r.$image AS image, r.deleted_at, a.name AS deleted_by FROM $kind r LEFT JOIN admins a ON a.id = r.deleted_by
            WHERE r.deleted_at IS NOT NULL ORDER BY r.deleted_at DESC");
        $total += count($rows);
        if (!$rows) continue;
        $items = implode('', array_map(fn ($row) => '<tr><td class="admin-cell-team">' . adminImageHtml($row['image']) . '<span><strong>' . escape($row['name']) . '</strong>'
            . '<br><span class="admin-muted admin-small">#' . (int) $row['id'] . '</span></span></td>'
            . '<td class="admin-wide-only">' . date('j M Y, g:i A', strtotime($row['deleted_at'])) . '<br><span class="admin-muted admin-small">by ' . escape($row['deleted_by'] ?? 'a removed user') . '</span></td>'
            . '<td class="admin-actions admin-trash-actions"><form method="post" action="{{BASE}}/admin/trash/' . $kind . '/' . (int) $row['id'] . '/restore">'
            . '<input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '"><button type="submit" class="admin-button-secondary admin-button-small">Restore</button></form>'
            . '<a class="admin-danger-link" href="{{BASE}}/admin/trash/' . $kind . '/' . (int) $row['id'] . '/delete">Delete permanently</a></td></tr>', $rows));
        $sections .= '<section class="admin-panel admin-section"><h2>' . $many . ' <span class="admin-muted">· ' . count($rows) . '</span></h2><div class="admin-table-wrap"><table class="admin-table admin-table--trash">'
            . '<thead><tr><th>' . ucfirst($one) . '</th><th class="admin-wide-only">Deleted</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>' . $items . '</tbody></table></div></section>';
    }
    if (!$total) $sections = '<section class="admin-panel admin-section"><p class="admin-empty">The trash is empty. Teams, players and owners that admins delete appear here.</p></section>';
    return adminView('templates.admin.trash', $admin, '/admin/users', ['tabs' => usersTabs('trash'), 'sections' => $sections]);
}

function restoreFromTrash(array $admin, string $kind, int $id): string {
    $record = trashedRecord($kind, $id);
    adminExecute("UPDATE $kind SET deleted_at = NULL, deleted_by = NULL WHERE id = ?", [$id]);
    adminFlash('Restored ' . $record['name'] . '. It is back on the website and in the admin.');
    adminRedirect('/admin/trash');
}

function renderTrashDelete(array $admin, string $kind, int $id): string {
    [$one] = TRASH_KINDS[$kind];
    $record = trashedRecord($kind, $id);
    $blocker = trashBlocker($kind, $id);
    if ($blocker) {
        $body = '<p>' . escape($record['name']) . ' can’t be deleted permanently. ' . escape($blocker) . '</p>'
            . '<p class="admin-form-actions"><a class="admin-button-secondary" href="{{BASE}}/admin/trash">Back to the trash</a></p>';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        adminTransaction(function () use ($kind, $id) {
            // Its links go with it: squads, seasons and owners for a team, squads for a player, teams for an owner.
            $links = ['teams' => ['season_players' => 'team_id', 'season_team_owners' => 'team_id', 'season_teams' => 'team_id'],
                'players' => ['season_players' => 'player_id'], 'owners' => ['season_team_owners' => 'owner_id']][$kind];
            foreach ($links as $table => $column) adminExecute("DELETE FROM $table WHERE $column = ?", [$id]);
            adminExecute("DELETE FROM $kind WHERE id = ? AND deleted_at IS NOT NULL", [$id]);
        });
        adminFlash('Deleted ' . $record['name'] . ' permanently.');
        adminRedirect('/admin/trash');
    } else {
        $body = '<p>Delete <strong>' . escape($record['name']) . '</strong> permanently? The ' . $one . ' and its links are removed from the database. <strong>This can’t be undone.</strong></p>'
            . '<form method="post" action="{{BASE}}/admin/trash/' . $kind . '/' . $id . '/delete" class="admin-form-actions"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">'
            . '<button type="submit" class="admin-button-danger">Delete permanently</button><a class="admin-button-secondary" href="{{BASE}}/admin/trash">Cancel</a></form>';
    }
    return adminView('templates.admin.delete', $admin, '/admin/users', ['title' => 'Delete ' . escape($record['name']) . ' permanently',
        'image' => adminImageHtml($record['image'], 'admin-logo-preview'), 'back' => '<a href="{{BASE}}/admin/trash">&larr; Trash</a>', 'body' => $body]);
}
