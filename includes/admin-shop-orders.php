<?php
declare(strict_types=1);

// Shop orders in the admin (/admin/shop/orders): a list with status tabs and search, an order page where the
// team packs, ships and closes an order, a printable packing slip and a CSV export. Changing the status goes
// through shopOrderSetStatus() (includes/shop-orders.php) when it exists, which also emails the customer.
require_once __DIR__ . '/admin-shop.php';

// Tabs of the list: key => [label, the statuses it shows (empty = all)].
const SHOP_ADMIN_ORDER_TABS = [
    'topack' => ['To pack', ['paid']], 'packed' => ['Packed', ['packed']], 'shipped' => ['Shipped', ['shipped']], 'delivered' => ['Delivered', ['delivered']],
    'closed' => ['Cancelled and refunded', ['cancelled', 'refunded']], 'unpaid' => ['Unpaid', ['pending', 'failed']], 'all' => ['All orders', []],
];

/** The order list's filters from the query string. */
function shopAdminOrderFilters(): array {
    $tab = is_string($_GET['status'] ?? null) && isset(SHOP_ADMIN_ORDER_TABS[$_GET['status']]) ? $_GET['status'] : 'topack';
    $date = fn (string $key) => is_string($_GET[$key] ?? null) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET[$key]) && strtotime($_GET[$key]) ? $_GET[$key] : '';
    return ['status' => $tab, 'q' => adminSearch(), 'from' => $date('from'), 'to' => $date('to')];
}
/** [WHERE clause, parameters] for the filters. */
function shopAdminOrderWhere(array $f): array {
    $where = ['1 = 1'];
    $params = [];
    $statuses = SHOP_ADMIN_ORDER_TABS[$f['status']][1];
    if ($statuses) { $where[] = 'o.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'; array_push($params, ...$statuses); }
    if ($f['q'] !== '') {
        $where[] = '(o.order_number LIKE ? OR o.name LIKE ? OR o.email LIKE ? OR o.phone LIKE ?)';
        array_push($params, ...array_fill(0, 4, likePattern($f['q'])));
    }
    if ($f['from'] !== '') { $where[] = 'o.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'] !== '') { $where[] = 'o.created_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }
    return [implode(' AND ', $where), $params];
}
function shopAdminOrderListUrl(array $f, ?int $page = null, string $path = '/admin/shop/orders'): string {
    $query = http_build_query(array_filter([...$f, 'page' => $page > 1 ? $page : null], fn ($v) => $v !== '' && $v !== null));
    return '{{BASE}}' . $path . ($query !== '' ? '?' . escape($query) : '');
}
function shopAdminOrderDate(?string $value): string {
    return $value ? date('j M Y, g:i A', strtotime($value)) : '—';
}

function renderAdminShopOrders(array $admin): string {
    $f = shopAdminOrderFilters();
    [$where, $params] = shopAdminOrderWhere($f);
    $total = (int) query("SELECT COUNT(*) AS n FROM shop_orders o WHERE $where", $params)[0]['n'];
    $lastPage = max(1, (int) ceil($total / SHOP_ADMIN_PER_PAGE));
    $page = min(shopAdminPage(), $lastPage);
    $orders = query("SELECT o.id, o.order_number, o.name, o.phone, o.total, o.status, o.created_at, (SELECT COALESCE(SUM(i.qty), 0) FROM shop_order_items i WHERE i.order_id = o.id) AS items
        FROM shop_orders o WHERE $where ORDER BY o.created_at DESC, o.id DESC LIMIT " . SHOP_ADMIN_PER_PAGE . ' OFFSET ' . (($page - 1) * SHOP_ADMIN_PER_PAGE), $params);
    $rows = implode('', array_map(fn ($o) => '<tr><td><a href="{{BASE}}/admin/shop/orders/' . (int) $o['id'] . '">' . escape($o['order_number']) . '</a></td>'
        . '<td class="admin-wide-only">' . escape(shopAdminOrderDate($o['created_at'])) . '</td>'
        . '<td>' . escape($o['name']) . '<br><span class="admin-muted">' . escape($o['phone']) . '</span></td>'
        . '<td class="admin-num admin-wide-only">' . (int) $o['items'] . '</td><td class="admin-num">' . shopAdminRupees($o['total']) . '</td><td>' . shopAdminBadge($o['status']) . '</td>'
        . '<td class="admin-actions"><a href="{{BASE}}/admin/shop/orders/' . (int) $o['id'] . '">View</a>'
        . ($o['status'] === 'paid' || $o['status'] === 'packed' ? '<a href="{{BASE}}/admin/shop/orders/' . (int) $o['id'] . '/slip">Slip</a>' : '') . '</td></tr>', $orders));
    $table = $orders
        ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Order</th><th class="admin-wide-only">Placed</th><th>Customer</th><th class="admin-num admin-wide-only">Items</th><th class="admin-num">Total</th><th>Status</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        : '<p class="admin-empty">' . ($f['q'] . $f['from'] . $f['to'] !== '' ? 'No orders match these filters.' : 'No orders in this list yet.') . '</p>';

    $byStatus = array_column(query('SELECT status, COUNT(*) AS n FROM shop_orders GROUP BY status'), 'n', 'status');
    $tabs = '<nav class="admin-tabs" aria-label="Order status">' . implode('', array_map(function ($key, $tab) use ($f, $byStatus) {
        $count = $tab[1] ? array_sum(array_map(fn ($s) => (int) ($byStatus[$s] ?? 0), $tab[1])) : array_sum($byStatus);
        return '<a href="' . shopAdminOrderListUrl(['status' => $key, 'q' => $f['q'], 'from' => $f['from'], 'to' => $f['to']]) . '"' . ($key === $f['status'] ? ' aria-current="page"' : '') . '>' . escape($tab[0]) . ' <span class="admin-muted">' . number_format($count) . '</span></a>';
    }, array_keys(SHOP_ADMIN_ORDER_TABS), SHOP_ADMIN_ORDER_TABS)) . '</nav>';
    return adminView('templates.admin.shop-orders', $admin, '/admin/shop/orders', [
        'summary' => number_format($total) . ' order' . ($total === 1 ? '' : 's') . ' in “' . escape(SHOP_ADMIN_ORDER_TABS[$f['status']][0]) . '”', 'tabs' => $tabs, 'table' => $table,
        'status' => escape($f['status']), 'search' => escape($f['q']), 'from' => escape($f['from']), 'to' => escape($f['to']),
        'export' => shopAdminOrderListUrl($f, null, '/admin/shop/orders/export.csv'), 'pager' => shopAdminPager($page, $lastPage, fn (int $n) => shopAdminOrderListUrl($f, $n)),
        'payments' => shopAdminPaymentStatus()[0] === 'ready' ? '' : shopAdminPaymentPanel(),
    ]);
}

/** A CSV of the orders in the current filters (all pages), one row per order. Spreadsheet formulas are defused. */
function sendAdminShopOrdersCsv(array $admin): never {
    $f = shopAdminOrderFilters();
    [$where, $params] = shopAdminOrderWhere($f);
    $safe = fn ($value) => preg_match('~^[=+\-@\t\r]~', (string) $value) ? "'" . $value : (string) $value;
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="shop-orders-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Order', 'Placed', 'Status', 'Name', 'Email', 'Phone', 'Address', 'City', 'State', 'Pincode', 'Items', 'Subtotal', 'Shipping', 'Total', 'Razorpay payment', 'Courier', 'Tracking', 'Customer note'], ',', '"', '');
    foreach (query("SELECT o.* FROM shop_orders o WHERE $where ORDER BY o.created_at DESC, o.id DESC LIMIT 5000", $params) as $o) {
        $items = implode('; ', array_map(fn ($i) => $i['qty'] . ' x ' . $i['product_name'] . ($i['variant_label'] ? ' (' . $i['variant_label'] . ')' : ''), query('SELECT * FROM shop_order_items WHERE order_id = ? ORDER BY id', [$o['id']])));
        fputcsv($out, array_map($safe, [$o['order_number'], $o['created_at'], SHOP_ADMIN_ORDER_STATUSES[$o['status']][0] ?? $o['status'], $o['name'], $o['email'], $o['phone'], trim($o['address_line1'] . ' ' . $o['address_line2']), $o['city'], $o['state'], $o['pincode'],
            $items, $o['subtotal'], $o['shipping'], $o['total'], $o['razorpay_payment_id'], $o['tracking_carrier'], $o['tracking_number'], $o['notes']]), ',', '"', '');
    }
    fclose($out);
    exit;
}

/** An order with its items and history, or a 404. */
function shopAdminOrder(int $id): array {
    $order = query('SELECT * FROM shop_orders WHERE id = ?', [$id])[0] ?? notFound();
    $order['items'] = query('SELECT * FROM shop_order_items WHERE order_id = ? ORDER BY id', [$id]);
    $order['events'] = query('SELECT * FROM shop_order_events WHERE order_id = ? ORDER BY created_at DESC, id DESC', [$id]);
    return $order;
}

/** Changes an order's status: shopOrderSetStatus() when the checkout code provides it (it emails the customer), else a plain update. */
function shopAdminSetStatus(int $id, string $status, ?string $note, ?array $tracking): bool {
    if (is_file(__DIR__ . '/shop-orders.php')) require_once __DIR__ . '/shop-orders.php';
    if (function_exists('shopOrderSetStatus')) return shopOrderSetStatus($id, $status, $note, $tracking);
    $ok = false;
    adminTransaction(function () use ($id, $status, $note, $tracking, &$ok) {
        $order = query('SELECT * FROM shop_orders WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
        if (!$order || !in_array($status, SHOP_ADMIN_ORDER_NEXT[$order['status']] ?? [], true)) return;
        adminExecute('UPDATE shop_orders SET status = ?, tracking_carrier = COALESCE(?, tracking_carrier), tracking_number = COALESCE(?, tracking_number), updated_at = NOW() WHERE id = ?',
            [$status, $tracking['carrier'] ?? null, $tracking['number'] ?? null, $id]);
        adminExecute('INSERT INTO shop_order_events (order_id, status, note, created_at) VALUES (?, ?, ?, NOW())', [$id, $status, $note]);
        // A cancelled or refunded order puts its stock back.
        if (in_array($status, ['cancelled', 'refunded'], true) && (int) $order['stock_deducted'] === 1) {
            foreach (query('SELECT product_id, variant_id, qty FROM shop_order_items WHERE order_id = ?', [$id]) as $item) {
                if ($item['variant_id']) adminExecute('UPDATE shop_product_variants SET stock = stock + ? WHERE id = ?', [$item['qty'], $item['variant_id']]);
                if ($item['product_id']) adminExecute('UPDATE shop_products SET stock = stock + ? WHERE id = ?', [$item['qty'], $item['product_id']]);
            }
            adminExecute('UPDATE shop_orders SET stock_deducted = 0 WHERE id = ?', [$id]);
        }
        $ok = true;
    });
    return $ok;
}

function renderAdminShopOrder(array $admin, int $id): string {
    $order = shopAdminOrder($id);
    $errors = [];
    $input = ['status' => '', 'note' => '', 'carrier' => (string) $order['tracking_carrier'], 'number' => (string) $order['tracking_number']];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = postedText('action');
        if ($action === 'note') {
            adminExecute('UPDATE shop_orders SET admin_note = ?, updated_at = NOW() WHERE id = ?', [mb_substr(postedText('admin_note'), 0, 2000) ?: null, $id]);
            adminFlash('Note saved.');
            adminRedirect('/admin/shop/orders/' . $id);
        }
        $input = ['status' => postedText('status'), 'note' => postedText('note'), 'carrier' => postedText('carrier'), 'number' => postedText('number')];
        if (!in_array($input['status'], SHOP_ADMIN_ORDER_NEXT[$order['status']] ?? [], true)) $errors['status'] = 'Choose a status from the list.';
        if (mb_strlen($input['note']) > 500) $errors['note'] = 'The note must be 500 characters or fewer.';
        if (mb_strlen($input['carrier']) > 120 || mb_strlen($input['number']) > 120) $errors['carrier'] = 'The courier and tracking number must be 120 characters or fewer.';
        if ($input['status'] === 'shipped' && $input['carrier'] === '' && $input['number'] === '') $errors['carrier'] = 'Enter the courier and/or the tracking number so the customer can follow the parcel.';
        if (!$errors) {
            $tracking = $input['status'] === 'shipped' ? ['carrier' => $input['carrier'] ?: null, 'number' => $input['number'] ?: null] : null;
            if (shopAdminSetStatus($id, $input['status'], $input['note'] ?: null, $tracking)) {
                adminFlash('Order ' . $order['order_number'] . ' is now “' . (SHOP_ADMIN_ORDER_STATUSES[$input['status']][0] ?? $input['status']) . '”.');
                adminRedirect('/admin/shop/orders/' . $id);
            }
            $errors['status'] = 'The status could not be changed: the order may have been updated elsewhere. Reload the page and try again.';
        }
    }
    $items = implode('', array_map(fn ($i) => '<tr><td class="admin-cell-team">' . shopAdminThumb($i['image'] ?? null) . '<span>' . escape($i['product_name']) . ($i['variant_label'] ? '<br><span class="admin-muted">' . escape($i['variant_label']) . '</span>' : '') . '</span></td>'
        . '<td class="admin-num">' . shopAdminRupees($i['unit_price']) . '</td><td class="admin-num">' . (int) $i['qty'] . '</td><td class="admin-num">' . shopAdminRupees($i['line_total']) . '</td></tr>', $order['items']));
    $timeline = $order['events'] ? '<ol class="admin-shop-timeline">' . implode('', array_map(fn ($e) => '<li><strong>' . escape(SHOP_ADMIN_ORDER_STATUSES[$e['status']][0] ?? ucfirst($e['status'])) . '</strong> <span class="admin-muted">' . escape(shopAdminOrderDate($e['created_at'])) . '</span>'
        . ($e['note'] ? '<br>' . escape($e['note']) : '') . '</li>', $order['events'])) . '</ol>' : '<p class="admin-muted">No history yet.</p>';
    $next = SHOP_ADMIN_ORDER_NEXT[$order['status']] ?? [];
    $statusOptions = '<option value="">Choose…</option>' . implode('', array_map(fn ($s) => '<option value="' . $s . '"' . ($input['status'] === $s ? ' selected' : '') . '>' . escape(SHOP_ADMIN_ORDER_STATUSES[$s][0]) . '</option>', $next));
    $action = $next
        ? '<form class="admin-form" method="post" action="{{BASE}}/admin/shop/orders/' . $id . '"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '"><input type="hidden" name="action" value="status">'
            . shopAdminField('status', 'Change the status to', '<select id="status" name="status" required' . fieldInvalid($errors, 'status') . '>' . $statusOptions . '</select>', $errors)
            . '<div class="admin-fields-2">' . shopAdminField('carrier', 'Courier', shopAdminInput('carrier', 'text', $input['carrier'], $errors, ' maxlength="120" placeholder="e.g. India Post"'), $errors, 'For Shipped.', true)
            . shopAdminField('number', 'Tracking number', shopAdminInput('number', 'text', $input['number'], [], ' maxlength="120"'), [], '', true) . '</div>'
            . shopAdminField('note', 'Message for the history', '<textarea id="note" name="note" rows="2" maxlength="500"' . fieldInvalid($errors, 'note') . '>' . escape($input['note']) . '</textarea>', $errors, 'Optional. The customer is emailed about shipped, delivered and cancelled orders.', true)
            . '<div class="admin-form-actions"><button type="submit">Update the order</button></div></form>'
        : '<p class="admin-muted">This order is ' . escape(strtolower(SHOP_ADMIN_ORDER_STATUSES[$order['status']][0] ?? $order['status'])) . ' and can’t change status.</p>';
    $address = escape($order['address_line1']) . ($order['address_line2'] ? '<br>' . escape($order['address_line2']) : '') . '<br>' . escape($order['city'] . ', ' . $order['state'] . ' ' . $order['pincode']);
    return adminView('templates.admin.shop-order', $admin, '/admin/shop/orders', [
        'title' => 'Order ' . escape($order['order_number']), 'status_badge' => shopAdminBadge($order['status']), 'placed' => escape(shopAdminOrderDate($order['created_at'])),
        'name' => escape($order['name']), 'email' => escape($order['email']), 'phone' => escape($order['phone']), 'address' => $address,
        'notes' => $order['notes'] ? '<p><strong>Customer note:</strong><br>' . nl2br(escape($order['notes'])) . '</p>' : '',
        'items' => $items, 'subtotal' => shopAdminRupees($order['subtotal']), 'shipping' => (float) $order['shipping'] > 0 ? shopAdminRupees($order['shipping']) : 'Free', 'total' => shopAdminRupees($order['total']),
        'payment' => '<dl class="admin-facts"><div><dt>Razorpay order</dt><dd>' . escape($order['razorpay_order_id'] ?: '—') . '</dd></div><div><dt>Razorpay payment</dt><dd>' . escape($order['razorpay_payment_id'] ?: '—') . '</dd></div><div><dt>Paid</dt><dd>' . escape(shopAdminOrderDate($order['paid_at'])) . '</dd></div>'
            . ($order['tracking_number'] || $order['tracking_carrier'] ? '<div><dt>Shipment</dt><dd>' . escape(trim($order['tracking_carrier'] . ' ' . $order['tracking_number'])) . '</dd></div>' : '') . '</dl>',
        'timeline' => $timeline, 'action' => $action, 'order_url' => '{{BASE}}/admin/shop/orders/' . $id, 'admin_note' => escape((string) $order['admin_note']), 'slip' => '{{BASE}}/admin/shop/orders/' . $id . '/slip',
        'summary' => $errors && !isset($errors['status']) && !isset($errors['carrier']) && !isset($errors['note']) ? '' : ($errors ? '<p class="admin-alert" role="alert">Please correct the highlighted fields.</p>' : ''),
    ]);
}

/** The printable packing slip: what to put in the parcel, where it goes, and any customer note (such as a jersey name and number). */
function renderAdminShopOrderSlip(array $admin, int $id): string {
    $order = shopAdminOrder($id);
    $items = implode('', array_map(fn ($i) => '<tr><td>' . escape($i['product_name']) . ($i['variant_label'] ? '<br><strong>' . escape($i['variant_label']) . '</strong>' : '') . '</td><td class="admin-num">' . (int) $i['qty'] . '</td><td class="admin-shop-slip__tick"><span aria-hidden="true">&#9744;</span><span class="admin-visually-hidden">Packed</span></td></tr>', $order['items']));
    return adminView('templates.admin.shop-slip', $admin, '/admin/shop/orders', [
        'title' => 'Packing slip ' . escape($order['order_number']), 'number' => escape($order['order_number']), 'placed' => escape(shopAdminOrderDate($order['created_at'])),
        'name' => escape($order['name']), 'phone' => escape($order['phone']),
        'address' => escape($order['address_line1']) . ($order['address_line2'] ? '<br>' . escape($order['address_line2']) : '') . '<br>' . escape($order['city'] . ', ' . $order['state']) . '<br><strong>' . escape($order['pincode']) . '</strong>',
        'items' => $items, 'notes' => $order['notes'] ? '<div class="admin-shop-slip__note"><strong>Customer note</strong><br>' . nl2br(escape($order['notes'])) . '</div>' : '',
        'back' => '{{BASE}}/admin/shop/orders/' . $id,
    ]);
}
