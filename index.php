<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/errors.php';

// Errors are always logged. Visitors see the error page (includes/errors.php); with APP_DEBUG on,
// the error itself is shown instead.
error_reporting(E_ALL);
ini_set('display_errors', appDebug() ? '1' : '0');
ini_set('log_errors', '1');
set_exception_handler(function (Throwable $error): void {
    error_log((string) $error);
    appDebug() ? debugErrorPage($error) : errorPage(500);
});
register_shutdown_function(function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    appDebug() ? debugErrorPage($error) : errorPage(500);
});
// Keep development and staging copies out of search engines.
if (appEnv() !== 'production') header('X-Robots-Tag: noindex, nofollow');

// Standalone front controller. No framework, Composer or admin panel. Player pages,
// player search and statistics are read from the database (includes/db.php).
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/.');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($base !== '' && str_starts_with($path, $base . '/')) $path = substr($path, strlen($base));
$path = '/' . trim($path, '/');
if ($path === '/index.php') $path = '/';
if ($path === '/players') $path = '/players/search';
require_once __DIR__ . '/includes/view.php';
require_once __DIR__ . '/includes/content.php';
$routes = json_decode(file_get_contents(__DIR__ . '/routes.json'), true, 512, JSON_THROW_ON_ERROR);
function escape(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function render(string $html): string {
    global $base;
    $html = str_ireplace('%7B%7BBASE%7D%7D', '{{BASE}}', $html);
    // A bare {{BASE}} link is the home page: "/" at the domain root rather than "", which reloads the page.
    $html = preg_replace('~\{\{BASE\}\}(?=["\'])~', '{{BASE}}/', $html);
    return str_replace('{{BASE}}', escape($base), $html);
}
function notFound(): never {
    errorPage(404);
}
// Admin sign-in (includes/admin.php) checks its own methods and starts its own session.
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    require_once __DIR__ . '/includes/admin.php';
    handleAdminRequest($path);
}
// The public pages that accept POST: the committee member form and its payment
// (includes/committee.php), the membership join form and its payment (includes/membership-join.php),
// and Razorpay's payment notifications (includes/razorpay.php).
if ($path === '/join-committee' || $path === '/join-committee/payment') {
    require_once __DIR__ . '/includes/committee.php';
    $path === '/join-committee' ? handleCommitteeRequest() : handleCommitteePayment();
}
if ($path === '/membership/join' || $path === '/membership/join/payment') {
    require_once __DIR__ . '/includes/membership-join.php';
    $path === '/membership/join' ? handleMembershipJoin() : handleMembershipPayment();
}
if ($path === '/razorpay/webhook') {
    require_once __DIR__ . '/includes/razorpay.php';
    handleRazorpayWebhook();
}
// On-site membership sales agent (includes/agent.php): the chat widget posts here.
if ($path === '/agent/chat') {
    require_once __DIR__ . '/includes/agent.php';
    handleAgentChat();
}
// WhatsApp membership agent (includes/whatsapp.php): Meta's webhook verify (GET) and messages (POST).
if ($path === '/whatsapp/webhook') {
    require_once __DIR__ . '/includes/whatsapp.php';
    handleWhatsappWebhook();
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    errorPage(405);
}
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    // Configure SITE_URL for the production domain instead of trusting Host headers.
    $siteUrl = rtrim(absoluteUrl('{{BASE}}'), '/');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($routes as $route => $file) if (!str_contains($route, '?')) echo '<url><loc>' . escape($siteUrl . ($route === '/' ? '/' : $route)) . '</loc></url>';
    require_once __DIR__ . '/includes/teams.php';
    $dynamicUrls = ['/', '/videos', '/blogs', '/join-committee'];
    foreach (query('SELECT slug FROM pages WHERE slug IN (' . implode(', ', array_fill(0, count(TEXT_PAGES), '?')) . ') ORDER BY slug', TEXT_PAGES) as $page) $dynamicUrls[] = '/' . $page['slug'];
    if (shopEnabled()) {
        require_once __DIR__ . '/includes/shop.php';
        $dynamicUrls[] = '/shop';
        foreach (shopCategories() as $category) $dynamicUrls[] = substr(categoryUrl($category, shopCategories()), strlen('{{BASE}}'));
        foreach (shopProducts() as $product) $dynamicUrls[] = substr(productUrl($product), strlen('{{BASE}}'));
    }
    foreach (query('SELECT route_key FROM seasons ORDER BY route_key') as $season) $dynamicUrls[] = '/stats/season/' . $season['route_key'];
    foreach (query('SELECT slug FROM blog_posts ORDER BY position') as $post) $dynamicUrls[] = '/blogs/' . rawurlencode($post['slug']);
    foreach (query('SELECT slug FROM video_categories ORDER BY position') as $category) $dynamicUrls[] = '/videos/category/' . rawurlencode($category['slug']);
    foreach (query('SELECT slug FROM videos ORDER BY position') as $video) $dynamicUrls[] = '/videos/' . rawurlencode($video['slug']);
    foreach (query('SELECT route_key FROM seasons ORDER BY route_key') as $season) $dynamicUrls[] = '/teams/season/' . $season['route_key'];
    foreach (query('SELECT s.route_key, t.slug FROM season_teams st JOIN seasons s ON s.id = st.season_id JOIN teams t ON t.id = st.team_id WHERE t.deleted_at IS NULL ORDER BY s.route_key, t.name') as $team) $dynamicUrls[] = '/teams/season/' . $team['route_key'] . '/' . rawurlencode($team['slug']);
    foreach (query('SELECT slug FROM players WHERE deleted_at IS NULL ORDER BY name') as $player) $dynamicUrls[] = '/player/' . rawurlencode($player['slug']);
    foreach ($dynamicUrls as $url) echo '<url><loc>' . escape($siteUrl . $url) . '</loc></url>';
    echo '</urlset>';
    exit;
}
$query = $_GET;
foreach ($query as $value) if (!is_scalar($value)) errorPage(400);
// Index and detail pages rendered from the database: pattern => [file, renderer]. A renderer
// gets the pattern's captures and returns null when the record does not exist.
if ($path === '/videos' && isset($query['category'])) {
    // Old category filter URLs, as on prohpcl.com.
    $slug = trim(preg_replace('~[^a-z0-9]+~', '-', strtolower((string) $query['category'])), '-');
    if ($slug !== '') { header('Location: ' . $base . '/videos/category/' . $slug, true, 301); exit; }
}
// Matches and points tables were removed; their indexed URLs go to the home page.
if (preg_match('~^/(matches(/season/\d+)?|season/\d+/matches(/\d+)?|point-tables(/season/\d+)?)$~', $path)) {
    header('Location: ' . $base . '/', true, 301);
    exit;
}
$dynamicRoutes = [
    '~^/$~' => ['content.php', fn () => renderHome()],
    '~^/membership$~' => ['membership.php', fn () => renderMembershipPage()],
    '~^/members$~' => ['members.php', fn () => renderMembersIndex()],
    '~^/member/([^/]+)$~' => ['members.php', fn ($slug) => renderMemberPage($slug)],
    '~^/(' . implode('|', TEXT_PAGES) . ')$~' => ['content.php', fn ($slug) => renderTextPage($slug)],
    '~^/stats/season/(\d+)$~' => ['catalog.php', fn ($season) => renderSeasonStats($season, $query)],
    '~^/player/([^/]+)$~' => ['catalog.php', fn ($slug) => renderPlayerPage($slug)],
    '~^/teams/season/(\d+)$~' => ['teams.php', fn ($season) => renderSeasonTeams($season)],
    '~^/teams/season/(\d+)/([^/]+)$~' => ['teams.php', fn ($season, $team) => renderTeamPage($season, $team)],
    '~^/videos$~' => ['videos.php', fn () => renderVideosIndex(null, null, $path, $query)],
    '~^/videos/category/([^/]+)$~' => ['videos.php', fn ($category) => renderVideosIndex($category, null, $path, $query)],
    '~^/videos/tag/([^/]+)$~' => ['videos.php', fn ($tag) => renderVideosIndex(null, $tag, $path, $query)],
    '~^/videos/([^/]+)$~' => ['videos.php', fn ($slug) => renderVideoPage($slug)],
    '~^/blogs$~' => ['blogs.php', fn () => renderBlogIndex($query)],
    '~^/blogs/([^/]+)$~' => ['blogs.php', fn ($slug) => renderBlogPost($slug)],
];
// The shop answers only when SHOP_ENABLED is on; otherwise its pages are 404.
if (shopEnabled()) $dynamicRoutes += [
    '~^/shop$~' => ['shop.php', fn () => renderShopIndex()],
    '~^/shop/category/([^/]+)$~' => ['shop.php', fn ($category) => renderShopIndex($category)],
    '~^/shop/category/([^/]+)/([^/]+)$~' => ['shop.php', fn ($parent, $category) => renderShopIndex($parent, $category)],
    '~^/shop/([^/]+)$~' => ['shop.php', fn ($slug) => renderProductPage($slug)],
];
foreach ($dynamicRoutes as $pattern => [$file, $renderer]) {
    if (!preg_match($pattern, $path, $match)) continue;
    require_once __DIR__ . '/includes/' . $file;
    $html = $renderer(...array_slice($match, 1)) ?? notFound();
    header('Content-Type: text/html; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo render($html);
    exit;
}
if (!isset($routes[$path])) notFound();
// Select existing pagination variants regardless of query parameter order.
$file = $routes[$path];
foreach ($routes as $route => $candidate) {
    if ((parse_url($route, PHP_URL_PATH) ?: '/') !== $path || !str_contains($route, '?')) continue;
    parse_str(parse_url($route, PHP_URL_QUERY) ?: '', $variant);
    $requested = array_filter($query, fn ($value) => $value !== '');
    if ($variant == $requested) { $file = $candidate; break; }
}
$html = renderTemplate(file_get_contents(__DIR__ . '/pages/' . $file));
if ($path === '/players/search') {
    require_once __DIR__ . '/includes/catalog.php';
    $html = renderPlayerSearch($html, $query);
} elseif ($path === '/teams') {
    require_once __DIR__ . '/includes/teams.php';
    $html = renderTeamsIndex($html);
} elseif ($path === '/stats') {
    require_once __DIR__ . '/includes/catalog.php';
    $html = renderStats($html, $path, $query);
}
header('Content-Type: text/html; charset=UTF-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo render($html);
