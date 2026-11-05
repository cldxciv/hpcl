<?php
declare(strict_types=1);

// Booking settings (/admin/bookings/settings): the on/off switch, the ground, slot times, how far ahead people can
// book, who is told about requests, the bank details and terms, and the packages and rates (booking_rates, one row per
// package). Blocked dates (/admin/bookings/blocks) live here too. Bank details and phone numbers are entered here and
// kept in the database (site_settings), never in the code.
require_once __DIR__ . '/admin-bookings.php';

const BOOKING_ADMIN_SETTING_DEFAULTS = ['booking_ground_name' => 'Shimla Cricket Ground', 'booking_ground_address' => '11 km from Mehli, Shimla', 'booking_hold_hours' => '48',
    'booking_min_days_ahead' => '1', 'booking_max_days_ahead' => '120', 'booking_meal_deduction' => '150', 'booking_stay_rate' => '1000'];

function bookingAdminSaveSetting(string $name, ?string $value): void {
    if ($value === null || $value === '') adminExecute('DELETE FROM site_settings WHERE name = ?', [$name]);
    else adminExecute('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
}

function renderAdminBookingSettings(array $admin): string {
    $keys = ['booking_ground_name', 'booking_ground_address', 'booking_slot_morning', 'booking_slot_afternoon', 'booking_slot_evening', 'booking_hold_hours', 'booking_min_days_ahead', 'booking_max_days_ahead',
        'booking_meal_deduction', 'booking_stay_rate', 'booking_stay_note', 'booking_notify_email', 'booking_support_phone', 'booking_payment_note', 'booking_terms_extra'];
    $current = ['booking_enabled' => bookingSetting('booking_enabled') === '1' ? '1' : '0', 'booking_stay_enabled' => bookingSetting('booking_stay_enabled') === '1' ? '1' : '0'];
    foreach ($keys as $key) $current[$key] = bookingSetting($key, BOOKING_ADMIN_SETTING_DEFAULTS[$key] ?? (str_starts_with($key, 'booking_slot_') ? BOOKING_DEFAULT_TIMES[substr($key, 13)] : ''));
    // The packages: format => row.
    $rates = array_column(query('SELECT * FROM booking_rates'), null, 'format');
    $rateInput = [];
    foreach (array_keys(BOOKING_ADMIN_FORMATS) as $format) {
        $r = $rates[$format] ?? ['price' => null, 'per_team' => $format === 'full_day' ? 0 : 1, 'advance_percent' => 50, 'duration_label' => '', 'overs_label' => '', 'includes' => ''];
        $trim = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
        $rateInput[$format] = ['price' => $r['price'] === null ? '' : $trim($r['price']), 'per_team' => (string) (int) $r['per_team'], 'advance' => $trim($r['advance_percent']),
            'duration' => (string) $r['duration_label'], 'overs' => (string) $r['overs_label'], 'includes' => (string) $r['includes']];
    }
    $input = $current;
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $input = ['booking_enabled' => ($_POST['booking_enabled'] ?? '') === '1' ? '1' : '0', 'booking_stay_enabled' => ($_POST['booking_stay_enabled'] ?? '') === '1' ? '1' : '0'];
        foreach ($keys as $key) $input[$key] = postedText($key);
        foreach (array_keys(BOOKING_ADMIN_FORMATS) as $format) foreach (['price', 'per_team', 'advance', 'duration', 'overs', 'includes'] as $field) $rateInput[$format][$field] = postedText("rate_{$format}_{$field}");
        if ($input['booking_ground_name'] === '' || mb_strlen($input['booking_ground_name']) > 120) $errors['booking_ground_name'] = 'Enter the ground’s name, up to 120 characters.';
        if (mb_strlen($input['booking_ground_address']) > 200) $errors['booking_ground_address'] = 'The address must be 200 characters or fewer.';
        foreach (array_keys(BOOKING_ADMIN_SLOTS) as $slot) if ($input["booking_slot_$slot"] === '' || mb_strlen($input["booking_slot_$slot"]) > 60) $errors["booking_slot_$slot"] = 'Enter the time window, such as 8:00 AM - 11:00 AM.';
        $whole = fn (string $v, int $min, int $max) => ctype_digit($v) && (int) $v >= $min && (int) $v <= $max;
        if (!$whole($input['booking_hold_hours'], 1, 720)) $errors['booking_hold_hours'] = 'Enter whole hours from 1 to 720.';
        if (!$whole($input['booking_min_days_ahead'], 0, 365)) $errors['booking_min_days_ahead'] = 'Enter whole days from 0 to 365.';
        if (!$whole($input['booking_max_days_ahead'], 1, 730)) $errors['booking_max_days_ahead'] = 'Enter whole days from 1 to 730.';
        elseif (!isset($errors['booking_min_days_ahead']) && (int) $input['booking_max_days_ahead'] < (int) $input['booking_min_days_ahead']) $errors['booking_max_days_ahead'] = 'This must not be less than the earliest booking.';
        if ($input['booking_meal_deduction'] === '' || bookingAdminAmount($input['booking_meal_deduction'], 10000) === null) $errors['booking_meal_deduction'] = 'Enter the amount in rupees for each skipped meal, from 0 to 10000.';
        if ($input['booking_stay_rate'] !== '' && bookingAdminAmount($input['booking_stay_rate'], 100000) === null) $errors['booking_stay_rate'] = 'Enter the price in rupees per person per night, or leave it empty to quote on approval.';
        if (mb_strlen($input['booking_stay_note']) > 500) $errors['booking_stay_note'] = 'This must be 500 characters or fewer.';
        if ($input['booking_notify_email'] !== '' && (!filter_var($input['booking_notify_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($input['booking_notify_email']) > 255)) $errors['booking_notify_email'] = 'Enter a valid email address, or leave it empty.';
        if (mb_strlen($input['booking_support_phone']) > 200) $errors['booking_support_phone'] = 'This must be 200 characters or fewer.';
        if (mb_strlen($input['booking_payment_note']) > 1500) $errors['booking_payment_note'] = 'This must be 1500 characters or fewer.';
        if (mb_strlen($input['booking_terms_extra']) > 2000) $errors['booking_terms_extra'] = 'This must be 2000 characters or fewer.';
        foreach (array_keys(BOOKING_ADMIN_FORMATS) as $format) {
            $r = $rateInput[$format];
            if ($r['price'] !== '' && (bookingAdminAmount($r['price']) ?? 0) <= 0) $errors["rate_{$format}_price"] = 'Enter the price in rupees, or leave it empty to quote on approval.';
            if (!in_array($r['per_team'], ['0', '1'], true)) $errors["rate_{$format}_per_team"] = 'Choose per team or flat price.';
            $adv = bookingAdminAmount($r['advance'], 100);
            if ($adv === null || $adv < 1) $errors["rate_{$format}_advance"] = 'Enter a percentage from 1 to 100.';
            if (mb_strlen($r['duration']) > 120) $errors["rate_{$format}_duration"] = 'This must be 120 characters or fewer.';
            if (mb_strlen($r['overs']) > 120) $errors["rate_{$format}_overs"] = 'This must be 120 characters or fewer.';
            $lines = array_values(array_filter(array_map('trim', explode("\n", $r['includes'])), fn ($l) => $l !== ''));
            if (count($lines) > 15 || array_filter($lines, fn ($l) => mb_strlen($l) > 200)) $errors["rate_{$format}_includes"] = 'Use up to 15 lines of 200 characters or fewer.';
        }
        if (!$errors) {
            bookingAdminSaveSetting('booking_enabled', $input['booking_enabled'] === '1' ? '1' : null);
            bookingAdminSaveSetting('booking_stay_enabled', $input['booking_stay_enabled'] === '1' ? '1' : null);
            foreach ($keys as $key) bookingAdminSaveSetting($key, $input[$key] === '' ? null : $input[$key]);
            $save = db()->prepare('INSERT INTO booking_rates (format, price, per_team, advance_percent, duration_label, overs_label, includes) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE price = VALUES(price), per_team = VALUES(per_team), advance_percent = VALUES(advance_percent), duration_label = VALUES(duration_label), overs_label = VALUES(overs_label), includes = VALUES(includes)');
            foreach (array_keys(BOOKING_ADMIN_FORMATS) as $format) {
                $r = $rateInput[$format];
                $lines = array_values(array_filter(array_map('trim', explode("\n", $r['includes'])), fn ($l) => $l !== ''));
                $save->execute([$format, $r['price'] === '' ? null : bookingAdminAmount($r['price']), (int) $r['per_team'], bookingAdminAmount($r['advance'], 100), $r['duration'] ?: null, $r['overs'] ?: null, $lines ? implode("\n", $lines) : null]);
            }
            adminFlash('Booking settings saved.');
            adminRedirect('/admin/bookings/settings');
        }
    }
    $v = fn (string $key) => escape($input[$key]);
    $bad = fn (string $key) => fieldInvalid($errors, $key);
    $cards = '';
    foreach (BOOKING_ADMIN_FORMATS as $format => $label) {
        $r = $rateInput[$format];
        $id = fn (string $f) => "rate_{$format}_{$f}";
        $cards .= '<fieldset class="admin-panel admin-booking-package"><legend>' . escape($label) . '</legend><div class="admin-fields-2">'
            . bookingAdminField($id('price'), 'Price (₹)', bookingAdminInput($id('price'), 'text', $r['price'], $errors, ' inputmode="decimal"'), $errors, 'Empty: you set the price when you approve.', true)
            . bookingAdminField($id('per_team'), 'The price is', '<select id="' . $id('per_team') . '" name="' . $id('per_team') . '"' . $bad($id('per_team')) . '><option value="1"' . ($r['per_team'] === '1' ? ' selected' : '') . '>Per team</option><option value="0"' . ($r['per_team'] === '0' ? ' selected' : '') . '>A flat price for the booking</option></select>', $errors)
            . '</div><div class="admin-fields-2">'
            . bookingAdminField($id('advance'), 'Advance paid online (%)', bookingAdminInput($id('advance'), 'text', $r['advance'], $errors, ' inputmode="decimal" required'), $errors, 'The rest is paid at the ground.')
            . bookingAdminField($id('duration'), 'Duration', bookingAdminInput($id('duration'), 'text', $r['duration'], $errors, ' maxlength="120"'), $errors, 'For example 3 hours.', true)
            . '</div>' . bookingAdminField($id('overs'), 'Overs', bookingAdminInput($id('overs'), 'text', $r['overs'], $errors, ' maxlength="120"'), $errors, 'For example 20 overs.', true)
            . bookingAdminField($id('includes'), 'What is included', '<textarea id="' . $id('includes') . '" name="' . $id('includes') . '" rows="7"' . $bad($id('includes')) . '>' . escape($r['includes']) . '</textarea>', $errors, 'One item per line.', true)
            . '</fieldset>';
    }
    $field = fn (string $key, string $label, string $hint = '', string $type = 'text', string $extra = '', bool $optional = false) => bookingAdminField($key, $label, bookingAdminInput($key, $type, $input[$key], $errors, $extra), $errors, $hint, $optional);
    $area = fn (string $key, string $label, int $rows, string $hint = '') => bookingAdminField($key, $label, '<textarea id="' . $key . '" name="' . $key . '" rows="' . $rows . '"' . $bad($key) . '>' . escape($input[$key]) . '</textarea>', $errors, $hint, true);
    $envOn = filter_var(getenv('BOOKING_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    $ready = fn (bool $ok, string $label, string $hint) => '<li class="' . ($ok ? 'is-ok' : 'is-warning') . '"><span>' . escape($label) . '<br><small>' . escape($hint) . '</small></span><strong>' . ($ok ? 'Done' : 'To do') . '</strong></li>';
    $priced = (int) query('SELECT COUNT(*) AS n FROM booking_rates WHERE price IS NOT NULL')[0]['n'];
    $checklist = '<ul class="admin-checklist">'
        . $ready($priced === 3, 'All three packages have a price', $priced . ' of 3 priced')
        . $ready(trim($current['booking_payment_note']) !== '', 'Bank details for the advance', trim($current['booking_payment_note']) !== '' ? 'Saved' : 'Needed when the advance is paid by bank transfer')
        . $ready(trim($current['booking_support_phone']) !== '', 'Contact numbers', trim($current['booking_support_phone']) !== '' ? 'Saved' : 'Shown to requesters')
        . $ready($current['booking_notify_email'] !== '', 'Email for new requests', $current['booking_notify_email'] !== '' ? $current['booking_notify_email'] : 'Not set: the site contact email is used')
        . '</ul>';
    return adminView('templates.admin.booking-settings', $admin, '/admin/bookings/settings', [
        'summary' => $errors ? '<p class="admin-alert" role="alert">Please correct the highlighted fields.</p>' : '', 'payments' => bookingAdminPaymentPanel(), 'checklist' => $checklist,
        'booking_url' => escape(absoluteUrl('{{BASE}}/book')), 'enabled' => $input['booking_enabled'] === '1' ? ' checked' : '',
        'env_note' => $envOn ? '<p class="admin-hint">Booking is also switched on by <code>BOOKING_ENABLED=true</code> in the server’s .env file, so it stays public whatever you choose here.</p>' : '',
        'ground' => $field('booking_ground_name', 'Ground name', '', 'text', ' maxlength="120" required') . $field('booking_ground_address', 'Address or directions', 'Shown on the booking pages and emails.', 'text', ' maxlength="200"', true),
        'slots' => '<div class="admin-fields-3">' . implode('', array_map(fn ($slot, $label) => $field("booking_slot_$slot", $label . ' slot', 'For example 8:00 AM - 11:00 AM.', 'text', ' maxlength="60" required'), array_keys(BOOKING_ADMIN_SLOTS), BOOKING_ADMIN_SLOTS)) . '</div>',
        'rules' => '<div class="admin-fields-3">' . $field('booking_hold_hours', 'Hold an approved slot (hours)', 'Unpaid after this, it expires.', 'text', ' inputmode="numeric" required')
            . $field('booking_min_days_ahead', 'Earliest booking (days ahead)', '1 = from tomorrow.', 'text', ' inputmode="numeric" required') . $field('booking_max_days_ahead', 'Latest booking (days ahead)', '', 'text', ' inputmode="numeric" required') . '</div>',
        'extras' => bookingAdminCheck('booking_stay_enabled', 'Offer overnight accommodation on the booking form', $input['booking_stay_enabled'] === '1')
            . '<div class="admin-fields-2">' . $field('booking_meal_deduction', 'Taken off for each skipped meal (₹)', 'Lunch and tea are included in the packages. Per meal, per team, per day.', 'text', ' inputmode="decimal" required')
            . $field('booking_stay_rate', 'Accommodation per person per night (₹)', 'Includes three meals and refreshments. Empty: you quote it on approval.', 'text', ' inputmode="decimal"', true) . '</div>'
            . $area('booking_stay_note', 'About the accommodation', 3, 'A short description shown to requesters, for example rooms, distance and what is included.'),
        'contact' => '<div class="admin-fields-2">' . $field('booking_notify_email', 'Send new requests to', 'Empty: the site’s contact email.', 'email', ' maxlength="255"', true) . $area('booking_support_phone', 'Contact numbers shown to requesters', 2, 'Names and phone numbers, one per line.') . '</div>',
        'payment_note' => $area('booking_payment_note', 'Bank details and payment instructions', 6, 'Shown with the payment link and in the approval email, for paying by bank transfer. Kept in the database only.'),
        'terms' => $area('booking_terms_extra', 'Extra terms', 4, 'One line each. Shown after the standard terms.'),
        'packages' => $cards, 'checklist_section' => $checklist,
    ]);
}

// ---------------------------------------------------------------- blocked dates

function renderAdminBookingBlocks(array $admin): string {
    $errors = [];
    $input = ['start_date' => '', 'end_date' => '', 'slot' => 'all', 'reason' => ''];
    $date = fn (string $v) => preg_match('~^\d{4}-\d{2}-\d{2}$~', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = postedText('action');
        // Back to the calendar day the block was made from, or the list.
        $back = preg_match('~^\d{4}-\d{2}-\d{2}$~', postedText('back_day')) ? '/admin/bookings/calendar?month=' . substr(postedText('back_day'), 0, 7) . '&day=' . postedText('back_day') : '/admin/bookings/blocks';
        if ($action === 'delete') {
            $id = (int) postedText('block_id');
            adminExecute('DELETE FROM booking_blocks WHERE id = ?', [$id]);
            adminFlash('The block was removed.');
            adminRedirect($back);
        }
        if ($action === 'season4') {
            $exists = query("SELECT 1 FROM booking_blocks WHERE start_date = '2026-11-17' AND end_date = '2026-12-01' AND slot = 'all'");
            if (!$exists) adminExecute("INSERT INTO booking_blocks (start_date, end_date, slot, reason, created_at) VALUES ('2026-11-17', '2026-12-01', 'all', 'Pro HPCL Season 4 matches', NOW())");
            adminFlash($exists ? 'The Season 4 match days were already blocked.' : 'The Season 4 match days (17 Nov to 1 Dec 2026) are blocked.');
            adminRedirect($back);
        }
        $input = ['start_date' => postedText('start_date'), 'end_date' => postedText('end_date'), 'slot' => postedText('slot'), 'reason' => postedText('reason')];
        if ($input['end_date'] === '') $input['end_date'] = $input['start_date'];
        if (!$date($input['start_date'])) $errors['start_date'] = 'Choose the first date.';
        if (!$date($input['end_date'])) $errors['end_date'] = 'Choose the last date.';
        elseif (!isset($errors['start_date']) && $input['end_date'] < $input['start_date']) $errors['end_date'] = 'The last date can’t be before the first.';
        elseif (!isset($errors['start_date']) && (strtotime($input['end_date']) - strtotime($input['start_date'])) > 400 * 86400) $errors['end_date'] = 'A block can cover at most about a year.';
        if (!in_array($input['slot'], ['all', ...array_keys(BOOKING_ADMIN_SLOTS)], true)) $errors['slot'] = 'Choose a slot.';
        if (mb_strlen($input['reason']) > 255) $errors['reason'] = 'The reason must be 255 characters or fewer.';
        if (!$errors) {
            adminExecute('INSERT INTO booking_blocks (start_date, end_date, slot, reason, created_at) VALUES (?, ?, ?, ?, NOW())', [$input['start_date'], $input['end_date'], $input['slot'], $input['reason'] ?: null]);
            $clash = (int) query("SELECT COUNT(*) AS n FROM bookings WHERE booking_date BETWEEN ? AND ? AND status IN ('approved', 'confirmed')", [$input['start_date'], $input['end_date']])[0]['n'];
            adminFlash('The dates are blocked.' . ($clash ? " Note: $clash approved or confirmed booking" . ($clash === 1 ? '' : 's') . ' already fall in this period; they are not cancelled automatically.' : ''));
            adminRedirect($back);
        }
    }
    $blocks = query('SELECT * FROM booking_blocks ORDER BY end_date < CURDATE(), start_date, id');
    $csrf = '<input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">';
    $rows = implode('', array_map(function ($b) use ($csrf) {
        $same = $b['start_date'] === $b['end_date'];
        $past = $b['end_date'] < date('Y-m-d');
        return '<tr><td>' . escape(bookingAdminDate($b['start_date'])) . ($same ? '' : ' to<br>' . escape(bookingAdminDate($b['end_date']))) . ($past ? '<br><span class="admin-muted">past</span>' : '') . '</td>'
            . '<td>' . ($b['slot'] === 'all' ? 'All day' : escape(BOOKING_ADMIN_SLOTS[$b['slot']])) . '</td><td>' . escape((string) $b['reason'] ?: '—') . '</td>'
            . '<td class="admin-actions"><form method="post" action="{{BASE}}/admin/bookings/blocks">' . $csrf . '<input type="hidden" name="action" value="delete"><input type="hidden" name="block_id" value="' . (int) $b['id'] . '">'
            . '<button type="submit" class="admin-danger-link">Remove<span class="admin-visually-hidden"> the block from ' . escape(bookingAdminDate($b['start_date'])) . '</span></button></form></td></tr>';
    }, $blocks));
    $table = $blocks ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Dates</th><th>Slot</th><th>Reason</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        : '<p class="admin-empty">No blocked dates. The whole calendar can be booked.</p>';
    $slotOptions = '<option value="all"' . ($input['slot'] === 'all' ? ' selected' : '') . '>All day</option>' . implode('', array_map(fn ($s, $l) => '<option value="' . $s . '"' . ($input['slot'] === $s ? ' selected' : '') . '>' . $l . ' only</option>', array_keys(BOOKING_ADMIN_SLOTS), BOOKING_ADMIN_SLOTS));
    return adminView('templates.admin.booking-blocks', $admin, '/admin/bookings/blocks', [
        'summary' => $errors ? '<p class="admin-alert" role="alert">Please correct the highlighted fields.</p>' : '', 'table' => $table,
        'start' => bookingAdminField('start_date', 'First date', bookingAdminInput('start_date', 'date', $input['start_date'], $errors, ' required'), $errors),
        'end' => bookingAdminField('end_date', 'Last date', bookingAdminInput('end_date', 'date', $input['end_date'], $errors), $errors, 'Empty: just the first date.', true),
        'slot' => bookingAdminField('slot', 'Which slot', '<select id="slot" name="slot"' . fieldInvalid($errors, 'slot') . '>' . $slotOptions . '</select>', $errors),
        'reason' => bookingAdminField('reason', 'Reason', bookingAdminInput('reason', 'text', $input['reason'], $errors, ' maxlength="255"'), $errors, 'For example ground maintenance or a tournament.', true),
    ]);
}
