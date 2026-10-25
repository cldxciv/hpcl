<?php
declare(strict_types=1);

// Blog listing and posts, rendered from the database. Ports of
// resources/views/public/blog/*.blade.php and Public\BlogController.
require_once __DIR__ . '/site.php';

const BLOG_CONTENT = '//div[@class="teams-linked-page__content"]';
const BLOG_PER_PAGE = 9;

function blogPosts(): array {
    return query('SELECT * FROM blog_posts ORDER BY position');
}
function blogUrl(array $post): string {
    return '{{BASE}}/blogs/' . rawurlencode($post['slug']);
}
function blogDate(?string $value): string {
    return $value ? date('d M Y', strtotime($value)) : '';
}

/** /blogs. */
function renderBlogIndex(array $query): string {
    $posts = blogPosts();
    $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $pagePosts = array_slice($posts, ($page - 1) * BLOG_PER_PAGE, BLOG_PER_PAGE);
    $html = '<section class="blog-page-wrap"><div class="blog-page-container"><div class="auto-breadcrumb-wrap"><nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3"><li class="breadcrumb-item"><a href="{{BASE}}">Home</a></li><li class="breadcrumb-item active fw-semibold" aria-current="page">Blog</li></ol></nav></div>'
        . '<div class="blog-page-head"><h1 class="blog-page-title">Pro HPCL Blog</h1><p class="blog-page-subtitle">Latest announcements, stories, and league updates.</p></div>';
    if (!$pagePosts) {
        $html .= '<div class="blog-empty">No blog posts are published yet.</div>';
    } else {
        $html .= '<div class="blog-grid">';
        foreach ($pagePosts as $post) {
            $title = escape($post['title']);
            $html .= '<article class="blog-card">' . ($post['featured_image'] ? '<a href="' . blogUrl($post) . '"><img src="' . mediaUrl($post['featured_image'], '') . '" alt="' . $title . '" class="blog-card-image" loading="lazy"></a>' : '')
                . '<div class="blog-card-body"><p class="blog-card-date">' . blogDate($post['published_at']) . '</p><h2 class="blog-card-title"><a href="' . blogUrl($post) . '">' . $title . '</a></h2><a href="' . blogUrl($post) . '" class="blog-card-link">Read more</a></div></article>';
        }
        $html .= '</div>';
        $pager = paginationHtml($page, max(1, (int) ceil(count($posts) / BLOG_PER_PAGE)), count($posts), BLOG_PER_PAGE, fn (int $number) => '{{BASE}}/blogs?' . escape(http_build_query(array_merge($query, ['page' => $number]))));
        if ($pager) $html .= '<div class="blog-pagination">' . $pager . '</div>';
    }
    return renderLayout('blogs.html', [], [BLOG_CONTENT => $html . '</div></section>']);
}

/** Player embed for a post's YouTube, Facebook or Instagram link (BlogController::buildEmbedData). */
function blogEmbed(?string $url): ?array {
    $value = trim((string) $url);
    if ($value === '') return null;
    $host = preg_replace('~^www\.~', '', strtolower((string) parse_url($value, PHP_URL_HOST)));
    $path = strtolower((string) parse_url($value, PHP_URL_PATH));
    if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be'], true)) {
        $segments = array_values(array_filter(explode('/', trim((string) parse_url($value, PHP_URL_PATH), '/'))));
        parse_str((string) parse_url($value, PHP_URL_QUERY), $params);
        $id = $host === 'youtu.be' ? ($segments[0] ?? null) : (is_string($params['v'] ?? null) && $params['v'] !== '' ? $params['v'] : (in_array($segments[0] ?? null, ['shorts', 'embed'], true) ? ($segments[1] ?? null) : null));
        if ($id) return ['provider' => 'youtube', 'original_url' => $value, 'url' => 'https://www.youtube.com/embed/' . $id, 'thumbnail_url' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg', 'variant' => 'wide'];
    }
    if (in_array($host, ['facebook.com', 'm.facebook.com', 'fb.watch'], true)) {
        $reel = str_contains($path, '/reel/');
        $video = str_contains($path, '/videos/') || $reel || $host === 'fb.watch' || str_contains($path, '/watch');
        return ['provider' => 'facebook', 'original_url' => $value, 'thumbnail_url' => null, 'variant' => $reel ? 'tall' : 'wide',
            'url' => 'https://www.facebook.com/plugins/' . ($video ? 'video.php?href=' . rawurlencode($value) . '&show_text=false' : 'post.php?href=' . rawurlencode($value) . '&show_text=true') . '&width=734'];
    }
    if (in_array($host, ['instagram.com', 'm.instagram.com'], true)) {
        return ['provider' => 'instagram', 'original_url' => $value, 'url' => preg_replace('~[?#].*$~', '', $value), 'thumbnail_url' => null, 'variant' => 'tall'];
    }
    return null;
}

/** /blogs/{slug}. Returns null for a 404. */
function renderBlogPost(string $slug): ?string {
    $posts = blogPosts();
    $post = null;
    foreach ($posts as $item) if ($item['slug'] === $slug) $post = $item;
    if (!$post) return null;
    $title = escape($post['title']);
    $url = blogUrl($post);
    $image = $post['featured_image'] ? mediaUrl($post['featured_image'], '') : '{{BASE}}/seo.png';
    $description = $post['seo_description'] ?? '';
    $author = $post['author_name'] ?: 'Pro HPCL Editorial Team';
    $published = $post['published_at'] ? date('c', strtotime($post['published_at'])) : null;
    $updated = $post['updated_at'] ? date('c', strtotime($post['updated_at'])) : null;
    $embed = blogEmbed($post['embed_url']);

    $html = '<section class="blog-article-page"><div class="blog-article-shell"><article class="blog-main-card" itemscope itemtype="https://schema.org/BlogPosting"><div class="blog-main-inner">'
        . '<nav aria-label="breadcrumb" class="blog-breadcrumb"><ol class="breadcrumb team-breadcrumb mb-0"><li class="breadcrumb-item"><a href="{{BASE}}">Home</a></li><li class="breadcrumb-item"><a href="{{BASE}}/blogs">Blog</a></li><li class="breadcrumb-item active fw-semibold" aria-current="page">' . $title . '</li></ol></nav>'
        . '<span class="blog-kicker">League Story</span><h1 class="blog-title" itemprop="headline">' . $title . '</h1><div class="blog-meta-row">'
        . ($post['published_at'] ? '<span>Published: <time datetime="' . date('Y-m-d', strtotime($post['published_at'])) . '" itemprop="datePublished">' . blogDate($post['published_at']) . '</time></span>' : '')
        . '<span itemprop="author" itemscope itemtype="https://schema.org/Person">By <span itemprop="name">' . escape($author) . '</span></span><span>' . $post['reading_minutes'] . ' min read</span></div>'
        . ($post['featured_image'] ? '<img src="' . $image . '" alt="' . $title . '" class="blog-featured-image" itemprop="image">' : '')
        . ($post['excerpt'] ? '<p class="blog-excerpt" itemprop="description">' . escape($post['excerpt']) . '</p>' : '');
    if ($embed) {
        $thumb = $embed['thumbnail_url'] ?? ($post['featured_image'] ? $image : null);
        $tall = $embed['variant'] === 'tall';
        $label = ucfirst($embed['provider']);
        $html .= '<div class="blog-embed"><button type="button" class="blog-embed-open" aria-label="Play video in modal" data-embed-modal-trigger data-embed-provider="' . escape($embed['provider']) . '" data-embed-url="' . escape($embed['url']) . '" data-embed-variant="' . escape($embed['variant']) . '" data-embed-original-url="' . escape($embed['original_url']) . '">'
            . ($thumb ? '<img src="' . escape($thumb) . '" alt="' . $label . ' thumbnail" class="blog-embed-thumb ' . ($tall ? 'blog-embed-thumb--tall' : '') . '" loading="lazy">' : '<div class="blog-embed-thumb-fallback ' . ($tall ? 'blog-embed-thumb-fallback--tall' : '') . '">' . $label . ' Preview</div>')
            . '<span class="blog-embed-play-wrap"><span class="blog-embed-play">▶</span></span></button>'
            . '<p class="blog-embed-fallback">Open original post/video: <a href="' . escape($embed['original_url']) . '" target="_blank" rel="noopener">' . escape($embed['original_url']) . '</a></p></div>';
    }
    $html .= '<div class="blog-content" itemprop="articleBody">' . $post['content_html'] . '</div></div></article><aside class="blog-side-card" aria-label="Recent Posts"><h2 class="blog-side-title">Recent Posts</h2><ul class="blog-side-list">';
    $related = array_slice(array_values(array_filter($posts, fn ($item) => $item['slug'] !== $post['slug'])), 0, 4);
    foreach ($related as $item) $html .= '<li class="blog-side-item"><a class="blog-side-link" href="' . blogUrl($item) . '">' . escape($item['title']) . '</a><span class="blog-side-date">' . blogDate($item['published_at']) . '</span></li>';
    if (!$related) $html .= '<li class="blog-side-item">No other published posts yet.</li>';
    $html .= '</ul><a class="blog-side-more" href="{{BASE}}/blogs">View all posts</a></aside></div></section>'
        . '<div class="blog-embed-modal" id="blog-embed-modal" aria-hidden="true" role="dialog" aria-label="Embedded media modal"><div class="blog-embed-modal__dialog"><button type="button" class="blog-embed-modal__close" data-embed-modal-close aria-label="Close media">x</button><div class="blog-embed-modal__content" id="blog-embed-modal-content"></div></div></div>';

    $schema = ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $post['title'], 'description' => $description, 'url' => $url,
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url], 'image' => [$image], 'author' => ['@type' => 'Person', 'name' => $author],
        'publisher' => ['@type' => 'Organization', 'name' => 'Pro HPCL', 'logo' => ['@type' => 'ImageObject', 'url' => '{{BASE}}/logo.png']]];
    if ($published) $schema['datePublished'] = $published;
    if ($updated) $schema['dateModified'] = $updated;
    if ($post['embed_url']) {
        $schema['sameAs'] = [$post['embed_url']];
        $host = preg_replace('~^www\.~', '', strtolower((string) parse_url($post['embed_url'], PHP_URL_HOST)));
        if (in_array($host, ['youtube.com', 'youtu.be', 'facebook.com', 'fb.watch', 'instagram.com'], true)) {
            $schema['video'] = ['@type' => 'VideoObject', 'name' => $post['title'], 'description' => $description, 'embedUrl' => $post['embed_url'], 'contentUrl' => $post['embed_url']] + (($published ?? $updated) ? ['uploadDate' => $published ?? $updated] : []);
        }
    }
    return renderLayout('blog.html', ['title' => ($post['seo_title'] ?: $post['title']) . ' | Pro HPCL Blog', 'description' => $description, 'url' => $url, 'image' => $image], [BLOG_CONTENT => $html], ['BlogPosting' => $schema, 'BreadcrumbList' => autoBreadcrumbSchema(autoBreadcrumbItems('/blogs/' . $post['slug']))]);
}
