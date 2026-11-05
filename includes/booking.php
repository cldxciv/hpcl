<?php
declare(strict_types=1);

// Ground booking (Shimla Cricket Ground): the core. A team captain requests a package (single match, two matches
// or a full day) for a date and time window; an admin approves it with a total price; the requester pays the
// advance online (includes/booking-pay.php) and the booking is confirmed; the balance is paid at the ground.
//
//   requested -> approved -> confirmed          (the happy path)
//   requested -> rejected | cancelled           approved -> expired | cancelled        confirmed -> cancelled
//
// Time windows: three consecutive windows of about three hours (settings booking_slot_morning/afternoon/evening).
//   single   occupies one window            start: morning | afternoon | evening
//   double   occupies two windows in a row  start: morning (morning + afternoon) | afternoon (afternoon + evening)
//   full_day occupies all three             bookings.slot = 'full_day'
// Prices are per team for single/double (a request may pay for both teams) and flat for the full day, per day of play
// (1 to 7 consecutive days, the same window every day). Lunch and tea are included; skipping one takes
// booking_meal_deduction off per team per day. Overnight accommodation is requested here and priced by the admin.
// The advance (advance_percent, 50 by default) is paid online to confirm; the balance is paid at the ground.
// bookingPriceBreakdown() is the one place that works out a price.
//
// The public pages and POST handlers are in booking-pages.php, the emails in booking-mail.php.
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/membership.php';
require_once __DIR__ . '/booking-mail.php';

const BOOKING_ASSET_VERSION = '20261105a';
const BOOKING_SLOTS = ['morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening', 'full_day' => 'Full day'];
const BOOKING_DAY_SLOTS = ['morning', 'afternoon', 'evening'];
const BOOKING_FORMATS = ['single' => 'Single match', 'double' => 'Two matches', 'full_day' => 'Full-day session'];
/** Which start slot each package can be booked at. */
const BOOKING_FORMAT_SLOTS = ['single' => ['morning', 'afternoon', 'evening'], 'double' => ['morning', 'afternoon'], 'full_day' => ['full_day']];
const BOOKING_STATUSES = ['requested' => 'Awaiting approval', 'approved' => 'Approved, advance due', 'confirmed' => 'Confirmed', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled', 'expired' => 'Expired'];
const BOOKING_DEFAULT_TIMES = ['morning' => '7:30 AM - 11:30 AM', 'afternoon' => '12:00 PM - 4:00 PM', 'evening' => '4:30 PM - 7:30 PM'];
const BOOKING_MAX_DAYS = 7;
const BOOKING_STANDARD_PLAYERS = 11;
/** The standard terms shown on the form, the status page and the approval email (more lines: setting booking_terms_extra). */
const BOOKING_TERMS = [
    '50% advance is required to confirm the booking. The balance is paid at the ground.',
    'No individual player payments: the team captain is responsible for payment and timings.',
    'Late arrival may result in a deduction of overs.',
    'Rates are for 11 players per team. Extra players are charged separately.',
    'Only the package price is quoted online. The final amount is confirmed when your request is approved.',
];
/** The editable requirements of a booking (the approver may change them; the first values are kept in request_snapshot). */
const BOOKING_REQUIREMENT_FIELDS = ['booking_date', 'days', 'slot', 'format', 'teams_paying', 'players_per_team', 'opponent', 'organisation', 'want_lunch', 'want_tea', 'stay_needed', 'stay_people', 'stay_nights', 'stay_notes', 'notes'];

// ---------------------------------------------------------------------------------------------- settings, labels

/** A site_settings row for booking, or $default when it is missing or empty (read once per request). */
function bookingSetting(string $name, string $default = ''): string {
    static $settings = null;
    $settings ??= array_column(query('SELECT name, value FROM site_settings'), 'value', 'name');
    return trim((string) ($settings[$name] ?? '')) !== '' ? trim((string) $settings[$name]) : $default;
}
function bookingGroundName(): string {
    return bookingSetting('booking_ground_name', 'Shimla Cricket Ground');
}
function bookingGroundAddress(): string {
    return bookingSetting('booking_ground_address', '11 km from Mehli, Shimla');
}
/** The terms to show: the standard ones plus the extra lines from the settings. */
function bookingTerms(): array {
    $extra = array_values(array_filter(array_map('trim', explode("\n", bookingSetting('booking_terms_extra')))));
    $meals = 'Lunch and tea are included in the package. Skipping a meal reduces the bill by ' . bookingMoney(bookingMealDeduction()) . ' per meal, per team, per day.';
    return [...BOOKING_TERMS, $meals, ...$extra];
}
/** The saving for each skipped meal (lunch or tea), per team per day: setting booking_meal_deduction, 150 by default. */
function bookingMealDeduction(): float {
    return max(0.0, (float) bookingSetting('booking_meal_deduction', '150'));
}
/** Whether the overnight accommodation request is offered (booking_stay_enabled = 1). */
function bookingStayEnabled(): bool {
    return bookingSetting('booking_stay_enabled') === '1';
}
function bookingStayNote(): string {
    return bookingSetting('booking_stay_note');
}
/** Accommodation per person per night (setting booking_stay_rate), including three meals and refreshments; 0 when it is not set. */
function bookingStayRate(): float {
    return max(0.0, (float) bookingSetting('booking_stay_rate', '0'));
}
/** people x nights x the rate (whole rupees); 0 when there is no rate or no people or nights. */
function bookingStayAmount(?int $people, ?int $nights): float {
    return $people && $nights && bookingStayRate() > 0 ? (float) round($people * $nights * bookingStayRate()) : 0.0;
}
/** "8 people × 2 nights" */
function bookingStayText(?int $people, ?int $nights): string {
    return (int) $people . ' ' . ((int) $people === 1 ? 'person' : 'people') . ' × ' . (int) $nights . ' night' . ((int) $nights === 1 ? '' : 's');
}
/** The display time of a window (morning, afternoon or evening), e.g. "8:00 AM - 11:00 AM". */
function bookingSlotTime(string $slot): string {
    return bookingSetting('booking_slot_' . $slot, BOOKING_DEFAULT_TIMES[$slot] ?? '');
}
/** [start, end] display strings of a time range written "8:00 AM - 11:00 AM" (a hyphen or a dash). */
function bookingSplitRange(string $range): array {
    $parts = preg_split('~\s*[-–—]\s*~u', trim($range), 2);
    return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
}
function bookingValidPair(string $slot, string $format): bool {
    return in_array($slot, BOOKING_FORMAT_SLOTS[$format] ?? [], true);
}
/** The windows a booking occupies: bookingOccupiedSlots('morning', 'double') = [morning, afternoon]. [] for an invalid pair. */
function bookingOccupiedSlots(string $slot, string $format): array {
    if (!bookingValidPair($slot, $format)) return [];
    return match ($format) {
        'single' => [$slot],
        'double' => $slot === 'morning' ? ['morning', 'afternoon'] : ['afternoon', 'evening'],
        default => BOOKING_DAY_SLOTS,
    };
}
/** The time range of a booking: from the start of its first window to the end of its last. */
function bookingTimeRange(string $slot, string $format = 'single'): string {
    $occupied = bookingOccupiedSlots($slot, $format) ?: [$slot];
    $first = bookingSplitRange(bookingSlotTime($occupied[0]))[0];
    $last = bookingSplitRange(bookingSlotTime($occupied[count($occupied) - 1]))[1];
    return $first !== '' && $last !== '' ? $first . ' - ' . $last : bookingSlotTime($occupied[0]);
}
/** "Morning (8:00 AM - 11:00 AM)", "Morning + Afternoon (8:00 AM - 2:30 PM)" or "Full day (8:00 AM - 6:00 PM)". */
function bookingSlotLabel(string $slot, string $format = 'single'): string {
    $occupied = bookingOccupiedSlots($slot, $format) ?: [$slot];
    $name = $slot === 'full_day' || $format === 'full_day' ? 'Full day' : implode(' + ', array_map(fn ($s) => BOOKING_SLOTS[$s] ?? $s, $occupied));
    $range = bookingTimeRange($slot, $format);
    return $name . ($range !== '' ? ' (' . $range . ')' : '');
}
function bookingFormatLabel(string $format): string {
    return BOOKING_FORMATS[$format] ?? $format;
}
function bookingStatusLabel(string $status): string {
    return BOOKING_STATUSES[$status] ?? ucfirst($status);
}
/** "Saturday, 21 November 2026". */
function bookingDateLabel(string $date): string {
    $day = DateTime::createFromFormat('!Y-m-d', $date);
    return $day ? $day->format('l, j F Y') : $date;
}
/** ₹ amount: whole rupees without decimals, with Indian-style grouping. */
function bookingMoney(float|string|null $amount): string {
    $amount = (float) $amount;
    $whole = floor($amount) === $amount;
    $text = number_format($amount, $whole ? 0 : 2, '.', '');
    [$int, $dec] = array_pad(explode('.', $text), 2, '');
    $int = strlen($int) > 3 ? preg_replace('~\B(?=(\d{2})+(?!\d))~', ',', substr($int, 0, -3)) . ',' . substr($int, -3) : $int;
    return '₹' . $int . ($dec !== '' ? '.' . $dec : '');
}
function bookingNow(): string {
    return date('Y-m-d H:i:s');
}

// ------------------------------------------------------------------------------------------ packages, prices, discount

/** The packages by format: price, per_team, advance_percent, duration_label, overs_label and includes (a list). */
function bookingPackages(): array {
    static $packages = null;
    if ($packages !== null) return $packages;
    $packages = [];
    foreach (query('SELECT * FROM booking_rates') as $row) {
        if (!isset(BOOKING_FORMATS[$row['format']])) continue;
        $row['price'] = $row['price'] === null ? null : (float) $row['price'];
        $row['per_team'] = (int) $row['per_team'] === 1;
        $row['advance_percent'] = (float) $row['advance_percent'] ?: 50.0;
        $row['includes'] = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', (string) $row['includes'])))));
        $packages[$row['format']] = $row;
    }
    // Shown in the order single, double, full day.
    uksort($packages, fn ($a, $b) => array_search($a, array_keys(BOOKING_FORMATS)) <=> array_search($b, array_keys(BOOKING_FORMATS)));
    return $packages;
}
function bookingPackage(string $format): ?array {
    return bookingPackages()[$format] ?? null;
}
/** The package price (per team per day, or flat per day for the full day). The slot does not change the price. */
function bookingRate(string $slot, string $format): ?float {
    return bookingPackage($format)['price'] ?? null;
}

/** The member discount of the person with this email: ['tier' => silver..platinum|null, 'percent' => float]. */
function bookingMemberDiscount(string $email): array {
    $email = strtolower(trim($email));
    $none = ['tier' => null, 'percent' => 0.0];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return $none;
    $tiers = array_column(query("SELECT tier FROM members WHERE published = 1 AND LOWER(email) = ?", [$email]), 'tier');
    $tiers = array_merge($tiers, array_column(query("SELECT tier FROM membership_applications WHERE status = 'approved' AND payment_status = 'paid' AND LOWER(email) = ?", [$email]), 'tier'));
    $best = $none;
    foreach ($tiers as $tier) {
        $percent = (float) (MEMBERSHIP_TIERS[$tier]['discount'] ?? 0);
        if ($percent > $best['percent']) $best = ['tier' => $tier, 'percent' => $percent];
    }
    return $best;
}

/** [advance now (whole rupees), balance at the ground] of a total. */
function bookingSplit(float $total, float $advancePercent): array {
    $advance = min($total, (float) round($total * $advancePercent / 100));
    return [$advance, round($total - $advance, 2)];
}
function bookingFlag(array $data, string $key, bool $default): bool {
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') return $default;
    return in_array($data[$key], [1, '1', true, 'on', 'yes'], true);
}
function bookingPercentText(float $percent): string {
    return rtrim(rtrim(number_format($percent, 2), '0'), '.');
}
/** "2 days" / "1 day". */
function bookingDaysText(int $days): string {
    return $days . ' day' . ($days === 1 ? '' : 's');
}

/**
 * THE price formula. $b is a bookings row, or the same keys from a form: format, teams_paying, days, want_lunch,
 * want_tea, discount_percent, member_tier, stay_needed, stay_people, stay_nights, stay_amount, extra_amount, extra_note, advance_percent.
 * The stay is stay_amount when it is above 0 (the figure saved on the booking, or typed by the approver); on a request that has
 * not been approved yet it is people x nights x booking_stay_rate; on an approved booking a stay_amount of 0 means no charge.
 *
 *   base      = package price x (teams_paying for a per-team package, else 1) x days
 *   meals     = skipped meals x booking_meal_deduction x the same teams x days
 *   subtotal  = base - meals;  discount = subtotal x member percent;  total = subtotal - discount + stay + extras (whole rupees)
 *   advance   = total x advance_percent (whole rupees);  balance = total - advance
 *
 * Returns the numbers (null when the package has no price) and 'lines' for display: each ['key', 'label', 'amount'
 * (negative for savings, null for a note)]. Keys: base, meals, discount, stay, extra, stay_note.
 */
function bookingPriceBreakdown(array $b): array {
    $format = (string) ($b['format'] ?? '');
    $package = bookingPackage($format);
    $perTeam = $package['per_team'] ?? true;
    $days = max(1, min(BOOKING_MAX_DAYS, (int) ($b['days'] ?? 1)));
    $teams = $perTeam ? max(1, min(2, (int) ($b['teams_paying'] ?? 1))) : 1;
    $lunch = bookingFlag($b, 'want_lunch', true);
    $tea = bookingFlag($b, 'want_tea', true);
    $skipped = (int) !$lunch + (int) !$tea;
    $percent = (float) ($b['discount_percent'] ?? 0);
    $advancePercent = (float) ($b['advance_percent'] ?? 0) ?: (float) ($package['advance_percent'] ?? 50);
    $stayPeople = isset($b['stay_people']) ? (int) $b['stay_people'] : 0;
    $stayNights = isset($b['stay_nights']) ? (int) $b['stay_nights'] : 0;
    $stayNeeded = !empty($b['stay_needed']);
    $approved = isset($b['total_amount']) && $b['total_amount'] !== null;
    $stay = !$stayNeeded && (float) ($b['stay_amount'] ?? 0) <= 0 ? 0.0 : ((float) ($b['stay_amount'] ?? 0) > 0 ? (float) $b['stay_amount'] : ($approved ? 0.0 : bookingStayAmount($stayPeople, $stayNights)));
    $out = ['ok' => false, 'rate' => $package['price'] ?? null, 'per_team' => $perTeam, 'teams' => $teams, 'days' => $days, 'skipped_meals' => $skipped, 'base' => null, 'meal_deduction' => 0.0,
        'subtotal' => null, 'percent' => $percent, 'tier' => $b['member_tier'] ?? null, 'discount' => 0.0, 'stay' => $stay, 'extra' => (float) ($b['extra_amount'] ?? 0),
        'total' => null, 'advance_percent' => $advancePercent, 'advance' => null, 'balance' => null, 'stay_pending' => $stayNeeded && $stay <= 0 && !$approved, 'lines' => []];
    if (!$package || $package['price'] === null) return $out;

    $out['base'] = round($package['price'] * $teams * $days, 2);
    $out['meal_deduction'] = round($skipped * bookingMealDeduction() * $teams * $days, 2);
    $out['subtotal'] = max(0.0, round($out['base'] - $out['meal_deduction'], 2));
    $out['discount'] = round($out['subtotal'] * $percent / 100, 2);
    $out['total'] = (float) round($out['subtotal'] - $out['discount'] + $out['stay'] + $out['extra']);
    [$out['advance'], $out['balance']] = bookingSplit($out['total'], $advancePercent);
    $out['ok'] = true;

    $label = bookingFormatLabel($format) . ($perTeam && $teams > 1 ? ' × ' . $teams . ' teams' : '') . ($days > 1 ? ' × ' . bookingDaysText($days) : '');
    $lines = [['key' => 'base', 'label' => $label, 'amount' => $out['base']]];
    if ($skipped > 0) {
        $what = $lunch ? 'tea' : ($tea ? 'lunch' : 'lunch and tea');
        $lines[] = ['key' => 'meals', 'label' => 'No ' . $what . ' (' . bookingMoney(bookingMealDeduction()) . ' per meal' . ($teams > 1 ? ' per team' : '') . ($days > 1 ? ' per day' : '') . ')', 'amount' => -$out['meal_deduction']];
    }
    if ($out['discount'] > 0) $lines[] = ['key' => 'discount', 'label' => 'Member discount (' . ($out['tier'] ? ucfirst((string) $out['tier']) . ', ' : '') . bookingPercentText($percent) . '%)', 'amount' => -$out['discount']];
    if ($out['stay'] > 0) $lines[] = ['key' => 'stay', 'label' => 'Accommodation' . ($stayPeople && $stayNights ? ', ' . bookingStayText($stayPeople, $stayNights) : '') . ' (includes 3 meals + refreshments)', 'amount' => $out['stay']];
    elseif ($out['stay_pending']) $lines[] = ['key' => 'stay_note', 'label' => 'Overnight accommodation: quoted when we approve', 'amount' => null];
    if ($out['extra'] > 0) $lines[] = ['key' => 'extra', 'label' => trim((string) ($b['extra_note'] ?? '')) !== '' ? (string) $b['extra_note'] : 'Extras', 'amount' => $out['extra']];
    $out['lines'] = $lines;
    return $out;
}

/**
 * What a request costs for the form: bookingPriceBreakdown() for a package, teams, days and meals, with the member
 * discount of the email and, when people and nights are given, the accommodation (people x nights x booking_stay_rate;
 * quoted on approval when no rate is set). 'quoted' is the subtotal
 * (before the discount) and 'amount' the same as 'total' (the old names). The slot does not change the price.
 */
function bookingQuote(string $slot, string $format, string $email, int $teamsPaying = 1, int $days = 1, bool $lunch = true, bool $tea = true, int $stayPeople = 0, int $stayNights = 0): array {
    $discount = bookingMemberDiscount($email);
    $quote = bookingPriceBreakdown(['format' => $format, 'teams_paying' => $teamsPaying, 'days' => $days, 'want_lunch' => $lunch ? 1 : 0, 'want_tea' => $tea ? 1 : 0,
        'discount_percent' => $discount['percent'], 'member_tier' => $discount['tier'], 'stay_needed' => bookingStayEnabled() && $stayPeople > 0 && $stayNights > 0 ? 1 : 0,
        'stay_people' => $stayPeople, 'stay_nights' => $stayNights]);
    $quote['percent'] = $discount['percent'];
    $quote['tier'] = $discount['tier'];
    $quote['quoted'] = $quote['subtotal'];
    $quote['amount'] = $quote['total'];
    return $quote;
}

/**
 * The money of a booking row, for emails and pages: ['lines' => [['key','label','amount']...], 'total', 'advance', 'balance',
 * 'advance_percent', 'indicative' (true until the team approves)]. Once approved the stored total, advance and balance
 * are used; if the team typed a total that differs from the formula, an "Adjustment by our team" line makes up the difference.
 */
function bookingMoneyLines(array $b): array {
    $breakdown = bookingPriceBreakdown($b);
    $lines = $breakdown['lines'];
    $approved = isset($b['total_amount']) && $b['total_amount'] !== null;
    if (!$approved) {
        return ['lines' => $lines, 'total' => $breakdown['total'], 'advance' => $breakdown['advance'], 'balance' => $breakdown['balance'], 'advance_percent' => $breakdown['advance_percent'],
            'indicative' => true, 'stay_pending' => $breakdown['stay_pending']];
    }
    $total = (float) $b['total_amount'];
    if ($breakdown['total'] !== null && abs($total - $breakdown['total']) >= 0.5) $lines[] = ['key' => 'adjustment', 'label' => 'Adjustment by our team', 'amount' => round($total - $breakdown['total'], 2)];
    return ['lines' => $lines, 'total' => $total, 'advance' => (float) $b['amount'], 'balance' => (float) $b['balance_amount'], 'advance_percent' => $breakdown['advance_percent'],
        'indicative' => false, 'stay_pending' => false];
}

// ------------------------------------------------------------------------------------------------- availability

/** The dates (Y-m-d) of a booking that starts on $date and lasts $days days. */
function bookingDates(string $date, int $days): array {
    $out = [];
    for ($i = 0; $i < max(1, min(BOOKING_MAX_DAYS, $days)); $i++) $out[] = date('Y-m-d', strtotime($date . " +$i days"));
    return $out;
}
function bookingEndDate(string $date, int $days): string {
    return bookingDates($date, $days)[max(1, min(BOOKING_MAX_DAYS, $days)) - 1];
}

/** Moves approved bookings whose payment deadline passed to 'expired' (once per request unless $force). Returns how many. */
function bookingExpireDue(bool $force = false): int {
    static $ran = false;
    if ($ran && !$force) return 0;
    $ran = true;
    $count = 0;
    foreach (query("SELECT id FROM bookings WHERE status = 'approved' AND payment_due_at IS NOT NULL AND payment_due_at < ?", [bookingNow()]) as $row) {
        $id = (int) $row['id'];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare("UPDATE bookings SET status = 'expired', updated_at = ? WHERE id = ? AND status = 'approved' AND payment_due_at < ?");
            $update->execute([bookingNow(), $id, bookingNow()]);
            $moved = $update->rowCount() === 1;
            if ($moved) bookingEvent($id, 'expired', 'The advance was not paid before the deadline, so the slot was released.');
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            error_log('Booking expiry failed for ' . $id . ': ' . $error->getMessage());
            continue;
        }
        if ($moved) { $count++; bookingMail($id, 'expired'); }
    }
    return $count;
}
function bookingEvent(int $bookingId, string $status, ?string $note = null): void {
    db()->prepare('INSERT INTO booking_events (booking_id, status, note, created_at) VALUES (?, ?, ?, ?)')->execute([$bookingId, $status, $note === null ? null : mb_substr($note, 0, 500), bookingNow()]);
}

const BOOKING_STATE_RANK = ['available' => 0, 'held' => 1, 'booked' => 2, 'blocked' => 3];

/**
 * The state of the three windows on each day from $from to $to: date => [morning|afternoon|evening => 'available' | 'held'
 * (an approved booking waits for its advance) | 'booked' (confirmed) | 'blocked' (a closed date or window)].
 * A booking with $excludeId is ignored (to re-check a booking against everything else).
 */
function bookingCalendarStates(string $from, string $to, ?int $excludeId = null): array {
    bookingExpireDue();
    $days = [];
    for ($day = $from; $day <= $to; $day = date('Y-m-d', strtotime("$day +1 day"))) $days[$day] = array_fill_keys(BOOKING_DAY_SLOTS, 'available');
    $raise = function (string $day, string $slot, string $state) use (&$days) {
        if (isset($days[$day]) && BOOKING_STATE_RANK[$state] > BOOKING_STATE_RANK[$days[$day][$slot]]) $days[$day][$slot] = $state;
    };
    foreach (query('SELECT start_date, end_date, slot FROM booking_blocks WHERE start_date <= ? AND end_date >= ?', [$to, $from]) as $block) {
        foreach (array_keys($days) as $day) {
            if ($day < $block['start_date'] || $day > $block['end_date']) continue;
            foreach ($block['slot'] === 'all' ? BOOKING_DAY_SLOTS : [$block['slot']] as $slot) $raise($day, $slot, 'blocked');
        }
    }
    $now = bookingNow();
    foreach (query("SELECT slot, format, status, payment_due_at, booking_date, COALESCE(end_date, booking_date) AS last_date FROM bookings
        WHERE status IN ('approved', 'confirmed') AND id <> ? AND booking_date <= ? AND COALESCE(end_date, booking_date) >= ?", [$excludeId ?? 0, $to, $from]) as $booking) {
        if ($booking['status'] === 'approved' && $booking['payment_due_at'] !== null && $booking['payment_due_at'] < $now) continue;
        foreach (array_keys($days) as $day) {
            if ($day < $booking['booking_date'] || $day > $booking['last_date']) continue;
            foreach (bookingOccupiedSlots($booking['slot'], $booking['format']) as $slot) $raise($day, $slot, $booking['status'] === 'confirmed' ? 'booked' : 'held');
        }
    }
    return $days;
}
/** The state of each window on one date. */
function bookingWindowStates(string $date, ?int $excludeId = null): array {
    return bookingCalendarStates($date, $date, $excludeId)[$date];
}
/** The worst state of each window over $days consecutive days from $date. */
function bookingRangeStates(string $date, int $days = 1, ?int $excludeId = null): array {
    $range = bookingCalendarStates($date, bookingEndDate($date, $days), $excludeId);
    $worst = array_fill_keys(BOOKING_DAY_SLOTS, 'available');
    foreach ($range as $states) foreach ($states as $slot => $state) if (BOOKING_STATE_RANK[$state] > BOOKING_STATE_RANK[$worst[$slot]]) $worst[$slot] = $state;
    return $worst;
}
/** The worst state among the windows a booking would occupy on every one of its days ('blocked' for an invalid pair). */
function bookingOptionState(string $date, string $slot, string $format, ?int $excludeId = null, int $days = 1): string {
    $occupied = bookingOccupiedSlots($slot, $format);
    if (!$occupied) return 'blocked';
    $states = bookingRangeStates($date, $days, $excludeId);
    $worst = 'available';
    foreach ($occupied as $window) if (BOOKING_STATE_RANK[$states[$window]] > BOOKING_STATE_RANK[$worst]) $worst = $states[$window];
    return $worst;
}
/** slot => state for the three windows plus 'full_day' (the worst of the three), over $days days. */
function bookingAvailability(string $date, int $days = 1): array {
    $states = bookingRangeStates($date, $days);
    $worst = 'available';
    foreach ($states as $state) if (BOOKING_STATE_RANK[$state] > BOOKING_STATE_RANK[$worst]) $worst = $state;
    return $states + ['full_day' => $worst];
}
/**
 * Whether a booking starting in $slot is free on every day from $date for $days days. The $format decides how many windows
 * it needs; without it a full_day slot means the full-day package and any other slot a single match.
 */
function bookingSlotFree(string $date, string $slot, ?int $excludeId = null, ?string $format = null, int $days = 1): bool {
    $format ??= $slot === 'full_day' ? 'full_day' : 'single';
    return bookingOptionState($date, $slot, $format, $excludeId, $days) === 'available';
}
/** The options of a package from a date for $days days, in order: [['slot', 'label', 'range', 'state', 'available'], ...]. */
function bookingOptions(string $date, string $format, int $days = 1): array {
    $options = [];
    foreach (BOOKING_FORMAT_SLOTS[$format] ?? [] as $slot) {
        $state = bookingOptionState($date, $slot, $format, null, $days);
        $options[] = ['slot' => $slot, 'label' => bookingOptionName($slot, $format), 'range' => bookingTimeRange($slot, $format), 'state' => $state, 'available' => $state === 'available'];
    }
    return $options;
}
/** "Morning", "Morning + Afternoon", "Full day": the name of an option without its time. */
function bookingOptionName(string $slot, string $format): string {
    if ($format === 'full_day' || $slot === 'full_day') return 'Full day';
    return implode(' + ', array_map(fn ($s) => BOOKING_SLOTS[$s], bookingOccupiedSlots($slot, $format)));
}
function bookingStateLabel(string $state): string {
    return ['available' => 'Available', 'held' => 'Held for another team', 'booked' => 'Booked', 'blocked' => 'Not available'][$state] ?? $state;
}
/** [earliest, latest] bookable start dates (Y-m-d) from booking_min_days_ahead (1) and booking_max_days_ahead (120). */
function bookingDateWindow(): array {
    $min = max(0, (int) bookingSetting('booking_min_days_ahead', '1'));
    $max = max($min, (int) bookingSetting('booking_max_days_ahead', '120'));
    return [date('Y-m-d', strtotime("+$min days")), date('Y-m-d', strtotime("+$max days"))];
}

// ---------------------------------------------------------------------------------------------------- requests

/** A 10-digit Indian mobile number from what was typed (+91, 0 and spaces allowed), or ''. */
function bookingCleanPhone(string $phone): string {
    $digits = preg_replace('~\D~', '', $phone);
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) $digits = substr($digits, 2);
    if (strlen($digits) === 11 && $digits[0] === '0') $digits = substr($digits, 1);
    return preg_match('~^[6-9]\d{9}$~', $digits) ? $digits : '';
}

/**
 * [clean values, errors by field] of a booking request (also used to check the approver's changes). Input keys: format, slot,
 * date, days, name, email, phone, organisation, opponent, notes, teams_paying, players_per_team, want_lunch, want_tea,
 * stay_needed, stay_people, stay_nights, stay_notes. $options: 'exclude_id' (a booking to ignore when checking that the
 * windows are free) and 'min_date' (the earliest start date, default booking_min_days_ahead from today).
 */
function bookingValidate(array $input, array $options = []): array {
    $text = fn (string $key, int $max): string => mb_substr(trim(preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~', '', (string) ($input[$key] ?? ''))), 0, $max);
    $clean = ['format' => $text('format', 12), 'slot' => $text('slot', 12), 'date' => $text('date', 10), 'name' => $text('name', 100), 'email' => $text('email', 255),
        'phone' => $text('phone', 20), 'organisation' => $text('organisation', 255), 'opponent' => $text('opponent', 255), 'notes' => $text('notes', 1000)];
    $errors = [];
    $package = bookingPackage($clean['format']);
    if (!$package) $errors['format'] = 'Choose a package.';
    $perTeam = $package['per_team'] ?? true;
    $teams = (int) ($input['teams_paying'] ?? 1);
    $clean['teams_paying'] = $perTeam && $teams === 2 ? 2 : 1;
    $players = trim((string) ($input['players_per_team'] ?? ''));
    $clean['players_per_team'] = $players === '' ? BOOKING_STANDARD_PLAYERS : (ctype_digit($players) ? (int) $players : 0);
    if ($clean['players_per_team'] < 5 || $clean['players_per_team'] > 30) $errors['players_per_team'] = 'Enter the number of players in your team (5 to 30).';
    $daysText = trim((string) ($input['days'] ?? '1'));
    $clean['days'] = $daysText === '' ? 1 : (ctype_digit($daysText) ? (int) $daysText : 0);
    if ($clean['days'] < 1 || $clean['days'] > BOOKING_MAX_DAYS) { $errors['days'] = 'Choose 1 to ' . BOOKING_MAX_DAYS . ' days.'; $clean['days'] = 1; }
    $clean['want_lunch'] = (int) bookingFlag($input, 'want_lunch', true);
    $clean['want_tea'] = (int) bookingFlag($input, 'want_tea', true);

    $clean['stay_needed'] = (int) (bookingStayEnabled() && bookingFlag($input, 'stay_needed', false));
    $clean['stay_people'] = null;
    $clean['stay_nights'] = null;
    $clean['stay_notes'] = $clean['stay_needed'] ? $text('stay_notes', 500) : '';
    if ($clean['stay_needed']) {
        $people = trim((string) ($input['stay_people'] ?? ''));
        $nights = trim((string) ($input['stay_nights'] ?? ''));
        if (!ctype_digit($people) || (int) $people < 1 || (int) $people > 200) $errors['stay_people'] = 'Enter how many people need a room (1 to 200).';
        else $clean['stay_people'] = (int) $people;
        if (!ctype_digit($nights) || (int) $nights < 1 || (int) $nights > 14) $errors['stay_nights'] = 'Enter the number of nights (1 to 14).';
        else $clean['stay_nights'] = (int) $nights;
    }

    $day = DateTime::createFromFormat('!Y-m-d', $clean['date']);
    [$earliest, $latest] = bookingDateWindow();
    $earliest = $options['min_date'] ?? $earliest;
    if (!$day || $day->format('Y-m-d') !== $clean['date']) $errors['date'] = 'Choose a date.';
    elseif ($clean['date'] < $earliest || $clean['date'] > $latest) $errors['date'] = 'Choose a date between ' . bookingDateLabel($earliest) . ' and ' . bookingDateLabel($latest) . '.';
    if ($package && !isset($errors['format'])) {
        if (!bookingValidPair($clean['slot'], $clean['format'])) $errors['slot'] = 'Choose a time for this package.';
        elseif (!isset($errors['date'])) {
            $exclude = $options['exclude_id'] ?? null;
            $state = bookingOptionState($clean['date'], $clean['slot'], $clean['format'], $exclude, $clean['days']);
            if ($state !== 'available') {
                $errors['slot'] = $state === 'held' ? 'That time is on hold for another team' . ($clean['days'] > 1 ? ' on one of the days' : '') . '. Try another time or date.'
                    : 'That time is not available' . ($clean['days'] > 1 ? ' on every one of the ' . $clean['days'] . ' days' : '') . '. Choose another time or date.';
            }
        }
    }
    if (mb_strlen($clean['name']) < 2) $errors['name'] = 'Enter your name.';
    if (!filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    $phone = bookingCleanPhone($clean['phone']);
    if ($phone === '') $errors['phone'] = 'Enter a 10-digit mobile number.';
    $clean['phone'] = $phone !== '' ? $phone : $clean['phone'];
    $clean['end_date'] = $day ? bookingEndDate($clean['date'], $clean['days']) : $clean['date'];
    return [$clean, $errors];
}

/**
 * Saves a request (status 'requested') and emails the requester and the team. Returns ['id', 'token', 'reference'],
 * or ['errors' => [field => message]] when something is wrong (see bookingValidate()). The price is worked out here from
 * the package, never taken from the browser. A signed-in user (when a session is active) is linked to the booking.
 */
function bookingCreate(array $input): array {
    [$clean, $errors] = bookingValidate($input);
    if (!$errors) {
        $open = (int) (query("SELECT COUNT(*) AS c FROM bookings WHERE status = 'requested' AND (LOWER(email) = ? OR phone = ?)", [strtolower($clean['email']), $clean['phone']])[0]['c'] ?? 0);
        if ($open >= 5) $errors['form'] = 'You already have several requests waiting for approval. Please wait for our reply before sending more.';
    }
    if ($errors) return ['errors' => $errors];

    $discount = bookingMemberDiscount($clean['email']);
    $breakdown = bookingPriceBreakdown($clean + ['discount_percent' => $discount['percent'], 'member_tier' => $discount['tier']]);
    $userId = session_status() === PHP_SESSION_ACTIVE ? (currentUser()['id'] ?? null) : null;
    $token = bin2hex(random_bytes(16));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO bookings (reference, token, user_id, name, email, phone, organisation, booking_date, days, end_date, slot, format, opponent, players_per_team, teams_paying,
                want_lunch, want_tea, meal_deduction, stay_needed, stay_people, stay_nights, stay_notes, stay_amount, notes, status, quoted_amount, advance_percent, member_tier, discount_percent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['T' . substr($token, 0, 20), $token, $userId, $clean['name'], $clean['email'], $clean['phone'], $clean['organisation'] ?: null, $clean['date'], $clean['days'], $clean['end_date'],
                $clean['slot'], $clean['format'], $clean['opponent'] ?: null, $clean['players_per_team'], $clean['teams_paying'], $clean['want_lunch'], $clean['want_tea'], $breakdown['meal_deduction'],
                $clean['stay_needed'], $clean['stay_people'], $clean['stay_nights'], $clean['stay_notes'] ?: null, $breakdown['stay'], $clean['notes'] ?: null, 'requested',
                $breakdown['subtotal'], $breakdown['advance_percent'], $discount['tier'], $discount['percent'], bookingNow()]);
        $id = (int) $pdo->lastInsertId();
        $reference = 'BK-' . (10000 + $id);
        $pdo->prepare('UPDATE bookings SET reference = ? WHERE id = ?')->execute([$reference, $id]);
        bookingEvent($id, 'requested', 'Request received.');
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        error_log('Booking request failed: ' . $error->getMessage());
        return ['errors' => ['form' => 'We could not save your request. Please try again in a moment.']];
    }
    bookingMail($id, 'requested');
    bookingMailAdmin($id, 'new');
    return ['id' => $id, 'token' => $token, 'reference' => $reference];
}

// ---------------------------------------------------------------------------------------------------- reading

/** "Sat 21 Nov" style text of a date. */
function bookingShortDate(string $date): string {
    $day = DateTime::createFromFormat('!Y-m-d', $date);
    return $day ? $day->format('D j M') : $date;
}
/** Adds the labels and links to a bookings row. */
function bookingDecorate(array $b): array {
    foreach (['quoted_amount', 'total_amount', 'amount', 'balance_amount'] as $key) $b[$key] = $b[$key] === null ? null : (float) $b[$key];
    foreach (['discount_percent', 'advance_percent', 'meal_deduction', 'stay_amount', 'extra_amount'] as $key) $b[$key] = (float) ($b[$key] ?? 0);
    $b['id'] = (int) $b['id'];
    $b['players_per_team'] = (int) $b['players_per_team'];
    $b['teams_paying'] = (int) $b['teams_paying'];
    $b['days'] = max(1, (int) ($b['days'] ?? 1));
    $b['end_date'] = $b['end_date'] ?: bookingEndDate($b['booking_date'], $b['days']);
    foreach (['want_lunch', 'want_tea', 'stay_needed', 'admin_modified'] as $key) $b[$key] = (int) ($b[$key] ?? 0);
    $b['package'] = bookingPackage($b['format']);
    $b['is_per_team'] = (bool) ($b['package']['per_team'] ?? true);
    $b['slot_label'] = bookingSlotLabel($b['slot'], $b['format']);
    $b['time_range'] = bookingTimeRange($b['slot'], $b['format']);
    $b['format_label'] = bookingFormatLabel($b['format']);
    $b['status_label'] = bookingStatusLabel($b['status']);
    $b['date_label'] = $b['days'] > 1 ? bookingShortDate($b['booking_date']) . ' to ' . bookingDateLabel($b['end_date']) . ' (' . bookingDaysText($b['days']) . ')' : bookingDateLabel($b['booking_date']);
    $b['meals_label'] = $b['want_lunch'] && $b['want_tea'] ? 'Lunch and tea included' : (!$b['want_lunch'] && !$b['want_tea'] ? 'No lunch, no tea' : ($b['want_lunch'] ? 'Lunch included, no tea' : 'Tea included, no lunch'));
    $b['stay_label'] = $b['stay_needed'] ? 'Yes: ' . (int) $b['stay_people'] . ' people, ' . (int) $b['stay_nights'] . ' night' . ((int) $b['stay_nights'] === 1 ? '' : 's') : 'No';
    $b['extra_players'] = $b['players_per_team'] > BOOKING_STANDARD_PLAYERS;
    $b['status_url'] = absoluteUrl('{{BASE}}/booking/' . $b['token']);
    $b['pay_url'] = absoluteUrl('{{BASE}}/booking/' . $b['token'] . '/pay');
    return $b;
}
/** A booking by id or by its 32-hex token, with 'events' (oldest first) and the labels; null when there is none. */
function bookingGet(int|string $idOrToken): ?array {
    $row = is_int($idOrToken) || ctype_digit($idOrToken)
        ? query('SELECT * FROM bookings WHERE id = ?', [(int) $idOrToken])[0] ?? null
        : (preg_match('~^[a-f0-9]{32}$~', $idOrToken) ? query('SELECT * FROM bookings WHERE token = ?', [$idOrToken])[0] ?? null : null);
    if (!$row) return null;
    $b = bookingDecorate($row);
    $b['events'] = query('SELECT status, note, created_at FROM booking_events WHERE booking_id = ? ORDER BY id', [$b['id']]);
    return $b;
}
/** WHERE clause and parameters of the list filters: status (string|array), q, from, to (bookings that play in that range), user_id, email. */
function bookingFilterSql(array $f): array {
    $where = ['1 = 1'];
    $params = [];
    if (!empty($f['status'])) {
        $statuses = array_values(array_intersect((array) $f['status'], array_keys(BOOKING_STATUSES)));
        if ($statuses) { $where[] = 'status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')'; array_push($params, ...$statuses); }
    }
    if (trim((string) ($f['q'] ?? '')) !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $f['q'])) . '%';
        $where[] = '(reference LIKE ? OR name LIKE ? OR email LIKE ? OR phone LIKE ? OR organisation LIKE ? OR opponent LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($f['from'])) { $where[] = 'COALESCE(end_date, booking_date) >= ?'; $params[] = $f['from']; }
    if (!empty($f['to'])) { $where[] = 'booking_date <= ?'; $params[] = $f['to']; }
    if (!empty($f['user_id'])) { $where[] = 'user_id = ?'; $params[] = (int) $f['user_id']; }
    if (!empty($f['email'])) { $where[] = 'LOWER(email) = ?'; $params[] = strtolower((string) $f['email']); }
    return [implode(' AND ', $where), $params];
}
/** Bookings (decorated rows, no events) for the filters; order: 'newest' (default), 'date_asc' or 'date_desc'; limit and offset. */
function bookingList(array $filters = []): array {
    bookingExpireDue();
    [$where, $params] = bookingFilterSql($filters);
    $order = ['date_asc' => 'booking_date ASC, id ASC', 'date_desc' => 'booking_date DESC, id DESC'][$filters['order'] ?? ''] ?? 'created_at DESC, id DESC';
    $sql = "SELECT * FROM bookings WHERE $where ORDER BY $order";
    if (isset($filters['limit'])) $sql .= ' LIMIT ' . max(1, (int) $filters['limit']) . ' OFFSET ' . max(0, (int) ($filters['offset'] ?? 0));
    return array_map('bookingDecorate', query($sql, $params));
}
function bookingCount(array $filters = []): int {
    [$where, $params] = bookingFilterSql($filters);
    return (int) (query("SELECT COUNT(*) AS c FROM bookings WHERE $where", $params)[0]['c'] ?? 0);
}
/** status => count for every status, plus 'all'. */
function bookingStatusCounts(): array {
    bookingExpireDue();
    $counts = array_fill_keys(array_keys(BOOKING_STATUSES), 0);
    foreach (query('SELECT status, COUNT(*) AS c FROM bookings GROUP BY status') as $row) $counts[$row['status']] = (int) $row['c'];
    return $counts + ['all' => array_sum($counts)];
}

// --------------------------------------------------------------------------------------- what the team changed

/** How a requirement is shown in the "what we changed" list. $v holds all the requirement fields of one side (original or current). */
function bookingRequirementText(string $field, array $v): string {
    $yes = fn ($x) => (int) $x === 1;
    return match ($field) {
        'booking_date' => bookingDateLabel((string) $v['booking_date']),
        'days' => bookingDaysText((int) $v['days']),
        'slot' => bookingValidPair((string) $v['slot'], (string) $v['format']) ? bookingSlotLabel((string) $v['slot'], (string) $v['format']) : (string) $v['slot'],
        'format' => bookingFormatLabel((string) $v['format']),
        'teams_paying' => (int) $v['teams_paying'] === 2 ? 'Both teams' : 'This team only',
        'players_per_team' => (string) (int) $v['players_per_team'],
        'want_lunch' => $yes($v['want_lunch']) ? 'Included' : 'Skipped',
        'want_tea' => $yes($v['want_tea']) ? 'Included' : 'Skipped',
        'stay_needed' => $yes($v['stay_needed']) ? 'Yes' : 'No',
        'stay_people' => $v['stay_people'] === null || $v['stay_people'] === '' ? '–' : (string) (int) $v['stay_people'],
        'stay_nights' => $v['stay_nights'] === null || $v['stay_nights'] === '' ? '–' : (string) (int) $v['stay_nights'],
        default => trim((string) ($v[$field] ?? '')) === '' ? '–' : mb_substr(trim((string) $v[$field]), 0, 200),
    };
}
const BOOKING_REQUIREMENT_LABELS = ['booking_date' => 'Start date', 'days' => 'Days', 'slot' => 'Time', 'format' => 'Package', 'teams_paying' => 'Payment for', 'players_per_team' => 'Players per team',
    'opponent' => 'Opponent', 'organisation' => 'Team', 'want_lunch' => 'Lunch', 'want_tea' => 'Tea', 'stay_needed' => 'Overnight stay', 'stay_people' => 'People staying', 'stay_nights' => 'Nights',
    'stay_notes' => 'Stay notes', 'notes' => 'Message'];
/** The requirements as they are now, in the shape bookingRequirementText() reads. */
function bookingRequirements(array $row): array {
    $out = [];
    foreach (BOOKING_REQUIREMENT_FIELDS as $field) $out[$field] = $row[$field] ?? null;
    return $out;
}
/**
 * What the approver changed: a list of ['field', 'label', 'from', 'to'] comparing the first requested values
 * (request_snapshot) with the current ones. Empty when the request was not modified. "Time" covers the slot and the package.
 */
function bookingChangeList(array $b): array {
    if (empty($b['admin_modified']) || trim((string) ($b['request_snapshot'] ?? '')) === '') return [];
    $then = json_decode((string) $b['request_snapshot'], true);
    if (!is_array($then)) return [];
    $now = bookingRequirements($b);
    $then += $now;
    $changes = [];
    foreach (BOOKING_REQUIREMENT_FIELDS as $field) {
        if ($field === 'format') continue;                                   // shown together with the time
        $slotSide = $field === 'slot';
        $same = $slotSide ? ($then['slot'] === $now['slot'] && $then['format'] === $now['format']) : (string) $then[$field] === (string) $now[$field];
        if ($same) continue;
        $changes[] = ['field' => $field, 'label' => $slotSide ? 'Package and time' : BOOKING_REQUIREMENT_LABELS[$field],
            'from' => $slotSide ? bookingFormatLabel((string) $then['format']) . ', ' . bookingRequirementText('slot', $then) : bookingRequirementText($field, $then),
            'to' => $slotSide ? bookingFormatLabel((string) $now['format']) . ', ' . bookingRequirementText('slot', $now) : bookingRequirementText($field, $now)];
    }
    return $changes;
}
/** The "We adjusted your request" block for pages (empty when nothing was changed); classes bk-box bk-box--changes. */
function bookingChangesHtml(array $b): string {
    $changes = bookingChangeList($b);
    if (!$changes) return '';
    $items = implode('', array_map(fn ($c) => '<li><strong>' . escape($c['label']) . ':</strong> <s>' . escape($c['from']) . '</s> &rarr; <strong>' . escape($c['to']) . '</strong></li>', $changes));
    return '<div class="bk-box bk-box--changes"><h3>We adjusted your request</h3><p>Our team changed some details of your request. Here is what was requested and what is approved:</p><ul>' . $items . '</ul></div>';
}

// ------------------------------------------------------------------------------------------------ state machine

/**
 * Runs $change on a locked booking row inside a transaction. $change receives the row and returns [columns to update,
 * [event status, note]] or null to refuse. Returns the row as it was before the change, or null when refused.
 */
function bookingTransition(int $id, array $from, callable $change): ?array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !in_array($row['status'], $from, true)) { $pdo->rollBack(); return null; }
        $result = $change($row);
        if ($result === null) { $pdo->rollBack(); return null; }
        [$set, $event] = $result;
        $set['updated_at'] = bookingNow();
        $sql = 'UPDATE bookings SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute([...array_values($set), $id]);
        bookingEvent($id, $event[0], $event[1] ?? null);
        $pdo->commit();
        return $row;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Booking ' . $id . ' change failed: ' . $error->getMessage());
        return null;
    }
}

/**
 * The approver changes what was requested: only while the booking is 'requested' or 'approved' (unpaid). $changes may hold
 * booking_date, days, slot, format, teams_paying, players_per_team, opponent, organisation, want_lunch, want_tea, stay_needed,
 * stay_people, stay_nights, stay_notes, notes (never name, email, phone or the status). Checked like a new request (a
 * valid package/time pair, a start date from today up to the booking window, every day free for the whole range - this
 * booking ignored). Recomputes quoted_amount and meal_deduction; keeps the original requirements in request_snapshot the
 * first time; sets admin_modified; logs exactly what changed. The payable amount of an approved booking is NOT touched:
 * approve it again (bookingApprove) to send the new amount. Returns ['ok' => bool, 'error' => ?string, 'errors' => [field => text],
 * 'changes' => [['label', 'from', 'to']]].
 */
function bookingUpdateRequirements(int $id, array $changes, ?int $adminId = null, ?string $note = null): array {
    $fail = fn (string $message, array $errors = []) => ['ok' => false, 'error' => $message, 'errors' => $errors, 'changes' => []];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $pdo->rollBack(); return $fail('Booking not found.'); }
        if (!in_array($row['status'], ['requested', 'approved'], true)) { $pdo->rollBack(); return $fail('Only a request or an unpaid approved booking can be changed.'); }

        $current = bookingRequirements($row);
        $new = $current;
        foreach (BOOKING_REQUIREMENT_FIELDS as $field) if (array_key_exists($field, $changes)) $new[$field] = $changes[$field];
        $new['days'] = $new['days'] ?? 1;
        $input = ['format' => $new['format'], 'slot' => $new['slot'], 'date' => $new['booking_date'], 'days' => (string) $new['days'], 'name' => $row['name'], 'email' => $row['email'], 'phone' => $row['phone'],
            'organisation' => $new['organisation'], 'opponent' => $new['opponent'], 'notes' => $new['notes'], 'teams_paying' => $new['teams_paying'], 'players_per_team' => (string) $new['players_per_team'],
            'want_lunch' => (int) bookingFlag($new, 'want_lunch', true), 'want_tea' => (int) bookingFlag($new, 'want_tea', true), 'stay_needed' => $new['stay_needed'],
            'stay_people' => (string) ($new['stay_people'] ?? ''), 'stay_nights' => (string) ($new['stay_nights'] ?? ''), 'stay_notes' => $new['stay_notes']];
        // The stay section is checked only when it is wanted; the approver may also record one when requests for it are switched off.
        [$clean, $errors] = bookingValidate($input, ['exclude_id' => $id, 'min_date' => date('Y-m-d')]);
        if (!$clean['stay_needed'] && bookingFlag($new, 'stay_needed', false)) {
            $people = trim((string) ($new['stay_people'] ?? ''));
            $nights = trim((string) ($new['stay_nights'] ?? ''));
            if (ctype_digit($people) && ctype_digit($nights) && (int) $people > 0 && (int) $nights > 0) {
                $clean['stay_needed'] = 1; $clean['stay_people'] = (int) $people; $clean['stay_nights'] = (int) $nights; $clean['stay_notes'] = mb_substr(trim((string) $new['stay_notes']), 0, 500);
            }
        }
        if ($errors) { $pdo->rollBack(); return $fail((string) reset($errors), $errors); }

        $after = ['booking_date' => $clean['date'], 'days' => $clean['days'], 'slot' => $clean['slot'], 'format' => $clean['format'], 'teams_paying' => $clean['teams_paying'], 'players_per_team' => $clean['players_per_team'],
            'opponent' => $clean['opponent'] !== '' ? $clean['opponent'] : null, 'organisation' => $clean['organisation'] !== '' ? $clean['organisation'] : null, 'want_lunch' => $clean['want_lunch'], 'want_tea' => $clean['want_tea'],
            'stay_needed' => $clean['stay_needed'], 'stay_people' => $clean['stay_people'], 'stay_nights' => $clean['stay_nights'], 'stay_notes' => $clean['stay_notes'] !== '' ? $clean['stay_notes'] : null,
            'notes' => $clean['notes'] !== '' ? $clean['notes'] : null];
        $before = bookingRequirements($row);
        $list = [];
        foreach (BOOKING_REQUIREMENT_FIELDS as $field) {
            if ((string) ($before[$field] ?? '') === (string) ($after[$field] ?? '')) continue;
            $list[] = ['label' => BOOKING_REQUIREMENT_LABELS[$field], 'from' => bookingRequirementText($field, $before), 'to' => bookingRequirementText($field, $after)];
        }
        if (!$list && abs((float) ($changes['stay_amount'] ?? $row['stay_amount']) - (float) $row['stay_amount']) < 0.005) { $pdo->rollBack(); return ['ok' => true, 'error' => null, 'errors' => [], 'changes' => []]; }
        if (!$list) $list[] = ['label' => 'Accommodation charge', 'from' => bookingMoney($row['stay_amount']), 'to' => bookingMoney((float) $changes['stay_amount'])];

        // The accommodation charge follows people and nights unless the approver typed a figure.
        $stayChanged = (int) $before['stay_needed'] !== (int) $after['stay_needed'] || (string) $before['stay_people'] !== (string) $after['stay_people'] || (string) $before['stay_nights'] !== (string) $after['stay_nights'];
        if (array_key_exists('stay_amount', $changes)) $stayAmount = max(0.0, round((float) $changes['stay_amount'], 2));
        elseif ($stayChanged) $stayAmount = $after['stay_needed'] ? bookingStayAmount($after['stay_people'], $after['stay_nights']) : 0.0;
        else $stayAmount = (float) $row['stay_amount'];
        $breakdown = bookingPriceBreakdown($after + ['discount_percent' => $row['discount_percent'], 'member_tier' => $row['member_tier'], 'advance_percent' => $row['advance_percent'], 'stay_amount' => $stayAmount]);
        $set = $after + ['end_date' => $clean['end_date'], 'meal_deduction' => $breakdown['meal_deduction'], 'quoted_amount' => $breakdown['subtotal'], 'stay_amount' => $stayAmount, 'admin_modified' => 1, 'updated_at' => bookingNow()];
        if (trim((string) $row['request_snapshot']) === '') $set['request_snapshot'] = json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare('UPDATE bookings SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $id]);
        $summary = implode('; ', array_map(fn ($c) => $c['label'] . ': ' . $c['from'] . ' -> ' . $c['to'], $list));
        bookingEvent($id, 'modified', 'Changed by the team' . ($note !== null && trim($note) !== '' ? ' (' . trim($note) . ')' : '') . ': ' . $summary);
        $pdo->commit();
        return ['ok' => true, 'error' => null, 'errors' => [], 'changes' => $list];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Booking ' . $id . ' requirements change failed: ' . $error->getMessage());
        return $fail('The change could not be saved. Please try again.');
    }
}

/**
 * Approves a request: sets the TOTAL price (the full price including any accommodation and extras, as the approver
 * typed it), works out the advance to pay now (total x advance_percent, whole rupees) and the balance at the ground,
 * and holds the slot for the hold hours (setting booking_hold_hours, 48, or $holdHours). Allowed from 'requested' and
 * from 'approved' while unpaid, so the amount can be revised after changing the requirements; that sends the approval
 * email with the new amount again, clears razorpay_order_id (the pay page then makes a fresh Razorpay order for the new
 * amount) and, when $holdHours is given, restarts the payment deadline. Refused while the windows are not free for every
 * day. $extras: stay_amount (float), extra_amount (float), extra_note (string), saved on the booking.
 */
function bookingApprove(int $id, float $total, ?string $customerNote = null, ?int $adminId = null, ?int $holdHours = null, array $extras = []): bool {
    if ($total <= 0) return false;
    $hours = $holdHours ?? max(1, (int) bookingSetting('booking_hold_hours', '48'));
    $done = bookingTransition($id, ['requested', 'approved'], function (array $row) use ($id, $total, $customerNote, $adminId, $hours, $holdHours, $extras) {
        $days = max(1, (int) ($row['days'] ?? 1));
        if (!bookingSlotFree($row['booking_date'], $row['slot'], $id, $row['format'], $days)) return null;
        [$advance, $balance] = bookingSplit(round($total, 2), (float) $row['advance_percent']);
        $revised = $row['status'] === 'approved';
        $now = bookingNow();
        $due = $revised && $holdHours === null && $row['payment_due_at'] !== null && $row['payment_due_at'] > $now ? $row['payment_due_at'] : date('Y-m-d H:i:s', time() + $hours * 3600);
        $set = ['status' => 'approved', 'total_amount' => round($total, 2), 'amount' => $advance, 'balance_amount' => $balance, 'approved_at' => $revised && $row['approved_at'] ? $row['approved_at'] : $now,
            'approved_by' => $adminId ?? $row['approved_by'], 'payment_due_at' => $due,
            'customer_note' => $customerNote !== null && trim($customerNote) !== '' ? mb_substr(trim($customerNote), 0, 500) : $row['customer_note']];
        if (array_key_exists('stay_amount', $extras)) $set['stay_amount'] = max(0, round((float) $extras['stay_amount'], 2));
        if (array_key_exists('extra_amount', $extras)) $set['extra_amount'] = max(0, round((float) $extras['extra_amount'], 2));
        if (array_key_exists('extra_note', $extras)) $set['extra_note'] = trim((string) $extras['extra_note']) !== '' ? mb_substr(trim((string) $extras['extra_note']), 0, 255) : null;
        if ($revised && abs((float) $row['amount'] - $advance) > 0.001) $set['razorpay_order_id'] = null;   // the old Razorpay order is for another amount
        return [$set, ['approved', ($revised ? 'Approval revised. ' : 'Approved. ') . 'Total ' . bookingMoney($total) . '; advance ' . bookingMoney($advance) . ' due by ' . date('j M Y, g:i A', strtotime($due)) . '.']];
    });
    if (!$done) return false;
    bookingMail($id, 'approved');
    return true;
}
function bookingReject(int $id, string $reason, ?int $adminId = null): bool {
    $reason = mb_substr(trim($reason), 0, 500);
    $done = bookingTransition($id, ['requested'], fn (array $row) => [['status' => 'rejected', 'customer_note' => $reason !== '' ? $reason : $row['customer_note'], 'approved_by' => $adminId],
        ['rejected', $reason !== '' ? $reason : 'Not approved.']]);
    if (!$done) return false;
    bookingMail($id, 'rejected');
    return true;
}
/** Cancels a request, an approved booking or a confirmed one (refunds of a paid advance are arranged by hand). */
function bookingCancel(int $id, ?string $note = null, ?int $adminId = null): bool {
    $note = $note === null ? '' : mb_substr(trim($note), 0, 500);
    $done = bookingTransition($id, ['requested', 'approved', 'confirmed'], fn (array $row) => [['status' => 'cancelled', 'customer_note' => $note !== '' ? $note : $row['customer_note']],
        ['cancelled', $note !== '' ? $note : 'Cancelled.']]);
    if (!$done) return false;
    bookingMail($id, 'cancelled');
    return true;
}
/**
 * Records the advance as paid: approved -> confirmed. Idempotent (a booking that is already confirmed returns true
 * and nothing is sent twice). A payment that arrives after the hold expired still confirms the booking when the
 * windows are free; if they were taken meanwhile it returns false (the payment agent flags that for a refund).
 */
function bookingMarkPaid(int $id, string $method, ?string $paymentId = null, ?int $adminId = null): bool {
    if (!in_array($method, ['razorpay', 'offline'], true)) return false;
    $current = query('SELECT status FROM bookings WHERE id = ?', [$id])[0]['status'] ?? null;
    if ($current === 'confirmed') return true;
    $done = bookingTransition($id, ['approved', 'expired'], function (array $row) use ($id, $method, $paymentId) {
        $late = $row['status'] === 'expired';
        if ($late && !bookingSlotFree($row['booking_date'], $row['slot'], $id, $row['format'], max(1, (int) ($row['days'] ?? 1)))) return null;
        $set = ['status' => 'confirmed', 'payment_method' => $method, 'paid_at' => bookingNow()];
        if ($paymentId !== null && $paymentId !== '') $set['razorpay_payment_id'] = $paymentId;
        return [$set, ['confirmed', 'Advance ' . bookingMoney($row['amount']) . ' received' . ($method === 'razorpay' ? ' online (Razorpay).' : ' (paid offline).') . ($late ? ' Paid after the hold expired; the slot was still free.' : '')]];
    });
    if (!$done) return false;
    bookingMail($id, 'confirmed');
    bookingMailAdmin($id, 'confirmed');
    return true;
}

// ---------------------------------------------------------------------------------------------- calendar file

/** 24-hour "H:i" of a time like "8:00 AM" (null when it can't be read). */
function bookingTime24(string $text): ?string {
    $time = DateTime::createFromFormat('g:i A', strtoupper(trim(preg_replace('~\s+~', ' ', $text))));
    return $time ? $time->format('H:i') : null;
}
/** An .ics calendar entry for a booking ("Add to calendar"), repeating daily for a multi-day booking; all-day when the times can't be read. */
function bookingIcs(array $b): string {
    $range = bookingSplitRange($b['time_range']);
    [$start, $end] = [bookingTime24($range[0]), bookingTime24($range[1])];
    $day = str_replace('-', '', $b['booking_date']);
    $esc = fn (string $s) => addcslashes(str_replace(["\r", "\n"], ['', '\\n'], $s), ',;\\');
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Pro HPCL//Ground booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VTIMEZONE', 'TZID:Asia/Kolkata', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0530', 'TZOFFSETTO:+0530', 'TZNAME:IST', 'END:STANDARD', 'END:VTIMEZONE',
        'BEGIN:VEVENT', 'UID:' . $b['reference'] . '@prohpcl.com', 'DTSTAMP:' . gmdate('Ymd\THis\Z')];
    if ($start && $end) {
        $lines[] = 'DTSTART;TZID=Asia/Kolkata:' . $day . 'T' . str_replace(':', '', $start) . '00';
        $lines[] = 'DTEND;TZID=Asia/Kolkata:' . $day . 'T' . str_replace(':', '', $end) . '00';
    } else {
        $lines[] = 'DTSTART;VALUE=DATE:' . $day;
        $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($b['booking_date'] . ' +1 day'));
    }
    if ($b['days'] > 1) $lines[] = 'RRULE:FREQ=DAILY;COUNT=' . (int) $b['days'];
    $balance = $b['balance_amount'] !== null && $b['balance_amount'] > 0 ? ' Pay ' . bookingMoney($b['balance_amount']) . ' balance at the ground.' : '';
    array_push($lines, 'SUMMARY:' . $esc(bookingGroundName() . ': ' . $b['format_label']), 'LOCATION:' . $esc(bookingGroundName() . ', ' . bookingGroundAddress()),
        'DESCRIPTION:' . $esc('Booking ' . $b['reference'] . ' (' . $b['slot_label'] . ').' . $balance), 'STATUS:CONFIRMED', 'END:VEVENT', 'END:VCALENDAR');
    return implode("\r\n", $lines) . "\r\n";
}

require_once __DIR__ . '/booking-pages.php';
