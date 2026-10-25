<?php
declare(strict_types=1);

// Teams overview, season team lists and team pages, rendered from the database.
require_once __DIR__ . '/catalog.php';

/** /teams: one card per season. Port of resources/views/public/teams/index.blade.php. */
function renderTeamsIndex(string $html): string {
    $seasons = query('SELECT s.*, (SELECT COUNT(*) FROM season_teams st JOIN teams t ON t.id = st.team_id WHERE st.season_id = s.id AND t.deleted_at IS NULL) AS teams_count FROM seasons s ORDER BY s.route_key, s.start_date DESC');
    $variants = ['red', 'gold', 'purple', 'blue', 'pink', 'teal', 'orange', 'yellow', 'magenta', 'violet', 'navy'];
    $cards = '';
    foreach ($seasons as $index => $season) {
        $cards .= '<a class="text-decoration-none" href="{{BASE}}/teams/season/' . $season['route_key'] . '" aria-label="' . escape($season['name']) . '">'
            . '<article class="teams-section__card teams-section__card--' . $variants[$index % count($variants)] . '"><div class="teams-section__logo-wrap">'
            . '<img class="teams-section__logo teams-section__logo--season" src="' . mediaUrl($season['image'], '/logo.png') . '" alt="' . escape($season['name']) . '" onerror="this.onerror=null;this.src=\'{{BASE}}/logo.png\';">'
            . '</div><h3 class="teams-section__name">' . escape($season['name']) . '</h3><p class="text-white-50 mb-0 small">' . $season['teams_count'] . ' teams</p></article></a>';
    }
    [$dom, $xpath] = loadPage($html);
    if ($grid = $xpath->query('//div[@class="teams-section__grid"]')->item(0)) replaceInnerHtml($dom, $grid, $cards ?: '<div class="alert alert-light border text-center w-100">No seasons available.</div>');
    $schema = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => 'Pro HPCL Teams', 'description' => 'Teams by season in Pro HPCL cricket league.', 'url' => '{{BASE}}/teams',
        'about' => ['@type' => 'SportsOrganization', 'name' => 'Pro HPCL - Himachal Premium Cricket League', 'sport' => 'Cricket', 'url' => '{{BASE}}'],
        'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(fn ($season, $index) => ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $season['name'], 'url' => '{{BASE}}/teams/season/' . $season['route_key']], $seasons, array_keys($seasons))]];
    foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
        if (str_contains($script->textContent, '"CollectionPage"')) $script->textContent = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return savePage($dom);
}

const TEAM_CARD_VARIANTS = ['red', 'gold', 'purple', 'blue', 'pink', 'teal', 'orange', 'yellow', 'magenta', 'violet', 'navy'];
const TEAMS_CONTENT = '//div[@class="teams-linked-page__content"]';

function seasonByRouteKey(string $routeKey): ?array {
    return query('SELECT * FROM seasons WHERE route_key = ?', [$routeKey])[0] ?? null;
}
function seasonTeams(array $season): array {
    return query('SELECT t.* FROM teams t JOIN season_teams st ON st.team_id = t.id WHERE st.season_id = ? AND t.deleted_at IS NULL ORDER BY t.name', [$season['id']]);
}
function teamSeasonUrl(array $season, array $team): string {
    return '{{BASE}}/teams/season/' . $season['route_key'] . '/' . rawurlencode((string) $team['slug']);
}

/** /teams/season/{n}. Port of resources/views/public/teams/season_index.blade.php. */
function renderSeasonTeams(string $routeKey): ?string {
    $season = seasonByRouteKey($routeKey);
    if (!$season) return null;
    $teams = seasonTeams($season);
    $name = escape($season['name']);
    $cards = '';
    foreach ($teams as $index => $team) {
        $cards .= '<a class="text-decoration-none" href="' . teamSeasonUrl($season, $team) . '" aria-label="' . escape($team['name']) . '"><article class="teams-section__card teams-section__card--' . TEAM_CARD_VARIANTS[$index % count(TEAM_CARD_VARIANTS)] . '">'
            . '<div class="teams-section__logo-wrap"><img class="teams-section__logo" src="' . mediaUrl($team['logo'], '/assets/media/logo.png') . '" alt="' . escape($team['name']) . '"></div>'
            . '<h3 class="teams-section__name">' . escape($team['name']) . '</h3></article></a>';
    }
    $content = '<section class="teams-section" aria-label="' . $name . ' Teams"><div class="teams-section__inner"><nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3">'
        . '<li class="breadcrumb-item"><a href="{{BASE}}">Home</a></li><li class="breadcrumb-item"><a href="{{BASE}}/teams">Teams</a></li><li class="breadcrumb-item active fw-semibold" aria-current="page">' . $name . '</li></ol></nav>'
        . '<h2 class="teams-section__title">' . $name . ' Teams</h2><div class="teams-section__grid">' . ($cards ?: '<div class="alert alert-light border text-center w-100">No teams mapped for this season.</div>') . '</div></div>'
        . '<div class="teams-section__bottom-decor" aria-hidden="true"></div></section>';
    $url = '{{BASE}}/teams/season/' . $season['route_key'];
    $schema = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $season['name'] . ' Teams', 'url' => $url,
        'about' => seasonEvent($season, $season['name'] . ' season event for Pro HPCL cricket league.', ['{{BASE}}/logo.png'],
            array_map(fn ($team) => ['@type' => 'SportsTeam', 'name' => $team['name'], 'url' => teamSeasonUrl($season, $team)], array_slice($teams, 0, 6))),
        'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(fn ($team, $index) => ['@type' => 'ListItem', 'position' => $index + 1, 'item' => [
            '@type' => 'SportsTeam', 'name' => $team['name'], 'sport' => 'Cricket', 'url' => teamSeasonUrl($season, $team), 'logo' => $team['logo'] ? mediaUrl($team['logo'], '') : null, 'location' => hpPlace()]], $teams, array_keys($teams))]];
    return renderLayout('team-season.html', [
        'title' => $season['name'] . ' Teams | Pro HPCL',
        'description' => 'Explore all teams participating in ' . $season['name'] . ' of Pro HPCL.',
        'url' => $url,
    ], [TEAMS_CONTENT => $content], ['CollectionPage' => $schema]);
}

/** /teams/season/{n}/{team}. Port of resources/views/public/teams/season_show.blade.php. */
function renderTeamPage(string $routeKey, string $slug): ?string {
    $season = seasonByRouteKey($routeKey);
    $team = query('SELECT t.* FROM teams t JOIN season_teams st ON st.team_id = t.id WHERE t.slug = ? AND st.season_id = ? AND t.deleted_at IS NULL', [$slug, $season['id'] ?? 0])[0] ?? null;
    if (!$season || !$team) return null;
    $owners = query('SELECT o.* FROM season_team_owners sto JOIN owners o ON o.id = sto.owner_id WHERE sto.season_id = ? AND sto.team_id = ? AND o.deleted_at IS NULL ORDER BY sto.display_order, o.name', [$season['id'], $team['id']]);
    $squad = query('SELECT p.*, sp.is_retained FROM season_players sp JOIN players p ON p.id = sp.player_id WHERE sp.season_id = ? AND sp.team_id = ? AND p.deleted_at IS NULL ORDER BY sp.position, p.id', [$season['id'], $team['id']]);
    $groups = [];
    foreach ($squad as $player) $groups[$player['type'] ?: 'Uncategorized'][] = $player;

    $teamName = escape($team['name']);
    $defaultLogo = '{{BASE}}/logo.png';
    $logo = $team['logo'] ? mediaUrl($team['logo'], '') : null;
    $ownerFallback = $logo ?: $defaultLogo;
    $playerFallback = '{{BASE}}/assets/media/player-fallback.svg';

    $hero = '<div class="row team-bg team-hero py-2 py-lg-3"><div class="col-12"><div class="team-page-shell"><div class="row g-4 py-lg-2 align-items-stretch"><div class="col-lg-6 me-auto"><div class="team-hero-panel h-100">'
        . '<nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3"><li class="breadcrumb-item"><a href="{{BASE}}/">Home</a></li><li class="breadcrumb-item"><a href="{{BASE}}/teams">Teams</a></li>'
        . '<li class="breadcrumb-item"><a href="{{BASE}}/teams/season/' . $season['route_key'] . '">' . escape($season['name']) . '</a></li><li class="breadcrumb-item active fw-semibold" aria-current="page">' . $teamName . '</li></ol></nav>'
        . '<div class="row team-identity-row g-3 g-lg-4 align-items-center"><div class="col-4 col-lg-3">'
        . ($logo ? '<img src="' . $logo . '" alt="' . $teamName . '" onerror="this.onerror=null;this.src=\'' . $defaultLogo . '\';" class="img-fluid rounded team-logo" style="background-color: var(--bs-white);">'
            : '<img src="' . $defaultLogo . '" alt="Default Logo" class="img-fluid rounded team-logo" style="background-color: var(--bs-white);">')
        . '</div><div class="col-8 col-lg-9"><h1 class="fw-bold m-0 team-title">' . $teamName . '</h1><div class="d-flex flex-wrap align-items-center gap-2 mt-2">'
        . ($team['short_name'] ? '<span class="badge badge-season">' . escape($team['short_name']) . '</span>' : '') . '<span class="badge badge-season">' . escape($season['name']) . '</span></div>'
        . ($team['description'] ? '<p class="team-hero-description"><strong>"' . escape($team['description']) . '"</strong></p>' : '')
        . '</div></div></div></div>';
    if ($owners) {
        $hero .= '<div class="col-lg-6"><div class="owners-panel h-100"><h2 class="h5 fw-bold text-uppercase team-owners">Team Owners</h2><div class="owners-grid mt-3">';
        foreach ($owners as $owner) {
            $hero .= '<article class="owner-card">' . ($owner['photo']
                    ? '<img src="' . mediaUrl($owner['photo'], '') . '" class="img-fluid" alt="' . escape($owner['name']) . '" onerror="this.onerror=null;this.src=\'' . $ownerFallback . '\';">'
                    : '<img src="' . $ownerFallback . '" class="img-fluid" alt="Owner">')
                . '<h3 class="owner-name">' . escape($owner['name']) . '</h3></article>';
        }
        $hero .= '</div></div></div>';
    }
    $hero .= '</div></div></div></div>';

    $players = '';
    foreach ($groups as $type => $members) {
        $players .= '<div class="col-12 mt-4"><h2 class="text-dark border-bottom border-2 h2 fw-bold pb-2">' . escape((string) $type) . '</h2></div><div class="col-12"><div class="row g-3">';
        foreach ($members as $player) {
            $location = implode(', ', array_filter([$player['city'], $player['state']], fn ($value) => $value !== null && $value !== ''));
            $players .= '<div class="col-lg-3 col-md-4 col-12"><a href="{{BASE}}/player/' . escape(rawurlencode($player['slug'])) . '" class="w-100 text-decoration-none"><article class="tpv2-card team-player-performer tpv2-card--orange">'
                . '<img class="tpv2-card__player" src="' . mediaUrl($player['photo'], '/assets/media/player-fallback.svg') . '" alt="' . escape($player['name']) . '" onerror="this.onerror=null;this.src=\'' . $playerFallback . '\';">'
                . '<div class="tpv2-card__meta"><h3 class="tpv2-card__name"><span class="team-player-link">' . escape($player['name']) . '</span></h3>'
                . ($player['is_retained'] ? '<div class="mb-1"><span class="badge bg-warning text-dark">Retained</span></div>' : '')
                . '<p class="tpv2-card__metric-label">' . escape(strLimit($location ?: ($player['batting_style'] ?: 'Pro HPCL Player'), 26)) . '</p>'
                . '<div class="team-player-details"><span>Bat: ' . escape($player['batting_style'] ?: 'N/A') . '</span><span>Bowl: ' . escape($player['bowling_style'] ?: 'N/A') . '</span>'
                . ($player['age'] ? '<span>Age: ' . $player['age'] . '</span>' : '') . '</div></div></article></a></div>';
        }
        $players .= '</div></div>';
    }
    $squadSection = '<section style="background-color: var(--bs-gray-100);" class="py-lg-3"><div class="row"><div class="col-12"><div class="team-page-shell"><div class="row g-3 py-2">'
        . ($players ?: '<div class="col-12 mt-4"><div class="section-card p-4 text-center text-muted">No players assigned for this season.</div></div>') . '</div></div></div></div></section>';

    $url = teamSeasonUrl($season, $team);
    $image = $logo ?: $defaultLogo;
    $description = $team['description'] ?: 'Explore ' . $team['name'] . ' in ' . $season['name'] . " on Pro HPCL, with\n    owners, players, and season context.";
    return renderLayout('team.html', ['title' => $team['name'] . ' - ' . $season['name'] . ' | Pro HPCL', 'description' => $description, 'url' => $url, 'image' => $image],
        [TEAMS_CONTENT => $hero . $squadSection], [
            'BreadcrumbList' => ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => '{{BASE}}'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Seasons', 'item' => '{{BASE}}/teams'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $season['name'], 'item' => '{{BASE}}/teams/season/' . $season['route_key']],
                ['@type' => 'ListItem', 'position' => 4, 'name' => $team['name'], 'item' => $url]]],
            'SportsTeam' => ['@context' => 'https://schema.org', '@type' => 'SportsTeam', 'name' => $team['name'], 'sport' => 'Cricket', 'url' => $url, 'logo' => $image,
                'description' => $team['description'] ?: $team['name'] . ' squad in ' . $season['name'] . ' of Pro HPCL.', 'location' => hpPlace(),
                'memberOf' => seasonEvent($season, $team['description'] ?: $season['name'] . ' season event for Pro HPCL cricket league.', [$image], ['@type' => 'SportsTeam', 'name' => $team['name'], 'url' => $url]),
                'athlete' => array_map(fn ($player) => ['@type' => 'Person', 'name' => $player['name'], 'url' => '{{BASE}}/player/' . rawurlencode($player['slug'])], array_slice(array_merge(...array_values($groups)), 0, 20))],
        ]);
}
