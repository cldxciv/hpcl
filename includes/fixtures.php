<?php
declare(strict_types=1);

// The match schedule of a season (/fixtures, /fixtures/season/<n>): the groups, then every match day
// with its matches, then the knockouts. Rows are in season_fixtures (database/fixtures.sql); the
// groups are the pool of each team in season_teams.
require_once __DIR__ . '/teams.php';

const FIXTURE_STAGE_LABELS = ['group' => 'Group', 'quarter_final' => 'Quarter Final', 'semi_final' => 'Semi Final', 'final' => 'Final'];

/** Team names saved in capitals are shown in capitals-and-lowercase so a schedule reads easily. */
function fixtureTeamName(string $name): string {
    return $name === mb_strtoupper($name, 'UTF-8') ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : $name;
}

/** The season shown at /fixtures: the newest one that has a schedule. */
function fixturesDefaultSeason(): ?array {
    return query('SELECT s.* FROM seasons s WHERE EXISTS (SELECT 1 FROM season_fixtures f WHERE f.season_id = s.id) ORDER BY s.route_key DESC LIMIT 1')[0]
        ?? query('SELECT * FROM seasons ORDER BY route_key DESC LIMIT 1')[0] ?? null;
}

/** A team as a small link with its logo. */
function fixtureTeamLink(array $season, string $name, string $slug, ?string $logo): string {
    return '<a class="fx-team" href="{{BASE}}/teams/season/' . $season['route_key'] . '/' . escape(rawurlencode($slug)) . '">'
        . '<img class="fx-team__logo" src="' . mediaUrl($logo, '/assets/media/logo.png') . '" alt="" width="32" height="32" loading="lazy" decoding="async">'
        . '<span>' . escape(fixtureTeamName($name)) . '</span></a>';
}

/** /fixtures and /fixtures/season/<n>. Returns null (a 404) for an unknown season. */
function renderFixturesPage(?string $routeKey = null): ?string {
    $season = $routeKey === null ? fixturesDefaultSeason() : seasonByRouteKey($routeKey);
    if (!$season) return null;
    $rows = query('SELECT f.*, ta.name AS a_name, ta.slug AS a_slug, ta.logo AS a_logo, tb.name AS b_name, tb.slug AS b_slug, tb.logo AS b_logo
        FROM season_fixtures f LEFT JOIN teams ta ON ta.id = f.team_a_id LEFT JOIN teams tb ON tb.id = f.team_b_id
        WHERE f.season_id = ? ORDER BY f.match_date, f.slot, f.id', [$season['id']]);
    $name = escape($season['name']);

    // The groups: each pool's teams.
    $pools = [];
    foreach (query('SELECT t.name, t.slug, t.logo, st.pool FROM season_teams st JOIN teams t ON t.id = st.team_id WHERE st.season_id = ? AND st.pool IS NOT NULL AND st.pool <> \'\' AND t.deleted_at IS NULL ORDER BY st.pool, t.name', [$season['id']]) as $team) {
        $pools[$team['pool']][] = $team;
    }
    $groups = '';
    foreach ($pools as $pool => $teams) {
        $groups .= '<section class="fx-card fx-group" aria-labelledby="fx-group-' . escape(strtolower($pool)) . '"><h2 id="fx-group-' . escape(strtolower($pool)) . '">Group ' . escape($pool) . '</h2><ul class="fx-group__teams">'
            . implode('', array_map(fn ($team) => '<li>' . fixtureTeamLink($season, $team['name'], $team['slug'], $team['logo']) . '</li>', $teams)) . '</ul></section>';
    }

    // The days, grouped under a heading per phase: Group A, Group B, Knockouts.
    $days = [];
    foreach ($rows as $row) $days[$row['match_date']][] = $row;
    $schedule = '';
    $phase = null;
    $counts = ['group' => 0, 'quarter_final' => 0, 'semi_final' => 0, 'final' => 0];
    foreach ($days as $date => $matches) {
        $first = current(array_filter($matches, fn ($m) => $m['stage'] !== 'break')) ?: null;
        $dayPhase = $first ? ($first['stage'] === 'group' ? 'Group ' . $first['pool'] . ' matches' : 'Knockouts') : $phase;
        if ($dayPhase !== null && $dayPhase !== $phase) {
            $schedule .= ($phase !== null ? '</div>' : '') . '<h2 class="fx-phase" id="fx-' . escape(strtolower(preg_replace('~[^a-z0-9]+~i', '-', $dayPhase))) . '">' . escape($dayPhase) . '</h2><div class="fx-days">';
            $phase = $dayPhase;
        }
        $stamp = strtotime($date);
        $items = '';
        foreach ($matches as $m) {
            if ($m['stage'] === 'break') {
                $items .= '<li class="fx-match fx-match--break"><span class="fx-break">' . escape((string) $m['label']) . '</span></li>';
                continue;
            }
            $counts[$m['stage']] = ($counts[$m['stage']] ?? 0) + 1;
            if ($m['stage'] === 'group') {
                $body = '<span class="fx-match__teams">' . fixtureTeamLink($season, (string) $m['a_name'], (string) $m['a_slug'], $m['a_logo'])
                    . '<span class="fx-vs">vs</span>' . fixtureTeamLink($season, (string) $m['b_name'], (string) $m['b_slug'], $m['b_logo']) . '</span>';
                $pill = '<span class="fx-pill fx-pill--' . escape(strtolower((string) $m['pool'])) . '">Group ' . escape((string) $m['pool']) . '</span>';
            } else {
                $body = '<span class="fx-match__teams fx-match__teams--text"><strong>' . escape((string) $m['label']) . '</strong>' . ($m['detail'] ? '<span class="fx-detail">' . escape($m['detail']) . '</span>' : '') . '</span>';
                $pill = '<span class="fx-pill fx-pill--ko">' . escape(FIXTURE_STAGE_LABELS[$m['stage']] ?? 'Knockout') . '</span>';
            }
            $items .= '<li class="fx-match"><span class="fx-match__no">Match ' . (int) $m['slot'] . '</span>' . $body . $pill . '</li>';
        }
        $schedule .= '<section class="fx-card fx-day" aria-labelledby="fx-day-' . $date . '"><h3 id="fx-day-' . $date . '"><time datetime="' . $date . '">' . escape(date('D, j M Y', $stamp)) . '</time></h3><ol class="fx-matches">' . $items . '</ol></section>';
    }
    if ($phase !== null) $schedule .= '</div>';

    $dates = array_keys($days);
    $range = $dates ? ($dates[0] === end($dates) ? date('j M Y', strtotime($dates[0])) : date('j M', strtotime($dates[0])) . ' – ' . date('j M Y', strtotime(end($dates)))) : '';
    $summary = $rows ? implode(' · ', array_filter([
        $counts['group'] ? $counts['group'] . ' group matches' : '',
        $counts['quarter_final'] ? $counts['quarter_final'] . ' quarter-finals' : '',
        $counts['semi_final'] ? $counts['semi_final'] . ' semi-finals' : '',
        $counts['final'] ? 'the final' : '',
    ])) : '';
    $jump = '';
    if ($phase !== null) {
        preg_match_all('~<h2 class="fx-phase" id="([^"]+)">([^<]+)</h2>~', $schedule, $phases, PREG_SET_ORDER);
        $jump = '<nav class="fx-jump" aria-label="Jump to"><span>Jump to</span>' . implode('', array_map(fn ($p) => '<a href="#' . $p[1] . '">' . $p[2] . '</a>', $phases)) . '</nav>';
    }
    $empty = $rows ? '' : '<p class="fx-card fx-empty">The ' . $name . ' fixtures will be published here soon.</p>';

    $events = [];
    foreach ($rows as $m) {
        if ($m['stage'] === 'break') continue;
        $label = $m['stage'] === 'group' ? fixtureTeamName((string) $m['a_name']) . ' vs ' . fixtureTeamName((string) $m['b_name']) : $m['label'] . ': ' . $m['detail'];
        $events[] = ['@type' => 'SportsEvent', 'name' => $label . ' · ' . $season['name'], 'startDate' => $m['match_date'], 'eventStatus' => 'https://schema.org/EventScheduled',
            'sport' => 'Cricket', 'location' => hpPlace(), 'organizer' => ['@type' => 'SportsOrganization', 'name' => 'Pro HPCL', 'url' => absoluteUrl('{{BASE}}')]];
    }
    return view('templates.fixtures', [
        'title' => $season['name'] . ' Fixtures | Pro HPCL',
        'description' => escape($season['name'] . ' fixtures of the Himachal Premium Cricket League (Pro HPCL): the match schedule ' . ($range ? 'from ' . $range : '') . ', groups, quarter-finals, semi-finals and the final.'),
        'heading' => $name . ' Fixtures',
        'range' => escape($range),
        'summary' => escape($summary),
        'groups' => $groups, 'groups_hidden' => $groups === '' ? ' hidden' : '',
        'jump' => $jump, 'schedule' => $schedule . $empty,
        'schema' => $events ? jsonLd(['@context' => 'https://schema.org', '@graph' => $events]) : '{}',
        'season_teams_url' => '{{BASE}}/teams/season/' . $season['route_key'],
    ]);
}
