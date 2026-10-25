<?php
declare(strict_types=1);

// Video listing (/videos, /videos/category/{slug}, /videos/tag/{slug}) and video pages,
// rendered from the database. Ports of resources/views/public/videos/*.blade.php.
require_once __DIR__ . '/site.php';

const VIDEOS_CONTENT = '//div[@class="teams-linked-page__content"]';
const VIDEOS_PER_PAGE = 12;

/** Laravel's YouTubeVideoService::formatViewCount(). */
function viewCountLabel(?int $count): ?string {
    if ($count === null) return null;
    foreach ([1000000000 => 'B', 1000000 => 'M', 1000 => 'K'] as $size => $suffix) {
        if ($count >= $size) return rtrim(rtrim(number_format($count / $size, 1), '0'), '.') . $suffix;
    }
    return number_format($count);
}

/** All videos in listing order, with tags, links and category label. */
function allVideos(): array {
    static $videos = null;
    if ($videos !== null) return $videos;
    $videos = [];
    foreach (query('SELECT v.*, c.name AS category_label FROM videos v LEFT JOIN video_categories c ON c.slug = v.category_slug ORDER BY v.position') as $video) {
        $video['tags'] = $video['links'] = [];
        $video['published_label'] = $video['published_on'] ? date('d M Y', strtotime($video['published_on'])) : null;
        $video['view_count'] = $video['view_count'] === null ? null : (int) $video['view_count'];
        $videos[$video['id']] = $video;
    }
    foreach (query('SELECT * FROM video_tags ORDER BY video_id, position') as $tag) if (isset($videos[$tag['video_id']])) $videos[$tag['video_id']]['tags'][] = $tag;
    foreach (query('SELECT * FROM video_links ORDER BY video_id, kind DESC, position') as $link) if (isset($videos[$link['video_id']])) $videos[$link['video_id']]['links'][$link['kind']][] = $link;
    return $videos = array_values($videos);
}
function videoThumbnail(array $video): string {
    return $video['thumbnail_url'] ?: 'https://i.ytimg.com/vi/' . $video['youtube_id'] . '/hqdefault.jpg';
}
function videoUrl(array $video): string {
    return '{{BASE}}/videos/' . rawurlencode($video['slug']);
}
function videoTagLinks(array $video): array {
    return array_map(fn ($tag) => ['name' => trim($tag['name']), 'url' => '{{BASE}}/videos/tag/' . rawurlencode($tag['slug'])], $video['tags']);
}
function videoPublicLinks(array $video, array $kinds): array {
    $links = [];
    foreach ($kinds as $kind) foreach ($video['links'][$kind] ?? [] as $link) $links[] = ['name' => trim($link['name']), 'url' => str_starts_with($link['url'], '/') ? '{{BASE}}' . $link['url'] : $link['url']];
    return $links;
}
function tagLinksHtml(array $links, string $class): string {
    return implode('', array_map(fn ($tag) => '<a class="' . $class . '" href="' . escape($tag['url']) . '">' . escape($tag['name']) . '</a>', $links));
}
/** Laravel's Str::headline() for tag slugs. */
function headline(string $value): string {
    $words = preg_split('~[\s_-]+~u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) === 1) $words = preg_split('~(?=\p{Lu})~u', $words[0], -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_map(fn ($word) => mb_convert_case($word, MB_CASE_TITLE, 'UTF-8'), $words));
}

const CALENDAR_ICON = '<svg class="video-card-meta-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v2H2V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm15 9v8a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3v-8h20Zm-5 3a1 1 0 0 0-1 1v2h-2a1 1 0 1 0 0 2h3a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1Z"/></svg>';
const EYE_ICON = '<svg class="video-card-meta-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 5c5.23 0 9.27 3.02 11 6.5C21.27 14.98 17.23 18 12 18S2.73 14.98 1 11.5C2.73 8.02 6.77 5 12 5Zm0 2C7.94 7 4.76 9.1 3.2 11.5 4.76 13.9 7.94 16 12 16s7.24-2.1 8.8-4.5C19.24 9.1 16.06 7 12 7Zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z"/></svg>';

/** /videos, /videos/category/{slug} and /videos/tag/{slug}. Returns null for a 404. */
function renderVideosIndex(?string $category, ?string $tag, string $path, array $query): ?string {
    $videos = allVideos();
    $categories = query('SELECT c.* FROM video_categories c WHERE EXISTS (SELECT 1 FROM videos v WHERE v.category_slug = c.slug) ORDER BY c.position');
    if ($category !== null) {
        if (!in_array($category, array_column($categories, 'slug'), true)) return null;
        $videos = array_values(array_filter($videos, fn ($video) => $video['category_slug'] === $category));
    }
    if ($tag !== null) {
        $videos = array_values(array_filter($videos, fn ($video) => in_array($tag, array_column($video['tags'], 'slug'), true)));
        if (!$videos) return null;
    }
    $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $total = count($videos);
    $lastPage = max(1, (int) ceil($total / VIDEOS_PER_PAGE));
    $channelUrl = query('SELECT value FROM site_settings WHERE name = ?', ['youtube_channel_url'])[0]['value'] ?? 'https://www.youtube.com/@prohpcl';

    $html = '<section class="videos-page-wrap"><div class="videos-page-container"><div class="videos-page-head"><div><h1 class="videos-page-title">Latest YouTube Videos</h1><p class="videos-page-subtitle">All available uploads from the official Pro HPCL YouTube channel.</p></div>'
        . '<a class="videos-page-link" href="' . escape($channelUrl) . '" target="_blank" rel="noopener noreferrer">Open Channel</a></div>';
    if ($categories) {
        $html .= '<div class="videos-category-filters"><a class="videos-category-chip ' . ($category === null && $tag === null ? 'is-active' : '') . '" href="{{BASE}}/videos">All</a>';
        foreach ($categories as $item) $html .= '<a class="videos-category-chip ' . ($category === $item['slug'] ? 'is-active' : '') . '" href="{{BASE}}/videos/category/' . rawurlencode($item['slug']) . '">' . escape($item['name']) . '</a>';
        $html .= '</div>';
    }
    if ($tag !== null) $html .= '<div class="mb-3 text-sm text-slate-600">Tag: <strong>' . escape(headline(str_replace('-', ' ', $tag))) . '</strong><a href="{{BASE}}/videos" class="ms-2 text-blue-700 hover:underline">Clear</a></div>';
    $pageVideos = array_slice($videos, ($page - 1) * VIDEOS_PER_PAGE, VIDEOS_PER_PAGE);
    if (!$pageVideos) {
        $html .= '<div class="videos-empty">Videos are not available right now. Please add videos from the admin panel.</div>';
    } else {
        $html .= '<div class="videos-grid">';
        foreach ($pageVideos as $video) {
            $title = escape($video['title']);
            $tags = array_slice(videoTagLinks($video), 0, 2);
            $public = array_slice(videoPublicLinks($video, ['team', 'player']), 0, 2);
            $html .= '<article class="video-card"><a class="video-thumb-link" href="' . videoUrl($video) . '" aria-label="' . $title . '"><img class="video-thumb-image" src="' . escape(videoThumbnail($video)) . '" alt="' . $title . '" loading="lazy"></a>'
                . '<div class="video-card-body"><h2 class="video-card-title"><a href="' . videoUrl($video) . '">' . $title . '</a></h2><div class="video-card-meta">'
                . ($video['published_label'] ? '<p class="video-card-date">' . CALENDAR_ICON . '<span>' . $video['published_label'] . '</span></p>' : '')
                . '<p class="video-card-views">' . EYE_ICON . '<span>' . escape(viewCountLabel($video['view_count']) ?? 'N/A') . '</span></p></div>'
                . ($tags ? '<div class="video-card-tag-section"><p class="video-card-tag-section-title">Tags</p><div class="video-card-tags">' . tagLinksHtml($tags, 'video-card-tag') . '</div></div>' : '')
                . ($public ? '<div class="video-card-tag-section"><p class="video-card-tag-section-title">Public Links</p><div class="video-card-tags">' . tagLinksHtml($public, 'video-card-tag') . '</div></div>' : '')
                . '</div></article>';
        }
        $html .= '</div>';
        $pager = paginationHtml($page, $lastPage, $total, VIDEOS_PER_PAGE, fn (int $number) => '{{BASE}}' . $path . '?' . escape(http_build_query(array_merge($query, ['page' => $number]))));
        if ($pager) $html .= '<div class="videos-pagination">' . $pager . '</div>';
    }
    $html .= '</div></section>';
    $crumbs = autoBreadcrumbItems($path);
    return renderLayout('videos.html', ['url' => '{{BASE}}' . $path], [VIDEOS_CONTENT => $html, '//div[@class="auto-breadcrumb-wrap"]' => autoBreadcrumbHtml($crumbs)], ['BreadcrumbList' => autoBreadcrumbSchema($crumbs)]);
}

/** Related videos: shared tags first, then the same category, then the latest. */
function relatedVideos(array $video): array {
    $pool = array_values(array_filter(allVideos(), fn ($item) => $item['slug'] !== $video['slug']));
    $tagSlugs = array_unique(array_column($video['tags'], 'slug'));
    $byTags = [];
    foreach ($pool as $item) {
        $score = count(array_intersect($tagSlugs, array_unique(array_column($item['tags'], 'slug'))));
        if ($score > 0) $byTags[] = $item + ['score' => $score];
    }
    // Newest first by publish date; the listing order (newest first apart from pinned
    // videos) breaks ties within a day, as only the date is published.
    $newest = fn ($a, $b) => [$b['published_on'], $a['position']] <=> [$a['published_on'], $b['position']];
    usort($pool, $newest);
    usort($byTags, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: $newest($a, $b));
    $byCategory = $video['category_slug'] ? array_filter($pool, fn ($item) => $item['category_slug'] === $video['category_slug']) : $pool;
    $related = [];
    foreach ([$byTags, $byCategory, $pool] as $group) {
        foreach ($group as $item) {
            $related[$item['slug']] ??= $item;
            if (count($related) === 8) break 2;
        }
    }
    return array_values($related);
}

/** /videos/{slug}. Returns null for a 404; an 11-character YouTube id redirects to the slug. */
function renderVideoPage(string $slug): ?string {
    $video = null;
    foreach (allVideos() as $item) if ($item['slug'] === $slug) $video = $item;
    if (!$video && preg_match('~^[A-Za-z0-9_-]{11}$~', $slug)) {
        foreach (allVideos() as $item) {
            if ($item['youtube_id'] === $slug) { header('Location: ' . render(videoUrl($item)), true, 301); exit; }
        }
    }
    if (!$video) return null;
    $title = trim($video['title']) ?: 'Pro HPCL Video';
    $description = trim((string) $video['description']) ?: trim($video['title']);
    $thumbnail = videoThumbnail($video);
    $url = videoUrl($video);
    $embed = (string) $video['embed_url'];
    if ($embed !== '') $embed .= (str_contains($embed, '?') ? '&' : '?') . 'autoplay=1&rel=0&modestbranding=1&fs=1&playsinline=0';
    $sections = array_filter(['Tags' => videoTagLinks($video), 'Team Links' => videoPublicLinks($video, ['team']), 'Player Links' => videoPublicLinks($video, ['player'])]);
    $views = viewCountLabel($video['view_count']);

    $html = '<section class="video-watch-wrap"><div class="video-watch-container"><nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3"><li class="breadcrumb-item"><a href="{{BASE}}">Home</a></li><li class="breadcrumb-item"><a href="{{BASE}}/videos">Videos</a></li>'
        . '<li class="breadcrumb-item active fw-semibold" aria-current="page">' . escape(strLimit($title, 52)) . '</li></ol></nav><div class="video-watch-grid"><article class="video-watch-card">'
        . '<div class="video-frame-wrap"><iframe class="video-frame" src="' . escape($embed) . '" title="' . escape($title) . '" loading="eager" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen" allowfullscreen webkitallowfullscreen mozallowfullscreen></iframe></div>'
        . '<div class="video-meta"><h1 class="video-title">' . escape($title) . '</h1><div class="video-meta-row">'
        . '<span class="video-meta-chip"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v2H2V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm15 9v8a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3v-8h20Z"/></svg>' . escape($video['published_label'] ?? 'Published date unavailable') . '</span>'
        . '<span class="video-meta-chip"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 5c5.23 0 9.27 3.02 11 6.5C21.27 14.98 17.23 18 12 18S2.73 14.98 1 11.5C2.73 8.02 6.77 5 12 5Zm0 2C7.94 7 4.76 9.1 3.2 11.5 4.76 13.9 7.94 16 12 16s7.24-2.1 8.8-4.5C19.24 9.1 16.06 7 12 7Z"/></svg>' . escape($views ?? 'N/A views') . '</span>'
        . ($video['category_slug'] ? '<a href="{{BASE}}/videos/category/' . rawurlencode($video['category_slug']) . '" class="video-meta-chip"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 6a3 3 0 0 1 3-3h6l2 2h4a3 3 0 0 1 3 3v2H3V6Zm0 4h18v8a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-8Z"/></svg>' . escape($video['category_label'] ?? headline($video['category_slug'])) . '</a>' : '')
        . '</div><p class="video-description">' . escape($description) . '</p>';
    foreach ($sections as $sectionTitle => $links) $html .= '<div class="video-tag-section"><p class="video-tag-section-title">' . $sectionTitle . '</p><div class="video-tags">' . tagLinksHtml($links, 'video-tag') . '</div></div>';
    $channelUrl = query('SELECT value FROM site_settings WHERE name = ?', ['youtube_channel_url'])[0]['value'] ?? 'https://www.youtube.com/@prohpcl';
    $share = [
        ['https://wa.me/?text=' . urlencode($title . ' ' . absoluteUrl($url)), '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.7 14.93L2 22l5.2-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.06-1.11l-.29-.17-3.09.77.83-3.02-.19-.31A8 8 0 1 1 12 20Z"/></svg>WhatsApp'],
        ['https://twitter.com/intent/tweet?url=' . urlencode(absoluteUrl($url)) . '&text=' . urlencode($title), '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.9 2H22l-6.77 7.73L23 22h-6.1l-4.78-6.27L6.63 22H3.5l7.24-8.28L1 2h6.25l4.32 5.7L18.9 2Z"/></svg>X'],
        ['https://www.facebook.com/sharer/sharer.php?u=' . urlencode(absoluteUrl($url)), '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 22v-8h2.7l.4-3h-3.1V9.1c0-.87.25-1.46 1.5-1.46h1.6V5a20 20 0 0 0-2.3-.12c-2.3 0-3.8 1.38-3.8 3.95V11H8v3h2.4v8h3.1Z"/></svg>Facebook'],
        ['https://t.me/share/url?url=' . urlencode(absoluteUrl($url)) . '&text=' . urlencode($title), '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m21.94 4.66-3.17 14.95c-.24 1.05-.87 1.31-1.77.82l-4.9-3.6-2.37 2.28c-.26.26-.48.48-.99.48l.35-5 9.1-8.22c.4-.35-.08-.55-.61-.2L6.33 13.9 1.5 12.4c-1.05-.33-1.06-1.05.22-1.56l18.84-7.27c.87-.32 1.64.2 1.38 1.09Z"/></svg>Telegram'],
    ];
    $html .= '<div class="video-actions"><a class="video-btn video-btn-primary" href="' . escape((string) $video['video_url']) . '" target="_blank" rel="noopener noreferrer">Watch on YouTube</a><a class="video-btn video-btn-light" href="{{BASE}}/videos">All Videos</a><a class="video-btn video-btn-light" href="' . escape($channelUrl) . '" target="_blank" rel="noopener noreferrer">Open Channel</a></div>'
        . '<div class="video-share-wrap"><p class="video-share-title">Share this video</p><div class="video-share-grid">';
    foreach ($share as [$href, $label]) $html .= '<a class="video-share-btn" href="' . escape($href) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
    $html .= '<a href="#" class="video-share-btn" data-copy-video-link="' . escape(absoluteUrl($url)) . '"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 3a2 2 0 0 0-2 2v10h2V5h10V3H8Zm3 4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-8Z"/></svg><span data-copy-label>Copy Link</span></a></div></div></div></article>'
        . '<aside class="video-related"><h2 class="video-related-title">Related Videos</h2><div class="video-related-list">';
    foreach (relatedVideos($video) as $item) {
        $html .= '<a class="video-related-item" href="' . videoUrl($item) . '"><img src="' . escape(videoThumbnail($item)) . '" alt="' . escape($item['title']) . '" loading="lazy"><div><p class="video-related-item-title">' . escape($item['title']) . '</p><p class="video-related-item-date">' . escape($item['published_label'] ?? '') . '</p></div></a>';
    }
    $html .= '</div></aside></div></div></section>';

    $schema = ['@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => $title, 'description' => $description, 'thumbnailUrl' => [$thumbnail],
        // Laravel parses the date-only label, so the time of day is the request time.
        'uploadDate' => $video['published_on'] ? date('Y-m-d', strtotime($video['published_on'])) . date('\TH:i:sP') : null,
        'url' => $url, 'mainEntityOfPage' => $url, 'embedUrl' => $video['embed_url'], 'contentUrl' => $video['video_url'], 'keywords' => $video['keywords'],
        'potentialAction' => ['@type' => 'WatchAction', 'target' => array_values(array_filter([$url, $video['video_url']]))],
        'interactionStatistic' => $video['view_count'] !== null ? ['@type' => 'InteractionCounter', 'interactionType' => ['@type' => 'WatchAction'], 'userInteractionCount' => $video['view_count']] : null,
        'publisher' => ['@type' => 'Organization', 'name' => 'Pro HPCL']];
    return renderLayout('video.html', ['title' => $title . ' | Pro HPCL', 'description' => $description, 'url' => $url], [VIDEOS_CONTENT => $html], ['VideoObject' => $schema]);
}
