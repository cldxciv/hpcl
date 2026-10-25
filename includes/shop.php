<?php
declare(strict_types=1);

// Shop listing (/shop, /shop/category/{slug}[/{slug}]) and product pages, rendered from the
// shop_* tables. There is no cart or checkout: products are shown for information.
require_once __DIR__ . '/content.php';

const SHOP_LOW_STOCK = 5;
const SHOP_MAX_PER_LINE = 20;

function shopIcon(string $paths, int $size = 18, string $strokeWidth = '2'): string {
    return '<svg class="pdp-icon " width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $strokeWidth . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
}
const ICON_CHECK = '<path d="M20 6 9 17l-5-5"></path>';
const ICON_X = '<path d="M18 6 6 18M6 6l12 12"></path>';
const ICON_CHEVRON = '<path d="m6 9 6 6 6-6"></path>';
const ICON_HEART = '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path>';
const ICON_SPARK = '<path d="M12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M5.6 18.4l2.1-2.1m8.6-8.6 2.1-2.1"></path>';

/** ₹ amount: whole rupees without decimals, with Indian-style thousands separators as on the live site. */
function rupees(float|string $amount): string {
    $amount = (float) $amount;
    return '&#8377;' . number_format($amount, floor($amount) === $amount ? 0 : 2);
}
function discountPercent(array $product): ?int {
    $compare = (float) $product['compare_price'];
    return $compare > (float) $product['price'] ? (int) round(($compare - (float) $product['price']) / $compare * 100) : null;
}
function productUrl(array $product): string {
    return '{{BASE}}/shop/' . rawurlencode($product['slug']);
}
function categoryUrl(array $category, array $categories): string {
    $parent = $category['parent_id'] ? $categories[$category['parent_id']] : null;
    return '{{BASE}}/shop/category/' . ($parent ? rawurlencode($parent['slug']) . '/' : '') . rawurlencode($category['slug']);
}
/** Star rating: the filled stars cover $rating / 5 of the row. */
function starsHtml(float $rating): string {
    $label = rtrim(rtrim(number_format($rating, 1), '0'), '.');
    $stars = '&#9733;&#9733;&#9733;&#9733;&#9733;';
    return '<span class="pdp-stars " role="img" aria-label="Rated ' . $label . ' out of 5"><span class="pdp-stars-base" aria-hidden="true">' . $stars . '</span>'
        . '<span class="pdp-stars-fill" style="width: ' . rtrim(rtrim(number_format($rating / 5 * 100, 2), '0'), '.') . '%" aria-hidden="true">' . $stars . '</span></span>';
}

/** All categories by id, in display order. */
function shopCategories(): array {
    static $categories = null;
    return $categories ??= array_column(query('SELECT * FROM shop_categories ORDER BY position, name'), null, 'id');
}

/** Products with their category, colours, variants, gallery and rating, in display order. */
function shopProducts(): array {
    static $products = null;
    if ($products !== null) return $products;
    $products = [];
    foreach (query('SELECT * FROM shop_products ORDER BY position, id') as $product) {
        $product += ['colours' => [], 'variants' => [], 'images' => [$product['image']], 'reviews' => []];
        $products[$product['id']] = $product;
    }
    foreach (query('SELECT * FROM shop_product_colours ORDER BY position, id') as $row) if (isset($products[$row['product_id']])) $products[$row['product_id']]['colours'][] = $row;
    foreach (query('SELECT * FROM shop_product_variants') as $row) if (isset($products[$row['product_id']])) $products[$row['product_id']]['variants'][] = $row;
    foreach (query('SELECT * FROM shop_product_images ORDER BY position') as $row) if (isset($products[$row['product_id']])) $products[$row['product_id']]['images'][] = $row['image'];
    foreach (query('SELECT * FROM shop_reviews ORDER BY reviewed_on DESC, id DESC') as $row) if (isset($products[$row['product_id']])) $products[$row['product_id']]['reviews'][] = $row;
    foreach ($products as &$product) {
        $product['in_stock'] = $product['variants'] ? array_sum(array_column($product['variants'], 'stock')) > 0 : $product['stock'] > 0;
        $product['rating'] = $product['reviews'] ? array_sum(array_column($product['reviews'], 'rating')) / count($product['reviews']) : 0;
        $product['category'] = shopCategories()[$product['category_id']] ?? null;
    }
    return $products;
}
/** Text under the title: the short description, or the start of the description. */
function productSummary(array $product): string {
    return trim((string) $product['short_description']) !== '' ? trim($product['short_description']) : strLimit(trim((string) $product['description']), 200);
}

function shopCard(array $product, ?array $current = null): string {
    $discount = discountPercent($product);
    $swatches = '';
    if ($product['colours']) {
        $names = array_column($product['colours'], 'name');
        $swatches = '<div class="shop-card-swatches" aria-label="' . count($names) . ' colour' . (count($names) === 1 ? '' : 's') . ': ' . escape(implode(', ', $names)) . '">'
            . implode('', array_map(fn ($colour) => '<span class="shop-swatch-dot" style="background: ' . escape($colour['hex']) . '" title="' . escape($colour['name']) . '"></span>', $product['colours'])) . '</div>';
    }
    return '<a href="' . productUrl($product) . '" class="shop-card">'
        . '<img src="' . escape(linkUrl($product['image'])) . '" alt="' . escape($product['name']) . '" class="shop-card-image" loading="lazy">'
        . '<div class="shop-card-body">'
        . ($product['category'] && (int) $product['category_id'] !== (int) ($current['id'] ?? 0) ? '<p class="shop-card-category">' . escape($product['category']['name']) . '</p>' : '')
        . '<h2 class="shop-card-name">' . escape($product['name']) . '</h2>'
        . '<div class="shop-price"><span>' . rupees($product['price']) . '</span>' . ($discount !== null ? '<span class="shop-price-compare">' . rupees($product['compare_price']) . '</span>' : '') . '</div>'
        . $swatches
        . (!$product['in_stock'] ? '<span class="shop-badge shop-badge-out">Out of stock</span>' : ($discount !== null ? '<span class="shop-badge shop-badge-sale">' . $discount . '% off</span>' : ''))
        . "</div></a>\n";
}

/** /shop and its category pages. Returns null for a 404. */
function renderShopIndex(?string $parentSlug = null, ?string $childSlug = null): ?string {
    $categories = shopCategories();
    $bySlug = array_column($categories, null, 'slug');
    $category = null;
    if ($parentSlug !== null) {
        $parent = $bySlug[$parentSlug] ?? null;
        if (!$parent || $parent['parent_id']) return null;
        $category = $parent;
        if ($childSlug !== null) {
            $category = $bySlug[$childSlug] ?? null;
            if (!$category || (int) $category['parent_id'] !== (int) $parent['id']) return null;
        }
    }
    $children = fn (array $of) => array_filter($categories, fn ($c) => (int) $c['parent_id'] === (int) $of['id']);

    $crumbs = [['label' => 'Home', 'url' => '{{BASE}}'], ['label' => 'Shop', 'url' => '{{BASE}}/shop']];
    if ($category && $category['parent_id']) $crumbs[] = ['label' => $categories[$category['parent_id']]['name'], 'url' => categoryUrl($categories[$category['parent_id']], $categories)];
    if ($category) $crumbs[] = ['label' => $category['name'], 'url' => categoryUrl($category, $categories)];

    $chip = fn (string $url, string $label) => '<a href="' . $url . '" class="shop-category-chip">' . $label . '</a>';
    if (!$category) {
        $nav = '<nav class="shop-categories" aria-label="Categories"><span class="shop-category-chip is-active" aria-current="page">All</span>'
            . implode('', array_map(fn ($c) => $chip(categoryUrl($c, $categories), escape($c['name'])), array_filter($categories, fn ($c) => !$c['parent_id']))) . '</nav>';
    } elseif (!$category['parent_id']) {
        $nav = '<nav class="shop-categories" aria-label="Sub-categories">' . $chip('{{BASE}}/shop', '&larr; All products')
            . implode('', array_map(fn ($c) => $chip(categoryUrl($c, $categories), escape($c['name'])), $children($category))) . '</nav>';
    } else {
        $parent = $categories[$category['parent_id']];
        $nav = '<nav class="shop-categories" aria-label="Sub-categories">' . $chip(categoryUrl($parent, $categories), '&larr; ' . escape($parent['name'])) . '</nav>';
    }

    $ids = $category ? array_merge([(int) $category['id']], array_map('intval', array_keys($children($category)))) : null;
    $products = array_filter(shopProducts(), fn ($p) => $ids === null || in_array((int) $p['category_id'], $ids, true));
    $grid = $products ? '<div class="shop-grid">' . "\n" . implode('', array_map(fn ($p) => shopCard($p, $category), $products)) . '</div>'
        : '<div class="shop-empty">' . ($category ? 'No products in this category yet.' : 'No products yet.') . '</div>';

    $url = $category ? categoryUrl($category, $categories) : '{{BASE}}/shop';
    return view('templates.shop', [
        'title' => $category ? escape($category['name']) . ' | Pro HPCL Shop' : 'Shop | Pro HPCL',
        'description' => $category ? 'Shop official Pro HPCL ' . escape($category['name']) . '.' : 'Official Pro HPCL merchandise: jerseys, caps, and more.',
        'url' => $url,
        'breadcrumb_schema' => jsonLd(autoBreadcrumbSchema($crumbs)),
        'breadcrumb' => autoBreadcrumbHtml($crumbs),
        'heading' => $category ? '<h1 class="shop-title">' . escape($category['name']) . '</h1>' : '<h1 class="shop-title">Pro HPCL Shop</h1><p class="shop-subtitle">Official league merchandise.</p>',
        'category_nav' => $nav,
        'products' => $grid,
    ]);
}

function relatedCard(array $product): string {
    $discount = discountPercent($product);
    $name = escape($product['name']);
    $action = !$product['in_stock'] ? '<button type="button" class="pdp-btn pdp-btn--sm" disabled>Out of Stock</button>'
        : '<a href="' . productUrl($product) . '" class="pdp-btn pdp-btn--ghost pdp-btn--sm">' . ($product['variants'] ? 'Choose Options' : 'View Product') . '<span class="pdp-sr"> for ' . $name . '</span></a>';
    return '<article class="pdp-pcard"><a href="' . productUrl($product) . '" class="pdp-pcard-media" tabindex="-1" aria-hidden="true">'
        . '<img src="' . escape(linkUrl($product['image'])) . '" alt="" width="400" height="400" loading="lazy" decoding="async">'
        . (!$product['in_stock'] ? '<span class="pdp-pcard-badge pdp-pcard-badge--out">Out of stock</span>' : ($discount !== null ? '<span class="pdp-pcard-badge">' . $discount . '% OFF</span>' : ''))
        . '</a><button type="button" class="pdp-icon-btn pdp-pcard-wish" data-wishlist-toggle data-product-id="' . $product['id'] . '" aria-pressed="false" aria-label="Add ' . $name . ' to wishlist">' . shopIcon(ICON_HEART) . '</button>'
        . '<div class="pdp-pcard-body"><h3 class="pdp-pcard-name"><a href="' . productUrl($product) . '">' . $name . '</a></h3>'
        . ($product['reviews'] ? '<div class="pdp-pcard-rating">' . starsHtml($product['rating']) . '<span>(' . count($product['reviews']) . ')</span></div>' : '')
        . '<div class="pdp-pcard-price"><strong>' . rupees($product['price']) . '</strong>'
        . ($discount !== null ? '<s><span class="pdp-sr">Original price</span>' . rupees($product['compare_price']) . '</s><em>' . $discount . '% off</em>' : '') . '</div>'
        . '<div class="pdp-pcard-action">' . $action . "</div></div></article>\n";
}

/** /shop/{slug}. Returns null for a 404. */
function renderProductPage(string $slug): ?string {
    $product = current(array_filter(shopProducts(), fn ($p) => $p['slug'] === $slug)) ?: null;
    if (!$product) return null;
    $categories = shopCategories();
    $name = escape($product['name']);
    $url = productUrl($product);
    $discount = discountPercent($product);
    $summary = productSummary($product);
    $images = array_map('linkUrl', $product['images']);
    $count = count($images);

    $thumbs = $slides = '';
    foreach ($images as $i => $image) {
        $thumbs .= '<button type="button" class="pdp-thumb" data-pdp-thumb="' . $i . '"' . ($i === 0 ? ' aria-current="true"' : '') . ' aria-label="Show image ' . ($i + 1) . ' of ' . $count . '">'
            . '<img src="' . escape($image) . '" alt="" width="64" height="64" loading="lazy" decoding="async"></button>' . "\n";
        $slides .= '<figure class="pdp-slide " data-pdp-slide="' . $i . '" aria-roledescription="slide" aria-label="' . ($i + 1) . ' of ' . $count . '">'
            . '<button type="button" class="pdp-slide-btn" data-pdp-open="' . $i . '" tabindex="-1" aria-label="Enlarge image ' . ($i + 1) . '">'
            . '<img src="' . escape($image) . '" alt="' . ($i === 0 ? $name : $name . ' &ndash; image ' . ($i + 1)) . '" width="800" height="800"' . ($i === 0 ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async">'
            . "</button></figure>\n";
    }

    $reviews = $product['reviews'];
    $reviewCount = count($reviews);
    $reviewsLabel = $reviewCount . ' review' . ($reviewCount === 1 ? '' : 's');
    $ratingLabel = number_format($product['rating'], 1);

    // Description, specifications and shipping tabs; the first one is open.
    $panels = [];
    $description = trim((string) $product['description']);
    $features = query('SELECT feature FROM shop_product_features WHERE product_id = ? ORDER BY position', [$product['id']]);
    if ($description !== '' || $features) {
        $panels['description'] = ['Description', ($description !== '' ? '<div class="pdp-prose">' . escape($description) . '</div>' : '')
            . ($features ? '<h3 class="pdp-subhead ">Key Features</h3><ul class="pdp-features">'
                . implode('', array_map(fn ($f) => '<li>' . shopIcon(ICON_CHECK) . ' <span>' . escape($f['feature']) . '</span></li>', $features)) . '</ul>' : '')];
    }
    $specs = query('SELECT name, value FROM shop_product_specs WHERE product_id = ? ORDER BY position', [$product['id']]);
    if ($specs) {
        $panels['specifications'] = ['Specifications', '<table class="pdp-spec-table"><caption class="pdp-sr">Specifications for ' . $name . '</caption><tbody>'
            . implode('', array_map(fn ($s) => '<tr><th scope="row">' . escape($s['name']) . '</th><td>' . escape($s['value']) . '</td></tr>', $specs)) . '</tbody></table>'];
    }
    $panels['shipping'] = ['Shipping &amp; Returns', '<h3 class="pdp-subhead">Shipping</h3><dl class="pdp-dl"><dt>Shipping method</dt><dd>Home delivery</dd>'
        . '<dt>Shipping charges</dt><dd>&#8377;20 per order</dd><dt>Free shipping</dt><dd>On orders of &#8377;2,500 or more</dd></dl>'
        . '<h3 class="pdp-subhead">Returns &amp; Refunds</h3><p class="mb-0">Returns and refunds are handled under the store\'s refund policy.</p>'
        . '<p class="mt-2 mb-0"><a href="{{BASE}}/refund_policy" class="pdp-link-btn">Read the full refund policy</a></p>'];
    $tabs = $panelsHtml = '';
    $first = true;
    foreach ($panels as $key => [$label, $body]) {
        $tabs .= '<button type="button" class="pdp-tab" role="tab" id="pdp-tab-' . $key . '" aria-controls="pdp-panel-' . $key . '" aria-selected="' . ($first ? 'true' : 'false') . '" tabindex="' . ($first ? '0' : '-1') . '" data-pdp-tab="' . $key . '">' . $label . "</button>\n";
        $panelsHtml .= '<div class="pdp-panel ' . ($first ? 'is-active is-open' : '') . '" id="pdp-panel-' . $key . '" data-pdp-panel="' . $key . '" aria-labelledby="pdp-tab-' . $key . '">'
            . '<h2 class="pdp-panel-heading"><button type="button" class="pdp-acc-toggle" aria-expanded="' . ($first ? 'true' : 'false') . '" aria-controls="pdp-panel-' . $key . '-body" data-pdp-acc>' . $label . shopIcon(ICON_CHEVRON, 20) . '</button></h2>'
            . '<div class="pdp-panel-body" id="pdp-panel-' . $key . '-body">' . $body . "</div></div>\n";
        $first = false;
    }

    $summaryHtml = '';
    $reviewList = '<div class="pdp-empty">' . shopIcon(ICON_SPARK, 32, '1.5') . '<p class="m-0 fw-semibold text-dark">No reviews yet</p><p class="m-0">Bought this product? Be the first to share your thoughts.</p></div>';
    if ($reviews) {
        $summaryHtml = '<div class="pdp-rating-big"><strong>' . $ratingLabel . '</strong><span>/ 5</span></div>' . starsHtml($product['rating'])
            . '<p class="m-0 text-secondary">Based on ' . $reviewsLabel . '</p><ul class="pdp-dist" aria-label="Rating distribution">';
        foreach ([5, 4, 3, 2, 1] as $stars) {
            $n = count(array_filter($reviews, fn ($r) => (int) $r['rating'] === $stars));
            $percent = (int) round($n / $reviewCount * 100);
            $summaryHtml .= '<li><span>' . $stars . ' &#9733;</span><span class="pdp-dist-bar" role="img" aria-label="' . $stars . ' star: ' . $n . ' review' . ($n === 1 ? '' : 's') . ' (' . $percent . '%)">'
                . '<span style="width: ' . $percent . '%"></span></span><span class="pdp-dist-count" aria-hidden="true">' . $n . '</span></li>';
        }
        $summaryHtml .= '</ul>';
        $reviewList = '';
        foreach ($reviews as $review) {
            $reviewList .= '<article class="pdp-review"><div class="pdp-review-head"><div class="pdp-review-author">'
                . '<span class="pdp-avatar" aria-hidden="true">' . escape(mb_strtoupper(mb_substr($review['author_name'], 0, 1, 'UTF-8'), 'UTF-8')) . '</span><span>' . escape($review['author_name']) . '</span>'
                . ($review['verified'] ? '<span class="pdp-verified">' . shopIcon(ICON_CHECK, 12, '3') . 'Verified Purchase</span>' : '') . '</div>'
                . '<time class="pdp-review-date" datetime="' . escape($review['reviewed_on']) . '">' . date('d M Y', strtotime($review['reviewed_on'])) . '</time></div>'
                . '<h3 class="pdp-review-title">' . starsHtml((float) $review['rating']) . ($review['title'] ? '<span>' . escape($review['title']) . '</span>' : '') . '</h3>'
                . ($review['body'] ? '<p class="pdp-review-body">' . escape($review['body']) . '</p>' : '') . "</article>\n";
        }
    }

    // Other products: the same category first.
    $others = array_filter(shopProducts(), fn ($p) => $p['id'] !== $product['id']);
    usort($others, fn ($a, $b) => ((int) ($b['category_id'] === $product['category_id'])) <=> ((int) ($a['category_id'] === $product['category_id'])));
    $related = $others ? '<section class="pdp-section" aria-labelledby="pdp-related-title"><div class="pdp-section-head"><h2 class="pdp-section-title" id="pdp-related-title">You May Also Like</h2>'
        . '<a href="{{BASE}}/shop" class="pdp-link-btn">View all</a></div><div class="pdp-grid">' . implode('', array_map('relatedCard', array_slice($others, 0, 4))) . '</div></section>' : '';

    // Breadcrumb: Home > Shop > category path > product.
    $crumbs = [['label' => 'Home', 'url' => '{{BASE}}'], ['label' => 'Shop', 'url' => '{{BASE}}/shop']];
    $category = $product['category'];
    $categoryNames = [];
    if ($category && $category['parent_id'] && isset($categories[$category['parent_id']])) {
        $crumbs[] = ['label' => $categories[$category['parent_id']]['name'], 'url' => categoryUrl($categories[$category['parent_id']], $categories)];
        $categoryNames[] = $categories[$category['parent_id']]['name'];
    }
    if ($category) {
        $crumbs[] = ['label' => $category['name'], 'url' => categoryUrl($category, $categories)];
        $categoryNames[] = $category['name'];
    }
    $crumbs[] = ['label' => $product['name'], 'url' => $url];

    $schema = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $product['name'], 'description' => strLimit($summary, 155), 'url' => $url,
        'sku' => 'PROHPCL-' . $product['id'], 'brand' => ['@type' => 'Brand', 'name' => $product['brand'] ?: 'Pro HPCL'], 'category' => implode(' > ', $categoryNames)];
    if ($product['colours']) $schema['color'] = implode(', ', array_column($product['colours'], 'name'));
    $schema['image'] = $images;
    $schema['offers'] = ['@type' => 'Offer', 'url' => $url, 'priceCurrency' => 'INR', 'price' => number_format((float) $product['price'], 2, '.', ''),
        'availability' => 'https://schema.org/' . ($product['in_stock'] ? 'InStock' : 'OutOfStock'), 'itemCondition' => 'https://schema.org/NewCondition', 'seller' => ['@type' => 'Organization', 'name' => 'Pro HPCL']];
    if ($reviews) {
        $schema['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => jsonNumber(round($product['rating'], 1)), 'reviewCount' => $reviewCount, 'bestRating' => 5, 'worstRating' => 1];
        $schema['review'] = array_map(fn ($r) => ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $r['author_name']], 'datePublished' => $r['reviewed_on'], 'name' => $r['title'],
            'reviewBody' => $r['body'], 'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) $r['rating'], 'bestRating' => 5, 'worstRating' => 1]], $reviews);
    }

    $config = ['productName' => $product['name'], 'cartUrl' => '{{BASE}}/shop',
        'media' => array_map(fn ($image, $i) => ['type' => 'image', 'src' => $image, 'thumb' => $image, 'alt' => $i === 0 ? $product['name'] : $product['name'] . " \u{2013} image " . ($i + 1), 'color' => null, 'embed' => null, 'external' => false], $images, array_keys($images)),
        'hasVariants' => (bool) $product['variants'], 'inStock' => $product['in_stock'], 'simpleStock' => $product['variants'] ? null : (int) $product['stock'],
        'lowStock' => SHOP_LOW_STOCK, 'maxPerLine' => SHOP_MAX_PER_LINE,
        'variants' => array_map(fn ($v) => ['color' => $v['colour_id'] === null ? null : (int) $v['colour_id'], 'size' => $v['size'], 'stock' => (int) $v['stock']], $product['variants'])];

    // Share image size and type, read from the stored file.
    $file = __DIR__ . '/../' . $product['image'];
    $size = !preg_match('~^https?://~', $product['image']) && is_file($file) ? @getimagesize($file) : false;

    return view('templates.shop-product', [
        'title' => $name . ' | Pro HPCL Shop', 'description' => escape(strLimit($summary, 155)), 'url' => $url, 'name' => $name, 'id' => $product['id'],
        'image' => escape($images[0]), 'image_type' => $size['mime'] ?? 'image/jpeg', 'image_width' => $size[0] ?? 800, 'image_height' => $size[1] ?? 800,
        'price_amount' => number_format((float) $product['price'], 2, '.', ''), 'availability' => $product['in_stock'] ? 'in stock' : 'out of stock', 'brand' => escape($product['brand'] ?: 'Pro HPCL'),
        'product_schema' => jsonLd($schema), 'breadcrumb_schema' => jsonLd(autoBreadcrumbSchema($crumbs)), 'breadcrumb' => autoBreadcrumbHtml($crumbs),
        'thumbs' => $thumbs, 'slides' => $slides, 'media_count' => $count,
        'stage_badge' => $discount !== null ? '<span class="pdp-stage-badge">' . $discount . '% OFF</span>' : '',
        'rating_link' => starsHtml($product['rating']) . ($reviews ? '<strong>' . $ratingLabel . '</strong><span class="pdp-divider" aria-hidden="true"></span><span>' . $reviewsLabel . '</span>' : '<span>No reviews yet</span>'),
        'price' => rupees($product['price']),
        'compare_price' => $discount !== null ? '<span class="pdp-sr">Original price</span><s class="pdp-price-compare">' . rupees($product['compare_price']) . '</s><span class="pdp-discount">' . $discount . '% OFF</span>' : '',
        'price_note' => 'Inclusive of all taxes' . ($product['gst_rate'] !== null ? ' (GST ' . jsonNumber($product['gst_rate']) . '%)' : '')
            . ($discount !== null ? ' &middot; <strong>You save ' . rupees((float) $product['compare_price'] - (float) $product['price']) . '</strong>' : ''),
        'stock' => '<span class="pdp-stock pdp-stock--' . ($product['in_stock'] ? 'ok' : 'out') . '" data-pdp-stock aria-live="polite">' . shopIcon($product['in_stock'] ? ICON_CHECK : ICON_X, 16)
            . '<span data-pdp-stock-text>' . ($product['in_stock'] ? 'In stock' : 'Out of stock') . '</span></span>',
        'short_description' => $summary !== '' ? '<p class="pdp-short">' . escape($summary) . '</p>' : '',
        'tabs' => $tabs, 'panels' => $panelsHtml, 'rating_summary' => $summaryHtml, 'reviews' => $reviewList, 'related' => $related,
        'sticky' => $product['in_stock'] ? '<div class="pdp-sticky" data-pdp-sticky aria-hidden="true"><div class="pdp-sticky-price"><strong>' . rupees($product['price']) . '</strong>'
            . ($discount !== null ? '<s>' . rupees($product['compare_price']) . '</s>' : '') . '</div>'
            . '<button type="submit" form="pdp-form" class="pdp-btn pdp-btn--primary" data-pdp-add tabindex="-1"><span data-pdp-btn-label>Add to Cart</span></button>'
            . '<button type="submit" form="pdp-form" class="pdp-btn pdp-btn--dark" name="buy_now" value="1" data-pdp-buy tabindex="-1">Buy Now</button></div>' : '',
        'config' => jsonLd($config),
    ]);
}
