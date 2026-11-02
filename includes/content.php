<?php
declare(strict_types=1);

// Pages whose content is edited in the database (database/schema.sql, "Content edited
// directly in the database"): the home page, the text pages and the shared footer.
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/videos.php';
require_once __DIR__ . '/hero.php';
require_once __DIR__ . '/membership.php';

/** A row of site_settings, or $default when it is missing or empty. */
function setting(string $name, string $default = ''): string {
    static $settings = null;
    $settings ??= array_column(query('SELECT name, value FROM site_settings'), 'value', 'name');
    return ($settings[$name] ?? '') !== '' ? $settings[$name] : $default;
}
/** A setting with one value per line, such as contact_phones. */
function settingLines(string $name): array {
    return array_values(array_filter(array_map('trim', explode("\n", setting($name)))));
}
function phoneLink(string $phone): string {
    return '<a href="tel:' . escape(preg_replace('~[^\d+]~', '', $phone)) . '">' . escape($phone) . '</a>';
}
/** URL of a stored link: absolute URLs as they are, local paths under {{BASE}}. */
function linkUrl(string $url): string {
    return preg_match('~^(?:[a-z]+:|\{\{BASE\}\}|#)~i', $url) ? $url : '{{BASE}}/' . ltrim($url, '/');
}

const SOCIAL_ICONS = [
    'instagram' => '<path d="M7.75 2h8.5A5.75 5.75 0 0 1 22 7.75v8.5A5.75 5.75 0 0 1 16.25 22h-8.5A5.75 5.75 0 0 1 2 16.25v-8.5A5.75 5.75 0 0 1 7.75 2Zm0 1.8A3.95 3.95 0 0 0 3.8 7.75v8.5A3.95 3.95 0 0 0 7.75 20.2h8.5a3.95 3.95 0 0 0 3.95-3.95v-8.5a3.95 3.95 0 0 0-3.95-3.95Zm8.75 2.4a1.35 1.35 0 1 1 0 2.7 1.35 1.35 0 0 1 0-2.7ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 1.8A3.2 3.2 0 1 0 12 15.2 3.2 3.2 0 0 0 12 8.8Z"></path>',
    'youtube' => '<path d="M22.54 7.2a2.79 2.79 0 0 0-1.96-1.97C18.86 4.8 12 4.8 12 4.8s-6.86 0-8.58.43A2.79 2.79 0 0 0 1.46 7.2 29.6 29.6 0 0 0 1 12a29.6 29.6 0 0 0 .46 4.8 2.79 2.79 0 0 0 1.96 1.97c1.72.43 8.58.43 8.58.43s6.86 0 8.58-.43a2.79 2.79 0 0 0 1.96-1.97A29.6 29.6 0 0 0 23 12a29.6 29.6 0 0 0-.46-4.8ZM9.75 15.03V8.97L15.75 12Z"></path>',
    'facebook' => '<path d="M13.5 21v-7h2.3l.4-2.8h-2.7V9.4c0-.8.3-1.4 1.5-1.4h1.3V5.5c-.2 0-1-.1-2-.1-2 0-3.4 1.2-3.4 3.5v2.3H8.6V14h2.3v7h2.6Z"></path>',
];

viewComposer('partials.footer', function (): array {
    $sponsors = ['primary' => '', 'associate' => ''];
    foreach (query('SELECT * FROM sponsors ORDER BY position, id') as $sponsor) {
        $associate = $sponsor['tier'] === 'associate';
        $sponsors[$associate ? 'associate' : 'primary'] .= '<article class="sponsor-card' . ($associate ? ' sponsor-card--compact' : '') . '">'
            . (!$associate && $sponsor['label'] ? '<p class="sponsor-card__label">' . escape($sponsor['label']) . '</p>' : '')
            . '<div class="sponsor-card__logo-wrap"><img src="' . escape(linkUrl($sponsor['logo'])) . '" alt="' . escape($sponsor['name']) . '" loading="lazy" decoding="async">'
            . '<span class="sponsor-card__hover-name">' . escape($sponsor['name']) . '</span></div></article>' . "\n";
    }
    $social = '';
    foreach (['instagram' => 'social_instagram', 'youtube' => 'youtube_channel_url', 'facebook' => 'social_facebook'] as $network => $name) {
        if ($url = setting($name)) $social .= '<a href="' . escape($url) . '" target="_blank" rel="noopener noreferrer" class="footer-social-link" aria-label="' . ($network === 'youtube' ? 'YouTube' : ucfirst($network)) . '">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true">' . SOCIAL_ICONS[$network] . '</svg></a>' . "\n";
    }
    return [
        'sponsors' => $sponsors['primary'],
        'associate_sponsors' => $sponsors['associate'],
        'address' => implode("<br>\n", array_map('escape', settingLines('contact_address'))),
        'email' => escape(setting('contact_email')),
        'phones' => implode("\n", array_map('phoneLink', settingLines('contact_phones'))),
        'social_links' => $social,
        'shop_link' => shopEnabled() ? '<a href="{{BASE}}/shop">Shop</a>' : '',
        'important_links' => implode("\n", array_map(fn ($link) => '<a href="' . escape(linkUrl($link['url'])) . '" target="_blank" rel="noopener noreferrer">' . escape($link['label']) . '</a>',
            query('SELECT * FROM footer_links ORDER BY position, id'))),
    ];
});

// The shop's menu item, shown when SHOP_ENABLED is on.
viewComposer('partials.header', fn (): array => ['shop_nav' => shopEnabled() ? '<li class="nav-item"><a class="nav-link " href="{{BASE}}/shop">SHOP</a></li>' : '']);

// The copyright bar, also shown on the error pages, which do not use the database.
viewComposer('partials.membership-popup', fn (): array => ['membership_popup' => membershipPopupHtml()]);
viewComposer('partials.footer-bottom', fn (): array => ['year' => date('Y')]);

/** Pages whose text is in includes/templates/{slug}.html and whose title and description are rows of the pages table. */
const TEXT_PAGES = ['about', 'contact', 'privacy_policy', 'refund_policy', 'terms'];

/** About, contact and the policy pages. Returns null for a 404. */
function renderTextPage(string $slug): ?string {
    $page = query('SELECT * FROM pages WHERE slug = ?', [$slug])[0] ?? null;
    if (!$page) return null;
    $data = ['title' => escape($page['title']), 'description' => escape((string) $page['description'])];
    if ($slug === 'contact') $data += ['email' => escape(setting('contact_email')),
        'phone_items' => implode("\n", array_map(fn ($phone) => '                        <li><strong>Phone:</strong> ' . phoneLink($phone) . '</li>', settingLines('contact_phones'))),
        'email_item' => setting('contact_email') !== '' ? '                        <li><strong>Email:</strong> <a href="mailto:' . escape(setting('contact_email')) . '">' . escape(setting('contact_email')) . '</a></li>' : '',
        'contact_actions' => contactActions()];
    return view('templates.' . $slug, $data);
}

/** The contact page's action buttons: email, call and WhatsApp, each only when the site has that detail. */
function contactActions(): string {
    $email = setting('contact_email');
    $phones = settingLines('contact_phones');
    $icon = fn (string $path) => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="currentColor">' . $path . '</svg>';
    $actions = '';
    if ($email !== '') $actions .= '<a class="contact-action contact-action--email" href="mailto:' . escape($email) . '">'
        . $icon('<path d="M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm1 2.8V17h16V7.8l-8 5.2-8-5.2ZM5.4 7 12 11.3 18.6 7H5.4Z"/>') . '<span>Email us<small>' . escape($email) . '</small></span></a>' . "\n";
    foreach ($phones as $i => $phone) {
        $actions .= '<a class="contact-action contact-action--call" href="tel:' . escape(preg_replace('~[^\d+]~', '', $phone)) . '">'
            . $icon('<path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25c1.1.37 2.3.57 3.6.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.6a1 1 0 0 1-.25 1l-2.2 2.2Z"/>')
            . '<span>' . (count($phones) > 1 ? 'Call ' . ($i + 1) : 'Call') . '<small>' . escape($phone) . '</small></span></a>' . "\n";
    }
    if ($phones) {
        // WhatsApp wants the number with its country code, digits only; a bare 10-digit Indian number gets 91.
        $digits = preg_replace('~\D~', '', $phones[0]);
        if (strlen($digits) === 10) $digits = '91' . $digits;
        if (strlen($digits) >= 11) $actions .= '<a class="contact-action contact-action--whatsapp" href="https://wa.me/' . $digits . '" target="_blank" rel="noopener noreferrer">'
            . $icon('<path d="M12 2a9.9 9.9 0 0 0-8.5 15L2 22l5.1-1.4A10 10 0 1 0 12 2Zm0 1.8a8.2 8.2 0 1 1-4.2 15.3l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 0 1 12 3.8Zm-3.2 4.2c-.2 0-.5.1-.7.3-.3.3-1 1-1 2.4s1 2.8 1.2 3c.1.2 2 3.1 4.9 4.3 2.4 1 2.9.8 3.4.7.6-.1 1.8-.7 2-1.4.3-.7.3-1.3.2-1.4l-.5-.3-1.7-.8c-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1-.9-.4-1.9-1-2.7-2.1-.3-.4-.5-.7-.7-1.1-.1-.2 0-.3.1-.5l.4-.5.2-.4v-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.3Z"/>')
            . '<span>WhatsApp<small>Chat on ' . escape($phones[0]) . '</small><span class="visually-hidden"> (opens in a new tab)</span></span></a>' . "\n";
    }
    return $actions;
}

/** The latest season with teams, shown in the home page's Retained Players and Teams. */
function currentSeason(): ?array {
    return query('SELECT * FROM seasons WHERE id IN (SELECT st.season_id FROM season_teams st JOIN teams t ON t.id = st.team_id WHERE t.deleted_at IS NULL) ORDER BY route_key DESC LIMIT 1')[0] ?? null;
}

const HOME_TEAM_COLOURS = ['red', 'gold', 'purple', 'blue', 'pink', 'teal', 'orange', 'yellow', 'magenta', 'violet', 'navy'];

function renderHome(): string {
    $slides = ''; $indicators = ''; $visibleIndex = 0;
    foreach (query('SELECT * FROM home_slides ORDER BY position, id') as $slide) {
        $o = heroOptions($slide);
        if ($o['enabled'] !== '1') continue;
        $title = trim((string) $slide['title']);
        $hasContent = $title !== '' || $o['subheading'] !== '' || $o['description'] !== '' || $o['button_label'] !== '';
        $position = in_array($o['content_position'], HERO_POSITIONS, true) ? $o['content_position'] : 'bottom-center';
        $focus = in_array($o['image_position'], ['left', 'center', 'right', 'top', 'bottom'], true) ? $o['image_position'] : 'center';
        $slides .= '<div class="carousel-item' . ($visibleIndex === 0 ? ' active' : '') . '"' . ($o['interval'] !== '' ? ' data-bs-interval="' . max(5000, min(60000, (int) $o['interval'])) . '"' : '') . '><div class="hero-slide-static' . ($hasContent && $o['overlay'] === '1' ? ' hero-slide-static--with-content' : '') . '">'
            . '<picture class="hero-slide-picture">'
            . ($slide['mobile_image'] ? '<source media="(max-width: 768px)" srcset="' . escape(linkUrl($slide['mobile_image'])) . '">' : '')
            . '<img class="hero-slide-image" style="object-position:' . $focus . '" src="' . escape(linkUrl($slide['image'])) . '" alt="' . escape($o['alt'] ?: ($title ?: 'HPCL hero slide')) . '"'
            . ($visibleIndex === 0 ? ' fetchpriority="high" loading="eager"' : ' loading="lazy"') . '></picture>';
        if ($hasContent) {
            $slides .= '<div class="hero-slide-content hero-slide-content--' . $position . '">'
                . ($title !== '' ? '<p class="hero-slide-title">' . escape($title) . '</p>' : '')
                . ($o['subheading'] !== '' ? '<p class="hero-slide-subheading">' . escape($o['subheading']) . '</p>' : '')
                . ($o['description'] !== '' ? '<p class="hero-slide-description">' . escape($o['description']) . '</p>' : '');
            if ($o['button_label'] !== '' && $o['button_url'] !== '') $slides .= '<a class="hero-slide-cta" href="' . escape(linkUrl($o['button_url'])) . '"' . ($o['new_tab'] === '1' ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . escape($o['button_label']) . '</a>';
            $slides .= '</div>';
        }
        $slides .= "</div></div>\n";
        $indicators .= '<button type="button" data-bs-target="#homeHeroCarousel" data-bs-slide-to="' . $visibleIndex . '"' . ($visibleIndex === 0 ? ' class="active" aria-current="true"' : '') . ' aria-label="Slide ' . ($visibleIndex + 1) . '"></button>';
        $visibleIndex++;
    }
    $carouselAttributes = 'data-bs-ride="' . (setting('hero_autoplay', '1') === '1' ? 'carousel' : 'false') . '" data-bs-touch="true" data-bs-interval="' . max(5000, min(60000, (int) setting('hero_interval', '6000'))) . '" data-bs-pause="' . (setting('hero_pause', '1') === '1' ? 'hover' : 'false') . '" data-bs-wrap="' . (setting('hero_wrap', '1') === '1' ? 'true' : 'false') . '"';
    $carouselClass = setting('hero_fade', '0') === '1' ? ' carousel-fade' : '';
    // Indicators and the pause button are always shown (WCAG 2.2.2): the slides move by themselves.
    $carouselIndicators = $visibleIndex > 1 ? '<div class="carousel-indicators">' . $indicators . '</div>' : '';
    $carouselPause = $visibleIndex > 1 ? '<div class="hero-carousel-controls"><button type="button" class="hero-carousel-pause" aria-pressed="false" aria-label="Pause slideshow"><svg class="hero-carousel-pause__icon hero-carousel-pause__icon--pause" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z"></path></svg><svg class="hero-carousel-pause__icon hero-carousel-pause__icon--play" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="currentColor" hidden><path d="M7 4.5v15l12-7.5z"></path></svg></button></div>' : '';
    $carouselControls = '';
    if ($visibleIndex > 1 && setting('hero_controls', '1') === '1') {
        foreach (['prev' => 'Previous', 'next' => 'Next'] as $direction => $label) $carouselControls .= '<button class="carousel-control-' . $direction . '" type="button" data-bs-target="#homeHeroCarousel" data-bs-slide="' . $direction . '" aria-label="' . $label . ' slide"><span class="carousel-control-' . $direction . '-icon" aria-hidden="true"></span></button>';
    }

    $season = currentSeason();
    $retained = $teams = '';
    if ($season) {
        foreach (query('SELECT p.name, p.slug, p.photo, t.name AS team_name, t.logo FROM season_players sp JOIN players p ON p.id = sp.player_id JOIN teams t ON t.id = sp.team_id
            WHERE sp.season_id = ? AND sp.is_retained = 1 AND p.deleted_at IS NULL AND t.deleted_at IS NULL ORDER BY BINARY t.name, BINARY p.name', [$season['id']]) as $player) {
            $retained .= '<a class="retained-players__slide text-decoration-none" href="{{BASE}}/player/' . rawurlencode($player['slug']) . '" aria-label="' . escape($player['name'] . ' - ' . $player['team_name']) . '">'
                . '<article class="tpv2-card tpv2-card--orange">'
                . '<img class="tpv2-card__player" src="' . escape(mediaUrl($player['photo'], '/assets/media/player-fallback.svg')) . '" alt="' . escape($player['name']) . '" loading="lazy" onerror="this.onerror=null;this.src=\'{{BASE}}/assets/media/player-fallback.svg\';">'
                . ($player['logo'] ? '<img class="tpv2-card__badge team-image" src="' . escape(mediaUrl($player['logo'], '/logo.png')) . '" alt="' . escape($player['team_name']) . '" loading="lazy" onerror="this.onerror=null;this.src=\'{{BASE}}/logo.png\';">' : '')
                . '<div class="tpv2-card__meta"><span class="tpv2-card__tag">RETAINED</span><h3 class="tpv2-card__name retained-players__name">' . escape($player['name']) . '</h3>'
                . '<p class="tpv2-card__metric-label retained-players__team">' . escape($player['team_name']) . "</p></div></article></a>\n";
        }
        foreach (query('SELECT t.name, t.slug, t.logo, (SELECT COUNT(*) FROM season_players sp2 WHERE sp2.season_id = st.season_id AND sp2.team_id = t.id) AS player_count FROM season_teams st JOIN teams t ON t.id = st.team_id WHERE st.season_id = ? AND t.deleted_at IS NULL AND ' . seasonPlayingTeamSql() . ' ORDER BY t.name', [$season['id']]) as $index => $team) {
            $teams .= '<a class="text-decoration-none d-block" href="{{BASE}}/teams/season/' . $season['route_key'] . '/' . rawurlencode($team['slug']) . '">'
                . '<article class="teams-section__card js-reveal-card teams-section__card--' . HOME_TEAM_COLOURS[$index % count(HOME_TEAM_COLOURS)] . '">'
                . '<div class="teams-section__logo-wrap"><img class="teams-section__logo" src="' . escape(mediaUrl($team['logo'], '/logo.png')) . '" alt="" onerror="this.onerror=null;this.src=\'{{BASE}}/logo.png\';"></div>'
                . '<h3 class="teams-section__name">' . escape($team['name']) . '</h3>'
                . ($team['player_count'] ? '<p class="teams-section__count">' . (int) $team['player_count'] . ' players</p>' : '') . "</article></a>\n";
        }
    }

    $performers = '';
    foreach (query('SELECT * FROM home_top_performers ORDER BY position, id') as $card) {
        $performers .= '<article class="tpv2-card js-reveal-card tpv2-card--' . escape($card['colour']) . '">'
            . '<img class="tpv2-card__player" src="' . escape(linkUrl($card['photo'])) . '" alt="' . escape($card['player_name']) . '">'
            . ($card['team_logo'] ? '<img class="tpv2-card__badge team-image" src="' . escape(linkUrl($card['team_logo'])) . '" alt="">' : '')
            . '<div class="tpv2-card__meta"><span class="tpv2-card__tag">' . escape($card['award']) . '</span><h3 class="tpv2-card__name">' . escape(mb_strtoupper($card['player_name'], 'UTF-8')) . '</h3>'
            . '<p class="tpv2-card__metric-label">' . escape($card['metric_label']) . '</p><p class="tpv2-card__metric-value">' . escape($card['metric_value']) . "</p></div></article>\n";
    }
    $stats = '';
    foreach (query('SELECT * FROM home_stats ORDER BY position, id') as $tile) {
        $stats .= $tile['image']
            ? '<article class="stats-tile stats-tile--image"><img src="' . escape(linkUrl($tile['image'])) . '" alt="' . escape((string) $tile['image_alt']) . '" loading="lazy" decoding="async"></article>' . "\n"
            : '<article class="stats-tile stats-tile--value"><p class="stats-value">' . escape((string) $tile['value']) . '</p><p class="stats-label">' . escape((string) $tile['label']) . "</p></article>\n";
    }
    $highlights = '';
    foreach (query('SELECT * FROM home_stat_highlights ORDER BY position, id') as $highlight) {
        $highlights .= '<article class="stats-highlight"><h3 class="stats-highlight-title">' . escape($highlight['title']) . '</h3>'
            . '<p class="stats-highlight-value">' . escape($highlight['value']) . '</p><p class="stats-highlight-name">' . escape($highlight['player_name']) . "</p></article>\n";
    }

    $videos = '';
    foreach (array_slice(allVideos(), 0, 8) as $video) {
        $views = viewCountLabel($video['view_count']);
        $videos .= '<article class="latest-news__card"><a href="' . videoUrl($video) . '" aria-label="' . escape($video['title']) . '">'
            . '<img class="latest-news__card-image" src="' . escape(videoThumbnail($video)) . '" alt="' . escape($video['title']) . '" loading="lazy"></a>'
            . '<h3 class="latest-news__card-title"><a href="' . videoUrl($video) . '">' . escape($video['title']) . '</a></h3><div class="latest-news__card-meta">'
            . ($video['published_label'] ? '<p class="latest-news__card-date">' . str_replace('video-card-meta-icon', 'latest-news__meta-icon', CALENDAR_ICON) . '<span>' . $video['published_label'] . '</span></p>' : '')
            . ($views !== null ? '<p class="latest-news__card-date latest-news__card-views">' . str_replace('video-card-meta-icon', 'latest-news__meta-icon', EYE_ICON) . '<span>' . $views . '</span></p>' : '')
            . "</div></article>\n";
    }

    $seasonName = $season ? 'Season ' . $season['route_key'] : '';
    return view('templates.home', [
        'slides' => $slides, 'carousel_attributes' => $carouselAttributes, 'carousel_class' => $carouselClass, 'carousel_indicators' => $carouselIndicators, 'carousel_controls' => $carouselControls, 'carousel_pause' => $carouselPause, 'retained_season' => $seasonName, 'retained_players' => $retained, 'teams_season' => $seasonName, 'teams' => $teams,
        'highlights_season' => escape(setting('home_highlights_season')), 'top_performers' => $performers, 'stats' => $stats, 'stat_highlights' => $highlights, 'videos' => $videos,
    ]);
}
