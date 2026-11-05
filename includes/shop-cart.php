<?php
declare(strict_types=1);

// The shop cart. It lives in the visitor's session ($_SESSION['shop_cart'], the same session as the user
// account) as lineKey => quantity, where lineKey is "{productId}:{variantId}" and variantId is 0 for a product
// without variants. Prices, names and stock are always read from the database again: nothing about a
// product is trusted from the browser.
//
//   shopCartLines(): array            validated lines (see below), dropping what is gone, inactive or sold out
//   shopCartTotals(?array $lines)     ['subtotal','shipping','total','count','free_over','to_free']
//   shopCartCount(): int              units in the cart, cheap (for the header badge)
//   shopCartClear(): void             empties the cart (the checkout does this after payment)
//   renderShopCart(), handleShopCartAction($action)   the /shop/cart page and its POSTs
// CSRF is the account session's token (accountCsrfToken() / accountCsrfValid() in account.php).
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/shop.php';

const SHOP_ASSET_VERSION = '20261105a';

/**
 * Starts the session the cart and its CSRF token live in. With $create = false it only does so when the
 * visitor already has the session cookie, so pages that don't need it never set one. Returns whether a
 * session is active.
 */
function shopCartSessionStart(bool $create = true): bool {
    if (session_status() === PHP_SESSION_ACTIVE) return true;
    if (!$create && !isset($_COOKIE[accountHttps() ? '__Host-user' : 'prohpcl_user'])) return false;
    accountStartSession();
    return true;
}

/** Units in the cart as stored (not re-validated against the catalogue). */
function shopCartCount(): int {
    if (!shopCartSessionStart(false)) return 0;
    $cart = $_SESSION['shop_cart'] ?? [];
    return is_array($cart) ? (int) array_sum(array_map('intval', $cart)) : 0;
}

function shopCartClear(): void {
    shopCartSessionStart();
    unset($_SESSION['shop_cart']);
    shopCartLinesCache(null, true);
}

/** Request-level cache of shopCartLines(), cleared after the cart changes. */
function shopCartLinesCache(?array $set = null, bool $reset = false): ?array {
    static $cache = null;
    if ($reset) $cache = null;
    if ($set !== null) $cache = $set;
    return $cache;
}

/**
 * The cart's lines, each: key, product, variant (row|null), variant_label ("Navy / L"), qty, unit_price,
 * line_total, image, max_qty. Lines whose product or variant is gone, inactive or out of stock are dropped
 * and quantities above the stock are lowered; the session is corrected and a notice is flashed.
 */
function shopCartLines(): array {
    if (($cached = shopCartLinesCache()) !== null) return $cached;
    if (!shopCartSessionStart(false)) return shopCartLinesCache([]);
    $stored = $_SESSION['shop_cart'] ?? [];
    if (!is_array($stored) || !$stored) return shopCartLinesCache([]);
    $lines = [];
    $clean = [];
    $adjusted = false;
    foreach ($stored as $key => $qty) {
        [$productId, $variantId] = array_map('intval', array_pad(explode(':', (string) $key, 2), 2, '0'));
        $product = shopProduct($productId);
        if (!$product) { $adjusted = true; continue; }
        $variant = null;
        if ($product['variants']) {
            $variant = $product['variants'][$variantId] ?? null;
            if (!$variant) { $adjusted = true; continue; }
            $stock = (int) $variant['stock'];
        } else {
            if ($variantId !== 0) { $adjusted = true; continue; }
            $stock = (int) $product['stock'];
        }
        if ($stock <= 0) { $adjusted = true; continue; }
        $max = min($stock, SHOP_MAX_PER_LINE);
        $quantity = max(1, min((int) $qty, $max));
        if ($quantity !== (int) $qty) $adjusted = true;
        $price = (float) $product['price'];
        $lines[] = ['key' => (string) $key, 'product' => $product, 'variant' => $variant, 'variant_label' => shopVariantLabel($product, $variant), 'qty' => $quantity,
            'unit_price' => $price, 'line_total' => round($price * $quantity, 2), 'image' => $product['image'], 'max_qty' => $max];
        $clean[(string) $key] = $quantity;
    }
    if ($adjusted) {
        $_SESSION['shop_cart'] = $clean;
        $_SESSION['shop_flash'] = ['error', 'Some items in your cart changed (stock or availability), so it was updated.'];
    }
    return shopCartLinesCache($lines);
}

/** Subtotal, shipping and total in rupees; shipping is flat unless the subtotal reaches shop_free_shipping_over. */
function shopCartTotals(?array $lines = null): array {
    $lines ??= shopCartLines();
    $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
    $count = (int) array_sum(array_column($lines, 'qty'));
    $fee = max(0.0, (float) shopSetting('shop_shipping_fee', '60'));
    $freeOver = (float) shopSetting('shop_free_shipping_over', '0');
    $freeOver = $freeOver > 0 ? $freeOver : null;
    $shipping = !$lines || ($freeOver !== null && $subtotal >= $freeOver) ? 0.0 : $fee;
    return ['subtotal' => $subtotal, 'shipping' => $shipping, 'total' => round($subtotal + $shipping, 2), 'count' => $count,
        'free_over' => $freeOver, 'to_free' => $freeOver !== null && $lines && $subtotal < $freeOver && $fee > 0 ? round($freeOver - $subtotal, 2) : null];
}

/** One cart line as a row: picture, name and option, quantity form, line total, remove. */
function shopCartLineHtml(array $line, string $csrf): string {
    $product = $line['product'];
    $key = escape($line['key']);
    $name = escape($product['name']);
    return '<li class="shop-line"><a class="shop-line__media" href="' . productUrl($product) . '" tabindex="-1" aria-hidden="true"><img src="' . escape(linkUrl($line['image'])) . '" alt="" width="96" height="96" loading="lazy" decoding="async"></a>'
        . '<div class="shop-line__main"><h2 class="shop-line__name"><a href="' . productUrl($product) . '">' . $name . '</a></h2>'
        . ($line['variant_label'] !== '' ? '<p class="shop-line__option">' . escape($line['variant_label']) . '</p>' : '')
        . '<p class="shop-line__unit">' . rupees($line['unit_price']) . ' each</p>'
        . '<div class="shop-line__actions">'
        . '<form method="post" action="{{BASE}}/shop/cart/update" class="shop-qty__form" data-shop-cart-form><input type="hidden" name="csrf_token" value="' . escape($csrf) . '"><input type="hidden" name="key" value="' . $key . '">'
        . '<div class="shop-qty"><label class="visually-hidden" for="qty-' . $key . '">Quantity of ' . $name . '</label><div class="shop-qty__box">'
        . '<button type="button" class="shop-qty__btn" data-qty-step="-1" aria-label="Decrease quantity">&minus;</button>'
        . '<input id="qty-' . $key . '" name="qty" type="number" inputmode="numeric" min="1" max="' . (int) $line['max_qty'] . '" value="' . (int) $line['qty'] . '" data-shop-qty>'
        . '<button type="button" class="shop-qty__btn" data-qty-step="1" aria-label="Increase quantity">+</button></div></div>'
        . '<button type="submit" class="shop-link shop-link--button">Update</button></form>'
        . '<form method="post" action="{{BASE}}/shop/cart/remove"><input type="hidden" name="csrf_token" value="' . escape($csrf) . '"><input type="hidden" name="key" value="' . $key . '">'
        . '<button type="submit" class="shop-link shop-link--button shop-link--danger">Remove<span class="visually-hidden"> ' . $name . '</span></button></form></div></div>'
        . '<p class="shop-line__total"><span class="visually-hidden">Line total </span>' . rupees($line['line_total']) . '</p></li>' . "\n";
}

/** /shop/cart. */
function renderShopCart(): ?string {
    shopCartSessionStart(false);
    $lines = shopCartLines();
    $csrf = $lines ? accountCsrfToken() : '';
    $totals = shopCartTotals($lines);
    $items = '';
    foreach ($lines as $line) $items .= shopCartLineHtml($line, $csrf);

    $shippingLine = $totals['shipping'] > 0 ? rupees($totals['shipping']) : '<span class="shop-free">Free</span>';
    $progress = '';
    if ($lines && $totals['free_over'] !== null) {
        $percent = (int) min(100, round($totals['subtotal'] / $totals['free_over'] * 100));
        $progress = '<div class="shop-ship"><p>' . ($totals['to_free'] !== null ? 'Add <strong>' . rupees($totals['to_free']) . '</strong> more for free shipping' : '<strong>You get free shipping</strong> on this order') . '</p>'
            . '<div class="shop-ship__bar" role="img" aria-label="' . $percent . ' percent of the way to free shipping"><span style="width: ' . $percent . '%"></span></div></div>';
    }
    $phone = shopSetting('shop_support_phone');
    $summary = $lines ? '<aside class="shop-summary" aria-labelledby="shop-summary-title"><h2 id="shop-summary-title">Order summary</h2>'
        . '<dl class="shop-summary__rows"><div><dt>Subtotal (' . $totals['count'] . ' item' . ($totals['count'] === 1 ? '' : 's') . ')</dt><dd>' . rupees($totals['subtotal']) . '</dd></div>'
        . '<div><dt>Shipping</dt><dd>' . $shippingLine . '</dd></div><div class="shop-summary__total"><dt>Total</dt><dd>' . rupees($totals['total']) . '</dd></div></dl>'
        . $progress . '<p class="shop-summary__note">Prices include GST.</p>'
        . '<a class="shop-btn shop-btn--primary shop-btn--block" href="{{BASE}}/shop/checkout">Proceed to checkout</a>'
        . '<a class="shop-link shop-summary__back" href="{{BASE}}/shop">Continue shopping</a>'
        . ($phone !== '' ? '<p class="shop-summary__help">Need help with an order? Call ' . phoneLink($phone) . '</p>' : '') . '</aside>' : '';
    $empty = '<div class="shop-empty"><p class="shop-empty__title">Your cart is empty</p><p>Browse jerseys, caps and cricket gear and add what you like.</p><a class="shop-btn shop-btn--primary" href="{{BASE}}/shop">Start shopping</a></div>';

    $crumbs = [['label' => 'Home', 'url' => '{{BASE}}'], ['label' => 'Shop', 'url' => '{{BASE}}/shop'], ['label' => 'Cart', 'url' => '{{BASE}}/shop/cart']];
    return view('templates.shop-cart', [
        'title' => 'Your cart | Pro HPCL Shop', 'description' => 'Review your Pro HPCL shop cart.', 'url' => '{{BASE}}/shop/cart',
        'breadcrumb' => autoBreadcrumbHtml($crumbs), 'flash' => shopFlashHtml(),
        'lines' => $lines ? '<ul class="shop-lines" aria-label="Items in your cart">' . "\n" . $items . '</ul>' : $empty,
        'summary' => $summary, 'css_version' => SHOP_ASSET_VERSION, 'has_lines' => $lines ? 'has-lines' : 'is-empty',
    ]);
}

/** Answers a cart POST: JSON for the page script, otherwise a flash message and a 303 redirect. */
function shopCartRespond(bool $ok, string $message, string $redirect, int $status = 200): never {
    global $base;
    $redirect = str_replace('{{BASE}}', $base, $redirect);
    $count = shopCartCount();
    if (wantsJson()) {
        http_response_code($ok ? 200 : $status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $ok, 'message' => $message, 'count' => $count, 'cart_url' => $base . '/shop/cart'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $_SESSION['shop_flash'] = [$ok ? 'ok' : 'error', $message];
    header('Location: ' . $redirect, true, 303);
    exit;
}

/** POST /shop/cart/add, /update and /remove. */
function handleShopCartAction(string $action): never {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Allow: POST'); errorPage(405); }
    shopCartSessionStart();
    $cart = '{{BASE}}/shop/cart';
    if (!accountCsrfValid()) shopCartRespond(false, 'Your session expired. Please reload the page and try again.', $cart, 400);
    $post = fn (string $name): string => trim((string) ($_POST[$name] ?? ''));
    $stored = is_array($_SESSION['shop_cart'] ?? null) ? $_SESSION['shop_cart'] : [];

    if ($action === 'add') {
        $product = ctype_digit($post('product_id')) ? shopProduct((int) $post('product_id')) : null;
        if (!$product) shopCartRespond(false, 'This product is no longer available.', '{{BASE}}/shop', 404);
        $back = productUrl($product);
        $qty = ctype_digit($post('qty')) ? (int) $post('qty') : 1;
        if ($qty < 1) shopCartRespond(false, 'Choose a quantity of at least 1.', $back, 422);

        $variant = null;
        if ($product['variants']) {
            if (ctype_digit($post('variant_id')) && isset($product['variants'][(int) $post('variant_id')])) $variant = $product['variants'][(int) $post('variant_id')];
            elseif (count($product['variants']) === 1) $variant = reset($product['variants']);
            else {
                $colour = ctype_digit($post('colour_id')) ? (int) $post('colour_id') : 0;
                foreach ($product['variants'] as $candidate) {
                    if ((int) $candidate['colour_id'] === $colour && (string) $candidate['size'] === $post('size')) { $variant = $candidate; break; }
                }
            }
            if (!$variant) shopCartRespond(false, 'Please choose ' . ($product['colours'] && array_filter(array_column($product['variants'], 'size'), fn ($s) => $s !== '') ? 'a colour and size' : ($product['colours'] ? 'a colour' : 'a size')) . '.', $back, 422);
            $stock = (int) $variant['stock'];
        } else {
            $stock = (int) $product['stock'];
        }
        $key = $product['id'] . ':' . ($variant ? $variant['id'] : 0);
        $max = min($stock, SHOP_MAX_PER_LINE);
        if ($max <= 0) shopCartRespond(false, 'Sorry, this option is out of stock.', $back, 409);
        $have = (int) ($stored[$key] ?? 0);
        if ($have >= $max) shopCartRespond(false, 'You already have the most we can offer of this item (' . $max . ') in your cart.', $cart, 409);
        $new = min($have + $qty, $max);
        $_SESSION['shop_cart'][$key] = $new;
        shopCartLinesCache(null, true);
        shopCartRespond(true, $new < $have + $qty ? 'Added. Only ' . $max . ' available, so your cart holds ' . $new . '.' : 'Added to your cart.', $cart);
    }

    $key = $post('key');
    if (!isset($stored[$key])) shopCartRespond(false, 'That item is not in your cart any more.', $cart, 404);
    if ($action === 'remove') {
        unset($_SESSION['shop_cart'][$key]);
        shopCartLinesCache(null, true);
        shopCartRespond(true, 'Item removed.', $cart);
    }
    // update
    $qty = ctype_digit($post('qty')) ? (int) $post('qty') : -1;
    if ($qty < 0) shopCartRespond(false, 'Enter a quantity.', $cart, 422);
    if ($qty === 0) { unset($_SESSION['shop_cart'][$key]); shopCartLinesCache(null, true); shopCartRespond(true, 'Item removed.', $cart); }
    $_SESSION['shop_cart'][$key] = $qty;
    shopCartLinesCache(null, true);
    $kept = array_column(shopCartLines(), 'qty', 'key');   // lowers it to the stock when needed
    shopCartRespond(true, isset($kept[$key]) && $kept[$key] < $qty ? 'Updated. Only ' . $kept[$key] . ' available.' : 'Cart updated.', $cart);
}
