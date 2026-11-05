<?php
declare(strict_types=1);

// Shop orders (tables shop_orders, shop_order_items, shop_order_events, see database/shop.sql): reading
// them, the status workflow and what a payment does. The checkout that creates them is
// shop-checkout.php, the emails are shop-mail.php, and the admin screens call the functions here:
//
//   shopOrderGet($idOrTokenOrNumber): ?array        order + 'items' + 'events'
//   shopOrderList(array $filters): array            rows, newest first; filters: status, q, user_id, email, limit, offset
//   shopOrderCount(array $filters): int             the same filters, for paging
//   shopOrderStatusCounts(): array                  status => number of orders
//   shopOrderSetStatus($id, $status, $note, $tracking): bool
//   shopOrderStatusLabel($status): string
//   shopMarkOrderPaid($razorpayOrderId, $paymentId, $paise): bool
//   shopPaymentReceived(...)                        the Razorpay webhook's entry point
//
// Statuses: pending (waiting for payment), paid, packed, shipped, delivered, and the ends cancelled,
// failed (never paid, expired) and refunded.
require_once __DIR__ . '/site.php';

const SHOP_ORDER_STATUSES = [
    'pending' => 'Awaiting payment', 'paid' => 'Paid', 'packed' => 'Packed', 'shipped' => 'Shipped', 'delivered' => 'Delivered',
    'cancelled' => 'Cancelled', 'failed' => 'Payment not completed', 'refunded' => 'Refunded',
];
// The statuses a customer sees on the order's timeline, in order.
const SHOP_ORDER_TIMELINE = ['paid', 'packed', 'shipped', 'delivered'];
// Pending orders older than this many hours are closed as "failed" (the customer left without paying).
const SHOP_PENDING_HOURS = 24;

function shopOrderStatusLabel(string $status): string {
    return SHOP_ORDER_STATUSES[$status] ?? ucfirst($status);
}
/** Whether money has been received for an order with this status (so it counts as a sale). */
function shopOrderIsPaidStatus(string $status): bool {
    return in_array($status, ['paid', 'packed', 'shipped', 'delivered'], true);
}

/** An order with its 'items' and 'events', found by id, by the 32-character link token or by the order number; null if none. */
function shopOrderGet(int|string $key): ?array {
    if (is_int($key) || ctype_digit((string) $key)) $order = query('SELECT * FROM shop_orders WHERE id = ?', [(int) $key])[0] ?? null;
    elseif (preg_match('~^[a-f0-9]{32}$~', (string) $key)) $order = query('SELECT * FROM shop_orders WHERE token = ?', [$key])[0] ?? null;
    else $order = query('SELECT * FROM shop_orders WHERE order_number = ?', [strtoupper((string) $key)])[0] ?? null;
    if (!$order) return null;
    $order['items'] = query('SELECT * FROM shop_order_items WHERE order_id = ? ORDER BY id', [$order['id']]);
    $order['events'] = query('SELECT * FROM shop_order_events WHERE order_id = ? ORDER BY id', [$order['id']]);
    return $order;
}

/** [WHERE clause, params] of the order filters: status, q (order number, name, email, phone), user_id, email. */
function shopOrderWhere(array $filters): array {
    $where = ['1 = 1'];
    $params = [];
    if (($filters['status'] ?? '') !== '' && isset(SHOP_ORDER_STATUSES[$filters['status']])) { $where[] = 'o.status = ?'; $params[] = $filters['status']; }
    if (($filters['q'] ?? '') !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $filters['q'])) . '%';
        $where[] = '(o.order_number LIKE ? OR o.name LIKE ? OR o.email LIKE ? OR o.phone LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    // A signed-in customer's orders: placed while signed in, or with the account's email address.
    if (isset($filters['user_id'])) {
        $where[] = '(o.user_id = ?' . (($filters['email'] ?? '') !== '' ? ' OR o.email = ?' : '') . ')';
        $params[] = (int) $filters['user_id'];
        if (($filters['email'] ?? '') !== '') $params[] = (string) $filters['email'];
    } elseif (($filters['email'] ?? '') !== '') { $where[] = 'o.email = ?'; $params[] = (string) $filters['email']; }
    return [implode(' AND ', $where), $params];
}
/** Orders, newest first, each with 'items_count'. */
function shopOrderList(array $filters = []): array {
    [$where, $params] = shopOrderWhere($filters);
    $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));
    return query("SELECT o.*, (SELECT COALESCE(SUM(i.qty), 0) FROM shop_order_items i WHERE i.order_id = o.id) AS items_count
        FROM shop_orders o WHERE $where ORDER BY o.created_at DESC, o.id DESC LIMIT $limit OFFSET $offset", $params);
}
function shopOrderCount(array $filters = []): int {
    [$where, $params] = shopOrderWhere($filters);
    return (int) query("SELECT COUNT(*) AS n FROM shop_orders o WHERE $where", $params)[0]['n'];
}
/** status => number of orders (every status present, 0 when none). */
function shopOrderStatusCounts(): array {
    $counts = array_fill_keys(array_keys(SHOP_ORDER_STATUSES), 0);
    foreach (query('SELECT status, COUNT(*) AS n FROM shop_orders GROUP BY status') as $row) $counts[$row['status']] = (int) $row['n'];
    return $counts;
}

function shopOrderEvent(int $orderId, string $status, ?string $note = null): void {
    db()->prepare('INSERT INTO shop_order_events (order_id, status, note, created_at) VALUES (?, ?, ?, NOW())')->execute([$orderId, $status, $note !== null ? mb_substr($note, 0, 500) : null]);
}

/**
 * Takes the stock of an order's items, inside the caller's transaction: all of it or (when anything
 * is short) none, so a paid order never leaves negative stock. Returns the names of items that are
 * short ([] = stock taken).
 */
function shopOrderTakeStock(int $orderId): array {
    $items = query('SELECT * FROM shop_order_items WHERE order_id = ? ORDER BY variant_id, product_id', [$orderId]);
    $short = [];
    foreach ($items as $item) {
        $row = $item['variant_id']
            ? (query('SELECT stock FROM shop_product_variants WHERE id = ? FOR UPDATE', [$item['variant_id']])[0] ?? null)
            : ($item['product_id'] ? (query('SELECT stock FROM shop_products WHERE id = ? FOR UPDATE', [$item['product_id']])[0] ?? null) : null);
        if (!$row || (int) $row['stock'] < (int) $item['qty']) $short[] = $item['product_name'] . ($item['variant_label'] ? ' (' . $item['variant_label'] . ')' : '');
    }
    if ($short) return $short;
    foreach ($items as $item) {
        if ($item['variant_id']) db()->prepare('UPDATE shop_product_variants SET stock = stock - ? WHERE id = ?')->execute([$item['qty'], $item['variant_id']]);
        elseif ($item['product_id']) db()->prepare('UPDATE shop_products SET stock = stock - ? WHERE id = ?')->execute([$item['qty'], $item['product_id']]);
    }
    return [];
}
/** Gives an order's stock back (a paid order that is cancelled or refunded). */
function shopOrderRestoreStock(int $orderId): void {
    foreach (query('SELECT * FROM shop_order_items WHERE order_id = ?', [$orderId]) as $item) {
        if ($item['variant_id']) db()->prepare('UPDATE shop_product_variants SET stock = stock + ? WHERE id = ?')->execute([$item['qty'], $item['variant_id']]);
        elseif ($item['product_id']) db()->prepare('UPDATE shop_products SET stock = stock + ? WHERE id = ?')->execute([$item['qty'], $item['product_id']]);
    }
}

/**
 * Records that an order is paid (by the browser's confirmation, the webhook or the admin): takes the
 * stock, sets the status and sends the emails. Safe to call twice (the second call changes nothing).
 * $paymentId is null for a payment taken outside Razorpay. Returns whether the order is paid now.
 */
function shopFinalizePaid(int $orderId, ?string $paymentId, ?string $note = null): bool {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $order = $pdo->prepare('SELECT * FROM shop_orders WHERE id = ? FOR UPDATE');
        $order->execute([$orderId]);
        $order = $order->fetch();
        if (!$order) { $pdo->rollBack(); return false; }
        if (shopOrderIsPaidStatus($order['status'])) { $pdo->commit(); return true; }
        if ($order['status'] === 'refunded') { $pdo->commit(); return false; }
        $attention = null;
        if ($order['status'] === 'cancelled') {
            // Money arrived for an order the team cancelled: record it, and leave the decision (refund) to the team.
            $pdo->prepare('UPDATE shop_orders SET razorpay_payment_id = COALESCE(?, razorpay_payment_id), updated_at = NOW() WHERE id = ?')->execute([$paymentId, $orderId]);
            shopOrderEvent($orderId, 'cancelled', 'NEEDS ATTENTION: a payment (' . ($paymentId ?? 'no id') . ') arrived for this cancelled order: refund it in Razorpay.');
            $pdo->commit();
            try { shopMailAdmin($orderId, 'attention'); } catch (Throwable $error) { error_log('Shop order ' . $orderId . ' alert email failed: ' . $error->getMessage()); }
            return false;
        }
        $short = shopOrderTakeStock($orderId);
        if ($short) $attention = 'NEEDS ATTENTION: paid, but not enough stock for ' . implode(', ', $short) . '.';
        $pdo->prepare("UPDATE shop_orders SET status = 'paid', paid_at = NOW(), razorpay_payment_id = COALESCE(?, razorpay_payment_id), stock_deducted = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$paymentId, $short ? 0 : 1, $orderId]);
        shopOrderEvent($orderId, 'paid', $note ?? ($paymentId ? 'Payment ' . $paymentId . ' received.' : 'Marked as paid.'));
        if ($attention) shopOrderEvent($orderId, 'paid', $attention);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    // Emails after the commit: a mail problem must never undo or hide a payment.
    try {
        shopMailOrder($orderId, 'paid');
        shopMailAdmin($orderId, $attention ? 'attention' : 'paid');
    } catch (Throwable $error) {
        error_log('Shop order ' . $orderId . ' emails failed: ' . $error->getMessage());
    }
    return true;
}

/** Razorpay's confirmation of a payment for the order with this Razorpay order id; true when it is now paid. */
function shopMarkOrderPaid(string $razorpayOrderId, string $paymentId, ?int $paise = null): bool {
    $order = query('SELECT id, total FROM shop_orders WHERE razorpay_order_id = ?', [$razorpayOrderId])[0] ?? null;
    if (!$order) return false;
    if ($paise !== null && $paise !== (int) round((float) $order['total'] * 100)) {
        error_log("Razorpay payment $paymentId for shop order {$order['id']} was $paise paise, not " . round((float) $order['total'] * 100) . ': not recorded');
        return false;
    }
    return shopFinalizePaid((int) $order['id'], $paymentId);
}
/** The webhook's entry point: true when $razorpayOrderId is a shop order (whatever came of it), false when it belongs to something else. */
function shopPaymentReceived(string $razorpayOrderId, string $paymentId, ?int $paise = null): bool {
    if (!query('SELECT 1 FROM shop_orders WHERE razorpay_order_id = ?', [$razorpayOrderId])) return false;
    try {
        shopMarkOrderPaid($razorpayOrderId, $paymentId, $paise);
    } catch (Throwable $error) {
        error_log('Shop payment ' . $paymentId . ' could not be recorded: ' . $error->getMessage());
    }
    return true;
}

/**
 * Moves an order to $status for the admin: writes the history, gives stock back when a paid order is
 * cancelled or refunded, and emails the customer about shipped, delivered, cancelled and refunded.
 * $tracking = ['carrier' => …, 'number' => …] (kept with the order, shown to the customer).
 * Returns false when there is no such order or nothing changed.
 */
function shopOrderSetStatus(int $orderId, string $status, ?string $note = null, ?array $tracking = null): bool {
    if (!isset(SHOP_ORDER_STATUSES[$status])) return false;
    $order = shopOrderGet($orderId);
    if (!$order) return false;
    if ($tracking !== null && ($tracking['number'] ?? '') !== '') {
        db()->prepare('UPDATE shop_orders SET tracking_carrier = ?, tracking_number = ?, updated_at = NOW() WHERE id = ?')
            ->execute([mb_substr(trim((string) ($tracking['carrier'] ?? '')), 0, 120) ?: null, mb_substr(trim((string) $tracking['number']), 0, 120), $orderId]);
    }
    if ($order['status'] === $status) {
        // Same status again: only a new tracking number or note is saved.
        if ($note !== null && trim($note) !== '') shopOrderEvent($orderId, $status, $note);
        return false;
    }
    if ($status === 'paid' && !shopOrderIsPaidStatus($order['status'])) {
        return shopFinalizePaid($orderId, null, $note ?? 'Marked as paid by the team.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE shop_orders SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $orderId]);
        if (in_array($status, ['cancelled', 'refunded'], true) && (int) $order['stock_deducted'] === 1) {
            shopOrderRestoreStock($orderId);
            $pdo->prepare('UPDATE shop_orders SET stock_deducted = 0 WHERE id = ?')->execute([$orderId]);
            $note = trim((string) $note) !== '' ? $note : 'Stock returned.';
        }
        shopOrderEvent($orderId, $status, $note);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if (in_array($status, ['shipped', 'delivered', 'cancelled', 'refunded'], true)) {
        try { shopMailOrder($orderId, $status); } catch (Throwable $error) { error_log('Shop order ' . $orderId . ' ' . $status . ' email failed: ' . $error->getMessage()); }
    }
    return true;
}

/** Closes pending orders that were never paid within SHOP_PENDING_HOURS ("failed"), so they stop looking like live orders. Returns how many. */
function shopExpirePendingOrders(): int {
    $ids = array_column(query("SELECT id FROM shop_orders WHERE status = 'pending' AND created_at < (NOW() - INTERVAL " . SHOP_PENDING_HOURS . ' HOUR) LIMIT 100'), 'id');
    foreach ($ids as $id) {
        db()->prepare("UPDATE shop_orders SET status = 'failed', updated_at = NOW() WHERE id = ? AND status = 'pending'")->execute([$id]);
        shopOrderEvent((int) $id, 'failed', 'Not paid within ' . SHOP_PENDING_HOURS . ' hours.');
    }
    return count($ids);
}

require_once __DIR__ . '/shop-mail.php';
