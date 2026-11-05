<?php
declare(strict_types=1);

// The public shop: the listing (/shop, /shop/category/{slug}[/{slug}]) and product pages (/shop/{slug}),
// rendered from the shop_* tables. Only active products in active categories are public; the admin
// (includes/admin-shop.php) asks for everything with $includeInactive. The cart is includes/shop-cart.php
// and the checkout is includes/shop-checkout.php.
require_once __DIR__ . '/content.php';

const SHOP_LOW_STOCK = 5;
const SHOP_MAX_PER_LINE = 20;
const SHOP_SORTS = ['featured' => 'Featured', 'newest' => 'Newest', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low'];

/** ₹ amount: whole rupees without decimals, with thousands separators. */
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
    $parent = $category['parent_id'] ? ($categories[$category['parent_id']] ?? null) : null;
    return '{{BASE}}/shop/category/' . ($parent ? rawurlencode($parent['slug']) . '/' : '') . rawurlencode($category['slug']);
}
/** Star rating: the filled stars cover $rating / 5 of the row. */
function starsHtml(float $rating): string {
    $label = rtrim(rtrim(number_format($rating, 1), '0'), '.');
    $stars = '&#9733;&#9733;&#9733;&#9733;&#9733;';
    return '<span class="shop-stars" role="img" aria-label="Rated ' . $label . ' out of 5"><span class="shop-stars__base" aria-hidden="true">' . $stars . '</span>'
        . '<span class="shop-stars__fill" style="width: ' . rtrim(rtrim(number_format($rating / 5 * 100, 2), '0'), '.') . '%" aria-hidden="true">' . $stars . '</span></span>';
}

/** A shop_* row of site_settings (shop_shipping_fee, shop_free_shipping_over, shop_notify_email, shop_support_phone, shop_enabled), or $default when unset or empty. */
function shopSetting(string $name, string $default = ''): string {
    static $all = null;
    $all ??= array_column(query("SELECT name, value FROM site_settings WHERE name LIKE 'shop\\_%'"), 'value', 'name');
    return isset($all[$name]) && trim((string) $all[$name]) !== '' ? trim((string) $all[$name]) : $default;
}

/** Categories by id, in display order. The public sees only active ones (and a child needs an active parent). */
function shopCategories(bool $includeInactive = false): array {
    static $cache = [];
    if (isset($cache[(int) $includeInactive])) return $cache[(int) $includeInactive];
    $all = array_column(query('SELECT * FROM shop_categories ORDER BY position, name'), null, 'id');
    if (!$includeInactive) {
        $all = array_filter($all, fn ($c) => (int) $c['is_active'] === 1 && (!$c['parent_id'] || ((int) ($all[$c['parent_id']]['is_active'] ?? 0) === 1)));
    }
    return $cache[(int) $includeInactive] = $all;
}

/**
 * Products with their category, colours, variants (shop_product_variants rows incl. id), gallery and
 * ratings, in display order. The public sees only active products whose category is active.
 */
function shopProducts(bool $includeInactive = false): array {
    static $cache = [];
    if (isset($cache[(int) $includeInactive])) return $cache[(int) $includeInactive];
    $categories = shopCategories($includeInactive);
    $products = [];
    foreach (query('SELECT * FROM shop_products ORDER BY position, id') as $product) {
        if (!$includeInactive && ((int) $product['is_active'] !== 1 || ($product['category_id'] && !isset($categories[$product['category_id']])))) continue;
        $product += ['colours' => [], 'variants' => [], 'images' => [], 'reviews' => []];
        if ($product['image'] !== '') $product['images'][] = $product['image'];
        $products[$product['id']] = $product;
    }
    foreach (query('SELECT * FROM shop_product_colours ORDER BY position, id') as $row) if (isset($products[$row['product_id']])) $products[$row['product_id']]['colours'][$row['id']] = $row;
    foreach (query('SELECT * FROM shop_product_variants ORDER BY id') as $row) if (isset($products[$row['product_id']])) $products[$row['product_id']]['variants'][$row['id']] = $row;
    foreach (query('SELECT * FROM shop_product_images ORDER BY position') as $row) {
        if (isset($products[$row['product_id']]) && !in_array($row['image'], $products[$row['product_id']]['images'], true)) $products[$row['product_id']]['images'][] = $row['image'];
    }
    foreach (query('SELECT * FROM shop_reviews ORDER BY reviewed_on DESC, id DESC') as $row) if (isset($products[$row['product_id']])) $products[$row['product_id']]['reviews'][] = $row;
    foreach ($products as &$product) {
        $stock = $product['variants'] ? array_sum(array_column($product['variants'], 'stock')) : (int) $product['stock'];
        $product['total_stock'] = $stock;
        $product['in_stock'] = $stock > 0;
        $product['rating'] = $product['reviews'] ? array_sum(array_column($product['reviews'], 'rating')) / count($product['reviews']) : 0;
        $product['category'] = $categories[$product['category_id']] ?? null;
    }
    return $cache[(int) $includeInactive] = $products;
}

/** The product with this id (active ones only unless $includeInactive), or null. */
function shopProduct(int $id, bool $includeInactive = false): ?array {
    return shopProducts($includeInactive)[$id] ?? null;
}

/** "Navy / L": the colour and size of a variant row, or '' when it has neither. */
function shopVariantLabel(array $product, ?array $variant): string {
    if (!$variant) return '';
    $colour = $variant['colour_id'] ? ($product['colours'][$variant['colour_id']]['name'] ?? '') : '';
    return implode(' / ', array_filter([$colour, (string) $variant['size']], fn ($part) => $part !== ''));
}

/** Text under the title: the short description, or the start of the description. */
function productSummary(array $product): string {
    return trim((string) $product['short_description']) !== '' ? trim($product['short_description']) : strLimit(trim((string) $product['description']), 200);
}

/** The session's flash message for the shop ([type, text]) shown once as a banner, or ''. */
function shopFlashHtml(): string {
    $flash = $_SESSION['shop_flash'] ?? null;
    unset($_SESSION['shop_flash']);
    return is_array($flash) ? '<div class="shop-flash shop-flash--' . ($flash[0] === 'error' ? 'error' : 'ok') . '" role="' . ($flash[0] === 'error' ? 'alert' : 'status') . '">' . escape($flash[1]) . '</div>' : '';
}

function shopCard(array $product, ?array $current = null): string {
    $discount = discountPercent($product);
    $swatches = '';
    if ($product['colours']) {
        $swatches = '<span class="shop-card__swatches" aria-label="' . count($product['colours']) . ' colour' . (count($product['colours']) === 1 ? '' : 's') . ': ' . escape(implode(', ', array_column($product['colours'], 'name'))) . '">'
            . implode('', array_map(fn ($colour) => '<span class="shop-swatch" style="background: ' . escape($colour['hex']) . '" aria-hidden="true"></span>', $product['colours'])) . '</span>';
    }
    $badge = !$product['in_stock'] ? '<span class="shop-badge shop-badge--out">Sold out</span>' : ($discount !== null ? '<span class="shop-badge shop-badge--sale">' . $discount . '% off</span>' : '');
    return '<li class="shop-card"><a href="' . productUrl($product) . '" class="shop-card__link">'
        . '<span class="shop-card__media"><img src="' . escape(linkUrl($product['image'])) . '" alt="" width="400" height="400" loading="lazy" decoding="async">' . $badge . '</span>'
        . '<span class="shop-card__body">'
        . ($product['category'] && (int) $product['category_id'] !== (int) ($current['id'] ?? 0) ? '<span class="shop-card__category">' . escape($product['category']['name']) . '</span>' : '')
        . '<h2 class="shop-card__name">' . escape($product['name']) . '</h2>'
        . '<span class="shop-price"><strong>' . rupees($product['price']) . '</strong>' . ($discount !== null ? '<s><span class="visually-hidden">Original price </span>' . rupees($product['compare_price']) . '</s>' : '') . '</span>'
        . $swatches . '</span></a></li>' . "\n";
}

/** /shop and its category pages (?sort=featured|newest|price_asc|price_desc). Returns null for a 404. */
function renderShopIndex(?string $parentSlug = null, ?string $childSlug = null): ?string {
    require_once __DIR__ . '/shop-cart.php';
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

    $chip = fn (string $url, string $label, bool $active = false) => '<a href="' . $url . '" class="shop-chip' . ($active ? ' is-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    $top = array_filter($categories, fn ($c) => !$c['parent_id']);
    if (!$category) {
        $chips = $chip('{{BASE}}/shop', 'All', true) . implode('', array_map(fn ($c) => $chip(categoryUrl($c, $categories), escape($c['name'])), $top));
    } elseif (!$category['parent_id']) {
        $chips = $chip('{{BASE}}/shop', 'All') . implode('', array_map(fn ($c) => $chip(categoryUrl($c, $categories), escape($c['name']), (int) $c['id'] === (int) $category['id']), $top))
            . implode('', array_map(fn ($c) => $chip(categoryUrl($c, $categories), escape($c['name'])), $children($category)));
    } else {
        $parent = $categories[$category['parent_id']];
        $chips = $chip('{{BASE}}/shop', 'All') . $chip(categoryUrl($parent, $categories), escape($parent['name']))
            . implode('', array_map(fn ($c) => $chip(categoryUrl($c, $categories), escape($c['name']), (int) $c['id'] === (int) $category['id']), $children($parent)));
    }

    $ids = $category ? array_merge([(int) $category['id']], array_map('intval', array_keys($children($category)))) : null;
    $products = array_filter(shopProducts(), fn ($p) => $ids === null || in_array((int) $p['category_id'], $ids, true));
    $sort = (string) ($_GET['sort'] ?? 'featured');
    if (!isset(SHOP_SORTS[$sort])) $sort = 'featured';
    if ($sort === 'newest') usort($products, fn ($a, $b) => [$b['created_at'] ?? '', $b['id']] <=> [$a['created_at'] ?? '', $a['id']]);
    elseif ($sort === 'price_asc') usort($products, fn ($a, $b) => (float) $a['price'] <=> (float) $b['price']);
    elseif ($sort === 'price_desc') usort($products, fn ($a, $b) => (float) $b['price'] <=> (float) $a['price']);

    $url = $category ? categoryUrl($category, $categories) : '{{BASE}}/shop';
    $sorts = '';
    foreach (SHOP_SORTS as $key => $label) {
        $sorts .= '<a href="' . $url . ($key === 'featured' ? '' : '?sort=' . $key) . '" class="shop-sort__link' . ($key === $sort ? ' is-active' : '') . '"' . ($key === $sort ? ' aria-current="true"' : '') . '>' . $label . '</a>';
    }
    $grid = $products ? '<ul class="shop-grid" aria-label="Products">' . "\n" . implode('', array_map(fn ($p) => shopCard($p, $category), $products)) . '</ul>'
        : '<div class="shop-empty"><p class="shop-empty__title">Products are coming soon</p>'
            . '<p>' . ($category ? 'There is nothing in this category yet.' : 'The official Pro HPCL range is being stocked. Please check back shortly.') . '</p>'
            . ($category ? '<a class="shop-btn shop-btn--ghost" href="{{BASE}}/shop">View all products</a>' : '') . '</div>';

    $session = shopCartSessionStart(false);
    return view('templates.shop', [
        'title' => $category ? escape($category['name']) . ' | Pro HPCL Shop' : 'Shop | Pro HPCL',
        'description' => $category ? 'Shop official Pro HPCL ' . escape($category['name']) . ' online.' : 'The official Pro HPCL shop: team jerseys, t-shirts, caps, accessories and cricket gear.',
        'url' => $url,
        'breadcrumb_schema' => jsonLd(autoBreadcrumbSchema($crumbs)),
        'breadcrumb' => autoBreadcrumbHtml($crumbs),
        'heading' => escape($category ? $category['name'] : 'Pro HPCL Shop'),
        'subheading' => $category ? '' : 'Official league merchandise: jerseys, caps, accessories and cricket gear.',
        'flash' => $session ? shopFlashHtml() : '',
        'category_nav' => $chips,
        'sort_nav' => $products ? $sorts : '',
        'product_count' => $products ? count($products) . ' product' . (count($products) === 1 ? '' : 's') : '',
        'products' => $grid,
        'css_version' => SHOP_ASSET_VERSION,
    ]);
}

/** "You may also like": other products, the same category first. */
function shopRelated(array $product): string {
    $others = array_filter(shopProducts(), fn ($p) => $p['id'] !== $product['id']);
    usort($others, fn ($a, $b) => ((int) ($b['category_id'] === $product['category_id'])) <=> ((int) ($a['category_id'] === $product['category_id'])));
    if (!$others) return '';
    return '<section class="shop-section" aria-labelledby="shop-related"><div class="shop-section__head"><h2 id="shop-related">You may also like</h2><a href="{{BASE}}/shop" class="shop-link">View all</a></div>'
        . '<ul class="shop-grid shop-grid--four">' . implode('', array_map(fn ($p) => shopCard($p), array_slice($others, 0, 4))) . '</ul></section>';
}

/** /shop/{slug}. Returns null for a 404. */
function renderProductPage(string $slug): ?string {
    require_once __DIR__ . '/shop-cart.php';
    $product = current(array_filter(shopProducts(), fn ($p) => $p['slug'] === $slug)) ?: null;
    if (!$product) return null;
    $categories = shopCategories();
    $name = escape($product['name']);
    $url = productUrl($product);
    $discount = discountPercent($product);
    $summary = productSummary($product);
    $images = array_map('linkUrl', $product['images'] ?: [$product['image']]);
    $count = count($images);

    $thumbs = '';
    if ($count > 1) {
        foreach ($images as $i => $image) {
            $thumbs .= '<li><button type="button" class="shop-thumb" data-shop-thumb="' . escape($image) . '" data-alt="' . ($i === 0 ? $name : $name . ' &ndash; image ' . ($i + 1)) . '"' . ($i === 0 ? ' aria-current="true"' : '') . ' aria-label="Show image ' . ($i + 1) . ' of ' . $count . '">'
                . '<img src="' . escape($image) . '" alt="" width="72" height="72" loading="lazy" decoding="async"></button></li>';
        }
    }

    // Options: colours and sizes drive which variant (shop_product_variants row) is bought.
    $variants = array_values($product['variants']);
    $firstAvailable = null;
    foreach ($variants as $variant) if ((int) $variant['stock'] > 0) { $firstAvailable = $variant; break; }
    $hasColours = (bool) $product['colours'];
    $sizes = array_values(array_unique(array_filter(array_column($variants, 'size'), fn ($s) => $s !== '')));
    $options = '';
    if ($hasColours) {
        $options .= '<fieldset class="shop-option"><legend>Colour: <strong data-shop-colour-name>' . escape($firstAvailable ? ($product['colours'][$firstAvailable['colour_id']]['name'] ?? '') : '') . '</strong></legend><div class="shop-option__row">';
        foreach ($product['colours'] as $colour) {
            $checked = $firstAvailable && (int) $firstAvailable['colour_id'] === (int) $colour['id'];
            $options .= '<label class="shop-colour"><input type="radio" name="colour_id" value="' . (int) $colour['id'] . '" data-name="' . escape($colour['name']) . '"' . ($checked ? ' checked' : '') . '>'
                . '<span class="shop-colour__dot" style="background: ' . escape($colour['hex']) . '"></span><span class="visually-hidden">' . escape($colour['name']) . '</span></label>';
        }
        $options .= '</div></fieldset>';
    }
    if ($sizes) {
        $options .= '<fieldset class="shop-option"><legend>Size</legend><div class="shop-option__row">';
        foreach ($sizes as $size) {
            $checked = $firstAvailable && (string) $firstAvailable['size'] === (string) $size;
            $options .= '<label class="shop-size"><input type="radio" name="size" value="' . escape($size) . '"' . ($checked ? ' checked' : '') . '><span>' . escape($size) . '</span></label>';
        }
        $options .= '</div></fieldset>';
    }
    $maxQty = $product['variants'] ? ($firstAvailable ? min((int) $firstAvailable['stock'], SHOP_MAX_PER_LINE) : 0) : min((int) $product['stock'], SHOP_MAX_PER_LINE);
    $currentStock = $product['variants'] ? (int) ($firstAvailable['stock'] ?? 0) : (int) $product['stock'];
    $stockText = $currentStock <= 0 ? 'Out of stock' : ($currentStock <= SHOP_LOW_STOCK ? 'Only ' . $currentStock . ' left' : 'In stock');
    $stockClass = $currentStock <= 0 ? 'out' : ($currentStock <= SHOP_LOW_STOCK ? 'low' : 'ok');
    $variantData = array_map(fn ($v) => ['id' => (int) $v['id'], 'colour' => $v['colour_id'] === null ? 0 : (int) $v['colour_id'], 'size' => (string) $v['size'], 'stock' => (int) $v['stock']], $variants);

    $session = shopCartSessionStart();
    $addForm = '<form class="shop-buy" method="post" action="{{BASE}}/shop/cart/add" data-shop-add data-low="' . SHOP_LOW_STOCK . '" data-max="' . SHOP_MAX_PER_LINE . '" data-stock="' . (int) $product['stock'] . '" novalidate>'
        . '<input type="hidden" name="csrf_token" value="' . escape(accountCsrfToken()) . '"><input type="hidden" name="product_id" value="' . (int) $product['id'] . '">'
        . $options
        . '<div class="shop-buy__row"><div class="shop-qty"><label for="shop-qty">Quantity</label>'
        . '<div class="shop-qty__box"><button type="button" class="shop-qty__btn" data-qty-step="-1" aria-label="Decrease quantity">&minus;</button>'
        . '<input id="shop-qty" name="qty" type="number" inputmode="numeric" min="1" max="' . max(1, $maxQty) . '" value="1" data-shop-qty>'
        . '<button type="button" class="shop-qty__btn" data-qty-step="1" aria-label="Increase quantity">+</button></div></div>'
        . '<button type="submit" class="shop-btn shop-btn--primary shop-buy__add" data-shop-submit' . ($currentStock <= 0 ? ' disabled' : '') . '>' . ($currentStock <= 0 ? 'Out of stock' : 'Add to cart') . '</button></div>'
        . '<p class="shop-buy__msg" data-shop-msg role="status" aria-live="polite"></p></form>';

    // Description, features, specifications and shipping.
    $sections = '';
    $description = trim((string) $product['description']);
    $features = query('SELECT feature FROM shop_product_features WHERE product_id = ? ORDER BY position', [$product['id']]);
    if ($description !== '') $sections .= '<section class="shop-info"><h2>About this product</h2><div class="shop-prose">' . nl2br(escape($description)) . '</div></section>';
    if ($features) $sections .= '<section class="shop-info"><h2>Key features</h2><ul class="shop-features">' . implode('', array_map(fn ($f) => '<li>' . escape($f['feature']) . '</li>', $features)) . '</ul></section>';
    $specs = query('SELECT name, value FROM shop_product_specs WHERE product_id = ? ORDER BY position', [$product['id']]);
    if ($specs) $sections .= '<section class="shop-info"><h2>Specifications</h2><table class="shop-specs"><tbody>' . implode('', array_map(fn ($s) => '<tr><th scope="row">' . escape($s['name']) . '</th><td>' . escape($s['value']) . '</td></tr>', $specs)) . '</tbody></table></section>';
    $fee = (float) shopSetting('shop_shipping_fee', '60');
    $free = (float) shopSetting('shop_free_shipping_over', '0');
    $sections .= '<section class="shop-info"><h2>Shipping &amp; returns</h2><ul class="shop-features"><li>Delivery across India. Shipping is ' . ($fee > 0 ? rupees($fee) . ' per order' : 'free')
        . ($fee > 0 && $free > 0 ? ', free on orders of ' . rupees($free) . ' or more' : '') . '.</li><li>All prices include GST.</li><li>Returns and refunds follow our <a href="{{BASE}}/refund_policy">refund policy</a>.</li></ul></section>';

    $reviews = $product['reviews'];
    $reviewCount = count($reviews);
    if ($reviews) {
        $sections .= '<section class="shop-info"><h2>Reviews</h2><p class="shop-rating-line">' . starsHtml($product['rating']) . ' <strong>' . number_format($product['rating'], 1) . '</strong> from ' . $reviewCount . ' review' . ($reviewCount === 1 ? '' : 's') . '</p>';
        foreach (array_slice($reviews, 0, 5) as $review) {
            $sections .= '<article class="shop-review"><p class="shop-review__head"><strong>' . escape($review['author_name']) . '</strong>' . ($review['verified'] ? ' <span class="shop-review__tag">Verified purchase</span>' : '') . ' ' . starsHtml((float) $review['rating']) . '</p>'
                . ($review['title'] ? '<p class="shop-review__title">' . escape($review['title']) . '</p>' : '') . ($review['body'] ? '<p>' . escape($review['body']) . '</p>' : '') . '</article>';
        }
        $sections .= '</section>';
    }

    // Breadcrumb: Home > Shop > category path > product.
    $crumbs = [['label' => 'Home', 'url' => '{{BASE}}'], ['label' => 'Shop', 'url' => '{{BASE}}/shop']];
    $category = $product['category'];
    $categoryNames = [];
    if ($category && $category['parent_id'] && isset($categories[$category['parent_id']])) {
        $crumbs[] = ['label' => $categories[$category['parent_id']]['name'], 'url' => categoryUrl($categories[$category['parent_id']], $categories)];
        $categoryNames[] = $categories[$category['parent_id']]['name'];
    }
    if ($category) { $crumbs[] = ['label' => $category['name'], 'url' => categoryUrl($category, $categories)]; $categoryNames[] = $category['name']; }
    $crumbs[] = ['label' => $product['name'], 'url' => $url];

    $schema = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $product['name'], 'description' => strLimit($summary !== '' ? $summary : $product['name'], 155), 'url' => $url,
        'sku' => 'PROHPCL-' . $product['id'], 'brand' => ['@type' => 'Brand', 'name' => $product['brand'] ?: 'Pro HPCL'], 'category' => implode(' > ', $categoryNames), 'image' => $images];
    if ($product['colours']) $schema['color'] = implode(', ', array_column($product['colours'], 'name'));
    $schema['offers'] = ['@type' => 'Offer', 'url' => $url, 'priceCurrency' => 'INR', 'price' => number_format((float) $product['price'], 2, '.', ''),
        'availability' => 'https://schema.org/' . ($product['in_stock'] ? 'InStock' : 'OutOfStock'), 'itemCondition' => 'https://schema.org/NewCondition', 'seller' => ['@type' => 'Organization', 'name' => 'Pro HPCL']];
    if ($reviews) {
        $schema['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => jsonNumber(round($product['rating'], 1)), 'reviewCount' => $reviewCount, 'bestRating' => 5, 'worstRating' => 1];
    }

    // Share image size and type, read from the stored file.
    $file = __DIR__ . '/../' . $product['image'];
    $size = !preg_match('~^https?://~', $product['image']) && is_file($file) ? @getimagesize($file) : false;

    return view('templates.shop-product', [
        'title' => $name . ' | Pro HPCL Shop', 'description' => escape(strLimit($summary !== '' ? $summary : $product['name'], 155)), 'url' => $url, 'name' => $name,
        'image' => escape($images[0]), 'image_type' => $size['mime'] ?? 'image/jpeg', 'image_width' => $size[0] ?? 800, 'image_height' => $size[1] ?? 800,
        'price_amount' => number_format((float) $product['price'], 2, '.', ''), 'availability' => $product['in_stock'] ? 'in stock' : 'out of stock', 'brand' => escape($product['brand'] ?: 'Pro HPCL'),
        'product_schema' => jsonLd($schema), 'breadcrumb_schema' => jsonLd(autoBreadcrumbSchema($crumbs)), 'breadcrumb' => autoBreadcrumbHtml($crumbs),
        'main_image' => escape($images[0]), 'thumbs' => $thumbs,
        'badge' => !$product['in_stock'] ? '<span class="shop-badge shop-badge--out">Sold out</span>' : ($discount !== null ? '<span class="shop-badge shop-badge--sale">' . $discount . '% off</span>' : ''),
        'rating' => $reviews ? '<p class="shop-rating-line">' . starsHtml($product['rating']) . ' <span>' . number_format($product['rating'], 1) . ' (' . $reviewCount . ')</span></p>' : '',
        'price' => rupees($product['price']),
        'compare' => $discount !== null ? '<s><span class="visually-hidden">Original price </span>' . rupees($product['compare_price']) . '</s><span class="shop-save">Save ' . rupees((float) $product['compare_price'] - (float) $product['price']) . '</span>' : '',
        'price_note' => 'Inclusive of GST. Shipping calculated in the cart.',
        'stock_text' => $stockText, 'stock_class' => $stockClass,
        'short_description' => $summary !== '' ? '<p class="shop-short">' . escape($summary) . '</p>' : '',
        'add_form' => $addForm, 'sections' => $sections, 'related' => shopRelated($product),
        'flash' => $session ? shopFlashHtml() : '',
        'variant_json' => jsonLd($variantData),
        'low_stock' => SHOP_LOW_STOCK, 'max_per_line' => SHOP_MAX_PER_LINE, 'css_version' => SHOP_ASSET_VERSION,
    ]);
}
