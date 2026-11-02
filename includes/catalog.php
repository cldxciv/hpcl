<?php
declare(strict_types=1);

// Player profiles, player search and statistics, rendered from the database.
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/cricheroes.php';

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
    // Team history comes from the season rosters, which also cover players whose matches have no scored stats yet.
    $rosters = query('SELECT sp.season_id, s.name AS season_name, s.route_key, s.status AS season_status, s.start_date, t.id AS team_id, t.name AS team_name, t.slug AS team_slug, t.short_name, t.logo
        FROM season_players sp JOIN seasons s ON s.id = sp.season_id JOIN teams t ON t.id = sp.team_id
        WHERE sp.player_id = ? AND t.deleted_at IS NULL AND EXISTS (SELECT 1 FROM season_teams st2 WHERE st2.season_id = sp.season_id AND st2.team_id = sp.team_id) ORDER BY s.start_date DESC, s.id DESC', [$player['id']]);
    // Stat rows with every counter at zero are placeholders, not results: they are left out of the totals and tables.
    $counters = array_flip(['matches', 'innings', 'runs', 'balls_faced', 'wickets', 'balls_bowled', 'catches', 'stumpings']);
    $played = array_values(array_filter($stats, fn (array $stat) => array_sum(array_map('intval', array_intersect_key($stat, $counters))) > 0));
    $latest = $rosters[0] ?? $stats[0] ?? null;
    $sum = fn (string $field) => array_sum(array_column($played, $field));
    $totals = [
        'seasons' => count(array_unique(array_column($played, 'season_id'))),
        'matches' => $sum('matches'), 'innings' => $sum('innings'), 'runs' => $sum('runs'), 'wickets' => $sum('wickets'),
        'catches' => $sum('catches'), 'stumpings' => $sum('stumpings'), 'mvp_total' => plainNumber(array_sum(array_map('floatval', array_column($played, 'mvp_total')))),
    ];
    // A rate with nothing to divide by is shown as a dash rather than 0.00.
    $rate = fn (float|int $top, float|int $bottom, int $scale = 1) => $bottom ? number_format(round($top / $bottom * $scale, 2), 2) : '&ndash;';
    $totals['bat_avg'] = $rate($totals['runs'], $totals['innings']);
    $totals['strike_rate'] = $rate($totals['runs'], $sum('balls_faced'), 100);
    $totals['bowl_avg'] = $rate($sum('runs_conceded'), $totals['wickets']);
    $totals['economy'] = $rate($sum('runs_conceded'), $sum('balls_bowled'), 6);
    $teamUrl = fn (array $stat) => '{{BASE}}/teams/season/' . $stat['route_key'] . '/' . rawurlencode((string) $stat['team_slug']);
    $seasonUrl = fn (array $stat) => '{{BASE}}/teams/season/' . $stat['route_key'];
    $photo = mediaUrl($player['photo'], '/assets/media/player-fallback.svg');
    $name = escape($player['name']);

    $breadcrumb = '<li class="breadcrumb-item"><a href="{{BASE}}">Home</a></li><li class="breadcrumb-item"><a href="{{BASE}}/teams">Teams</a></li>'
        . ($latest ? '<li class="breadcrumb-item"><a href="' . $seasonUrl($latest) . '">' . escape($latest['season_name']) . '</a></li>' : '<li class="breadcrumb-item">Season</li>')
        . ($latest ? '<li class="breadcrumb-item"><a href="' . $teamUrl($latest) . '">' . escape($latest['team_name']) . '</a></li>' : '<li class="breadcrumb-item">Team</li>')
        . '<li class="breadcrumb-item active fw-semibold" aria-current="page">' . $name . '</li>';
    $card = fn (string $label, string|int|float $value, string $column = 'col-md-3 col-6', string $extra = '') => '<div class="' . $column . '"><div class="stat-card"><div class="stat-label">' . $label . '</div><div class="stat-value">' . $value . '</div>' . $extra . '</div></div>';

    $html = '<nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3">' . $breadcrumb . '</ol></nav>'
        . '<div class="row g-0"><div class="col-lg-10 col-xl-9 mx-auto"><div class="profile"><div class="row g-0">'
        . '<div class="col-md-5"><div class="playerImage" style="background-image: url(\'' . $photo . '\');"><div class="league-title">PHPCL/P/' . str_pad((string) $player['id'], 3, '0', STR_PAD_LEFT) . '</div></div></div>'
        . '<div class="col-md-7"><div class="mainInfo"><div class="row g-0">'
        . '<div class="col-12"><h1 class="playerName">' . escape(mb_strtoupper($player['name'])) . '</h1></div>'
        . '<div class="col-12"><p class="playerType">' . escape($player['type'] ?? 'Player') . '</p><div class="playerInfo"><table class="table table-sm">'
        . '<tr><th scope="row">Batting</th><td>' . escape($player['batting_style'] ?? '-') . '</td></tr><tr><th scope="row">Bowling</th><td>' . escape($player['bowling_style'] ?? '-') . '</td></tr></table></div>'
        . (($crichUrl = cricheroesUrl((string) ($player['cricheroes_url'] ?? ''))) ? '<a class="player-cricheroes" href="' . escape($crichUrl) . '" target="_blank" rel="noopener">View on CricHeroes<span class="visually-hidden"> (opens in a new tab)</span></a>' : '')
        . '</div></div></div></div>'
        . ($player['achievements'] ? '<div class="achievementContainer"><h2 class="achievementTitle">Achievements</h2>' . nl2br(escape($player['achievements'])) . '</div>' : '')
        . '</div></div></div>';

    $html .= '<div class="player-panel">';
    if ($played) {
        $single = count($played) === 1 ? $played[0] : null;
        $html .= '<div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h2 class="player-heading mb-0">' . ($single ? 'Career Stats' : 'Grand Total (All Seasons)') . '</h2><span class="overall-badge">' . ($single ? escape($single['season_name']) : 'Overall') . '</span></div>'
            . ($single ? '<p class="player-note mb-3">From ' . escape($single['season_name']) . ' with <a href="' . $teamUrl($single) . '">' . escape($single['team_name']) . '</a>.</p>' : '<div class="mb-3"></div>');
        $overview = $card('Seasons Played', $totals['seasons']) . $card('Matches', $totals['matches']);
        if ((float) $totals['mvp_total'] > 0) $overview .= $card('MVP Total', $totals['mvp_total']);
        $groups = ['Overview' => $overview];
        if ($totals['innings'] || $totals['runs'] || $sum('balls_faced')) $groups['Batting'] = $card('Innings', $totals['innings']) . $card('Runs', $totals['runs']) . $card('Batting Avg', $totals['bat_avg']) . $card('Strike Rate', $totals['strike_rate']);
        if ($totals['wickets'] || $sum('balls_bowled')) $groups['Bowling'] = $card('Wickets', $totals['wickets']) . $card('Bowling Avg', $totals['bowl_avg']) . $card('Economy', $totals['economy']);
        if ($totals['catches'] || $totals['stumpings']) $groups['Fielding'] = $card('Catches', $totals['catches']) . $card('Stumpings', $totals['stumpings']);
        foreach ($groups as $label => $cards) $html .= '<h3 class="player-subheading">' . $label . '</h3><div class="row g-3 mb-4">' . $cards . '</div>';
    } else {
        $html .= '<h2 class="player-heading">Career Stats</h2><p class="player-empty">Career statistics for this player aren&rsquo;t available yet.'
            . ($rosters ? ' They will appear here once ' . escape($rosters[0]['season_name']) . ' matches have been played.' : '') . '</p>';
    }

    $html .= '<h2 class="player-heading">Teams Played For</h2>';
    $teams = [];
    foreach ($rosters ?: $stats as $stat) $teams[$stat['team_id']] ??= $stat;
    if ($teams) {
        $html .= '<ul class="player-teams list-unstyled mb-4">';
        foreach ($teams as $stat) {
            $html .= '<li><a href="' . $teamUrl($stat) . '"><span class="player-teams__logo"><img src="' . mediaUrl($stat['logo'], '/assets/media/logo.png') . '" alt="" loading="lazy"></span>'
                . '<span class="player-teams__name">' . escape($stat['short_name'] ?: $stat['team_name']) . '</span><span class="player-teams__season">' . escape($stat['season_name']) . '</span></a></li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<p class="player-empty mb-4">No team history available yet.</p>';
    }

    if (count($played) > 1) {
        $recent = $played[0];
        $heading = match ($recent['season_status'] ?? null) { 'ongoing', 'upcoming' => 'Current Season Stats', 'completed' => 'Last Season Stats', default => 'Season Stats' };
        $html .= '<div class="d-flex align-items-center gap-2 mb-3"><h2 class="player-heading mb-0">' . $heading . '</h2>'
            . (!empty($recent['season_status']) ? '<span class="badge badge-season text-uppercase">' . escape($recent['season_status']) . '</span>' : '') . '</div>'
            . '<div class="row g-3">'
            . $card('Season', '<a href="' . $seasonUrl($recent) . '">' . escape($recent['season_name']) . '</a>', 'col-md-4', '<div class="player-note">Team: <a href="' . $teamUrl($recent) . '">' . escape($recent['team_name']) . '</a></div>')
            . $card('Matches', $recent['matches'], 'col-md-4') . $card('Runs', $recent['runs'], 'col-md-4') . $card('Wickets', $recent['wickets'], 'col-md-4') . $card('MVP Total', $recent['mvp_total'], 'col-md-4') . '</div>';
        $html .= '<div class="mt-4"><h3 class="player-subheading">All Season Stats</h3><div class="table-responsive" tabindex="0" role="region" aria-label="All season stats"><table class="table table-bordered bg-white"><thead class="table-light"><tr><th scope="col">Season</th><th scope="col">Team</th><th scope="col">Matches</th><th scope="col">Runs</th><th scope="col">Wickets</th><th scope="col">MVP</th></tr></thead><tbody>';
        foreach ($played as $stat) {
            $html .= '<tr><td><a href="' . $seasonUrl($stat) . '">' . escape($stat['season_name']) . '</a></td><td><a href="' . $teamUrl($stat) . '">' . escape($stat['team_name']) . '</a></td>'
                . '<td>' . $stat['matches'] . '</td><td>' . $stat['runs'] . '</td><td>' . $stat['wickets'] . '</td><td>' . $stat['mvp_total'] . '</td></tr>';
        }
        $html .= '<tr class="table-light fw-bold"><td colspan="2">Total</td><td>' . $totals['matches'] . '</td><td>' . $totals['runs'] . '</td><td>' . $totals['wickets'] . '</td><td>' . $totals['mvp_total'] . '</td></tr></tbody></table></div></div>';
    }
    $html .= '</div></div></div>';

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
    $membership = ($season !== '' ? ' AND sp.season_id = ' . (int) $season : '') . ($team !== '' ? ' AND sp.team_id = ' . (int) $team : '')
        . ' AND EXISTS (SELECT 1 FROM season_teams st2 WHERE st2.season_id = sp.season_id AND st2.team_id = sp.team_id)';
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
        $label = escape($player['name']);
        $cards .= '<li><article class="ps-card"><div class="ps-card__body"><div class="ps-card__player"><img src="' . mediaUrl($player['photo'], '/assets/media/player-fallback.svg') . '" alt="" loading="lazy"><div>'
            . '<h3 class="ps-card__name">' . $label . '</h3><p>' . escape($player['type'] ?: 'Player') . '</p>' . ($player['city'] ? '<p>' . escape($player['city']) . '</p>' : '')
            . ($player['team_names'] !== '' ? '<p class="ps-card__teams">Teams: ' . escape($player['team_names']) . '</p>' : '') . '</div></div>'
            . '<div class="ps-card__stats"><div class="ps-card__stat"><b>' . $player['seasons_played'] . '</b><span>Seasons</span></div><div class="ps-card__stat"><b>' . $player['total_matches'] . '</b><span>Matches</span></div>'
            . '<div class="ps-card__stat"><b>' . $player['total_runs'] . '</b><span>Runs</span></div><div class="ps-card__stat"><b>' . $player['total_wickets'] . '</b><span>Wickets</span></div></div></div>'
            . '<a class="ps-card__link" href="{{BASE}}/player/' . escape(rawurlencode($player['slug'])) . '" aria-label="View profile: ' . $label . '">View profile</a></article></li>';
    }
    $empty = '<li class="ps-empty"><h3 class="ps-empty__title">No players found</h3><p>' . ($search !== '' ? 'Nothing matched &ldquo;' . escape($search) . '&rdquo;' . ($season !== '' || $team !== '' ? ' with these filters' : '') . '. ' : 'No players match these filters. ')
        . 'Try a shorter name, search by city or role, or remove the season or team filter.</p><a href="{{BASE}}/players/search" class="btn btn-primary">Clear search</a></li>';
    foreach ($xpath->query('//input[@name="q"]') as $input) $input->setAttribute('value', $search);
    if ($select = $xpath->query('//select[@name="season"]')->item(0)) replaceInnerHtml($dom, $select, '<option value="">All Seasons</option>' . options(seasonsList(), $season));
    if ($select = $xpath->query('//select[@name="team"]')->item(0)) replaceInnerHtml($dom, $select, '<option value="">All Teams</option>' . options(teamsList($season ?: null), $team));
    if ($grid = $xpath->query('//ul[contains(concat(" ", normalize-space(@class), " "), " ps-grid ")]')->item(0)) replaceInnerHtml($dom, $grid, $cards ?: $empty);
    if ($counter = $xpath->query('//span[contains(concat(" ", normalize-space(@class), " "), " ps-count ")]')->item(0)) $counter->textContent = $total . ($total === 1 ? ' player' : ' players');
    if ($pager = $xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " ps-pager ")]')->item(0)) {
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
    $group = fn (string $class, string $title, array $items) => '<article class="season-overview__group season-overview__group--' . $class . '"><h3>' . $title . '</h3><div class="season-overview__stats">'
        . implode('', array_map(fn ($label, $value) => '<div><span>' . $label . '</span><strong>' . $value . '</strong></div>', array_keys($items), $items)) . '</div></article>';
    return '<section class="season-overview mb-4" aria-label="All seasons summary"><div class="season-overview__header"><h2 class="season-overview__title">All Seasons Summary</h2><p class="season-overview__subtitle">Aggregated Pro HPCL performance snapshot.</p></div><div class="season-overview__groups">'
        . $group('batting', 'Batting', ['Runs' => $n($s['runs']), 'Innings' => $n($s['innings']), 'Balls Faced' => $n($s['balls_faced']), '4s / 6s' => $n($s['fours']) . ' / ' . $n($s['sixes']), '50s / 100s' => $n($s['fifties']) . ' / ' . $n($s['hundreds']), 'Strike Rate' => $n($s['balls_faced'] ? round($s['runs'] / $s['balls_faced'] * 100, 2) : 0, 2)])
        . $group('bowling', 'Bowling', ['Wickets' => $n($s['wickets']), 'Balls Bowled' => $n($s['balls']), 'Runs Conceded' => $n($s['extras']), 'Economy' => $n($s['balls'] ? round($s['extras'] / $s['balls'] * 6, 2) : 0, 2), 'Balls / Wicket' => $n($s['wickets'] ? round($s['balls'] / $s['wickets'], 2) : 0, 2)])
        . $group('fielding', 'Fielding', ['Catches' => $n($s['catches']), 'Stumpings' => $n($s['stumpings']), 'Matches' => $n($s['matches'])])
        . $group('insights', 'Boundary Insights', ['Total Boundaries' => $n($boundaries), 'Boundary %' => $n($s['balls_faced'] ? round($boundaries / $s['balls_faced'] * 100, 2) : 0, 2), 'Boundary Frequency' => $n($boundaries ? round($s['balls_faced'] / $boundaries, 2) : 0, 2)])
        . '</div></section>';
}

/** The newest season that has scored stats (the current season may not have any yet). */
function latestStatsSeason(): ?array {
    return query('SELECT s.name, s.route_key FROM seasons s WHERE EXISTS (SELECT 1 FROM player_season_stats st WHERE st.season_id = s.id) ORDER BY s.start_date DESC, s.id DESC LIMIT 1')[0] ?? null;
}

/** Port of resources/views/public/stats/_results.blade.php. */
function statsResults(array $filters, array $teams, bool $withSummary): string {
    $allSeasons = $filters['season'] === '';
    // A season with no scored matches has nothing to filter, so the metric, team and player filters are switched off for it too.
    $seasonHasStats = $allSeasons || (int) query('SELECT COUNT(*) AS total FROM player_season_stats WHERE season_id = ?', [$filters['season']])[0]['total'] > 0;
    $disabled = $seasonHasStats && !$allSeasons ? '' : ' disabled';
    $metricOptions = '';
    foreach (STAT_METRICS as $key => $label) $metricOptions .= '<option value="' . $key . '"' . ($filters['metric'] === $key ? ' selected' : '') . '>' . $label . '</option>';
    $hint = !$seasonHasStats ? 'No stats have been recorded for this season yet, so the other filters are switched off.' : ($allSeasons ? 'Choose a season to filter by metric, team or player.' : '');
    $html = '<div class="row mb-4"><div class="col-12"><form method="GET" action="{{BASE}}/stats" class="filter-bar"><div class="row g-3">'
        . '<div class="col-lg-3 col-md-6"><label for="stats-filter-season" class="filter-label">Season</label><select id="stats-filter-season" name="season" class="form-select filter-control js-filter-select"><option value="">All Seasons</option>' . options(seasonsList(), $filters['season']) . '</select></div>'
        . '<div class="col-lg-3 col-md-6"><label for="stats-filter-metric" class="filter-label">Leaderboard</label><select id="stats-filter-metric" name="metric" class="form-select filter-control js-filter-select"' . $disabled . '>' . $metricOptions . '</select></div>'
        . '<div class="col-lg-3 col-md-6"><label for="stats-filter-team" class="filter-label">Team</label><select id="stats-filter-team" name="team" class="form-select filter-control js-filter-select"' . $disabled . '><option value="">All Teams</option>' . options($teams, $filters['team']) . '</select></div>'
        . '<div class="col-lg-3 col-md-6"><label for="stats-filter-q" class="filter-label">Player</label><input type="text" id="stats-filter-q" name="q" value="' . escape($filters['q']) . '" class="form-control filter-control" placeholder="Search By Player Name"' . $disabled . '></div>'
        . '</div>' . ($hint !== '' ? '<p class="filter-hint mb-0 mt-2">' . $hint . '</p>' : '') . '</form></div></div>';
    if ($allSeasons && $withSummary) return $html . statsSummary();

    $title = STAT_METRICS[$filters['metric']] ?? 'Most Runs';
    $seasonLabel = null;
    $seasonRoute = null;
    foreach (seasonsList() as $season) if ((string) $season['id'] === $filters['season']) [$seasonLabel, $seasonRoute] = [$season['name'], $season['route_key']];
    $rows = statsLeaderboard($filters);
    if (!$rows) {
        // No rows: say why, and point to the newest season that does have stats instead of rendering an empty table.
        $newest = latestStatsSeason();
        $empty = fn (string $heading, string $text, string $label, string $href) => $html . '<div class="stats-empty"><h2 class="stats-empty__title">' . $heading . '</h2><p class="stats-empty__text">' . $text . '</p>'
            . '<a href="' . $href . '" class="btn btn-primary">' . $label . '</a></div>';
        if (!$seasonHasStats) {
            $name = escape((string) $seasonLabel);
            return $empty("No stats yet for $name", "$name stats will appear here once matches are played.",
                $newest ? 'View ' . escape($newest['name']) . ' stats' : 'View all stats', $newest ? '{{BASE}}/stats/season/' . $newest['route_key'] : '{{BASE}}/stats');
        }
        return $empty('No players match these filters', 'Try a different team or player name, or clear the filters to see the full leaderboard.',
            'Clear filters', $seasonRoute !== null ? '{{BASE}}/stats/season/' . $seasonRoute : '{{BASE}}/stats');
    }
    $html .= '<div class="stats-section"><div class="stats-section-title mb-3"><h2 class="h5 fw-bold mb-0">' . escape($seasonLabel ? "$seasonLabel · $title" : "$title (All Seasons)") . '</h2><span class="badge bg-primary">' . $title . '</span></div>';
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
    $html .= '<div class="table-responsive stats-table-wrap" tabindex="0" role="region" aria-label="' . escape($title) . ' leaderboard (scrolls sideways on small screens)"><table class="table table-bordered align-middle bg-white stats-table"><thead class="table-light"><tr><th scope="col" style="width: 70px;">Pos</th><th scope="col" class="stats-table__player">Player</th>';
    foreach (['Mat', 'Inns', 'Runs', 'Wkts', 'HS', 'Avg', 'BF', 'SR', '100', '50', '4s', '6s', 'BB', 'BBow', 'RC', 'BA', 'Econ', 'BSR', 'Best Bowl', 'Ct', 'St', 'MVP'] as $label) $html .= "<th scope=\"col\">$label</th>";
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $index => $row) {
        $c = $computed($row);
        $cells = [$row['matches'], $row['innings'], $row['runs'], $row['wickets'], $row['highest_score_num'] ?? 0, number_format($c['avg'], 2), $row['balls_faced'], number_format($c['sr'], 2),
            $row['hundreds'], $row['fifties'], $row['fours'], $row['sixes'], $row['best_bowling_wkts'] ?? 0, $row['balls_bowled'], $row['runs_conceded'],
            number_format($c['bowl_avg'], 2), number_format($c['econ'], 2), number_format($c['bowl_sr'], 2), escape($row['best_bowling'] ?? '-'), $row['catches'], $row['stumpings'], number_format((float) $row['mvp_total'], 2)];
        $html .= '<tr><td class="text-center fw-bold">' . ($index + 1) . '</td><td class="stats-table__player"><div class="d-flex align-items-center gap-3"><img src="' . mediaUrl($row['photo'], '/assets/media/player-fallback.svg') . '" alt="" class="leader-player" style="width: 56px; height: 56px;">'
            . '<div><div class="fw-bold"><a href="{{BASE}}/player/' . escape(rawurlencode($row['slug'])) . '" class="text-decoration-none text-dark">' . escape($row['name']) . '</a></div><div class="stats-table__role">' . escape($row['type'] ?? 'Player') . '</div></div></div></td>';
        foreach ($cells as $column => $cell) $html .= ($column === 2 ? '<td class="fw-bold">' : '<td>') . $cell . '</td>';
        $html .= '</tr>';
    }
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
    // The hero button points at the newest season that has stats, not just the newest season.
    if (($link = $xpath->query('//*[@id="stats-latest-link"]')->item(0)) && ($newest = latestStatsSeason())) {
        $link->setAttribute('href', '{{BASE}}/stats/season/' . $newest['route_key']);
        $link->textContent = 'Latest Stats';
    }
    return savePage($dom);
}
