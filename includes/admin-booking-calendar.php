<?php
declare(strict_types=1);

// The booking calendar (/admin/bookings/calendar): a month grid with the state of each slot of each day, and
// a day view with its bookings and shortcuts to block it. The states come from the core (bookingCalendarStates()), so a
// two-match, full-day or multi-day booking shows in every slot of every day it occupies. States are written out, not only coloured: Free, N pending, Held, Booked, Blocked.
require_once __DIR__ . '/admin-bookings.php';

// state => [text, css class]; a slot takes the first state that applies, in this order.
const BOOKING_ADMIN_CAL_STATES = ['blocked' => ['Blocked', 'blocked'], 'booked' => ['Booked', 'booked'], 'held' => ['Held', 'held'], 'pending' => ['pending', 'pending'], 'free' => ['Free', 'free']];

/**
 * The state of each slot on $date: the core's state (blocked, booked, held or available, over every day of every booking) with
 * pending requests laid over free slots. slot => ['state', 'pending' (count), 'refs' (references)].
 */
function bookingAdminDayStates(string $date, array $core, array $requests): array {
    $slots = [];
    foreach (array_keys(BOOKING_ADMIN_SLOTS) as $slot) {
        $state = ['available' => 'free', 'held' => 'held', 'booked' => 'booked', 'blocked' => 'blocked'][$core[$date][$slot] ?? 'available'] ?? 'free';
        $slots[$slot] = ['state' => $state, 'pending' => 0, 'refs' => []];
    }
    foreach ($requests as $b) {
        if ($date < $b['booking_date'] || $date > $b['last_date']) continue;
        foreach (bookingOccupiedSlots($b['slot'], $b['format']) as $slot) {
            if (!isset($slots[$slot])) continue;
            $slots[$slot]['refs'][] = $b['reference'];
            $slots[$slot]['pending']++;
            if ($slots[$slot]['state'] === 'free') $slots[$slot]['state'] = 'pending';
        }
    }
    return $slots;
}

function renderAdminBookingCalendar(array $admin): string {
    bookingExpireDue();
    $month = is_string($_GET['month'] ?? null) && preg_match('~^(\d{4})-(\d{2})$~', $_GET['month'], $m) && checkdate((int) $m[2], 1, (int) $m[1]) && (int) $m[1] >= 2020 && (int) $m[1] <= 2100 ? $_GET['month'] : date('Y-m');
    $first = new DateTimeImmutable($month . '-01');
    $daysInMonth = (int) $first->format('t');
    $last = $month . '-' . sprintf('%02d', $daysInMonth);
    $day = is_string($_GET['day'] ?? null) && preg_match('~^\d{4}-\d{2}-\d{2}$~', $_GET['day']) && strtotime($_GET['day']) ? $_GET['day'] : '';
    $core = bookingCalendarStates($month . '-01', $last);
    $requests = query("SELECT reference, booking_date, COALESCE(end_date, booking_date) AS last_date, slot, format FROM bookings WHERE status = 'requested' AND booking_date <= ? AND COALESCE(end_date, booking_date) >= ?", [$last, $month . '-01']);
    $url = fn (string $m, string $d = '') => '{{BASE}}/admin/bookings/calendar?month=' . $m . ($d !== '' ? '&amp;day=' . $d : '');
    $today = date('Y-m-d');

    $cells = [];
    for ($i = 1 - (int) $first->format('N'); $i < $daysInMonth + (7 - ((int) $first->format('N') - 1 + $daysInMonth) % 7) % 7; $i++) {
        if ($i < 0 || $i >= $daysInMonth) { $cells[] = '<td class="admin-cal__empty" aria-hidden="true"></td>'; continue; }
        $date = $first->modify('+' . $i . ' days')->format('Y-m-d');
        $states = bookingAdminDayStates($date, $core, $requests);
        $items = '';
        foreach ($states as $slot => $s) {
            [$text, $class] = BOOKING_ADMIN_CAL_STATES[$s['state']];
            $items .= '<li class="admin-cal__slot admin-cal__slot--' . $class . '"><span class="admin-cal__slot-name">' . escape(BOOKING_ADMIN_SLOTS[$slot]) . '</span> <span class="admin-cal__slot-state">'
                . ($s['state'] === 'pending' ? $s['pending'] . ' pending' : $text) . '</span></li>';
        }
        $label = date('l j F', strtotime($date));
        $cells[] = '<td class="admin-cal__day' . ($date === $today ? ' is-today' : '') . ($date === $day ? ' is-selected' : '') . '"><a class="admin-cal__num" href="' . $url($month, $date) . '#day"' . ($date === $today ? ' aria-current="date"' : '') . '>'
            . '<span aria-hidden="true">' . (int) substr($date, 8) . '</span><span class="admin-visually-hidden">' . escape($label) . '</span></a><ul class="admin-cal__slots">' . $items . '</ul></td>';
    }
    $weeks = array_chunk($cells, 7);
    $table = '<div class="admin-table-wrap"><table class="admin-cal"><caption class="admin-visually-hidden">Bookings in ' . escape($first->format('F Y')) . '</caption><thead><tr>'
        . implode('', array_map(fn ($d) => '<th scope="col"><span aria-hidden="true">' . substr($d, 0, 3) . '</span><span class="admin-visually-hidden">' . $d . '</span></th>', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])) . '</tr></thead><tbody>'
        . implode('', array_map(fn ($w) => '<tr>' . implode('', $w) . '</tr>', $weeks)) . '</tbody></table></div>';

    // The day view.
    $dayPanel = '';
    if ($day !== '') {
        $csrf = '<input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">';
        $list = query('SELECT * FROM bookings WHERE booking_date <= ? AND COALESCE(end_date, booking_date) >= ? ORDER BY FIELD(status, \'confirmed\', \'approved\', \'requested\', \'rejected\', \'cancelled\', \'expired\'), created_at', [$day, $day]);
        $rows = implode('', array_map(fn ($b) => '<tr><td><a href="{{BASE}}/admin/bookings/' . (int) $b['id'] . '">' . escape($b['reference']) . '</a></td><td>' . escape($b['name']) . '</td><td>' . escape(BOOKING_ADMIN_FORMATS[$b['format']] ?? $b['format'])
            . '<br><span class="admin-muted">' . escape(bookingAdminWhen($b['slot'], $b['format'])) . ((int) $b['days'] > 1 ? ' · ' . escape(bookingAdminDays($b)) : '') . '</span></td><td>' . bookingAdminBadge($b['status']) . '</td></tr>', $list));
        $dayBlocks = query('SELECT * FROM booking_blocks WHERE ? BETWEEN start_date AND end_date', [$day]);
        $blockHtml = $dayBlocks ? '<ul>' . implode('', array_map(fn ($b) => '<li>Blocked' . ($b['slot'] === 'all' ? ' all day' : ': ' . escape(BOOKING_ADMIN_SLOTS[$b['slot']]) . ' slot') . ($b['reason'] ? ' (' . escape($b['reason']) . ')' : '') . ' <form class="admin-inline-form" method="post" action="{{BASE}}/admin/bookings/blocks">' . $csrf
            . '<input type="hidden" name="action" value="delete"><input type="hidden" name="block_id" value="' . (int) $b['id'] . '"><input type="hidden" name="back_day" value="' . $day . '"><button type="submit" class="admin-danger-link">Remove the block</button></form></li>', $dayBlocks)) . '</ul>' : '<p class="admin-muted">Nothing is blocked on this day.</p>';
        $blockForms = implode('', array_map(fn ($slot, $label) => '<form class="admin-inline-form" method="post" action="{{BASE}}/admin/bookings/blocks">' . $csrf . '<input type="hidden" name="action" value="add"><input type="hidden" name="start_date" value="' . $day . '"><input type="hidden" name="end_date" value="' . $day . '">'
            . '<input type="hidden" name="slot" value="' . $slot . '"><input type="hidden" name="back_day" value="' . $day . '"><input type="hidden" name="reason" value="Blocked from the calendar"><button type="submit" class="admin-button-secondary">' . $label . '</button></form>',
            ['all', ...array_keys(BOOKING_ADMIN_SLOTS)], ['Block the whole day', ...array_map(fn ($l) => 'Block ' . strtolower($l), BOOKING_ADMIN_SLOTS)]));
        $dayPanel = '<section class="admin-panel admin-section" id="day" aria-labelledby="cal-day"><h2 id="cal-day">' . escape(bookingAdminDate($day, 'l, j F Y')) . '</h2>'
            . ($list ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Reference</th><th>Requester</th><th>Package</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="admin-muted">No bookings or requests on this day.</p>')
            . '<h3>Blocked</h3>' . $blockHtml . '<div class="admin-inline-actions">' . $blockForms . '</div></section>';
    }
    $prev = $first->modify('-1 month')->format('Y-m');
    $next = $first->modify('+1 month')->format('Y-m');
    return adminView('templates.admin.booking-calendar', $admin, '/admin/bookings/calendar', [
        'month_label' => escape($first->format('F Y')), 'prev' => $url($prev), 'next' => $url($next), 'today' => $url(date('Y-m'), $today) . '#day', 'table' => $table, 'day_panel' => $dayPanel,
        'legend' => '<p class="admin-hint">Each day shows its three slots: <strong>Free</strong>, <strong>N pending</strong> requests, <strong>Held</strong> (approved, waiting for the advance), <strong>Booked</strong> (confirmed) or <strong>Blocked</strong>. A two-match booking shows in two slots, a full day in all three, and a multi-day booking on each of its days.</p>',
    ]);
}
