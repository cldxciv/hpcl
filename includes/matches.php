<?php
declare(strict_types=1);

// Matches by season, season fixtures, match pages and points tables, rendered from the
// database. Ports of resources/views/public/matches/*.blade.php, Public\SeasonMatchController
// and App\Services\SeasonStandingsService.
require_once __DIR__ . '/site.php';

const STAGE_LABELS = ['league' => 'League', 'quarter_final' => 'Quarter Final', 'semi_final' => 'Semi Final', 'final' => 'Final'];

function matchSeasons(): array {
    return query('SELECT s.*, (SELECT COUNT(*) FROM matches m WHERE m.season_id = s.id) AS matches_count,
        (SELECT COUNT(*) FROM matches m WHERE m.season_id = s.id AND m.status IN ("completed", "abandoned")) AS points_table_match_count
        FROM seasons s ORDER BY s.route_key DESC, s.start_date DESC');
}
function seasonStarted(array $season): bool {
    return !$season['start_date'] || strtotime($season['start_date']) <= strtotime('today');
}
function pointsTableVisible(array $season): bool {
    return seasonStarted($season) && (bool) query('SELECT 1 FROM matches WHERE season_id = ? AND status IN ("completed", "abandoned") LIMIT 1', [$season['id']]);
}
/** Matches of a season with team details and scores; Laravel's default fixture order. */
function seasonMatches(array $season, array $filters = []): array {
    $where = ['m.season_id = ?'];
    $params = [$season['id']];
    if (($filters['stage'] ?? '') !== '') { $where[] = 'm.stage = ?'; $params[] = $filters['stage']; }
    if (($filters['status'] ?? '') !== '') { $where[] = 'm.status = ?'; $params[] = $filters['status']; }
    if (($filters['team'] ?? '') !== '') { $where[] = '(m.team_a_id = ? OR m.team_b_id = ?)'; $params[] = $filters['team']; $params[] = $filters['team']; }
    $matches = query('SELECT m.*, ta.name AS team_a_name, ta.short_name AS team_a_short, ta.logo AS team_a_logo, ta.slug AS team_a_slug,
            tb.name AS team_b_name, tb.short_name AS team_b_short, tb.logo AS team_b_logo, tb.slug AS team_b_slug
        FROM matches m LEFT JOIN teams ta ON ta.id = m.team_a_id LEFT JOIN teams tb ON tb.id = m.team_b_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY m.scheduled_at IS NULL, m.scheduled_at DESC, m.id DESC', $params);
    $scores = [];
    foreach (query('SELECT sc.* FROM match_scores sc JOIN matches m ON m.id = sc.match_id WHERE m.season_id = ?', [$season['id']]) as $score) $scores[$score['match_id']][$score['team_id']] = $score;
    foreach ($matches as &$match) $match['scores'] = $scores[$match['id']] ?? [];
    return $matches;
}
function fixtureName(array $match): string {
    return trim(($match['team_a_name'] ?? 'TBD') . ' vs ' . ($match['team_b_name'] ?? 'TBD'));
}
function scoreboard(array $match, ?int $teamId): string {
    $score = $teamId ? ($match['scores'][$teamId] ?? null) : null;
    return $score ? sprintf('%d/%d (%s)', $score['runs'], $score['wickets'], $score['overs'] . '.' . $score['balls']) : '-';
}
function resultSummary(array $match): string {
    return $match['result_summary'] ?: ucfirst($match['status']);
}
function matchUrl(array $season, array $match): string {
    return '{{BASE}}/season/' . $season['route_key'] . '/matches/' . $match['id'];
}
function matchEventStatus(array $match): ?string {
    return match ($match['status']) { 'abandoned' => 'https://schema.org/EventCancelled', 'scheduled' => 'https://schema.org/EventScheduled', default => null };
}
function isoTime(?string $value): ?string {
    return $value ? date('c', strtotime($value)) : null;
}
function teamLogoImg(?string $logo, ?string $alt, string $class = ''): string {
    return '<img' . ($class ? ' class="' . $class . '"' : '') . ' src="' . mediaUrl($logo, '/logo.png') . '" alt="' . escape((string) $alt) . '" onerror="this.onerror=null;this.src=\'{{BASE}}/logo.png\';">';
}
function seasonsCollectionSchema(string $name, array $seasons, callable $item): array {
    return ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $name, 'url' => null,
        'about' => ['@type' => 'SportsOrganization', 'name' => 'Pro HPCL - Himachal Premium Cricket League', 'sport' => 'Cricket', 'url' => '{{BASE}}'],
        'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(fn ($season, $index) => ['@type' => 'ListItem', 'position' => $index + 1] + $item($season), $seasons, array_keys($seasons))]];
}

/** /matches. */
function renderMatchesIndex(): string {
    $seasons = matchSeasons();
    $cards = '';
    foreach ($seasons as $season) {
        $cards .= seasonStarted($season)
            ? '<a href="{{BASE}}/matches/season/' . $season['route_key'] . '" class="matches-season-card"><h2 class="matches-season-card__name">' . escape($season['name']) . '</h2><p class="matches-season-card__meta">' . (int) $season['matches_count'] . ' matches</p></a>'
            : '<div class="matches-season-card is-disabled"><h2 class="matches-season-card__name">' . escape($season['name']) . '</h2><p class="matches-season-card__meta">Matches available from ' . date('d M Y', strtotime($season['start_date'])) . '</p></div>';
    }
    $html = '<section class="matches-seasons-shell"><h1 class="matches-seasons-title">Matches by Season</h1><p class="text-muted mb-0">Open any season to view fixtures, results, and scorecards.</p><div class="matches-seasons-grid">'
        . ($cards ?: '<div class="alert alert-light border text-center w-100">No seasons found.</div>') . '</div></section>';
    $schema = seasonsCollectionSchema('Pro HPCL Matches by Season', $seasons, fn ($season) => ['name' => $season['name'], 'url' => '{{BASE}}/matches/season/' . $season['route_key']]);
    $schema['url'] = '{{BASE}}/matches';
    return renderLayout('matches.html', [], ['outer://section[@class="matches-seasons-shell"]' => $html], ['CollectionPage' => $schema]);
}

/** /point-tables. */
function renderPointTablesIndex(): string {
    $seasons = matchSeasons();
    $cards = '';
    foreach ($seasons as $season) {
        $started = seasonStarted($season);
        if ($started && (int) $season['points_table_match_count'] > 0) {
            $cards .= '<a href="{{BASE}}/point-tables/season/' . $season['route_key'] . '" class="points-season-card"><h2 class="points-season-card__name">' . escape($season['name']) . '</h2><p class="points-season-card__meta">View points table</p></a>';
        } else {
            $cards .= '<div class="points-season-card is-disabled"><h2 class="points-season-card__name">' . escape($season['name']) . '</h2><p class="points-season-card__meta">'
                . (!$started && $season['start_date'] ? 'Available from ' . date('d M Y', strtotime($season['start_date'])) : 'Points table will appear after first match result.') . '</p></div>';
        }
    }
    $html = '<section class="points-seasons-shell"><h1 class="points-seasons-title">Point Tables by Season</h1><p class="text-muted mb-0">Open any season to view standings and recent match results.</p><div class="points-seasons-grid">'
        . ($cards ?: '<div class="alert alert-light border text-center w-100">No seasons found.</div>') . '</div></section>';
    $schema = seasonsCollectionSchema('Pro HPCL Point Tables', $seasons, fn ($season) => ['name' => $season['name'] . ' Points Table', 'url' => '{{BASE}}/point-tables/season/' . $season['route_key']]);
    $schema['url'] = '{{BASE}}/point-tables';
    return renderLayout('point-tables.html', [], ['outer://section[@class="points-seasons-shell"]' => $html], ['CollectionPage' => $schema]);
}

/** Standings from completed and abandoned league matches (SeasonStandingsService::forSeason). */
function standings(array $season): array {
    $table = [];
    foreach (query('SELECT t.*, st.pool FROM teams t JOIN season_teams st ON st.team_id = t.id WHERE st.season_id = ? ORDER BY t.name', [$season['id']]) as $team) {
        $table[$team['id']] = ['team' => $team, 'pool' => $team['pool'], 'played' => 0, 'won' => 0, 'lost' => 0, 'tied' => 0, 'no_result' => 0, 'points' => 0, 'runs_for' => 0, 'balls_faced' => 0, 'runs_against' => 0, 'balls_bowled' => 0];
    }
    foreach (seasonMatches($season) as $match) {
        if ($match['stage'] !== 'league' || !in_array($match['status'], ['completed', 'abandoned'], true)) continue;
        [$a, $b] = [$match['team_a_id'], $match['team_b_id']];
        if (!isset($table[$a], $table[$b])) continue;
        $table[$a]['played']++;
        $table[$b]['played']++;
        $scoreA = $match['scores'][$a] ?? null;
        $scoreB = $match['scores'][$b] ?? null;
        if ($scoreA && $scoreB) {
            foreach ([[$a, $scoreA, $scoreB], [$b, $scoreB, $scoreA]] as [$team, $for, $against]) {
                $table[$team]['runs_for'] += $for['runs'];
                $table[$team]['balls_faced'] += $for['overs'] * 6 + $for['balls'];
                $table[$team]['runs_against'] += $against['runs'];
                $table[$team]['balls_bowled'] += $against['overs'] * 6 + $against['balls'];
            }
        }
        if ($match['status'] === 'abandoned' || $match['result_type'] === 'no_result' || $match['result_type'] === 'tie') {
            $key = $match['result_type'] === 'tie' && $match['status'] !== 'abandoned' ? 'tied' : 'no_result';
            foreach ([$a, $b] as $team) { $table[$team][$key]++; $table[$team]['points']++; }
        } elseif ($match['winner_team_id'] === $a || $match['winner_team_id'] === $b) {
            $winner = $match['winner_team_id'];
            $table[$winner]['won']++;
            $table[$winner]['points'] += 2;
            $table[$winner === $a ? $b : $a]['lost']++;
        }
    }
    foreach ($table as &$row) {
        $for = $row['balls_faced'] > 0 ? $row['runs_for'] / $row['balls_faced'] * 6 : 0.0;
        $against = $row['balls_bowled'] > 0 ? $row['runs_against'] / $row['balls_bowled'] * 6 : 0.0;
        $row['net_run_rate'] = round($for - $against, 3);
    }
    unset($row);
    usort($table, fn ($l, $r) => ($r['points'] <=> $l['points']) ?: ($r['net_run_rate'] <=> $l['net_run_rate']) ?: ($r['won'] <=> $l['won']) ?: strcmp($l['team']['name'], $r['team']['name']));
    foreach ($table as $index => &$row) $row['position'] = $index + 1;
    return $table;
}
/** Standings grouped by pool: A–D first, then other pools, then unassigned. */
function poolStandings(array $standings): array {
    $grouped = [];
    foreach ($standings as $row) $grouped[$row['pool'] ?: 'unassigned'][] = $row;
    $others = array_diff(array_keys($grouped), ['A', 'B', 'C', 'D', 'unassigned']);
    sort($others);
    $keys = array_merge(array_values(array_intersect(['A', 'B', 'C', 'D'], array_keys($grouped))), $others, isset($grouped['unassigned']) ? ['unassigned'] : []);
    return array_map(function ($key) use ($grouped) {
        $rows = array_values($grouped[$key]);
        foreach ($rows as $index => &$row) $row['position'] = $index + 1;
        return ['pool_label' => $key === 'unassigned' ? 'Unassigned Pool' : 'Pool ' . $key, 'rows' => $rows];
    }, $keys);
}

/** /matches/season/{n}. */
function renderSeasonMatches(string $routeKey, array $query): ?string {
    $season = query('SELECT * FROM seasons WHERE route_key = ?', [$routeKey])[0] ?? null;
    if (!$season) return null;
    $started = seasonStarted($season);
    $filters = ['stage' => (string) ($query['stage'] ?? ''), 'status' => (string) ($query['status'] ?? ''), 'team' => (string) ($query['team'] ?? '')];
    $matches = $started ? seasonMatches($season, $filters) : [];
    $all = seasonMatches($season);
    $count = fn (array $statuses) => count(array_filter($all, fn ($match) => in_array($match['status'], $statuses, true)));
    $stats = ['Matches' => count($all), 'Completed' => $count(['completed', 'abandoned']), 'Upcoming' => $count(['scheduled']), 'Live' => $count(['live'])];
    $name = escape($season['name']);
    $seasonUrl = '{{BASE}}/matches/season/' . $season['route_key'];

    $html = '<section class="match-shell"><nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3"><li class="breadcrumb-item"><a href="{{BASE}}">Home</a></li><li class="breadcrumb-item"><a href="{{BASE}}/matches">Matches</a></li><li class="breadcrumb-item active fw-semibold" aria-current="page">' . $name . '</li></ol></nav>'
        . '<div class="match-hero"><div><div class="match-hero__label">Season ' . $season['route_key'] . '</div><h1 class="mb-2">' . $name . ' Matches &amp; Results</h1><p class="mb-0 text-white-50">Fixtures, completed results, and official match updates.</p>';
    if (!pointsTableVisible($season)) {
        $html .= '<div class="match-hero__notice">' . ($season['start_date'] && !$started ? 'Matches will be available from ' . date('d M Y', strtotime($season['start_date'])) . '.' : 'Match schedule is being prepared and will appear shortly.') . '</div>';
    }
    $html .= '</div><div class="match-stats">';
    foreach ($stats as $label => $value) $html .= '<div class="match-stat"><div class="match-stat__label">' . $label . '</div><div class="match-stat__value">' . ($started ? $value : '-') . '</div></div>';
    $html .= '</div></div><div class="row g-4 mt-1"><div class="col-12"><div class="match-surface"><div class="match-surface__header"><div><h2 class="match-surface__title">Match Schedule</h2><p class="match-surface__sub">'
        . ($started ? 'Use filters to narrow by stage, status, or team.' : 'Match schedule will be published once the tournament starts.') . '</p></div></div>';
    if ($started) {
        $select = function (string $name, string $id, string $label, string $all, array $options) use ($filters) {
            $html = '<div class="match-filter-field"><label for="' . $id . '">' . $label . '</label><select id="' . $id . '" name="' . $name . '" class="form-select"><option value="">' . $all . '</option>';
            foreach ($options as $value => $text) $html .= '<option value="' . escape((string) $value) . '"' . ($filters[$name] === (string) $value ? ' selected' : '') . '>' . escape($text) . '</option>';
            return $html . '</select></div>';
        };
        $teams = array_column(query('SELECT t.id, t.name FROM teams t JOIN season_teams st ON st.team_id = t.id WHERE st.season_id = ? ORDER BY t.name', [$season['id']]), 'name', 'id');
        $html .= '<form method="GET" action="' . $seasonUrl . '" class="match-filter-grid">'
            . $select('stage', 'filter-stage', 'Stage', 'All stages', STAGE_LABELS)
            . $select('status', 'filter-status', 'Status', 'All statuses', ['scheduled' => 'Scheduled', 'live' => 'Live', 'completed' => 'Completed', 'abandoned' => 'Abandoned'])
            . $select('team', 'filter-team', 'Team', 'All teams', $teams)
            . '<div class="match-filter-actions"><button type="submit" class="btn btn-primary">Apply</button><a href="' . $seasonUrl . '" class="btn btn-outline-secondary">Reset</a></div></form><div class="mt-4 d-grid gap-3">';
        foreach ($matches as $match) {
            $time = $match['scheduled_at'] ? strtotime($match['scheduled_at']) : null;
            $html .= '<article class="match-card"><div class="match-card__meta"><span>' . ($match['match_no'] ? 'Match ' . $match['match_no'] : 'Fixture') . '</span><span>' . escape(STAGE_LABELS[$match['stage']] ?? mb_convert_case(str_replace('_', ' ', $match['stage']), MB_CASE_TITLE)) . '</span>'
                . '<span class="status-' . escape(strtolower($match['status'])) . '">' . escape(ucfirst($match['status'])) . '</span>' . ($time ? '<span>' . date('d M Y', $time) . '</span>' : '') . '</div>';
            foreach (['a' => 'Team A', 'b' => 'Team B'] as $side => $fallback) {
                $html .= '<div class="match-scoreline"><div class="match-team-wrap"><div class="match-team-logo">' . teamLogoImg($match["team_{$side}_logo"], $match["team_{$side}_name"] ?: $fallback) . '</div><div><div class="match-team">' . escape((string) $match["team_{$side}_name"]) . '</div>'
                    . ($match["team_{$side}_short"] ? '<small class="text-muted">' . escape($match["team_{$side}_short"]) . '</small>' : '') . '</div></div><div class="match-score">' . scoreboard($match, $match["team_{$side}_id"]) . '</div></div>';
            }
            $html .= '<div class="match-card__footer"><div class="text-muted small">' . escape($match['venue'] ?: 'Venue TBD') . ($time ? '<span class="ms-2">' . date('h:i A', $time) . '</span>' : '') . '</div>'
                . '<div class="d-flex align-items-center gap-3"><div class="match-card__result">' . escape(resultSummary($match)) . '</div><a href="' . matchUrl($season, $match) . '" class="btn btn-sm btn-outline-primary">View Scorecard</a></div></div></article>';
        }
        if (!$matches) $html .= '<div class="text-center py-5 text-muted">No matches found for the selected filters.</div>';
        $html .= '</div>';
    } else {
        $html .= '<div class="alert alert-light border mb-0">Match fixtures and results for ' . $name . ' will be visible from <strong>' . date('d M Y', strtotime($season['start_date'])) . '</strong>.</div>';
    }
    $html .= '</div></div></div></section>';
    $schema = ['@context' => 'https://schema.org', '@type' => 'SportsEvent', 'name' => $season['name'] . ' Matches', 'sport' => 'Cricket', 'url' => $seasonUrl,
        'organizer' => ['@type' => 'SportsOrganization', 'name' => 'Pro HPCL - Himachal Premium Cricket League', 'url' => '{{BASE}}'],
        'startDate' => $season['start_date'], 'eventStatus' => $started ? 'https://schema.org/EventScheduled' : 'https://schema.org/EventPostponed', 'description' => 'Fixtures and results for ' . $season['name'] . '.',
        'subEvent' => array_map(fn ($match) => array_filter(['@type' => 'SportsEvent', 'name' => fixtureName($match), 'sport' => 'Cricket', 'url' => matchUrl($season, $match), 'startDate' => isoTime($match['scheduled_at']), 'eventStatus' => matchEventStatus($match)], fn ($value) => $value !== null), array_slice($matches, 0, 20))];
    return renderLayout('season-matches.html', ['title' => $season['name'] . ' Matches & Results | Pro HPCL', 'description' => 'View fixtures, results, and recent standings for ' . $season['name'] . ' in Pro HPCL.', 'url' => $seasonUrl],
        ['outer://section[@class="match-shell"]' => $html], ['SportsEvent' => $schema]);
}

/** /season/{n}/matches/{id}. */
function renderMatchPage(string $routeKey, string $id): ?string {
    $season = query('SELECT * FROM seasons WHERE route_key = ?', [$routeKey])[0] ?? null;
    $match = null;
    if ($season) foreach (seasonMatches($season) as $item) if ((string) $item['id'] === $id) $match = $item;
    if (!$match) return null;
    $fixture = fixtureName($match);
    $seasonName = escape($season['name']);
    $seasonUrl = '{{BASE}}/matches/season/' . $season['route_key'];
    $time = $match['scheduled_at'] ? strtotime($match['scheduled_at']) : null;
    $html = '<section class="match-center-shell"><nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3"><li class="breadcrumb-item"><a href="{{BASE}}">Home</a></li><li class="breadcrumb-item"><a href="{{BASE}}/matches">Matches</a></li><li class="breadcrumb-item"><a href="' . $seasonUrl . '">' . $seasonName . '</a></li>'
        . '<li class="breadcrumb-item active fw-semibold" aria-current="page">Match ' . ($match['match_no'] ?: $match['id']) . ' (' . escape($match['team_a_name'] ?: 'TBD') . ' vs ' . escape($match['team_b_name'] ?: 'TBD') . ')</li></ol></nav>'
        . '<div class="match-center-hero"><div class="d-flex flex-column flex-lg-row justify-content-between gap-4"><div><a href="' . $seasonUrl . '" class="match-center-chip mb-3">&larr; Back to Matches</a><h1 class="mb-2">' . escape($fixture) . '</h1><p class="mb-0 text-white-50">' . $seasonName . ' &middot; ' . escape(resultSummary($match)) . '</p></div>'
        . '<div class="d-flex flex-wrap gap-2 align-content-start"><span class="match-center-chip match-center-chip--ghost">' . escape(ucfirst($match['status'])) . '</span>' . ($match['match_no'] ? '<span class="match-center-chip match-center-chip--ghost">Match ' . $match['match_no'] . '</span>' : '')
        . '<span class="match-center-chip match-center-chip--ghost">' . escape(STAGE_LABELS[$match['stage']] ?? mb_convert_case(str_replace('_', ' ', $match['stage']), MB_CASE_TITLE)) . '</span></div></div><div class="match-center-scoreboard">';
    foreach (['a', 'b'] as $side) {
        $html .= '<div class="match-center-team"><div><div class="match-center-team__name">' . escape((string) $match["team_{$side}_name"]) . '</div>' . ($match["team_{$side}_short"] ? '<div class="match-center-team__meta">' . escape($match["team_{$side}_short"]) . '</div>' : '')
            . '</div><div class="match-center-team__score">' . scoreboard($match, $match["team_{$side}_id"]) . '</div></div>';
    }
    $info = ['Venue' => $match['venue'] ?: 'Venue TBD', 'Schedule' => $time ? date('d M Y, h:i A', $time) : 'TBD', 'Toss' => $match['toss_summary'] ?: 'TBD', 'Result' => resultSummary($match)];
    $html .= '</div></div><div class="match-center-grid"><aside class="match-panel"><div class="match-panel__body"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><h2 class="h5 mb-0">Match Info</h2><span class="text-muted small">' . $seasonName . '</span></div><div class="match-meta-grid">';
    foreach ($info as $label => $value) $html .= '<div class="match-meta-card"><div class="match-meta-card__label">' . $label . '</div><div class="match-meta-card__value">' . escape($value) . '</div></div>';
    // No innings or ball-by-ball data is published on prohpcl.com.
    $html .= '</div></div></aside><div class="d-grid gap-4"><section class="match-panel"><div class="match-panel__body"><div class="commentary-empty">No scorecard has been published for this match yet.</div></div></section></div></div></section>';
    $competitor = fn (string $side) => $match["team_{$side}_name"] ? ['@type' => 'SportsTeam', 'name' => $match["team_{$side}_name"], 'sport' => 'Cricket', 'logo' => $match["team_{$side}_logo"] ? mediaUrl($match["team_{$side}_logo"], '') : null] : null;
    $schema = array_filter(['@context' => 'https://schema.org', '@type' => 'SportsEvent', 'name' => $fixture, 'sport' => 'Cricket', 'url' => matchUrl($season, $match), 'description' => 'Scorecard for ' . $fixture . ' in ' . $season['name'] . '.',
        'startDate' => isoTime($match['scheduled_at']), 'eventStatus' => matchEventStatus($match), 'competitor' => array_values(array_filter([$competitor('a'), $competitor('b')])),
        'organizer' => ['@type' => 'SportsOrganization', 'name' => 'Pro HPCL - Himachal Premium Cricket League', 'url' => '{{BASE}}']], fn ($value) => $value !== null);
    return renderLayout('match.html', ['title' => $fixture . ' | ' . $season['name'] . ' | Pro HPCL', 'description' => 'Match scorecard and ball by ball commentary for ' . $fixture . ' in ' . $season['name'] . '.', 'url' => matchUrl($season, $match)],
        ['outer://section[@class="match-center-shell"]' => $html], ['SportsEvent' => $schema]);
}

/** /point-tables/season/{n}. Returns null (404) until a result is in. */
function renderPointsTable(string $routeKey): ?string {
    $season = query('SELECT * FROM seasons WHERE route_key = ?', [$routeKey])[0] ?? null;
    if (!$season || !pointsTableVisible($season)) return null;
    $standings = standings($season);
    $pools = poolStandings($standings);
    $name = escape($season['name']);
    $row = function (array $row, bool $highlight) use ($season) {
        $team = $row['team'];
        $top = $highlight && $row['position'] <= 4;
        return '<tr' . ($highlight ? ' style="' . ($top ? 'background:rgba(16,185,129,.08); box-shadow: inset 4px 0 0 #059669;' : '') . '"' : '') . '><td><div class="points-position"' . ($highlight ? ' style="' . ($top ? 'background:linear-gradient(135deg,#059669,#10b981);' : '') . '"' : '') . '>' . $row['position'] . '</div></td>'
            . '<td><div class="points-team"><div class="points-team-logo">' . teamLogoImg($team['logo'], $team['name']) . '</div><div><div class="fw-bold"><a href="{{BASE}}/teams/season/' . $season['route_key'] . '/' . rawurlencode((string) $team['slug']) . '" class="text-decoration-none">' . escape($team['name']) . '</a></div>'
            . ($team['short_name'] ? '<div class="text-muted small">' . escape($team['short_name']) . '</div>' : '') . '</div></div></td>'
            . '<td>' . $row['played'] . '</td><td>' . $row['won'] . '</td><td>' . $row['lost'] . '</td><td>' . $row['tied'] . '</td><td>' . $row['no_result'] . '</td><td class="fw-bold">' . $row['points'] . '</td><td>' . number_format($row['net_run_rate'], 3) . '</td></tr>';
    };
    $head = '<thead><tr><th>Pos</th><th>Team</th><th>Played</th><th>Won</th><th>Lost</th><th>Tied</th><th>NR</th><th>Points</th><th>NRR</th></tr></thead>';
    $html = '<section class="points-shell"><nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3"><li class="breadcrumb-item"><a href="{{BASE}}">Home</a></li><li class="breadcrumb-item"><a href="{{BASE}}/point-tables">Point Tables</a></li><li class="breadcrumb-item active fw-semibold" aria-current="page">' . $name . '</li></ol></nav>'
        . '<div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-4"><div><h1 class="mb-1">' . $name . ' Points Table</h1><p class="text-muted mb-0">Computed from completed and abandoned matches.</p></div></div>'
        . '<div class="points-panel" id="public-standings-tabs"><div class="mb-3 points-tabs"><button type="button" class="points-tab-btn is-active" data-points-tab-trigger data-tab="overall">Overall</button><button type="button" class="points-tab-btn" data-points-tab-trigger data-tab="pool">Pool-wise</button></div>'
        . '<div data-points-tab-panel="overall"><div class="points-table-wrap"><table class="points-table">' . $head . '<tbody>'
        . ($standings ? implode('', array_map(fn ($item) => $row($item, true), $standings)) : '<tr><td colspan="9" class="text-center py-5 text-muted">No teams are available in this season yet.</td></tr>')
        . '</tbody></table></div></div><div class="d-none" data-points-tab-panel="pool"><div class="d-grid" style="gap: 18px;">';
    foreach ($pools as $pool) {
        $html .= '<div><h3 class="mb-2" style="font-size:1rem; font-weight:800; letter-spacing:0.04em; text-transform:uppercase; color:#334155;">' . escape($pool['pool_label']) . '</h3><div class="points-table-wrap"><table class="points-table">' . $head . '<tbody>'
            . implode('', array_map(fn ($item) => $row($item, false), $pool['rows'])) . '</tbody></table></div></div>';
    }
    if (!$pools) $html .= '<div class="text-center py-5 text-muted">No teams are available in this season yet.</div>';
    $html .= '</div></div></div><div class="points-panel mt-4"><h2 class="mb-3" style="font-size:1.25rem; font-weight:800;">Knockout Stage</h2><div class="d-grid" style="gap: 18px;">';
    $knockouts = array_filter(seasonMatches($season), fn ($match) => in_array($match['stage'], ['quarter_final', 'semi_final', 'final'], true));
    usort($knockouts, fn ($a, $b) => [$a['scheduled_at'] === null ? 0 : 1, $a['scheduled_at'], $a['id']] <=> [$b['scheduled_at'] === null ? 0 : 1, $b['scheduled_at'], $b['id']]);
    foreach (['quarter_final' => 'Quarter Final', 'semi_final' => 'Semi Final', 'final' => 'Final'] as $stage => $label) {
        $html .= '<div><h3 class="mb-2" style="font-size:1rem; font-weight:800; letter-spacing:0.04em; text-transform:uppercase; color:#334155;">' . $label . '</h3><div class="d-grid" style="gap: 10px;">';
        $stageMatches = array_filter($knockouts, fn ($match) => $match['stage'] === $stage);
        foreach ($stageMatches as $match) {
            $final = $stage === 'final';
            $time = $match['scheduled_at'] ? strtotime($match['scheduled_at']) : null;
            $html .= '<div class="ko-card ' . ($final ? 'ko-card--final' : '') . '"><div class="ko-fixture"><div class="ko-team">' . teamLogoImg($match['team_a_logo'], $match['team_a_name'] ?: 'Team A', 'ko-logo')
                . '<div class="min-w-0"><div class="ko-name">' . escape($match['team_a_name'] ?: 'TBD') . '</div>' . ($match['team_a_short'] ? '<div class="ko-short">' . escape($match['team_a_short']) . '</div>' : '') . '</div></div>'
                . '<div class="ko-vs ' . ($final ? 'ko-vs--final' : '') . '">VS</div><div class="ko-team ko-team--right"><div class="min-w-0"><div class="ko-name">' . escape($match['team_b_name'] ?: 'TBD') . '</div>' . ($match['team_b_short'] ? '<div class="ko-short">' . escape($match['team_b_short']) . '</div>' : '') . '</div>'
                . teamLogoImg($match['team_b_logo'], $match['team_b_name'] ?: 'Team B', 'ko-logo') . '</div></div><div class="ko-meta"><div class="ko-time">' . ($time ? date('d M Y, h:i A', $time) : 'Schedule TBD') . '</div><div class="ko-result">' . escape(resultSummary($match)) . '</div></div></div>';
        }
        if (!$stageMatches) $html .= '<div class="rounded-3 border p-3 text-muted small" style="border:1px dashed rgba(100,116,139,.45);">No ' . strtolower($label) . ' matches yet.</div>';
        $html .= '</div></div>';
    }
    $html .= '</div></div></section>';
    $competitors = array_slice(array_merge(...array_map(fn ($pool) => $pool['rows'], $pools ?: [['rows' => []]])), 0, 20);
    $schema = ['@context' => 'https://schema.org', '@type' => 'SportsEvent', 'name' => $season['name'] . ' Points Table', 'sport' => 'Cricket', 'url' => '{{BASE}}/point-tables/season/' . $season['route_key'],
        'description' => 'Official standings for ' . $season['name'] . ' in Pro HPCL.', 'startDate' => $season['start_date'],
        'organizer' => ['@type' => 'SportsOrganization', 'name' => 'Pro HPCL - Himachal Premium Cricket League', 'url' => '{{BASE}}'],
        'competitor' => array_map(fn ($row) => ['@type' => 'SportsTeam', 'name' => $row['team']['name'], 'sport' => 'Cricket', 'url' => '{{BASE}}/teams/season/' . $season['route_key'] . '/' . rawurlencode((string) $row['team']['slug']), 'logo' => $row['team']['logo'] ? mediaUrl($row['team']['logo'], '') : null], $competitors)];
    return renderLayout('points-table.html', ['title' => $season['name'] . ' Points Table | Pro HPCL', 'description' => 'Track the points table and recent results for ' . $season['name'] . ' in Pro HPCL.', 'url' => '{{BASE}}/point-tables/season/' . $season['route_key']],
        ['outer://section[@class="points-shell"]' => $html], ['SportsEvent' => $schema]);
}
