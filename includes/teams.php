<?php
declare(strict_types=1);

// Teams overview, season team lists and team pages, rendered from the database.
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/team-card.php'; // teamLogoHtml(): the logo circle, with an initials badge for a team without a logo

/** /teams: one card per season. Port of resources/views/public/teams/index.blade.php. */
function renderTeamsIndex(string $html): string {
    $seasons = query('SELECT s.*, (SELECT COUNT(*) FROM season_teams st JOIN teams t ON t.id = st.team_id WHERE st.season_id = s.id AND t.deleted_at IS NULL AND ' . seasonPlayingTeamSql() . ') AS teams_count FROM seasons s ORDER BY s.route_key, s.start_date DESC');
    $variants = ['red', 'gold', 'purple', 'blue', 'pink', 'teal', 'orange', 'yellow', 'magenta', 'violet', 'navy'];
    $cards = '';
    foreach ($seasons as $index => $season) {
        $cards .= '<a class="text-decoration-none" href="{{BASE}}/teams/season/' . $season['route_key'] . '">'
            . '<article class="teams-section__card teams-section__card--' . $variants[$index % count($variants)] . '"><div class="teams-section__logo-wrap teams-section__logo-wrap--season">'
            . '<img class="teams-section__logo teams-section__logo--season" src="' . mediaUrl($season['image'], '/logo.png') . '" alt="" onerror="this.onerror=null;this.src=\'{{BASE}}/logo.png\';">'
            . '</div><h2 class="teams-section__name">' . escape($season['name']) . '</h2><p class="teams-section__count">' . (int) $season['teams_count'] . ((int) $season['teams_count'] === 1 ? ' team' : ' teams') . '</p></article></a>';
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
    return query('SELECT t.*, st.pool, (SELECT COUNT(*) FROM season_players sp WHERE sp.season_id = st.season_id AND sp.team_id = t.id) AS player_count
        FROM teams t JOIN season_teams st ON st.team_id = t.id WHERE st.season_id = ? AND t.deleted_at IS NULL ORDER BY t.name', [$season['id']]);
}
function playerInitials(string $name): string {
    $words = preg_split('~\s+~u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $letters = array_map(fn ($word) => mb_substr($word, 0, 1, 'UTF-8'), array_slice($words, 0, 2));
    return mb_strtoupper(implode('', $letters), 'UTF-8') ?: '?';
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
    $grouped = false;
    try {
        $grouped = (bool) query('SELECT 1 FROM season_fixtures WHERE season_id = ? LIMIT 1', [$season['id']]);
    } catch (PDOException) {
        // The fixtures table isn't created yet: show the plain list.
    }
    // $muted: a team attached to the season that is not in its fixtures ("not participating").
    $card = fn (array $team, int $index, string $tag, bool $muted = false) => '<a class="text-decoration-none" href="' . teamSeasonUrl($season, $team) . '"><article class="teams-section__card '
        . ($muted ? 'teams-section__card--muted' : 'teams-section__card--' . TEAM_CARD_VARIANTS[$index % count(TEAM_CARD_VARIANTS)]) . '">'
        . teamLogoHtml($team)
        . '<' . $tag . ' class="teams-section__name">' . escape($team['name']) . '</' . $tag . '>'
        . ($muted ? '<p class="teams-section__count teams-section__count--note">Not participating</p>'
            : ($team['player_count'] ? '<p class="teams-section__count">' . (int) $team['player_count'] . ' players</p>' : '')) . '</article></a>';
    $cards = '';
    $heading = fn (string $text, string $note = '') => '<h2 class="teams-section__group">' . $text . '</h2>' . ($note !== '' ? '<p class="teams-section__group-note">' . $note . '</p>' : '');
    if ($grouped && array_filter(array_column($teams, 'pool'))) {
        // A season with a schedule shows its groups (Group A, Group B) one after the other, then the teams that are not in it.
        $byPool = [];
        foreach ($teams as $team) $byPool[$team['pool'] ?: 'Other'][] = $team;
        ksort($byPool);
        $index = 0;
        foreach ($byPool as $pool => $poolTeams) {
            $other = $pool === 'Other';
            $cards .= $other ? $heading('Other Teams (Not Participating)', 'These teams are not playing in ' . $name . '.') : $heading('Group ' . escape((string) $pool));
            $cards .= '<div class="teams-section__grid teams-section__grid--group' . ($other ? ' teams-section__grid--other' : '') . '">';
            foreach ($poolTeams as $team) $cards .= $card($team, $index++, 'h3', $other);
            $cards .= '</div>';
        }
    } else {
        foreach ($teams as $index => $team) $cards .= $card($team, $index, 'h3');
        $cards = $cards !== '' ? $heading('All teams') . '<div class="teams-section__grid teams-section__grid--group">' . $cards . '</div>'
            : '<div class="teams-section__grid"><div class="alert alert-light border text-center w-100">No teams mapped for this season.</div></div>';
    }
    $cards = '<style>' . teamCardCss() . '.teams-section__group { margin: 22px 0 6px; font-family: "Bebas Neue", sans-serif; font-weight: 400; font-size: 2rem; letter-spacing: 0.03em; color: #0a1f44; }'
        . 'body.home-unified-theme .teams-section__group-note { margin: 0 0 12px; font-size: 0.95rem; font-weight: 500; color: #0a1f44; }</style>' . $cards;
    $fixturesLink = $grouped ? '<p class="mb-3"><a class="btn btn-light fw-bold" href="{{BASE}}/fixtures/season/' . $season['route_key'] . '">View ' . $name . ' fixtures</a></p>' : '';
    $content = '<section class="teams-section" aria-label="' . $name . ' Teams"><div class="teams-section__inner"><nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3">'
        . '<li class="breadcrumb-item"><a href="{{BASE}}">Home</a></li><li class="breadcrumb-item"><a href="{{BASE}}/teams">Teams</a></li><li class="breadcrumb-item active fw-semibold" aria-current="page">' . $name . '</li></ol></nav>'
        . '<h1 class="teams-section__title">' . $name . ' Teams</h1>' . $fixturesLink . $cards . '</div>'
        . '<div class="teams-section__bottom-decor" aria-hidden="true"></div></section>';
    $url = '{{BASE}}/teams/season/' . $season['route_key'];
    $schema = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $season['name'] . ' Teams', 'url' => $url,
        'about' => seasonEvent($season, $season['name'] . ' season event for Pro HPCL cricket league.', ['{{BASE}}/logo.png'],
            array_map(fn ($team) => ['@type' => 'SportsTeam', 'name' => $team['name'], 'url' => teamSeasonUrl($season, $team)], array_slice($teams, 0, 6))),
        'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(fn ($team, $index) => ['@type' => 'ListItem', 'position' => $index + 1, 'item' => [
            '@type' => 'SportsTeam', 'name' => $team['name'], 'sport' => 'Cricket', 'url' => teamSeasonUrl($season, $team), 'logo' => $team['logo'] ? mediaUrl($team['logo'], '') : null, 'location' => hpPlace()]], $teams, array_keys($teams))]];
    return renderLayout('team-season.html', [
        'title' => $season['name'] . ' Teams | Pro HPCL',
        'description' => 'Explore all teams in ' . $season['name'] . ' of the Himachal Premium Cricket League (Pro HPCL): team profiles, owners, squads and players.',
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
    $groups = $unassigned = [];
    foreach ($squad as $player) {
        if ($player['type']) $groups[$player['type']][] = $player;
        else $unassigned[] = $player;
    }
    // Players without a recorded role go last, under a neutral heading.
    if ($unassigned) $groups[$groups ? 'Other Squad Members' : 'Squad'] = $unassigned;

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
            // No photo on file: an initials avatar instead of the generic silhouette.
            $avatar = $player['photo']
                ? '<img class="tpv2-card__player" src="' . mediaUrl($player['photo'], '/assets/media/player-fallback.svg') . '" alt="' . escape($player['name']) . '" onerror="this.onerror=null;this.src=\'' . $playerFallback . '\';">'
                : '<span class="team-player-avatar" aria-hidden="true">' . escape(playerInitials((string) $player['name'])) . '</span>';
            $players .= '<div class="col-lg-3 col-md-4 col-12"><a href="{{BASE}}/player/' . escape(rawurlencode($player['slug'])) . '" class="w-100 text-decoration-none"><article class="tpv2-card team-player-performer tpv2-card--orange">'
                . $avatar
                . '<div class="tpv2-card__meta"><h3 class="tpv2-card__name"><span class="team-player-link">' . escape($player['name']) . '</span></h3>'
                . ($player['is_retained'] ? '<div class="mb-1"><span class="badge bg-warning text-dark">Retained</span></div>' : '')
                . '<p class="tpv2-card__metric-label">' . escape(strLimit($location ?: ($player['batting_style'] ?: 'Pro HPCL Player'), 26)) . '</p>'
                . '<div class="team-player-details"><span>Bat: ' . escape($player['batting_style'] ?: 'N/A') . '</span><span>Bowl: ' . escape($player['bowling_style'] ?: 'N/A') . '</span>'
                . ($player['age'] ? '<span>Age: ' . $player['age'] . '</span>' : '') . '</div></div></article></a></div>';
        }
        $players .= '</div></div>';
    }
    $squadSection = '<section style="background-color: var(--bs-gray-100);" class="py-lg-3"><div class="row"><div class="col-12"><div class="team-page-shell"><div class="row g-3 py-2">'
        . ($players ?: '<div class="col-12 mt-4"><div class="section-card team-squad-empty p-4 text-center"><p class="h5 fw-bold mb-1">Squad to be announced</p><p class="mb-0">' . $teamName . ' has no players assigned for ' . escape($season['name']) . ' yet. Please check back soon.</p></div></div>') . '</div></div></div></div></section>';

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
