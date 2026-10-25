<?php
declare(strict_types=1);

// Player profiles, player search and statistics, rendered from the database.
require_once __DIR__ . '/site.php';

function seasonsList(): array {
    return query('SELECT * FROM seasons ORDER BY start_date DESC, id DESC');
}
function teamsList(?string $seasonId = null): array {
    return $seasonId
        ? query('SELECT t.* FROM teams t JOIN season_teams st ON st.team_id = t.id WHERE st.season_id = ? AND t.deleted_at IS NULL ORDER BY t.name', [$seasonId])
        : query('SELECT * FROM teams WHERE deleted_at IS NULL ORDER BY name');
}
function options(array $rows, string $selected, string $indent = ''): string {
    $html = '';
    foreach ($rows as $row) $html .= $indent . '<option value="' . $row['id'] . '"' . ((string) $row['id'] === $selected ? ' selected' : '') . '>' . escape($row['name']) . '</option>';
    return $html;
}
/** Matches PHP's float-to-string output used by the Laravel views for summed MVP values. */
function plainNumber(float|int|string|null $value): string {
    return (string) (float) $value;
}

// ---------------------------------------------------------------- player profile

function renderPlayerPage(string $slug): ?string {
    $player = query('SELECT * FROM players WHERE slug = ? AND deleted_at IS NULL', [$slug])[0] ?? null;
    if (!$player) return null;
    $stats = query('SELECT st.*, s.name AS season_name, s.route_key, s.status AS season_status, s.start_date, t.name AS team_name, t.slug AS team_slug, t.short_name, t.logo
        FROM player_season_stats st JOIN seasons s ON s.id = st.season_id JOIN teams t ON t.id = st.team_id
        WHERE st.player_id = ? ORDER BY s.start_date DESC, s.id DESC', [$player['id']]);
    $latest = $stats[0] ?? null;
    $sum = fn (string $field) => array_sum(array_column($stats, $field));
    $totals = [
        'seasons' => count(array_unique(array_column($stats, 'season_id'))),
        'matches' => $sum('matches'), 'innings' => $sum('innings'), 'runs' => $sum('runs'), 'wickets' => $sum('wickets'),
        'catches' => $sum('catches'), 'stumpings' => $sum('stumpings'), 'mvp_total' => plainNumber(array_sum(array_map('floatval', array_column($stats, 'mvp_total')))),
    ];
    $totals['bat_avg'] = $totals['innings'] ? round($totals['runs'] / $totals['innings'], 2) : 0;
    $totals['strike_rate'] = $sum('balls_faced') ? round($totals['runs'] / $sum('balls_faced') * 100, 2) : 0;
    $totals['bowl_avg'] = $totals['wickets'] ? round($sum('runs_conceded') / $totals['wickets'], 2) : 0;
    $totals['economy'] = $sum('balls_bowled') ? round($sum('runs_conceded') / $sum('balls_bowled') * 6, 2) : 0;
    $teamUrl = fn (array $stat) => '{{BASE}}/teams/season/' . $stat['route_key'] . '/' . rawurlencode((string) $stat['team_slug']);
    $seasonUrl = fn (array $stat) => '{{BASE}}/teams/season/' . $stat['route_key'];
    $photo = mediaUrl($player['photo'], '/assets/media/player-fallback.svg');
    $name = escape($player['name']);

    $breadcrumb = '<li class="breadcrumb-item"><a href="{{BASE}}">Home</a></li><li class="breadcrumb-item"><a href="{{BASE}}/teams">Teams</a></li>'
        . ($latest ? '<li class="breadcrumb-item"><a href="' . $seasonUrl($latest) . '">' . escape($latest['season_name']) . '</a></li>' : '<li class="breadcrumb-item">Season</li>')
        . ($latest ? '<li class="breadcrumb-item"><a href="' . $teamUrl($latest) . '">' . escape($latest['team_name']) . '</a></li>' : '<li class="breadcrumb-item">Team</li>')
        . '<li class="breadcrumb-item active fw-semibold" aria-current="page">' . $name . '</li>';
    $card = fn (string $label, string|int|float $value, string $column = 'col-md-3 col-6', string $extra = '') => '<div class="' . $column . '"><div class="stat-card"><div class="stat-label">' . $label . '</div><div class="stat-value">' . $value . '</div>' . $extra . '</div></div>';

    $html = '<div class="container"><div class="public-page-card"><div class="player-page-bg"><div class="container"><nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3">' . $breadcrumb . '</ol></nav></div>'
        . '<div class="row g-0 my-lg-5"><div class="col-md-8 mx-auto"><div class="profile shadow border-start border-primary border-4"><div class="row g-0">'
        . '<div class="col-12 d-md-none"><div class="text-center py-3"><img src="{{BASE}}/assets/media/logo.png" alt="Pro HPCL" class="logo" style="height: 48px;"></div></div>'
        . '<div class="col-md-5"><div class="playerImage" style="background-image: url(\'' . $photo . '\');"><div class="league-title">PHPCL/P/' . str_pad((string) $player['id'], 3, '0', STR_PAD_LEFT) . '</div></div></div>'
        . '<div class="col-md-7"><div class="mainInfo"><img src="{{BASE}}/assets/media/logo.png" alt="Pro HPCL" class="logo d-none d-md-block" style="height: 48px;"><div class="row g-0">'
        . '<div class="col-12"><h2 class="playerName">' . escape(mb_strtoupper($player['name'])) . '</h2></div>'
        . '<div class="col-12"><h3 class="playerType">' . escape($player['type'] ?? 'Player') . '</h3><div class="playerInfo"><table class="table table-sm">'
        . '<tr><th>Batting</th><td>' . escape($player['batting_style'] ?? '-') . '</td></tr><tr><th>Bowling</th><td>' . escape($player['bowling_style'] ?? '-') . '</td></tr></table></div></div></div></div>'
        . ($player['achievements'] ? '<div class="achievementContainer"><h3 class="achievementTitle">Achievements</h3>' . nl2br(escape($player['achievements'])) . '</div>' : '')
        . '</div></div></div>';

    $html .= '<div class="mt-4"><div class="d-flex flex-wrap align-items-center gap-2 mb-3"><h3 class="h5 fw-bold mb-0">Grand Total (All Seasons)</h3><span class="overall-badge">Overall</span></div><div class="row g-3 mb-4">'
        . $card('Seasons Played', $totals['seasons']) . $card('Matches', $totals['matches']) . $card('Innings', $totals['innings']) . $card('Runs', $totals['runs'])
        . $card('Wickets', $totals['wickets']) . $card('Catches', $totals['catches']) . $card('Stumpings', $totals['stumpings']) . $card('MVP Total', $totals['mvp_total'])
        . $card('Batting Avg', number_format($totals['bat_avg'], 2)) . $card('Strike Rate', number_format($totals['strike_rate'], 2))
        . $card('Bowling Avg', number_format($totals['bowl_avg'], 2)) . $card('Economy', number_format($totals['economy'], 2)) . '</div>';

    $html .= '<h3 class="h5 fw-bold mb-3">Teams Played For</h3>';
    $teams = [];
    foreach ($stats as $stat) $teams[$stat['team_id']] ??= $stat;
    if ($teams) {
        $html .= '<div class="d-flex flex-wrap gap-3 mb-4">';
        foreach ($teams as $stat) {
            $html .= '<div class="text-center"><a href="' . $teamUrl($stat) . '" class="text-decoration-none"><div class="border rounded-circle overflow-hidden bg-white shadow-sm" style="width: 72px; height: 72px;">'
                . '<img src="' . mediaUrl($stat['logo'], '/assets/media/logo.png') . '" alt="' . escape($stat['team_name']) . '" style="width: 100%; height: 100%; object-fit: cover;"></div>'
                . '<div class="mt-2 small text-muted">' . escape($stat['short_name'] ?: $stat['team_name']) . '</div></a></div>';
        }
        $html .= '</div>';
    } else {
        $html .= '<div class="alert alert-light border mb-4">No team history available yet.</div>';
    }

    $heading = match ($latest['season_status'] ?? null) { 'ongoing', 'upcoming' => 'Current Season Stats', 'completed' => 'Last Season Stats', default => 'Season Stats' };
    $html .= '<div class="d-flex align-items-center gap-2 mb-3"><h3 class="h5 fw-bold mb-0">' . $heading . '</h3>'
        . (!empty($latest['season_status']) ? '<span class="badge badge-season text-uppercase">' . escape($latest['season_status']) . '</span>' : '') . '</div>';
    if ($latest) {
        $html .= '<div class="row g-3">'
            . $card('Season', '<a href="' . $seasonUrl($latest) . '" class="text-decoration-none">' . escape($latest['season_name']) . '</a>', 'col-md-4', '<div class="text-muted text-sm">Team: <a href="' . $teamUrl($latest) . '" class="text-decoration-none">' . escape($latest['team_name']) . '</a></div>')
            . $card('Matches', $latest['matches'], 'col-md-4') . $card('Runs', $latest['runs'], 'col-md-4') . $card('Wickets', $latest['wickets'], 'col-md-4') . $card('MVP Total', $latest['mvp_total'], 'col-md-4') . '</div>';
    } else {
        $html .= '<div class="alert alert-light border">No stats available for this player yet.</div>';
    }
    if (count($stats) > 1) {
        $html .= '<div class="mt-4"><h4 class="h6 fw-bold mb-2">All Season Stats</h4><div class="table-responsive"><table class="table table-bordered bg-white"><thead class="table-light"><tr><th>Season</th><th>Team</th><th>Matches</th><th>Runs</th><th>Wickets</th><th>MVP</th></tr></thead><tbody>';
        foreach ($stats as $stat) {
            $html .= '<tr><td><a href="' . $seasonUrl($stat) . '" class="text-decoration-none">' . escape($stat['season_name']) . '</a></td><td><a href="' . $teamUrl($stat) . '" class="text-decoration-none">' . escape($stat['team_name']) . '</a></td>'
                . '<td>' . $stat['matches'] . '</td><td>' . $stat['runs'] . '</td><td>' . $stat['wickets'] . '</td><td>' . $stat['mvp_total'] . '</td></tr>';
        }
        $html .= '<tr class="table-light fw-bold"><td colspan="2">Total</td><td>' . $totals['matches'] . '</td><td>' . $totals['runs'] . '</td><td>' . $totals['wickets'] . '</td><td>' . $totals['mvp_total'] . '</td></tr></tbody></table></div></div>';
    }
    $html .= '</div></div></div></div></div></div>';

    // Structured data, as on prohpcl.com.
    $url = '{{BASE}}/player/' . rawurlencode($player['slug']);
    $json = fn (array $data) => '<script type="application/ld+json">' . str_replace('</', '<\/', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</script>';
    $person = ['@context' => 'https://schema.org', '@type' => 'Person', 'name' => $player['name'], 'image' => $photo, 'jobTitle' => 'Cricketer', 'knowsAbout' => 'Cricket',
        'memberOf' => array_values(array_filter([['@type' => 'SportsOrganization', 'name' => 'Pro HPCL - Himachal Premium Cricket League', 'url' => '{{BASE}}'], $latest ? ['@type' => 'SportsTeam', 'name' => $latest['team_name'], 'url' => $teamUrl($latest)] : null])),
        'url' => $url, 'identifier' => 'PHPCL/P/' . str_pad((string) $player['id'], 3, '0', STR_PAD_LEFT), 'description' => $player['name'] . ' Profile Pro HPCL - Himachal Premium Cricket League',
        'affiliation' => $latest ? ['@type' => 'SportsEvent', 'name' => $latest['season_name'], 'sport' => 'Cricket', 'url' => '{{BASE}}/teams/season/' . $latest['route_key'], 'startDate' => $latest['start_date'] ?? date('Y-m-d'),
            'location' => ['@type' => 'Place', 'name' => 'Padechi village, Shimla, Himachal Pradesh 171013', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Shimla', 'addressRegion' => 'Himachal Pradesh', 'addressCountry' => 'IN']]] : null];
    $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => '{{BASE}}'], ['@type' => 'ListItem', 'position' => 2, 'name' => 'Teams', 'item' => '{{BASE}}/teams']];
    if ($latest) {
        $crumbs[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $latest['season_name'], 'item' => $seasonUrl($latest)];
        $crumbs[] = ['@type' => 'ListItem', 'position' => 4, 'name' => $latest['team_name'], 'item' => $teamUrl($latest)];
    }
    $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => $player['name'], 'item' => $url];

    return str_replace(
        ['{{TITLE}}', '{{DESCRIPTION}}', '{{URL}}', '{{IMAGE}}', '{{CONTENT}}', '{{SCHEMA}}'],
        [escape($player['name'] . ' | Player Profile | Pro HPCL'), escape('View ' . $player['name'] . "'s Pro HPCL player profile, including team details, role, and season performance."), $url, $photo, $html,
            $json($person) . "\n" . $json(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $crumbs])],
        view('templates.player')
    );
}

// ---------------------------------------------------------------- player search

function renderPlayerSearch(string $html, array $query): string {
    [$dom, $xpath] = loadPage($html);
    $search = trim((string) ($query['q'] ?? ''));
    $season = (string) ($query['season'] ?? '');
    $team = (string) ($query['team'] ?? '');
    // Integer ids are inlined: the membership filter appears in several subqueries.
    $membership = ($season !== '' ? ' AND sp.season_id = ' . (int) $season : '') . ($team !== '' ? ' AND sp.team_id = ' . (int) $team : '');
    // Players in the admin's trash are left out.
    $where = ['p.deleted_at IS NULL'];
    $params = [];
    if ($search !== '') {
        $where[] = '(p.name LIKE :q1 OR p.city LIKE :q2 OR p.type LIKE :q3)';
        $params += ['q1' => "%$search%", 'q2' => "%$search%", 'q3' => "%$search%"];
    }
    if ($season !== '' || $team !== '') $where[] = "EXISTS (SELECT 1 FROM season_players sp WHERE sp.player_id = p.id$membership)";
    $where = implode(' AND ', $where);
    $total = (int) (query("SELECT COUNT(*) AS total FROM players p WHERE $where", $params)[0]['total']);
    $lastPage = max(1, (int) ceil($total / 24));
    $page = max(1, min($lastPage, (int) ($query['page'] ?? 1)));
    $players = query("SELECT p.*,
            (SELECT COALESCE(SUM(runs), 0) FROM player_season_stats WHERE player_id = p.id) AS total_runs,
            (SELECT COALESCE(SUM(wickets), 0) FROM player_season_stats WHERE player_id = p.id) AS total_wickets,
            (SELECT COALESCE(SUM(matches), 0) FROM player_season_stats WHERE player_id = p.id) AS total_matches,
            (SELECT COUNT(DISTINCT sp.season_id) FROM season_players sp WHERE sp.player_id = p.id$membership) AS seasons_played,
            (SELECT COALESCE(GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', '), '') FROM season_players sp JOIN teams t ON t.id = sp.team_id WHERE sp.player_id = p.id AND t.deleted_at IS NULL$membership) AS team_names
        FROM players p WHERE $where ORDER BY p.name LIMIT 24 OFFSET " . (($page - 1) * 24), $params);

    $cards = '';
    foreach ($players as $player) {
        $cards .= '<article class="card"><div class="card-body"><div class="player"><img src="' . mediaUrl($player['photo'], '/assets/media/player-fallback.svg') . '" alt="' . escape($player['name']) . '"><div>'
            . '<h3>' . escape($player['name']) . '</h3><p>' . escape($player['type'] ?: 'Player') . '</p><p>' . escape($player['city'] ?: 'City not available') . '</p>'
            . ($player['team_names'] !== '' ? '<p class="team-line">Teams: ' . escape($player['team_names']) . '</p>' : '') . '</div></div>'
            . '<div class="stats"><div class="stat"><b>' . $player['seasons_played'] . '</b><span>Seasons</span></div><div class="stat"><b>' . $player['total_matches'] . '</b><span>Matches</span></div>'
            . '<div class="stat"><b>' . $player['total_runs'] . '</b><span>Runs</span></div><div class="stat"><b>' . $player['total_wickets'] . '</b><span>Wickets</span></div></div></div>'
            . '<a href="{{BASE}}/player/' . escape(rawurlencode($player['slug'])) . '">View Profile</a></article>';
    }
    foreach ($xpath->query('//input[@name="q"]') as $input) $input->setAttribute('value', $search);
    if ($select = $xpath->query('//select[@name="season"]')->item(0)) replaceInnerHtml($dom, $select, '<option value="">All Seasons</option>' . options(seasonsList(), $season));
    if ($select = $xpath->query('//select[@name="team"]')->item(0)) replaceInnerHtml($dom, $select, '<option value="">All Teams</option>' . options(teamsList($season ?: null), $team));
    $grid = $xpath->query('//section[contains(concat(" ", normalize-space(@class), " "), " grid ")]')->item(0);
    if ($grid) replaceInnerHtml($dom, $grid, $cards ?: '<p class="empty">No players found for this filter.</p>');
    $counter = $xpath->query('//span[@class="count"]')->item(0);
    if ($counter) $counter->textContent = $total . ' players';
    if ($pager = $xpath->query('//nav[@class="pager"]')->item(0)) {
        $link = fn (int $number, string $label) => '<a href="{{BASE}}/players/search?' . escape(http_build_query(array_merge(array_filter($query, fn ($value) => $value !== ''), ['page' => $number]))) . '">' . $label . '</a>';
        replaceInnerHtml($dom, $pager, ($page > 1 ? $link($page - 1, 'Previous') : '<span>Previous</span>') . '<span>Page ' . $page . ' of ' . $lastPage . '</span>' . ($page < $lastPage ? $link($page + 1, 'Next') : '<span>Next</span>'));
    }
    return savePage($dom);
}

// ---------------------------------------------------------------- statistics

const STAT_METRICS = [
    'runs' => 'Most Runs', 'fours' => 'Most Fours', 'sixes' => 'Most Sixes', 'wickets' => 'Most Wickets', 'mvp' => 'Top MVP',
    'mvp_batting' => 'MVP Batting', 'mvp_bowling' => 'MVP Bowling', 'mvp_fielding' => 'MVP Fielding', 'avg' => 'Best Average', 'sr' => 'Best Strike Rate',
    'fifties' => 'Most 50s', 'hundreds' => 'Most 100s', 'hs' => 'Highest Score', 'balls_faced' => 'Most Balls Faced', 'balls_bowled' => 'Most Balls Bowled',
    'runs_conceded' => 'Most Runs Conceded', 'catches' => 'Most Catches', 'stumpings' => 'Most Stumpings', 'bowl_avg' => 'Best Bowling Average',
    'econ' => 'Best Bowling Economy', 'bowl_sr' => 'Best Bowling Strike Rate', 'best_bowling' => 'Best Bowling Figures',
];

function statsLeaderboard(array $filters): array {
    $where = ['p.deleted_at IS NULL'];
    $params = [];
    if ($filters['season'] !== '') { $where[] = 'st.season_id = :season'; $params['season'] = $filters['season']; }
    if ($filters['team'] !== '') { $where[] = 'st.team_id = :team'; $params['team'] = $filters['team']; }
    if ($filters['q'] !== '') { $where[] = 'p.name LIKE :q'; $params['q'] = '%' . $filters['q'] . '%'; }
    $order = match ($filters['metric']) {
        'fours', 'sixes', 'wickets', 'mvp_batting', 'mvp_bowling', 'mvp_fielding', 'fifties', 'hundreds', 'balls_faced', 'balls_bowled', 'runs_conceded', 'catches', 'stumpings' => $filters['metric'] . ' DESC',
        'mvp' => 'mvp_total DESC',
        'avg' => 'CASE WHEN SUM(st.innings) > 0 THEN SUM(st.runs) / SUM(st.innings) ELSE 0 END DESC',
        'sr' => 'CASE WHEN SUM(st.balls_faced) > 0 THEN (SUM(st.runs) / SUM(st.balls_faced)) * 100 ELSE 0 END DESC',
        'hs' => 'highest_score_num DESC',
        'bowl_avg' => 'CASE WHEN SUM(st.wickets) > 0 THEN SUM(st.runs_conceded) / SUM(st.wickets) ELSE 0 END ASC',
        'econ' => 'CASE WHEN SUM(st.balls_bowled) > 0 THEN (SUM(st.runs_conceded) / SUM(st.balls_bowled)) * 6 ELSE 0 END ASC',
        'bowl_sr' => 'CASE WHEN SUM(st.wickets) > 0 THEN SUM(st.balls_bowled) / SUM(st.wickets) ELSE 0 END ASC',
        'best_bowling' => 'best_bowling_wkts DESC, best_bowling_runs ASC',
        default => 'runs DESC',
    };
    return query('SELECT p.name, p.slug, p.type, p.photo,
            SUM(st.matches) AS matches, SUM(st.innings) AS innings, SUM(st.runs) AS runs,
            MAX(CAST(REPLACE(st.highest_score, "*", "") AS UNSIGNED)) AS highest_score_num,
            MAX(st.best_bowling) AS best_bowling,
            MAX(CAST(SUBSTRING_INDEX(st.best_bowling, "/", 1) AS UNSIGNED)) AS best_bowling_wkts,
            MIN(CAST(SUBSTRING_INDEX(st.best_bowling, "/", -1) AS UNSIGNED)) AS best_bowling_runs,
            SUM(st.balls_faced) AS balls_faced, SUM(st.fours) AS fours, SUM(st.sixes) AS sixes, SUM(st.fifties) AS fifties, SUM(st.hundreds) AS hundreds,
            SUM(st.wickets) AS wickets, SUM(st.balls_bowled) AS balls_bowled, SUM(st.runs_conceded) AS runs_conceded, SUM(st.catches) AS catches, SUM(st.stumpings) AS stumpings,
            SUM(st.mvp_batting) AS mvp_batting, SUM(st.mvp_bowling) AS mvp_bowling, SUM(st.mvp_fielding) AS mvp_fielding, SUM(st.mvp_total) AS mvp_total
        FROM player_season_stats st JOIN players p ON p.id = st.player_id
        WHERE ' . implode(' AND ', $where) . ' GROUP BY st.player_id ORDER BY ' . $order . ', st.player_id LIMIT 50', $params);
}

function statsSummary(): string {
    $s = array_map('intval', query('SELECT COALESCE(SUM(matches), 0) matches, COALESCE(SUM(runs), 0) runs, COALESCE(SUM(wickets), 0) wickets, COALESCE(SUM(balls_bowled), 0) balls,
        COALESCE(SUM(runs_conceded), 0) extras, COALESCE(SUM(fours), 0) fours, COALESCE(SUM(sixes), 0) sixes, COALESCE(SUM(fifties), 0) fifties, COALESCE(SUM(hundreds), 0) hundreds,
        COALESCE(SUM(innings), 0) innings, COALESCE(SUM(catches), 0) catches, COALESCE(SUM(stumpings), 0) stumpings, COALESCE(SUM(balls_faced), 0) balls_faced FROM player_season_stats')[0]);
    $boundaries = $s['fours'] + $s['sixes'];
    $n = fn (int|float $value, int $decimals = 0) => number_format($value, $decimals);
    $group = fn (string $class, string $title, array $items) => '<article class="season-overview__group season-overview__group--' . $class . '"><h4>' . $title . '</h4><div class="season-overview__stats">'
        . implode('', array_map(fn ($label, $value) => '<div><span>' . $label . '</span><strong>' . $value . '</strong></div>', array_keys($items), $items)) . '</div></article>';
    return '<section class="season-overview mb-4" aria-label="All seasons summary"><div class="season-overview__header"><h3 class="season-overview__title">All Seasons Summary</h3><p class="season-overview__subtitle">Aggregated Pro HPCL performance snapshot.</p></div><div class="season-overview__groups">'
        . $group('batting', 'Batting', ['Runs' => $n($s['runs']), 'Innings' => $n($s['innings']), 'Balls Faced' => $n($s['balls_faced']), '4s / 6s' => $n($s['fours']) . ' / ' . $n($s['sixes']), '50s / 100s' => $n($s['fifties']) . ' / ' . $n($s['hundreds']), 'Strike Rate' => $n($s['balls_faced'] ? round($s['runs'] / $s['balls_faced'] * 100, 2) : 0, 2)])
        . $group('bowling', 'Bowling', ['Wickets' => $n($s['wickets']), 'Balls Bowled' => $n($s['balls']), 'Runs Conceded' => $n($s['extras']), 'Economy' => $n($s['balls'] ? round($s['extras'] / $s['balls'] * 6, 2) : 0, 2), 'Balls / Wicket' => $n($s['wickets'] ? round($s['balls'] / $s['wickets'], 2) : 0, 2)])
        . $group('fielding', 'Fielding', ['Catches' => $n($s['catches']), 'Stumpings' => $n($s['stumpings']), 'Matches' => $n($s['matches'])])
        . $group('insights', 'Boundary Insights', ['Total Boundaries' => $n($boundaries), 'Boundary %' => $n($s['balls_faced'] ? round($boundaries / $s['balls_faced'] * 100, 2) : 0, 2), 'Boundary Frequency' => $n($boundaries ? round($s['balls_faced'] / $boundaries, 2) : 0, 2)])
        . '</div></section>';
}

/** Port of resources/views/public/stats/_results.blade.php. */
function statsResults(array $filters, array $teams, bool $withSummary): string {
    $allSeasons = $filters['season'] === '';
    $disabled = $allSeasons ? ' disabled' : '';
    $metricOptions = '';
    foreach (STAT_METRICS as $key => $label) $metricOptions .= '<option value="' . $key . '"' . ($filters['metric'] === $key ? ' selected' : '') . '>' . $label . '</option>';
    $html = '<div class="row mb-4"><div class="col-12"><form method="GET" action="{{BASE}}/stats" class="filter-bar"><div class="row g-3">'
        . '<div class="col-lg-3 col-md-6"><select name="season" class="form-select filter-control js-filter-select"><option value="">All Seasons</option>' . options(seasonsList(), $filters['season']) . '</select></div>'
        . '<div class="col-lg-3 col-md-6"><select name="metric" class="form-select filter-control js-filter-select"' . $disabled . '>' . $metricOptions . '</select></div>'
        . '<div class="col-lg-3 col-md-6"><select name="team" class="form-select filter-control js-filter-select"' . $disabled . '><option value="">All Teams</option>' . options($teams, $filters['team']) . '</select></div>'
        . '<div class="col-lg-3 col-md-6"><input type="text" name="q" value="' . escape($filters['q']) . '" class="form-control filter-control" placeholder="Search By Player Name"' . $disabled . '></div>'
        . '</div></form></div></div>';
    if ($allSeasons && $withSummary) return $html . statsSummary();

    $title = STAT_METRICS[$filters['metric']] ?? 'Most Runs';
    $seasonLabel = null;
    foreach (seasonsList() as $season) if ((string) $season['id'] === $filters['season']) $seasonLabel = $season['name'];
    $rows = statsLeaderboard($filters);
    $html .= '<div class="stats-section"><div class="stats-section-title mb-3"><h3 class="h5 fw-bold mb-0">' . escape($seasonLabel ? "$seasonLabel · $title" : "$title (All Seasons)") . '</h3><span class="badge bg-primary">' . $title . '</span></div>';
    $computed = function (array $row): array {
        return [
            'avg' => $row['innings'] > 0 ? $row['runs'] / $row['innings'] : 0,
            'sr' => $row['balls_faced'] > 0 ? $row['runs'] / $row['balls_faced'] * 100 : 0,
            'bowl_avg' => $row['wickets'] > 0 ? $row['runs_conceded'] / $row['wickets'] : 0,
            'econ' => $row['balls_bowled'] > 0 ? $row['runs_conceded'] / $row['balls_bowled'] * 6 : 0,
            'bowl_sr' => $row['wickets'] > 0 ? $row['balls_bowled'] / $row['wickets'] : 0,
        ];
    };
    if ($rows) {
        $html .= '<div class="row g-4 mb-4">';
        foreach (array_slice($rows, 0, 3) as $index => $row) {
            $c = $computed($row);
            $value = match ($filters['metric']) {
                'avg', 'sr', 'bowl_avg', 'econ', 'bowl_sr' => number_format($c[$filters['metric']], 2),
                'mvp' => $row['mvp_total'] ?? 0,
                'hs' => $row['highest_score_num'] ?? 0,
                'best_bowling' => $row['best_bowling'] ?? '-',
                default => $row[$filters['metric']] ?? 0,
            };
            $long = strlen(trim($row['name'])) > 14 || str_word_count(trim($row['name'])) > 2;
            $html .= '<div class="col-lg-4 col-md-6"><article class="tpv2-card ' . ['tpv2-card--teal', 'tpv2-card--orange', 'tpv2-card--red'][$index] . ' stats-performer-card ' . ($long ? 'stats-performer-card--long' : '') . '">'
                . '<img class="tpv2-card__player" src="' . mediaUrl($row['photo'], '/assets/media/player-fallback.svg') . '" alt="' . escape($row['name']) . '" />'
                . '<div class="tpv2-card__meta"><span class="tpv2-card__tag">' . $title . '</span><h3 class="tpv2-card__name"><a href="{{BASE}}/player/' . escape(rawurlencode($row['slug'])) . '" class="leader-link stats-performer-link">' . escape($row['name']) . '</a></h3>'
                . '<p class="tpv2-card__metric-label">' . escape($seasonLabel ?: 'All Seasons') . '</p><p class="tpv2-card__metric-value">' . escape((string) $value) . '</p></div></article></div>';
        }
        $html .= '</div>';
    }
    $html .= '<div class="table-responsive"><table class="table table-bordered align-middle bg-white"><thead class="table-light"><tr><th style="width: 70px;">Pos</th><th>Player</th>';
    foreach (['Mat', 'Inns', 'Runs', 'Wkts', 'HS', 'Avg', 'BF', 'SR', '100', '50', '4s', '6s', 'BB', 'BBow', 'RC', 'BA', 'Econ', 'BSR', 'Best Bowl', 'Ct', 'St', 'MVP'] as $label) $html .= "<th>$label</th>";
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $index => $row) {
        $c = $computed($row);
        $cells = [$row['matches'], $row['innings'], $row['runs'], $row['wickets'], $row['highest_score_num'] ?? 0, number_format($c['avg'], 2), $row['balls_faced'], number_format($c['sr'], 2),
            $row['hundreds'], $row['fifties'], $row['fours'], $row['sixes'], $row['best_bowling_wkts'] ?? 0, $row['balls_bowled'], $row['runs_conceded'],
            number_format($c['bowl_avg'], 2), number_format($c['econ'], 2), number_format($c['bowl_sr'], 2), escape($row['best_bowling'] ?? '-'), $row['catches'], $row['stumpings'], number_format((float) $row['mvp_total'], 2)];
        $html .= '<tr><td class="text-center fw-bold">' . ($index + 1) . '</td><td><div class="d-flex align-items-center gap-3"><img src="' . mediaUrl($row['photo'], '/assets/media/player-fallback.svg') . '" alt="' . escape($row['name']) . '" class="leader-player" style="width: 56px; height: 56px;">'
            . '<div><div class="fw-bold"><a href="{{BASE}}/player/' . escape(rawurlencode($row['slug'])) . '" class="text-decoration-none text-dark">' . escape($row['name']) . '</a></div><div class="small text-muted">' . escape($row['type'] ?? 'Player') . '</div></div></div></td>';
        foreach ($cells as $column => $cell) $html .= ($column === 2 ? '<td class="fw-bold">' : '<td>') . $cell . '</td>';
        $html .= '</tr>';
    }
    if (!$rows) $html .= '<tr><td colspan="24"><div class="alert alert-light border mb-0">No stats available.</div></td></tr>';
    return $html . '</tbody></table></div></div>';
}

/** /stats (with optional filters and AJAX) and /stats/season/{n}. */
/** /stats/season/{n}: the season's stats page, with its top 20 run scorers in the JSON-LD. Returns null for a 404. */
function renderSeasonStats(string $routeKey, array $query): ?string {
    $season = query('SELECT * FROM seasons WHERE route_key = ?', [$routeKey])[0] ?? null;
    if (!$season) return null;
    $top = array_slice(statsLeaderboard(['season' => (string) $season['id'], 'team' => '', 'metric' => 'runs', 'q' => '']), 0, 20);
    $schema = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $season['name'] . ' Player Stats', 'url' => '{{BASE}}/stats/season/' . $season['route_key'],
        'description' => 'Season-wise cricket player statistics for ' . $season['name'] . '.',
        'about' => ['@type' => 'SportsEvent', 'name' => $season['name'], 'sport' => 'Cricket', 'url' => '{{BASE}}/teams/season/' . $season['route_key'], 'startDate' => $season['start_date'],
            'location' => ['@type' => 'Place', 'name' => 'Padechi village, Shimla, Himachal Pradesh 171013', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Shimla', 'addressRegion' => 'Himachal Pradesh', 'addressCountry' => 'IN']],
            'organizer' => ['@type' => 'SportsOrganization', 'name' => 'Pro HPCL - Himachal Premium Cricket League', 'url' => '{{BASE}}']],
        'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(fn ($row, $i) => ['@type' => 'ListItem', 'position' => $i + 1,
            'item' => ['@type' => 'Person', 'name' => $row['name'], 'jobTitle' => 'Cricketer', 'knowsAbout' => 'Cricket', 'url' => '{{BASE}}/player/' . rawurlencode($row['slug'])]], $top, array_keys($top))]];
    $html = view('templates.stats-season', ['season' => escape($season['name']), 'route_key' => $season['route_key'], 'schema' => jsonLd($schema)]);
    return renderStats($html, '/stats/season/' . $season['route_key'], $query);
}

function renderStats(string $html, string $path, array $query): string {
    $filters = ['season' => (string) ($query['season'] ?? ''), 'team' => (string) ($query['team'] ?? ''), 'metric' => (string) ($query['metric'] ?? 'runs'), 'q' => trim((string) ($query['q'] ?? ''))];
    if (!isset(STAT_METRICS[$filters['metric']])) $filters['metric'] = 'runs';
    $seasonPage = preg_match('~^/stats/season/(\d+)$~', $path, $match);
    if ($seasonPage) {
        $season = query('SELECT id FROM seasons WHERE route_key = ?', [$match[1]])[0] ?? null;
        if (!$season) notFound();
        $filters['season'] = (string) $season['id'];
        $teams = teamsList($filters['season']);
    } else {
        if ($filters['season'] === '') $filters = ['season' => '', 'team' => '', 'metric' => 'runs', 'q' => ''];
        $teams = teamsList();
    }
    $content = statsResults($filters, $teams, !$seasonPage);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['html' => render($content)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    [$dom, $xpath] = loadPage($html);
    if ($results = $xpath->query('//*[@id="stats-results"]')->item(0)) replaceInnerHtml($dom, $results, $content);
    return savePage($dom);
}
