<?php
declare(strict_types=1);

// Ground bookings in the admin (/admin/bookings): the list with tabs and search, a booking page where the team
// edits the requirements, approves (with the total and the advance to pay online), revises an approval, rejects,
// cancels or marks the advance paid offline, and a CSV export. The calendar, the settings (packages and rates)
// and the blocked dates are in admin-booking-calendar.php and admin-booking-settings.php. Prices, availability and
// the state changes all go through the booking core (includes/booking.php), which also emails the requester.
require_once __DIR__ . '/admin-forms.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/razorpay.php';
require_once __DIR__ . '/booking.php';

const BOOKING_ADMIN_PER_PAGE = 25;
const BOOKING_ADMIN_SLOTS = ['morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening'];
const BOOKING_ADMIN_FORMATS = BOOKING_FORMATS;
// Status => [label, badge class].
const BOOKING_ADMIN_STATUSES = [
    'requested' => ['Requested', 'requested'], 'approved' => ['Awaiting payment', 'approved'], 'confirmed' => ['Confirmed', 'confirmed'],
    'rejected' => ['Rejected', 'rejected'], 'cancelled' => ['Cancelled', 'cancelled'], 'expired' => ['Expired', 'expired'],
];
// Tabs of the list: key => [label, the WHERE fragment, ORDER BY]. "Confirmed" and "Past" split on the LAST day of the booking.
const BOOKING_ADMIN_TABS = [
    'requests' => ['Requests', "b.status = 'requested'", 'b.booking_date ASC, b.created_at ASC'],
    'awaiting' => ['Awaiting payment', "b.status = 'approved'", 'b.booking_date ASC'],
    'confirmed' => ['Confirmed', "b.status = 'confirmed' AND COALESCE(b.end_date, b.booking_date) >= CURDATE()", 'b.booking_date ASC'],
    'past' => ['Past', "b.status = 'confirmed' AND COALESCE(b.end_date, b.booking_date) < CURDATE()", 'b.booking_date DESC'],
    'closed' => ['Rejected, cancelled, expired', "b.status IN ('rejected', 'cancelled', 'expired')", 'b.updated_at DESC, b.id DESC'],
    'all' => ['All', '1 = 1', 'b.created_at DESC, b.id DESC'],
];

// ---------------------------------------------------------------- small helpers

/** The time window of a slot as the admin set it (or the default). */
function bookingAdminSlotTimes(string $slot): string {
    return bookingSlotTime($slot);
}
/** What a booking takes, as text: "Morning (7:30 AM - 11:30 AM)", "Morning + Afternoon", "Full day: all three slots". */
function bookingAdminWhen(string $slot, string $format): string {
    $slots = bookingOccupiedSlots($slot, $format);
    if (!$slots) return BOOKING_ADMIN_FORMATS[$format] ?? $format;
    if (count($slots) === 3) return 'Full day: all three slots';
    if (count($slots) === 1) return BOOKING_ADMIN_SLOTS[$slots[0]] . ' (' . bookingAdminSlotTimes($slots[0]) . ')';
    return implode(' + ', array_map(fn ($s) => BOOKING_ADMIN_SLOTS[$s], $slots));
}
/** The slots a booking occupies on each of its days (see the core). */
function bookingAdminOccupied(string $slot, string $format): array {
    return bookingOccupiedSlots($slot, $format);
}
function bookingAdminDate(?string $value, string $format = 'D, j M Y'): string {
    return $value ? date($format, strtotime($value)) : '—';
}
function bookingAdminDateTime(?string $value): string {
    return $value ? date('j M Y, g:i A', strtotime($value)) : '—';
}
/** "Thu, 10 Dec 2026" for one day, "3 days (Thu 10 Dec - Sat 12 Dec 2026)" for several. */
function bookingAdminDays(array $b): string {
    $days = max(1, (int) ($b['days'] ?? 1));
    if ($days === 1) return bookingAdminDate($b['booking_date']);
    $end = $b['end_date'] ?: bookingEndDate($b['booking_date'], $days);
    return bookingDaysText($days) . ' (' . bookingAdminDate($b['booking_date'], 'D j M') . ' – ' . bookingAdminDate($end, 'D j M Y') . ')';
}
/** "₹7,500" or "₹7,500.50", as HTML. */
function bookingAdminRupees(float|string|null $amount): string {
    if ($amount === null || $amount === '') return '—';
    $amount = (float) $amount;
    return '&#8377;' . number_format($amount, floor($amount) === $amount ? 0 : 2);
}
/** A non-negative amount typed in a form, or null when it isn't a number in range. */
function bookingAdminAmount(string $text, float $max = 1000000): ?float {
    $text = str_replace([',', '₹', ' '], '', $text);
    return preg_match('~^\d{1,9}(\.\d{1,2})?$~', $text) && (float) $text <= $max ? (float) $text : null;
}
/** A number as typed in a form field: no trailing zeros. */
function bookingAdminNumber(float|string|null $value): string {
    return $value === null || $value === '' ? '' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
}
function bookingAdminBadge(string $status): string {
    [$label, $class] = BOOKING_ADMIN_STATUSES[$status] ?? [ucfirst($status), 'requested'];
    return '<span class="admin-badge admin-badge--booking-' . $class . '">' . escape($label) . '</span>';
}
function bookingAdminField(string $id, string $label, string $control, array $errors, string $hint = '', bool $optional = false): string {
    return '<div class="admin-field"><label for="' . $id . '">' . escape($label) . ($optional ? ' <span class="admin-optional">optional</span>' : '') . '</label>' . $control
        . ($hint !== '' ? '<p class="admin-hint">' . $hint . '</p>' : '') . fieldError($errors, $id) . '</div>';
}
function bookingAdminInput(string $id, string $type, string $value, array $errors, string $extra = ''): string {
    return '<input id="' . $id . '" name="' . $id . '" type="' . $type . '" value="' . escape($value) . '"' . $extra . fieldInvalid($errors, $id) . '>';
}
function bookingAdminCheck(string $id, string $label, bool $checked, string $extra = ''): string {
    return '<div class="admin-field"><label class="admin-check"><input type="checkbox" id="' . $id . '" name="' . $id . '" value="1"' . ($checked ? ' checked' : '') . $extra . '>' . escape($label) . '</label></div>';
}
function bookingAdminPage(): int {
    return max(1, (int) (is_string($_GET['page'] ?? null) ? $_GET['page'] : 1));
}
function bookingAdminPager(int $page, int $lastPage, callable $url): string {
    return $lastPage > 1 ? '<nav class="admin-pager" aria-label="Pages">'
        . ($page > 1 ? '<a href="' . $url($page - 1) . '" rel="prev">&larr; Previous</a>' : '<span></span>')
        . '<span class="admin-muted">Page ' . $page . ' of ' . $lastPage . '</span>'
        . ($page < $lastPage ? '<a href="' . $url($page + 1) . '" rel="next">Next &rarr;</a>' : '<span></span>') . '</nav>' : '';
}
/** The Razorpay panel (booking payments use the same keys as the shop, membership and committee). */
function bookingAdminPaymentPanel(): string {
    $config = razorpayConfig();
    $mode = $config['mode'] === 'live' ? 'live' : 'test';
    if ($config['key_id'] === '' || $config['key_secret'] === '') [$state, $headline, $detail] = ['missing', 'Razorpay is not set up', 'No API keys are saved yet, so the advance cannot be paid online. Use “Mark as paid offline” for bank transfers.'];
    elseif (!$config['enabled']) [$state, $headline, $detail] = ['off', 'Razorpay keys are saved but payments are switched off', 'Turn on “Accept payments” in the Razorpay settings. Mode: ' . $mode . '.'];
    else [$state, $headline, $detail] = ['ready', 'Razorpay is ready (' . $mode . ' mode)', $mode === 'test' ? 'Test mode takes no real money.' : 'Live keys are saved: requesters pay for real.'];
    $link = adminCan('root')
        ? '<a class="admin-button' . ($state === 'ready' ? '-secondary' : '') . '" href="{{BASE}}/admin/integrations">' . ($state === 'ready' ? 'Razorpay settings' : 'Enter the Razorpay keys') . '</a>'
        : '<span class="admin-muted">Only the root user can enter the Razorpay keys (Settings, Integrations).</span>';
    return '<div class="admin-shop-payments admin-shop-payments--' . $state . '"><div><p class="admin-shop-payments__label">Online payments</p>'
        . '<p class="admin-shop-payments__headline"><span class="admin-status' . ($state === 'ready' ? ' admin-status--ok' : '') . '">' . escape($headline) . '</span></p>'
        . '<p class="admin-hint">' . escape($detail) . ' The same keys take shop, membership and committee payments.</p></div><div>' . $link . '</div></div>';
}

// ---------------------------------------------------------------- conflicts

/**
 * What stops a booking of $slot / $format from $date for $days days from being approved, as an explanation: confirmed
 * bookings, approved bookings still holding their slot, and blocked dates that overlap any slot it occupies on any of
 * its days. A list of ['type', 'text', 'id']. (Whether it is free at all is decided by the core's bookingSlotFree().)
 */
function bookingAdminConflicts(string $date, string $slot, string $format, int $days = 1, ?int $excludeId = null): array {
    $mine = bookingOccupiedSlots($slot, $format);
    $end = bookingEndDate($date, $days);
    $found = [];
    foreach (query("SELECT id, reference, booking_date, days, COALESCE(end_date, booking_date) AS last_date, slot, format, status, name FROM bookings WHERE booking_date <= ? AND COALESCE(end_date, booking_date) >= ? AND id <> ?
        AND (status = 'confirmed' OR (status = 'approved' AND (payment_due_at IS NULL OR payment_due_at > ?)))", [$end, $date, (int) $excludeId, bookingNow()]) as $other) {
        if (!array_intersect($mine, bookingOccupiedSlots($other['slot'], $other['format']))) continue;
        $found[] = ['type' => 'booking', 'id' => (int) $other['id'], 'text' => $other['reference'] . ' (' . $other['name'] . ') is ' . ($other['status'] === 'confirmed' ? 'confirmed' : 'approved and holding') . ' for '
            . bookingAdminWhen($other['slot'], $other['format']) . ' on ' . bookingAdminDays($other) . '.'];
    }
    foreach (query('SELECT * FROM booking_blocks WHERE start_date <= ? AND end_date >= ?', [$end, $date]) as $block) {
        if ($block['slot'] !== 'all' && !in_array($block['slot'], $mine, true)) continue;
        $found[] = ['type' => 'block', 'id' => (int) $block['id'], 'text' => 'The ground is blocked' . ($block['slot'] === 'all' ? '' : ' for the ' . strtolower(BOOKING_ADMIN_SLOTS[$block['slot']]) . ' slot')
            . ' from ' . bookingAdminDate($block['start_date'], 'j M') . ' to ' . bookingAdminDate($block['end_date'], 'j M Y') . ($block['reason'] ? ': ' . $block['reason'] : '') . '.'];
    }
    return $found;
}

// ---------------------------------------------------------------- the list

function bookingAdminFilters(): array {
    $tab = is_string($_GET['status'] ?? null) && isset(BOOKING_ADMIN_TABS[$_GET['status']]) ? $_GET['status'] : 'requests';
    $date = fn (string $key) => is_string($_GET[$key] ?? null) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET[$key]) && strtotime($_GET[$key]) ? $_GET[$key] : '';
    return ['status' => $tab, 'q' => adminSearch(), 'from' => $date('from'), 'to' => $date('to')];
}
/** [WHERE clause, parameters] for the filters; the date range applies to the days of the booking (any overlap). */
function bookingAdminWhere(array $f): array {
    $where = [BOOKING_ADMIN_TABS[$f['status']][1]];
    $params = [];
    if ($f['q'] !== '') {
        $where[] = '(b.reference LIKE ? OR b.name LIKE ? OR b.email LIKE ? OR b.phone LIKE ? OR b.organisation LIKE ?)';
        array_push($params, ...array_fill(0, 5, likePattern($f['q'])));
    }
    if ($f['from'] !== '') { $where[] = 'COALESCE(b.end_date, b.booking_date) >= ?'; $params[] = $f['from']; }
    if ($f['to'] !== '') { $where[] = 'b.booking_date <= ?'; $params[] = $f['to']; }
    return [implode(' AND ', $where), $params];
}
function bookingAdminListUrl(array $f, ?int $page = null, string $path = '/admin/bookings'): string {
    $query = http_build_query(array_filter([...$f, 'page' => $page > 1 ? $page : null], fn ($v) => $v !== '' && $v !== null));
    return '{{BASE}}' . $path . ($query !== '' ? '?' . escape($query) : '');
}

function renderAdminBookings(array $admin): string {
    bookingExpireDue();
    $f = bookingAdminFilters();
    [$where, $params] = bookingAdminWhere($f);
    $total = (int) query("SELECT COUNT(*) AS n FROM bookings b WHERE $where", $params)[0]['n'];
    $lastPage = max(1, (int) ceil($total / BOOKING_ADMIN_PER_PAGE));
    $page = min(bookingAdminPage(), $lastPage);
    $rows = query("SELECT b.* FROM bookings b WHERE $where ORDER BY " . BOOKING_ADMIN_TABS[$f['status']][2] . ' LIMIT ' . BOOKING_ADMIN_PER_PAGE . ' OFFSET ' . (($page - 1) * BOOKING_ADMIN_PER_PAGE), $params);
    $body = implode('', array_map(function ($b) {
        $url = '{{BASE}}/admin/bookings/' . (int) $b['id'];
        $amount = $b['total_amount'] !== null ? bookingAdminRupees($b['total_amount']) . ($b['amount'] !== null ? '<br><span class="admin-muted">advance ' . bookingAdminRupees($b['amount']) . '</span>' : '') : ($b['quoted_amount'] !== null ? '<span class="admin-muted">' . bookingAdminRupees($b['quoted_amount']) . ' quoted</span>' : '—');
        return '<tr><td><a href="' . $url . '">' . escape($b['reference']) . '</a>' . ((int) $b['admin_modified'] ? '<br><span class="admin-badge admin-badge--booking-approved">edited</span>' : '') . '</td>'
            . '<td>' . escape($b['name']) . ($b['organisation'] ? '<br><span class="admin-muted">' . escape($b['organisation']) . '</span>' : '') . '<br><span class="admin-muted">' . escape($b['phone']) . '</span></td>'
            . '<td>' . escape(bookingAdminDays($b)) . '</td>'
            . '<td>' . escape(BOOKING_ADMIN_FORMATS[$b['format']] ?? $b['format']) . '<br><span class="admin-muted">' . escape(bookingAdminWhen($b['slot'], $b['format'])) . '</span></td>'
            . '<td class="admin-num">' . $amount . '</td><td>' . bookingAdminBadge($b['status']) . '</td>'
            . '<td class="admin-wide-only">' . escape(bookingAdminDateTime($b['created_at'])) . '</td>'
            . '<td class="admin-actions"><a href="' . $url . '">' . ($b['status'] === 'requested' ? 'Review' : 'View') . '</a></td></tr>';
    }, $rows));
    $table = $rows
        ? '<div class="admin-table-wrap"><table class="admin-table admin-table--booking"><thead><tr><th>Reference</th><th>Requester</th><th>Date</th><th>Package</th><th class="admin-num">Amount</th><th>Status</th><th class="admin-wide-only">Requested</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>' . $body . '</tbody></table></div>'
        : '<p class="admin-empty">' . ($f['q'] . $f['from'] . $f['to'] !== '' ? 'No bookings match these filters.' : 'No bookings in this list yet.') . '</p>';

    $byStatus = array_column(query('SELECT status, COUNT(*) AS n, COALESCE(SUM(COALESCE(end_date, booking_date) >= CURDATE()), 0) AS upcoming FROM bookings GROUP BY status'), null, 'status');
    $n = fn (string $s, string $key = 'n') => (int) ($byStatus[$s][$key] ?? 0);
    $counts = ['requests' => $n('requested'), 'awaiting' => $n('approved'), 'confirmed' => $n('confirmed', 'upcoming'), 'past' => $n('confirmed') - $n('confirmed', 'upcoming'),
        'closed' => $n('rejected') + $n('cancelled') + $n('expired'), 'all' => array_sum(array_column($byStatus, 'n'))];
    $tabs = '<nav class="admin-tabs" aria-label="Booking status">' . implode('', array_map(fn ($key, $tab) => '<a href="' . bookingAdminListUrl(['status' => $key, 'q' => $f['q'], 'from' => $f['from'], 'to' => $f['to']]) . '"'
        . ($key === $f['status'] ? ' aria-current="page"' : '') . '>' . escape($tab[0]) . ' <span class="admin-muted">' . number_format($counts[$key]) . '</span></a>', array_keys(BOOKING_ADMIN_TABS), BOOKING_ADMIN_TABS)) . '</nav>';
    return adminView('templates.admin.booking-list', $admin, '/admin/bookings', [
        'summary' => number_format($total) . ' booking' . ($total === 1 ? '' : 's') . ' in “' . escape(BOOKING_ADMIN_TABS[$f['status']][0]) . '”', 'tabs' => $tabs, 'table' => $table,
        'status' => escape($f['status']), 'search' => escape($f['q']), 'from' => escape($f['from']), 'to' => escape($f['to']),
        'export' => bookingAdminListUrl($f, null, '/admin/bookings/export.csv'), 'pager' => bookingAdminPager($page, $lastPage, fn (int $p) => bookingAdminListUrl($f, $p)),
        'enabled_note' => bookingSetting('booking_enabled') === '1' || filter_var(getenv('BOOKING_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN) ? ''
            : '<p class="admin-alert admin-alert--info" role="status">Booking is switched off, so the public booking page is hidden. Turn it on in <a href="{{BASE}}/admin/bookings/settings">Booking settings</a>.</p>',
    ]);
}

/** A CSV of the bookings in the current filters (all pages), one row each. Spreadsheet formulas are defused. */
function sendAdminBookingsCsv(array $admin): never {
    $f = bookingAdminFilters();
    [$where, $params] = bookingAdminWhere($f);
    $safe = fn ($value) => preg_match('~^[=+\-@\t\r]~', (string) $value) ? "'" . $value : (string) $value;
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bookings-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Reference', 'Requested', 'Status', 'Name', 'Email', 'Phone', 'Team or club', 'Opponent', 'Start date', 'Days', 'End date', 'Package', 'Slot', 'Players per team', 'Teams paying', 'Lunch', 'Tea', 'Meal deduction',
        'Stay needed', 'Stay people', 'Stay nights', 'Stay notes', 'Member tier', 'Discount %', 'Quoted', 'Stay charge', 'Extra charges', 'Extra note', 'Total', 'Advance %', 'Advance to pay now', 'Balance at the ground',
        'Edited by the team', 'Payment', 'Payment reference', 'Paid at', 'Message', 'Note to requester'], ',', '"', '');
    foreach (query("SELECT b.* FROM bookings b WHERE $where ORDER BY " . BOOKING_ADMIN_TABS[$f['status']][2] . ' LIMIT 5000', $params) as $b) {
        fputcsv($out, array_map($safe, [$b['reference'], $b['created_at'], BOOKING_ADMIN_STATUSES[$b['status']][0] ?? $b['status'], $b['name'], $b['email'], $b['phone'], $b['organisation'], $b['opponent'], $b['booking_date'],
            (int) $b['days'], $b['end_date'] ?: $b['booking_date'], BOOKING_ADMIN_FORMATS[$b['format']] ?? $b['format'], bookingAdminWhen($b['slot'], $b['format']), $b['players_per_team'], $b['teams_paying'],
            $b['want_lunch'] ? 'included' : 'skipped', $b['want_tea'] ? 'included' : 'skipped', $b['meal_deduction'], $b['stay_needed'] ? 'yes' : 'no', $b['stay_people'], $b['stay_nights'], $b['stay_notes'],
            $b['member_tier'], $b['discount_percent'], $b['quoted_amount'], $b['stay_amount'], $b['extra_amount'], $b['extra_note'], $b['total_amount'], $b['advance_percent'], $b['amount'], $b['balance_amount'],
            $b['admin_modified'] ? 'yes' : 'no', $b['payment_method'], $b['razorpay_payment_id'], $b['paid_at'], $b['notes'], $b['customer_note']]), ',', '"', '');
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------- one booking

function bookingAdminGet(int $id): array {
    $booking = query('SELECT * FROM bookings WHERE id = ?', [$id])[0] ?? notFound();
    $booking['events'] = query('SELECT * FROM booking_events WHERE booking_id = ? ORDER BY created_at DESC, id DESC', [$id]);
    return $booking;
}
/** Sends the approval email with the payment link again, through the core's mailer. */
function bookingAdminResend(array $b): bool {
    $sent = bookingMail((int) $b['id'], 'approved');
    bookingEvent((int) $b['id'], 'approved', 'The payment link was sent again.');
    return $sent;
}
/** Which form field an error key from the core belongs to. */
function bookingAdminErrorField(string $key): string {
    return ['slot' => 'slot_format', 'format' => 'slot_format', 'booking_date' => 'date'][$key] ?? $key;
}
/** The valid "package and time" pairs for the edit form's select: value "format:slot". */
function bookingAdminPairOptions(string $selected): string {
    $out = '';
    foreach (BOOKING_FORMAT_SLOTS as $format => $slots) {
        $out .= '<optgroup label="' . escape(BOOKING_ADMIN_FORMATS[$format]) . '">';
        foreach ($slots as $slot) $out .= '<option value="' . $format . ':' . $slot . '"' . ($selected === $format . ':' . $slot ? ' selected' : '') . '>' . escape(bookingOptionName($slot, $format) . ' (' . bookingTimeRange($slot, $format) . ')') . '</option>';
        $out .= '</optgroup>';
    }
    return $out;
}

function renderAdminBooking(array $admin, int $id): string {
    bookingExpireDue();
    $b = bookingAdminGet($id);
    $errors = [];
    $editErrors = [];
    $editMessage = '';
    $editOpen = false;
    $days = max(1, (int) $b['days']);
    $hold = (int) bookingSetting('booking_hold_hours', '48');
    $editable = in_array($b['status'], ['requested', 'approved'], true);
    $conflicts = $editable ? bookingAdminConflicts($b['booking_date'], $b['slot'], $b['format'], $days, $id) : [];
    $free = !$editable || bookingSlotFree($b['booking_date'], $b['slot'], $id, $b['format'], $days);
    if (!$free && !$conflicts) $conflicts[] = ['type' => 'other', 'id' => 0, 'text' => 'This time is not free on every day of the booking.'];
    $past = $b['booking_date'] < date('Y-m-d');
    $bd = bookingPriceBreakdown($b);
    $baseDefault = $bd['ok'] ? round((float) $bd['subtotal'] - (float) $bd['discount'], 2) : (float) $b['quoted_amount'];
    $isRevise = $b['status'] === 'approved';
    $input = ['base' => bookingAdminNumber($baseDefault), 'stay_amount' => bookingAdminNumber($bd['stay']), 'extra_amount' => bookingAdminNumber($b['extra_amount']), 'extra_note' => (string) $b['extra_note'],
        'advance_percent' => bookingAdminNumber($b['advance_percent']), 'hold_hours' => $isRevise ? '' : (string) $hold, 'customer_note' => '', 'reason' => '', 'note' => '', 'reference' => ''];
    // The edit form starts from what the booking is now.
    $edit = ['date' => $b['booking_date'], 'days' => (string) $days, 'slot_format' => $b['format'] . ':' . $b['slot'], 'teams_paying' => (string) (int) $b['teams_paying'], 'players_per_team' => (string) (int) $b['players_per_team'],
        'opponent' => (string) $b['opponent'], 'organisation' => (string) $b['organisation'], 'want_lunch' => (int) $b['want_lunch'], 'want_tea' => (int) $b['want_tea'], 'stay_needed' => (int) $b['stay_needed'],
        'stay_people' => (string) $b['stay_people'], 'stay_nights' => (string) $b['stay_nights'], 'stay_notes' => (string) $b['stay_notes'], 'notes' => (string) $b['notes'], 'edit_note' => ''];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = postedText('action');
        foreach (array_keys($input) as $key) if (isset($_POST[$key]) && is_string($_POST[$key])) $input[$key] = postedText($key);
        if ($action === 'note') {
            adminExecute('UPDATE bookings SET admin_note = ?, updated_at = NOW() WHERE id = ?', [mb_substr(postedText('admin_note'), 0, 2000) ?: null, $id]);
            adminFlash('Note saved.');
            adminRedirect('/admin/bookings/' . $id);
        }
        $flash = null;
        if ($action === 'edit') {
            $editOpen = true;
            foreach (['date', 'days', 'slot_format', 'teams_paying', 'players_per_team', 'opponent', 'organisation', 'stay_people', 'stay_nights', 'stay_notes', 'notes', 'edit_note'] as $key) $edit[$key] = postedText($key);
            foreach (['want_lunch', 'want_tea', 'stay_needed'] as $key) $edit[$key] = isset($_POST[$key]) ? 1 : 0;
            [$format, $slot] = array_pad(explode(':', $edit['slot_format'], 2), 2, '');
            if (!$editable) $editMessage = 'Only a request or an unpaid approved booking can be changed.';
            elseif (mb_strlen($edit['edit_note']) > 200) $editErrors['edit_note'] = 'The note must be 200 characters or fewer.';
            else {
                $result = bookingUpdateRequirements($id, ['booking_date' => $edit['date'], 'days' => (int) $edit['days'], 'slot' => $slot, 'format' => $format, 'teams_paying' => (int) $edit['teams_paying'],
                    'players_per_team' => $edit['players_per_team'], 'opponent' => $edit['opponent'], 'organisation' => $edit['organisation'], 'want_lunch' => $edit['want_lunch'], 'want_tea' => $edit['want_tea'],
                    'stay_needed' => $edit['stay_needed'], 'stay_people' => $edit['stay_people'], 'stay_nights' => $edit['stay_nights'], 'stay_notes' => $edit['stay_notes'], 'notes' => $edit['notes']], (int) $admin['id'], $edit['edit_note'] ?: null);
                if ($result['ok']) {
                    $flash = $result['changes'] ? 'Request updated. ' . implode('; ', array_map(fn ($c) => $c['label'] . ': ' . $c['from'] . ' → ' . $c['to'], $result['changes']))
                        . ($b['status'] === 'approved' ? '. The payable amount is unchanged until you revise the approval.' : '.') : 'No changes were made.';
                } else {
                    foreach ($result['errors'] as $key => $message) $editErrors[bookingAdminErrorField((string) $key)] ??= $message;
                    $editMessage = (string) ($result['error'] ?? 'The request could not be changed.');
                }
            }
        } elseif ($action === 'approve') {
            $base = bookingAdminAmount($input['base']);
            $stay = $input['stay_amount'] === '' ? 0.0 : bookingAdminAmount($input['stay_amount']);
            $extra = $input['extra_amount'] === '' ? 0.0 : bookingAdminAmount($input['extra_amount']);
            $percent = bookingAdminAmount($input['advance_percent'], 100);
            if (!$editable) $errors['approve'] = 'Only a request or an unpaid approved booking can be approved.';
            elseif ($conflicts || $past) $errors['approve'] = $past ? 'The first day has passed.' : 'This time is no longer free. See the warnings above.';
            if ($base === null || $base <= 0) $errors['base'] = 'Enter the price in rupees, more than 0.';
            if ($stay === null) $errors['stay_amount'] = 'Enter the accommodation charge in rupees, or leave it empty.';
            if ($extra === null) $errors['extra_amount'] = 'Enter the extra charges in rupees, or leave it empty.';
            if ($percent === null || $percent < 1) $errors['advance_percent'] = 'Enter the advance as a percentage from 1 to 100.';
            if ($input['hold_hours'] === '' ? !$isRevise : (!ctype_digit($input['hold_hours']) || (int) $input['hold_hours'] < 1 || (int) $input['hold_hours'] > 720)) $errors['hold_hours'] = 'Enter whole hours from 1 to 720.';
            if ($extra > 0 && $input['extra_note'] === '') $errors['extra_note'] = 'Say what the extra charges are for (for example extra players).';
            if (mb_strlen($input['customer_note']) > 300) $errors['customer_note'] = 'The message must be 300 characters or fewer.';
            if (mb_strlen($input['extra_note']) > 255) $errors['extra_note'] = 'The note must be 255 characters or fewer.';
            if (!$errors) {
                $total = (float) ($base + $stay + $extra);
                adminExecute("UPDATE bookings SET advance_percent = ? WHERE id = ? AND status IN ('requested', 'approved')", [$percent, $id]);
                if (bookingApprove($id, $total, $input['customer_note'] !== '' ? $input['customer_note'] : null, (int) $admin['id'], $input['hold_hours'] === '' ? null : (int) $input['hold_hours'],
                    ['stay_amount' => $stay, 'extra_amount' => $extra, 'extra_note' => $input['extra_note']])) {
                    $flash = 'Booking ' . $b['reference'] . ($isRevise ? ' approval revised. The requester gets the new payment details by email.' : ' approved. The requester gets the payment details by email.');
                } else $errors['approve'] = 'The booking could not be approved: the time may have just been taken or the request changed. Reload the page and check.';
            }
        } elseif ($action === 'reject') {
            if ($input['reason'] === '') $errors['reason'] = 'Say why, so the requester understands.';
            elseif (mb_strlen($input['reason']) > 300) $errors['reason'] = 'The reason must be 300 characters or fewer.';
            elseif ($b['status'] !== 'requested') $errors['reject'] = 'Only a new request can be rejected.';
            elseif (bookingReject($id, $input['reason'], (int) $admin['id'])) $flash = 'Booking ' . $b['reference'] . ' rejected.';
            else $errors['reject'] = 'The booking could not be rejected. Reload the page and try again.';
        } elseif ($action === 'cancel') {
            if (mb_strlen($input['note']) > 300) $errors['note'] = 'The note must be 300 characters or fewer.';
            elseif (!in_array($b['status'], ['requested', 'approved', 'confirmed'], true)) $errors['cancel'] = 'This booking can’t be cancelled.';
            elseif (bookingCancel($id, $input['note'] ?: null, (int) $admin['id'])) $flash = 'Booking ' . $b['reference'] . ' cancelled.';
            else $errors['cancel'] = 'The booking could not be cancelled. Reload the page and try again.';
        } elseif ($action === 'paid') {
            if (mb_strlen($input['reference']) > 64) $errors['reference'] = 'The reference must be 64 characters or fewer.';
            elseif (!in_array($b['status'], ['approved', 'expired'], true)) $errors['paid'] = 'Only a booking awaiting payment can be marked as paid.';
            elseif (bookingMarkPaid($id, 'offline', $input['reference'] ?: null, (int) $admin['id'])) $flash = 'Booking ' . $b['reference'] . ' is confirmed: the advance was recorded as paid offline.';
            else $errors['paid'] = 'The booking could not be marked as paid. Reload the page and try again.';
        } elseif ($action === 'resend') {
            if ($b['status'] !== 'approved') $errors['resend'] = 'The payment link can only be sent for an approved booking awaiting payment.';
            else $flash = bookingAdminResend($b) ? 'The payment link was sent to ' . $b['email'] . '.' : 'The email could not be sent. Check the email settings.';
        } else {
            $errors['approve'] = 'Unknown action.';
        }
        if ($flash !== null && !$errors && !$editErrors && $editMessage === '') {
            adminFlash($flash);
            adminRedirect('/admin/bookings/' . $id);
        }
    }

    $csrf = '<input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">';
    $post = '{{BASE}}/admin/bookings/' . $id;
    $form = fn (string $act, string $inner, string $button, string $class = '') => '<form class="admin-form" method="post" action="' . $post . '">' . $csrf . '<input type="hidden" name="action" value="' . $act . '">' . $inner
        . '<div class="admin-form-actions"><button type="submit"' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . $button . '</button></div></form>';
    $alert = fn (string $key) => isset($errors[$key]) ? '<p class="admin-field-error" role="alert">' . escape($errors[$key]) . '</p>' : '';
    $text = fn (string $key, string $label, int $max, string $hint = '', bool $optional = false, int $rows = 2) => bookingAdminField($key, $label, '<textarea id="' . $key . '" name="' . $key . '" rows="' . $rows . '" maxlength="' . $max . '"' . fieldInvalid($errors, $key) . '>' . escape($input[$key]) . '</textarea>', $errors, $hint, $optional);

    // ---- the actions that make sense for the current status
    $panels = '';
    if ($editable) {
        $e = fn (string $key) => $edit[$key];
        $ef = fn (string $id, string $label, string $control, string $hint = '', bool $optional = false) => bookingAdminField($id, $label, $control, $editErrors, $hint, $optional);
        $daysOptions = implode('', array_map(fn ($n) => '<option value="' . $n . '"' . ((int) $e('days') === $n ? ' selected' : '') . '>' . bookingDaysText($n) . '</option>', range(1, BOOKING_MAX_DAYS)));
        $teamOptions = '<option value="1"' . ($e('teams_paying') === '1' ? ' selected' : '') . '>This team only</option><option value="2"' . ($e('teams_paying') === '2' ? ' selected' : '') . '>Both teams (this request pays for both)</option>';
        $editForm = ($editMessage !== '' ? '<p class="admin-alert" role="alert">' . escape($editMessage) . '</p>' : '')
            . '<p class="admin-hint">Change what the requester asked for. The price is recalculated and the requester is told what changed when you approve. Their name, email and phone can’t be edited here.</p>'
            . '<div class="admin-fields-3">' . $ef('date', 'First day', bookingAdminInput('date', 'date', $e('date'), $editErrors, ' required min="' . date('Y-m-d') . '"'))
            . $ef('days', 'Number of days', '<select id="days" name="days"' . fieldInvalid($editErrors, 'days') . '>' . $daysOptions . '</select>')
            . $ef('slot_format', 'Package and time', '<select id="slot_format" name="slot_format"' . fieldInvalid($editErrors, 'slot_format') . '>' . bookingAdminPairOptions($e('slot_format')) . '</select>', 'Only valid combinations are listed. The time must be free on every day.') . '</div>'
            . '<div class="admin-fields-3">' . $ef('teams_paying', 'Payment for', '<select id="teams_paying" name="teams_paying"' . fieldInvalid($editErrors, 'teams_paying') . '>' . $teamOptions . '</select>', 'Not used for the full-day package, which is a flat price.')
            . $ef('players_per_team', 'Players per team', bookingAdminInput('players_per_team', 'text', $e('players_per_team'), $editErrors, ' inputmode="numeric"'), 'The rates cover 11.')
            . $ef('organisation', 'Team or club', bookingAdminInput('organisation', 'text', $e('organisation'), $editErrors, ' maxlength="255"'), '', true) . '</div>'
            . $ef('opponent', 'Opponent', bookingAdminInput('opponent', 'text', $e('opponent'), $editErrors, ' maxlength="255"'), '', true)
            . '<fieldset class="admin-booking-checks"><legend>Included meals</legend>' . bookingAdminCheck('want_lunch', 'Lunch', (bool) $e('want_lunch')) . bookingAdminCheck('want_tea', 'Tea', (bool) $e('want_tea'))
            . '<p class="admin-hint">Each meal skipped takes ' . bookingMoney(bookingMealDeduction()) . ' off, per team per day.</p></fieldset>'
            . '<fieldset class="admin-booking-checks"><legend>Overnight accommodation</legend>' . bookingAdminCheck('stay_needed', 'Accommodation is needed', (bool) $e('stay_needed'))
            . '<div class="admin-fields-3">' . $ef('stay_people', 'People', bookingAdminInput('stay_people', 'text', $e('stay_people'), $editErrors, ' inputmode="numeric"'), '', true)
            . $ef('stay_nights', 'Nights', bookingAdminInput('stay_nights', 'text', $e('stay_nights'), $editErrors, ' inputmode="numeric"'), '', true)
            . $ef('stay_notes', 'Notes', bookingAdminInput('stay_notes', 'text', $e('stay_notes'), $editErrors, ' maxlength="500"'), '', true) . '</div></fieldset>'
            . $ef('notes', 'Message from the requester', '<textarea id="notes" name="notes" rows="3" maxlength="2000"' . fieldInvalid($editErrors, 'notes') . '>' . escape($e('notes')) . '</textarea>', '', true)
            . $ef('edit_note', 'Note for the history', bookingAdminInput('edit_note', 'text', $e('edit_note'), $editErrors, ' maxlength="200"'), 'Optional: why you changed it. It is kept in the history.', true);
        $panels .= '<details class="admin-panel admin-booking-edit" aria-labelledby="b-edit"' . ($editOpen || $editMessage !== '' ? ' open' : '') . '><summary id="b-edit">Edit the request</summary>'
            . '<form class="admin-form" method="post" action="' . $post . '">' . $csrf . '<input type="hidden" name="action" value="edit">' . $editForm
            . '<div class="admin-form-actions"><button type="submit">Save the changes</button></div></form></details>';

        $warn = $conflicts || $past;
        $stayHint = $b['stay_needed'] ? 'Requested: ' . bookingStayText($b['stay_people'] === null ? null : (int) $b['stay_people'], $b['stay_nights'] === null ? null : (int) $b['stay_nights'])
            . '. At the standard rate of ' . bookingMoney(bookingStayRate()) . ' per person per night, including three meals and refreshments, that is ' . bookingMoney(bookingStayAmount($b['stay_people'] === null ? null : (int) $b['stay_people'], $b['stay_nights'] === null ? null : (int) $b['stay_nights'])) . '.' : 'No accommodation was requested.';
        $approve = ($warn ? '<p class="admin-alert" role="alert">' . ($past ? 'The first day has passed, so the request can only be rejected or cancelled.' : 'This time is not free any more: approving is switched off until the clash is cleared. Reject or cancel this request, change its date or time with “Edit the request”, or change the other booking.') . '</p>' : '')
            . ($isRevise ? '<p class="admin-muted">Currently approved at ' . bookingAdminRupees($b['total_amount']) . ' (advance ' . bookingAdminRupees($b['amount']) . '). Revising sends the new payment details and a fresh payment link.</p>' : '')
            . '<div class="admin-fields-2">'
            . bookingAdminField('base', 'Package price after meals and member discount (₹)', bookingAdminInput('base', 'text', $input['base'], $errors, ' inputmode="decimal" required data-booking-calc'), $errors,
                'Pre-filled from the rates: ' . ($bd['ok'] ? bookingAdminRupees($bd['subtotal']) . ' for ' . escape(BOOKING_ADMIN_FORMATS[$b['format']] ?? '') . ($bd['teams'] > 1 ? ' × ' . $bd['teams'] . ' teams' : '') . ($days > 1 ? ' × ' . bookingDaysText($days) : '')
                    . ($bd['meal_deduction'] > 0 ? ', less ' . bookingAdminRupees($bd['meal_deduction']) . ' for skipped meals' : '') . ($bd['discount'] > 0 ? ', less ' . bookingAdminRupees($bd['discount']) . ' member discount' : '') : 'no price set for this package') . '. You can change it.')
            . bookingAdminField('advance_percent', 'Advance to pay online (%)', bookingAdminInput('advance_percent', 'text', $input['advance_percent'], $errors, ' inputmode="decimal" required data-booking-calc'), $errors, 'The rest is paid at the ground.')
            . '</div><div class="admin-fields-2">'
            . bookingAdminField('stay_amount', 'Accommodation charge (₹)', bookingAdminInput('stay_amount', 'text', $input['stay_amount'], $errors, ' inputmode="decimal" data-booking-calc'), $errors, $stayHint, true)
            . bookingAdminField('extra_amount', 'Extra charges (₹)', bookingAdminInput('extra_amount', 'text', $input['extra_amount'], $errors, ' inputmode="decimal" data-booking-calc'), $errors,
                (int) $b['players_per_team'] > 11 ? '<strong>' . (int) $b['players_per_team'] . ' players per team requested:</strong> the rates cover 11, so add the extra-player charge here.' : 'For example extra players beyond 11.', true)
            . '</div>' . bookingAdminField('extra_note', 'What the extra charges are for', bookingAdminInput('extra_note', 'text', $input['extra_note'], $errors, ' maxlength="255"'), $errors, 'Shown to the requester. Needed when there are extra charges.', true)
            . '<p class="admin-booking-sum" aria-live="polite">Total <strong data-booking-total>—</strong> · pay now <strong data-booking-now>—</strong> · balance at the ground <strong data-booking-balance>—</strong></p>'
            . '<div class="admin-fields-2">' . bookingAdminField('hold_hours', $isRevise ? 'Restart the payment deadline (hours)' : 'Hold the slot for (hours)', bookingAdminInput('hold_hours', 'text', $input['hold_hours'], $errors, ' inputmode="numeric"' . ($isRevise ? '' : ' required')), $errors,
                $isRevise ? 'Leave empty to keep the current deadline (' . escape(bookingAdminDateTime($b['payment_due_at'])) . ').' : 'If the advance is not paid in this time, the booking expires and the slot is free again.', $isRevise) . '</div>'
            . $text('customer_note', 'Message to the requester', 300, 'Optional. It goes in the approval email with the payment details.', true, 3) . $alert('approve');
        $panels .= '<section class="admin-panel" aria-labelledby="b-approve"><h2 id="b-approve">' . ($isRevise ? 'Revise the approval' : 'Approve') . '</h2>'
            . '<form class="admin-form" method="post" action="' . $post . '">' . $csrf . '<input type="hidden" name="action" value="approve">' . $approve
            . '<div class="admin-form-actions"><button type="submit"' . ($warn ? ' disabled' : '') . '>' . ($isRevise ? 'Revise and send the new payment details' : 'Approve and send the payment details') . '</button></div></form></section>';
    }
    if ($b['status'] === 'requested') {
        $panels .= '<section class="admin-panel" aria-labelledby="b-reject"><h2 id="b-reject">Reject</h2>'
            . $form('reject', $text('reason', 'Reason', 300, 'The requester is emailed this reason.') . $alert('reject'), 'Reject the request', 'admin-button-danger') . '</section>';
    }
    if (in_array($b['status'], ['approved', 'expired'], true) && $b['amount'] !== null) {
        $panels .= '<section class="admin-panel" aria-labelledby="b-paid"><h2 id="b-paid">' . ($b['status'] === 'expired' ? 'The hold expired. Advance of ' : 'Awaiting the advance of ') . bookingAdminRupees($b['amount']) . '</h2>'
            . ($b['status'] === 'expired' ? '<p class="admin-muted">If the advance arrived after the hold ended, you can still confirm the booking while the slot is free.</p>' : '<p class="admin-muted">Held until ' . escape(bookingAdminDateTime($b['payment_due_at'])) . '. The requester pays online with the link, or you can record a bank transfer or cash here.</p>')
            . $form('paid', bookingAdminField('reference', 'Payment reference', bookingAdminInput('reference', 'text', $input['reference'], $errors, ' maxlength="64"'), $errors, 'For example the UPI or bank transaction ID.', true) . $alert('paid'), 'Mark the advance as paid offline')
            . ($b['status'] === 'approved' ? $form('resend', $alert('resend'), 'Send the payment link again', 'admin-button-secondary') : '') . '</section>';
    }
    if (in_array($b['status'], ['requested', 'approved', 'confirmed'], true)) {
        $panels .= '<section class="admin-panel" aria-labelledby="b-cancel"><h2 id="b-cancel">Cancel the booking</h2>'
            . ($b['status'] === 'confirmed' ? '<p class="admin-muted">The advance was paid. Refunds are handled outside the site: note it below.</p>' : '')
            . $form('cancel', $text('note', 'Note to the requester', 300, '', true) . $alert('cancel'), 'Cancel this booking', 'admin-button-danger') . '</section>';
    }
    if ($panels === '') $panels = '<section class="admin-panel"><p class="admin-muted">This booking is ' . escape(strtolower(BOOKING_ADMIN_STATUSES[$b['status']][0] ?? $b['status'])) . ' and can’t change status.</p></section>';

    $warnings = $conflicts ? '<div class="admin-alert" role="alert"><strong>Clash with another booking or a blocked date</strong><ul>' . implode('', array_map(fn ($c) => '<li>' . escape($c['text']) . ($c['type'] === 'booking' ? ' <a href="{{BASE}}/admin/bookings/' . $c['id'] . '">Open it</a>' : ($c['type'] === 'block' ? ' <a href="{{BASE}}/admin/bookings/blocks">Blocked dates</a>' : '')) . '</li>', $conflicts)) . '</ul></div>' : '';
    // What the approver changed from the original request.
    $changes = bookingChangeList($b);
    $changed = $changes ? '<div class="admin-alert admin-alert--info" role="status"><strong>Changed from the original request</strong><ul>' . implode('', array_map(fn ($c) => '<li>' . escape($c['label']) . ': <s>' . escape($c['from']) . '</s> → <strong>' . escape($c['to']) . '</strong></li>', $changes))
        . '</ul><p class="admin-hint">The requester is shown these changes in the approval email and on their booking page.</p></div>' : '';
    $pending = $editable ? (int) query("SELECT COUNT(*) AS n FROM bookings WHERE booking_date <= ? AND COALESCE(end_date, booking_date) >= ? AND id <> ? AND status = 'requested'", [bookingEndDate($b['booking_date'], $days), $b['booking_date'], $id])[0]['n'] : 0;
    $facts = fn (array $rows) => '<dl class="admin-facts">' . implode('', array_map(fn ($r) => '<div><dt>' . $r[0] . '</dt><dd>' . $r[1] . '</dd></div>', $rows)) . '</dl>';
    $skipped = [];
    if (!(int) $b['want_lunch']) $skipped[] = 'lunch';
    if (!(int) $b['want_tea']) $skipped[] = 'tea';
    $details = $facts([
        ['Date', escape(bookingAdminDays($b))], ['Package', escape(BOOKING_ADMIN_FORMATS[$b['format']] ?? $b['format'])], ['Slots', escape(bookingAdminWhen($b['slot'], $b['format']))],
        ['Teams paying', $b['format'] === 'full_day' ? 'Flat package price' : ((int) $b['teams_paying'] === 2 ? 'Both teams (this request pays for both)' : 'This team only')],
        ['Players per team', (int) $b['players_per_team'] . ((int) $b['players_per_team'] > 11 ? ' <span class="admin-badge admin-badge--booking-approved">over 11: extra charge</span>' : '')],
        ['Opponent', escape($b['opponent'] ?: '—')],
        ['Lunch and tea', $skipped ? 'Skipping ' . implode(' and ', $skipped) . ' (−' . escape(bookingMoney($b['meal_deduction'])) . ')' : 'Both included'],
        ['Accommodation', $b['stay_needed'] ? escape(bookingStayText($b['stay_people'] === null ? null : (int) $b['stay_people'], $b['stay_nights'] === null ? null : (int) $b['stay_nights'])) . ((float) $b['stay_amount'] > 0 ? '<br><span class="admin-muted">charge ' . bookingAdminRupees($b['stay_amount']) . '</span>' : '<br><span class="admin-muted">charge not set yet</span>') . ($b['stay_notes'] ? '<br><span class="admin-muted">' . escape($b['stay_notes']) . '</span>' : '') : 'Not needed'],
    ]);
    // The price: the core's lines (the formula), with the stored total once approved.
    $money = bookingMoneyLines($b);
    $lines = implode('', array_map(fn ($l) => '<tr><td>' . escape($l['label']) . '</td><td class="admin-num">' . ($l['amount'] === null ? '—' : ($l['amount'] < 0 ? '−' : '') . bookingAdminRupees(abs((float) $l['amount']))) . '</td></tr>', $money['lines']));
    $priceTable = '<div class="admin-table-wrap"><table class="admin-table admin-table--price"><caption class="admin-visually-hidden">Price breakdown</caption><thead><tr><th>Item</th><th class="admin-num">Amount</th></tr></thead><tbody>' . $lines . '</tbody><tfoot>'
        . '<tr><th scope="row">' . ($money['indicative'] ? 'Total (indicative until approved)' : 'Total') . '</th><td class="admin-num"><strong>' . bookingAdminRupees($money['total']) . '</strong></td></tr>'
        . '<tr><th scope="row">Advance (' . escape(bookingPercentText((float) $money['advance_percent'])) . '%) paid online</th><td class="admin-num">' . bookingAdminRupees($money['advance']) . '</td></tr>'
        . '<tr><th scope="row">Balance at the ground</th><td class="admin-num">' . bookingAdminRupees($money['balance']) . '</td></tr></tfoot></table></div>';
    $payment = $facts([
        ['Member discount', $b['member_tier'] ? escape(ucfirst($b['member_tier'])) . ' member: ' . escape(bookingPercentText((float) $b['discount_percent'])) . '%' : 'Not a member'],
        ['Payment', $b['paid_at'] ? escape(bookingAdminDateTime($b['paid_at'])) . ' · ' . escape((string) $b['payment_method']) . ($b['razorpay_payment_id'] ? '<br><span class="admin-muted">' . escape($b['razorpay_payment_id']) . '</span>' : '') : ($b['status'] === 'approved' ? 'Held until ' . escape(bookingAdminDateTime($b['payment_due_at'])) : '—')],
    ]);
    $label = fn (array $e) => $e['status'] === 'modified' ? 'Request changed' : (BOOKING_ADMIN_STATUSES[$e['status']][0] ?? ucfirst($e['status']));
    $timeline = $b['events'] ? '<ol class="admin-shop-timeline">' . implode('', array_map(fn ($e) => '<li><strong>' . escape($label($e)) . '</strong> <span class="admin-muted">' . escape(bookingAdminDateTime($e['created_at'])) . '</span>'
        . ($e['note'] ? '<br>' . escape($e['note']) : '') . '</li>', $b['events'])) . '</ol>' : '<p class="admin-muted">No history yet.</p>';
    return adminView('templates.admin.booking-detail', $admin, '/admin/bookings', [
        'title' => 'Booking ' . escape($b['reference']), 'status_badge' => bookingAdminBadge($b['status']), 'requested' => escape(bookingAdminDateTime($b['created_at'])),
        'name' => escape($b['name']), 'email' => escape($b['email']), 'phone' => escape($b['phone']), 'organisation' => $b['organisation'] ? '<br>' . escape($b['organisation']) : '',
        'message' => $b['notes'] ? '<p><strong>Message from the requester:</strong><br>' . nl2br(escape($b['notes'])) . '</p>' : '', 'note_to_requester' => $b['customer_note'] ? '<p><strong>Note to the requester:</strong><br>' . nl2br(escape($b['customer_note'])) . '</p>' : '',
        'details' => $details, 'money' => $priceTable . $payment, 'warnings' => $warnings . $changed, 'pending' => $pending > 0 ? '<p class="admin-hint">' . $pending . ' other request' . ($pending === 1 ? ' is' : 's are') . ' waiting for these dates.</p>' : '',
        'panels' => $panels, 'timeline' => $timeline, 'order_url' => $post, 'admin_note' => escape((string) $b['admin_note']),
        'status_url' => '{{BASE}}/booking/' . $b['token'], 'pay_url' => $b['status'] === 'approved' ? '<a href="{{BASE}}/booking/' . $b['token'] . '/pay">Payment page</a>' : '',
        'calendar_url' => '{{BASE}}/admin/bookings/calendar?month=' . substr($b['booking_date'], 0, 7) . '&amp;day=' . $b['booking_date'],
    ]);
}
