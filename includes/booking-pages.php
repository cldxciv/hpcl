<?php
declare(strict_types=1);

// Ground booking: the public pages and endpoints (loaded by includes/booking.php).
//   GET  /book                     renderBookingForm()          the request form
//   GET  /book/availability        handleBookingAvailability()  JSON: ?date&days&format -> the time options (with their HTML);
//                                                               ?quote=1&format&teams&days&lunch&tea&email -> the price breakdown (with its HTML)
//   POST /book                     handleBookingRequest()       saves a request, then sends the visitor to /booking/<token>
//   GET  /booking/<token>          renderBookingStatus()        private status page (and ?ics=1, the calendar file)
// CSRF is the account session's token (accountCsrfToken() / accountCsrfValid()).

// The real photos of the ground (assets/ground, WebP, already size-optimised). path (under assets/ground), width, height, alt.
const BOOKING_PHOTOS = [
    'aerial' => ['slots/ground-hero-aerial.webp', 1920, 941, 'Aerial view of Shimla Cricket Ground and the surrounding hills'],
    'players' => ['slots/landmark-master-pitch.webp', 800, 533, 'Players on the cricket pitch at Shimla Cricket Ground'],
    'pitch' => ['slots/pitch-report.webp', 1200, 675, 'The pitch at Shimla Cricket Ground with forested hills behind it'],
    'panorama' => ['slots/ground-panorama.webp', 864, 432, 'Panorama of Shimla Cricket Ground, a green oval among forested hills'],
    'vista' => ['slots/vault-dhauladhar-vista.webp', 800, 604, 'Forested hills around Shimla Cricket Ground'],
    'dew' => ['slots/gallery-morning-dew.webp', 680, 510, 'Two white leather cricket balls on the grass at Shimla Cricket Ground'],
];
/** The photo shown on each package card. */
const BOOKING_FORMAT_PHOTO = ['single' => 'players', 'double' => 'pitch', 'full_day' => 'aerial'];
/** Matches played here: file name (in gallery/events), thumbnail size, full size, alt text. */
const BOOKING_EVENT_PHOTOS = [
    ['IMG_4410', 340, 255, 1280, 960, 'Team photo of players and officials at Shimla Cricket Ground'],
    ['IMG_4358', 300, 400, 960, 1280, 'Three men holding a trophy after a match at Shimla Cricket Ground'],
    ['IMG_4412', 400, 225, 1280, 720, 'Two teams lined up for a group photo on the ground'],
    ['IMG_4460', 255, 340, 816, 1088, 'Players and guests with a trophy at Shimla Cricket Ground'],
    ['IMG_4440', 400, 225, 1280, 720, 'Team photo after a match at Shimla Cricket Ground'],
    ['IMG_4420', 300, 400, 960, 1280, 'Two men presenting a trophy at Shimla Cricket Ground'],
    ['IMG_4429', 255, 340, 816, 1088, 'Players and officials after a trophy presentation at the ground'],
    ['IMG_4472', 255, 340, 816, 1088, 'Players receiving a trophy at Shimla Cricket Ground'],
];
/** The URL of a ground photo ({{BASE}} is filled in when the page is rendered). */
function bookingPhotoUrl(string $path): string {
    return '{{BASE}}/assets/ground/' . $path;
}
/** An <img> of a photo from BOOKING_PHOTOS, with its size and alt text; $lazy for everything below the fold. */
function bkPhoto(string $key, string $class, bool $lazy = true): string {
    [$path, $w, $h, $alt] = BOOKING_PHOTOS[$key];
    return '<img class="' . $class . '" src="' . bookingPhotoUrl($path) . '" width="' . $w . '" height="' . $h . '" alt="' . escape($alt) . '"' . ($lazy ? ' loading="lazy" decoding="async"' : ' decoding="async" fetchpriority="high"') . '>';
}

function bookingSession(): void {
    accountStartSession();
}
function bookingPrivateHeaders(): void {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');
}
function bookingJson(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    bookingPrivateHeaders();
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------------------------- form pieces

/** One field: a label, the control, a hint and the (possibly empty) error line. */
function bkField(string $id, string $label, string $control, string $error = '', string $hint = '', bool $optional = false): string {
    return '<div class="bk-field' . ($error !== '' ? ' has-error' : '') . '"><label for="' . $id . '">' . escape($label) . ($optional ? ' <span class="bk-optional">(optional)</span>' : '') . '</label>'
        . $control . ($hint !== '' ? '<p class="bk-hint" id="hint-' . $id . '">' . $hint . '</p>' : '') . '<p class="bk-error" id="err-' . $id . '">' . escape($error) . '</p></div>';
}
/** An input with the attributes that tie it to its hint and error. */
function bkInput(string $id, string $type, string $value, string $error, bool $hasHint, string $attrs = ''): string {
    $describedBy = trim(($hasHint ? 'hint-' . $id : '') . ($error !== '' ? ' err-' . $id : ''));
    return '<input class="bk-input" id="' . $id . '" name="' . $id . '" type="' . $type . '" value="' . escape($value) . '"' . ($attrs !== '' ? ' ' . $attrs : '')
        . ($describedBy !== '' ? ' aria-describedby="' . $describedBy . '"' : '') . ($error !== '' ? ' aria-invalid="true"' : '') . '>';
}
/** A checkbox that always posts a value (a hidden 0 first, then 1 when ticked). */
function bkCheckbox(string $id, string $label, bool $checked, string $hint = ''): string {
    return '<div class="bk-check"><input type="hidden" name="' . $id . '" value="0"><input type="checkbox" id="' . $id . '" name="' . $id . '" value="1"' . ($checked ? ' checked' : '') . ($hint !== '' ? ' aria-describedby="hint-' . $id . '"' : '') . '>'
        . '<label for="' . $id . '">' . $label . ($hint !== '' ? ' <span class="bk-check__hint" id="hint-' . $id . '">' . escape($hint) . '</span>' : '') . '</label></div>';
}

/** The package cards (radio buttons): price, duration, overs and what is included. */
function bkPackageCards(string $selected): string {
    $html = '';
    foreach (bookingPackages() as $format => $p) {
        $price = $p['price'] === null ? '<strong>Price on request</strong>' : '<strong>' . escape(bookingMoney($p['price'])) . '</strong> <small>' . ($p['per_team'] ? 'per team, per day' : 'flat price, per day') . '</small>';
        $meta = array_filter([$p['duration_label'], $p['overs_label']]);
        $html .= '<div class="bk-pack">' . (isset(BOOKING_FORMAT_PHOTO[$format]) ? bkPhoto(BOOKING_FORMAT_PHOTO[$format], 'bk-pack__img') : '')
            . '<input class="bk-sr" type="radio" name="format" id="format-' . $format . '" value="' . $format . '" data-per-team="' . ($p['per_team'] ? '1' : '0') . '"'
            . ($selected === $format ? ' checked' : '') . ' aria-describedby="pack-' . $format . '-info">'
            . '<label class="bk-pack__name" for="format-' . $format . '">' . escape(bookingFormatLabel($format)) . '</label>'
            . '<div class="bk-pack__body" id="pack-' . $format . '-info"><p class="bk-pack__price">' . $price . '</p>'
            . ($meta ? '<p class="bk-pack__meta">' . escape(implode(' · ', $meta)) . '</p>' : '')
            . ($p['includes'] ? '<ul class="bk-pack__list">' . implode('', array_map(fn ($line) => '<li>' . escape($line) . '</li>', $p['includes'])) . '</ul>' : '') . '</div></div>';
    }
    return $html;
}

/** The time options of a package from a date for some days, as radio cards. Without a package and a date: a hint. */
function bkSlotOptionsHtml(string $date, string $format, string $selected, int $days = 1): string {
    $day = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$day || $day->format('Y-m-d') !== $date || !isset(BOOKING_FORMATS[$format])) {
        return '<p class="bk-hint bk-slots__hint">Choose a package and a date to see the available times.</p>';
    }
    [$earliest, $latest] = bookingDateWindow();
    if ($date < $earliest || $date > $latest) return '<p class="bk-hint bk-slots__hint">Bookings open from ' . escape(bookingDateLabel($earliest)) . ' to ' . escape(bookingDateLabel($latest)) . '.</p>';
    $days = max(1, min(BOOKING_MAX_DAYS, $days));
    $html = '';
    $anyFree = false;
    foreach (bookingOptions($date, $format, $days) as $o) {
        $anyFree = $anyFree || $o['available'];
        $html .= '<div class="bk-slot bk-slot--' . $o['state'] . '"><input class="bk-sr" type="radio" name="slot" id="slot-' . $o['slot'] . '" value="' . $o['slot'] . '"'
            . ($o['available'] ? '' : ' disabled') . ($selected === $o['slot'] && $o['available'] ? ' checked' : '') . ' aria-describedby="slot-' . $o['slot'] . '-state">'
            . '<label for="slot-' . $o['slot'] . '"><span class="bk-slot__name">' . escape($o['label']) . '</span><span class="bk-slot__range">' . escape($o['range']) . '</span></label>'
            . '<span class="bk-slot__state" id="slot-' . $o['slot'] . '-state">' . escape(bookingStateLabel($o['state'])) . '</span></div>';
    }
    $range = $days > 1 ? bookingShortDate($date) . ' to ' . bookingDateLabel(bookingEndDate($date, $days)) : bookingDateLabel($date);
    return $html . (!$anyFree ? '<p class="bk-hint bk-slots__hint">Nothing is free for this package on ' . escape($range) . '. Please try other dates.</p>' : '');
}

/** The values the form starts with: the query string, the account of a signed-in user, then the posted values. */
function bookingFormValues(?array $posted): array {
    $v = ['format' => '', 'date' => '', 'days' => '1', 'slot' => '', 'name' => '', 'email' => '', 'phone' => '', 'organisation' => '', 'opponent' => '', 'players_per_team' => (string) BOOKING_STANDARD_PLAYERS,
        'teams_paying' => '1', 'want_lunch' => '1', 'want_tea' => '1', 'stay_needed' => '0', 'stay_people' => '', 'stay_nights' => '', 'stay_notes' => '', 'notes' => '', 'terms' => ''];
    foreach (['format', 'date', 'slot', 'days'] as $key) if (isset($_GET[$key]) && is_string($_GET[$key])) $v[$key] = substr(trim($_GET[$key]), 0, 12);
    if (!isset(BOOKING_FORMATS[$v['format']])) $v['format'] = '';
    $user = currentUser();
    if ($user) {
        $v['name'] = (string) $user['name'];
        $v['email'] = (string) $user['email'];
        $v['phone'] = preg_replace('~\D~', '', (string) ($user['phone'] ?? ''));
    }
    if ($posted !== null) foreach (array_keys($v) as $key) if (isset($posted[$key]) && is_string($posted[$key])) $v[$key] = trim($posted[$key]);
    if (!ctype_digit($v['days']) || (int) $v['days'] < 1 || (int) $v['days'] > BOOKING_MAX_DAYS) $v['days'] = '1';
    return $v;
}

/** The quote of the current choices (null while no package is chosen). */
function bkQuoteFor(array $v): ?array {
    return bookingPackage($v['format']) ? bookingQuote($v['slot'], $v['format'], $v['email'], (int) $v['teams_paying'], (int) $v['days'], $v['want_lunch'] !== '0', $v['want_tea'] !== '0',
        $v['stay_needed'] === '1' && ctype_digit($v['stay_people']) ? (int) $v['stay_people'] : 0, $v['stay_needed'] === '1' && ctype_digit($v['stay_nights']) ? (int) $v['stay_nights'] : 0) : null;
}
function bkMoneyOrDash(?float $amount): string {
    return $amount === null ? '–' : bookingMoney($amount);
}
/** The price rows of the summary (also what the page script swaps in): the lines, the total, the advance and the balance. */
function bkMoneyBlockHtml(?array $q): string {
    if (!$q || !$q['ok']) return '<dl class="bk-sum bk-sum--money"><div class="bk-sum__total"><dt>Total</dt><dd>–</dd></div></dl><p class="bk-sum__note">Choose a package to see the price.</p>';
    $rows = '';
    foreach ($q['lines'] as $line) {
        $rows .= '<div class="bk-sum__line' . ($line['amount'] !== null && $line['amount'] < 0 ? ' bk-sum__line--save' : '') . '"><dt>' . escape($line['label']) . '</dt><dd>' . escape(bookingMailSigned($line['amount'])) . '</dd></div>';
    }
    $percent = bookingPercentText($q['advance_percent']);
    return '<dl class="bk-sum bk-sum--money">' . $rows . '<div class="bk-sum__total"><dt>Total</dt><dd>' . escape(bookingMoney($q['total'])) . '</dd></div>'
        . '<div><dt>Pay now to confirm (' . $percent . '%)</dt><dd>' . escape(bookingMoney($q['advance'])) . '</dd></div><div><dt>Balance at the ground</dt><dd>' . escape(bookingMoney($q['balance'])) . '</dd></div></dl>';
}
/** The live accommodation sentence: "8 people × 2 nights × ₹1,000 = ₹16,000, includes three meals and refreshments." */
function bkStayText(?array $q, int $people, int $nights): string {
    $rate = bookingStayRate();
    if ($rate <= 0) return 'The accommodation charge is quoted when we approve your request.';
    if ($people < 1 || $nights < 1) return 'Accommodation is ' . bookingMoney($rate) . ' per person per night, including three meals and refreshments. Enter the people and nights to see the charge.';
    return 'Accommodation: ' . bookingStayText($people, $nights) . ' × ' . bookingMoney($rate) . ' = ' . bookingMoney($people * $nights * $rate) . ', includes three meals and refreshments.';
}
/** The sentence under the meal checkboxes. */
function bkMealText(?array $q): string {
    $unit = bookingMoney(bookingMealDeduction());
    if ($q && $q['ok'] && $q['meal_deduction'] > 0) return 'You save ' . bookingMoney($q['meal_deduction']) . ' by skipping ' . ($q['skipped_meals'] === 2 ? 'both meals' : 'a meal') . '.';
    return 'Lunch and tea are included in the package. Skipping a meal takes ' . $unit . ' off per meal, per team, per day.';
}

/** "The ground" strip of scenic photos and the "Matches played here" gallery (thumbnails; the full photo opens in a lightbox). */
function bkGroundSectionsHtml(): string {
    $name = escape(bookingGroundName());
    $strip = '<section class="bk-ground" aria-labelledby="bk-h-ground"><h2 id="bk-h-ground">The ground</h2><p class="bk-section-lead">' . $name . ', ' . escape(bookingGroundAddress()) . '. A full-size turf ground among the hills.</p>'
        . '<ul class="bk-strip">'
        . '<li class="bk-strip__wide">' . bkPhoto('panorama', 'bk-strip__img') . '</li><li>' . bkPhoto('vista', 'bk-strip__img') . '</li><li>' . bkPhoto('dew', 'bk-strip__img') . '</li></ul></section>';
    $items = '';
    foreach (BOOKING_EVENT_PHOTOS as [$file, $tw, $th, $fw, $fh, $alt]) {
        $items .= '<li><a class="bk-gallery__link" href="{{BASE}}/assets/ground/gallery/events/' . $file . '.webp" data-lightbox data-w="' . $fw . '" data-h="' . $fh . '" data-alt="' . escape($alt) . '">'
            . '<img class="bk-gallery__img" src="{{BASE}}/assets/ground/gallery/thumb/' . $file . '.webp" width="' . $tw . '" height="' . $th . '" alt="' . escape($alt) . '" loading="lazy" decoding="async"></a></li>';
    }
    $gallery = '<section class="bk-gallery" aria-labelledby="bk-h-gallery"><h2 id="bk-h-gallery">Matches played here</h2><p class="bk-section-lead">Tournaments, trophy presentations and team photos at ' . $name . '. Select a photo to enlarge it.</p>'
        . '<ul class="bk-gallery__grid">' . $items . '</ul></section>'
        . '<dialog class="bk-lightbox" id="bk-lightbox" aria-label="Photo viewer"><div class="bk-lightbox__bar"><span class="bk-lightbox__count" id="bk-lightbox-count" aria-live="polite"></span>'
        . '<button type="button" class="bk-lightbox__btn" data-lightbox-prev aria-label="Previous photo">&lsaquo;</button><button type="button" class="bk-lightbox__btn" data-lightbox-next aria-label="Next photo">&rsaquo;</button>'
        . '<button type="button" class="bk-lightbox__btn bk-lightbox__close" data-lightbox-close aria-label="Close">&times;</button></div>'
        . '<img class="bk-lightbox__img" id="bk-lightbox-img" alt="" width="1280" height="960"></dialog>';
    return $strip . $gallery;
}

/** GET /book, or the form again with its errors. */
function renderBookingForm(?array $posted = null, array $errors = []): ?string {
    bookingSession();
    bookingPrivateHeaders();
    $v = bookingFormValues($posted);
    $e = fn (string $key): string => (string) ($errors[$key] ?? '');
    [$earliest, $latest] = bookingDateWindow();
    $package = bookingPackage($v['format']);
    $perTeam = $package['per_team'] ?? true;
    $q = bkQuoteFor($v);
    $days = (int) $v['days'];
    $extra = (int) $v['players_per_team'] > BOOKING_STANDARD_PLAYERS;
    $stayOn = $v['stay_needed'] === '1';

    $errorList = '';
    if ($errors) {
        $labels = ['format' => 'Package', 'date' => 'Date', 'days' => 'Days', 'slot' => 'Time', 'players_per_team' => 'Players', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'terms' => 'Terms',
            'stay_people' => 'People staying', 'stay_nights' => 'Nights', 'form' => 'Your request'];
        foreach ($errors as $key => $message) $errorList .= '<li><a href="#' . ($key === 'form' ? 'bk-form' : ($key === 'format' ? 'format-single' : ($key === 'slot' ? 'bk-slots' : escape($key)))) . '">' . escape(($labels[$key] ?? ucfirst($key)) . ': ' . $message) . '</a></li>';
    }
    $errorBox = '<div class="bk-errors" id="bk-errors" role="alert" tabindex="-1"' . ($errors ? '' : ' hidden') . '><p class="bk-errors__title">Please fix the following:</p><ul>' . $errorList . '</ul></div>';

    $teamsCard = fn (string $value, string $label, string $price) => '<div class="bk-choice"><input class="bk-sr" type="radio" name="teams_paying" id="teams-' . $value . '" value="' . $value . '"' . ($v['teams_paying'] === $value || ($value === '1' && $v['teams_paying'] !== '2') ? ' checked' : '') . '>'
        . '<label for="teams-' . $value . '"><span>' . escape($label) . '</span><span class="bk-choice__price" data-teams-price="' . $value . '">' . escape($price) . '</span></label></div>';
    $one = $package && $package['price'] !== null ? bookingMoney($package['price']) . ' / day' : '';
    $both = $package && $package['price'] !== null ? bookingMoney($package['price'] * 2) . ' / day' : '';
    $dayOptions = '';
    for ($i = 1; $i <= BOOKING_MAX_DAYS; $i++) $dayOptions .= '<option value="' . $i . '"' . ($days === $i ? ' selected' : '') . '>' . ($i === 1 ? '1 day' : $i . ' days (consecutive)') . '</option>';
    $stayNote = trim(bookingStayNote());

    $phone = bookingSetting('booking_support_phone');
    $terms = '<ul class="bk-terms">' . implode('', array_map(fn ($t) => '<li>' . escape($t) . '</li>', bookingTerms())) . '</ul>';
    $crumbs = [['label' => 'Home', 'url' => '{{BASE}}'], ['label' => 'Book the ground', 'url' => '{{BASE}}/book']];

    $stay = bookingStayEnabled()
        ? '<fieldset class="bk-fieldset bk-stay" id="bk-stay"><legend>Overnight stay</legend>' . bkCheckbox('stay_needed', 'We need overnight accommodation', $stayOn)
            . '<div class="bk-stay__details" id="bk-stay-details"' . ($stayOn ? '' : ' hidden') . '><div class="bk-grid">'
            . bkField('stay_people', 'People who need a room', bkInput('stay_people', 'number', $v['stay_people'], $e('stay_people'), false, 'min="1" max="200" inputmode="numeric"'), $e('stay_people'))
            . bkField('stay_nights', 'Number of nights', bkInput('stay_nights', 'number', $v['stay_nights'], $e('stay_nights'), false, 'min="1" max="14" inputmode="numeric"'), $e('stay_nights'))
            . '</div><p class="bk-hint" id="bk-stay-calc" aria-live="polite">' . escape(bkStayText($q, ctype_digit($v['stay_people']) ? (int) $v['stay_people'] : 0, ctype_digit($v['stay_nights']) ? (int) $v['stay_nights'] : 0)) . '</p>'
            . bkField('stay_notes', 'Rooms and special needs', '<textarea class="bk-input bk-textarea" id="stay_notes" name="stay_notes" rows="2" maxlength="500">' . escape($v['stay_notes']) . '</textarea>', $e('stay_notes'), '', true)
            . '<p class="bk-note">' . ($stayNote !== '' ? escape($stayNote) . ' ' : '') . (bookingStayRate() > 0 ? 'The charge is added to your total; our team confirms it when approving your request.' : 'The charge is quoted when we approve your request. You do not pay for it online now.') . '</p></div></fieldset>'
        : '';

    $form = '<form class="bk-form" id="bk-form" method="post" action="{{BASE}}/book" novalidate data-availability-url="{{BASE}}/book/availability" data-standard-players="' . BOOKING_STANDARD_PLAYERS . '">'
        . '<input type="hidden" name="csrf_token" value="' . escape(accountCsrfToken()) . '">'
        . '<div class="bk-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
        . $errorBox
        . '<section class="bk-step" aria-labelledby="bk-h-package"><h2 id="bk-h-package"><span class="bk-step__n" aria-hidden="true">1</span> Choose a package</h2>'
        . '<fieldset class="bk-fieldset"><legend class="bk-sr">Package</legend><div class="bk-packs">' . bkPackageCards($v['format']) . '</div><p class="bk-error" id="err-format">' . escape($e('format')) . '</p></fieldset></section>'
        . '<section class="bk-step" aria-labelledby="bk-h-when"><h2 id="bk-h-when"><span class="bk-step__n" aria-hidden="true">2</span> Pick the dates and time</h2><div class="bk-grid">'
        . bkField('date', 'Start date', bkInput('date', 'date', $v['date'], $e('date'), true, 'min="' . $earliest . '" max="' . $latest . '" required'), $e('date'), 'Bookable from ' . escape(bookingDateLabel($earliest)) . ' to ' . escape(bookingDateLabel($latest)) . '.')
        . bkField('days', 'Days of play', '<select class="bk-input" id="days" name="days"' . ($e('days') !== '' ? ' aria-invalid="true" aria-describedby="err-days"' : '') . '>' . $dayOptions . '</select>', $e('days'), 'The same time is booked on every day.')
        . '</div><fieldset class="bk-fieldset" id="bk-slots" tabindex="-1"><legend>Time</legend><div class="bk-slots" id="bk-slot-list" aria-live="polite">' . bkSlotOptionsHtml($v['date'], $v['format'], $v['slot'], $days) . '</div>'
        . '<p class="bk-error" id="err-slot">' . escape($e('slot')) . '</p></fieldset></section>'
        . '<section class="bk-step" aria-labelledby="bk-h-team"><h2 id="bk-h-team"><span class="bk-step__n" aria-hidden="true">3</span> Your team</h2>'
        . '<div class="bk-grid">'
        . bkField('organisation', 'Team or club name', bkInput('organisation', 'text', $v['organisation'], $e('organisation'), false, 'maxlength="255" autocomplete="organization"'), $e('organisation'), '', true)
        . bkField('opponent', 'Opponent team', bkInput('opponent', 'text', $v['opponent'], $e('opponent'), false, 'maxlength="255"'), $e('opponent'), '', true)
        . bkField('players_per_team', 'Players in your team', bkInput('players_per_team', 'number', $v['players_per_team'], $e('players_per_team'), true, 'min="5" max="30" inputmode="numeric"'), $e('players_per_team'),
            'Rates are for ' . BOOKING_STANDARD_PLAYERS . ' players.')
        . '</div><p class="bk-note" id="bk-extra-players"' . ($extra ? '' : ' hidden') . '>Extra players are charged separately; we will add this when approving your request.</p>'
        . '<fieldset class="bk-fieldset bk-teams" id="bk-teams"' . ($perTeam ? '' : ' hidden') . '><legend>Who pays?</legend><div class="bk-choices">'
        . $teamsCard('1', 'Only our team pays', $one) . $teamsCard('2', 'We pay for both teams', $both) . '</div></fieldset></section>'
        . '<section class="bk-step" aria-labelledby="bk-h-extras"><h2 id="bk-h-extras"><span class="bk-step__n" aria-hidden="true">4</span> Meals and stay</h2>'
        . '<fieldset class="bk-fieldset bk-meals"><legend>Lunch and tea</legend>' . bkCheckbox('want_lunch', 'Lunch', $v['want_lunch'] !== '0', 'Included in the package') . bkCheckbox('want_tea', 'Tea', $v['want_tea'] !== '0', 'Included in the package')
        . '<p class="bk-hint" id="bk-meal-saving" aria-live="polite">' . escape(bkMealText($q)) . '</p></fieldset>' . $stay . '</section>'
        . '<section class="bk-step" aria-labelledby="bk-h-you"><h2 id="bk-h-you"><span class="bk-step__n" aria-hidden="true">5</span> Your details</h2><div class="bk-grid">'
        . bkField('name', 'Your name', bkInput('name', 'text', $v['name'], $e('name'), false, 'maxlength="100" autocomplete="name" required'), $e('name'))
        . bkField('phone', 'Mobile number', bkInput('phone', 'tel', $v['phone'], $e('phone'), true, 'maxlength="20" inputmode="tel" autocomplete="tel" required'), $e('phone'), '10 digits. We may call you about the booking.')
        . '</div>' . bkField('email', 'Email', bkInput('email', 'email', $v['email'], $e('email'), true, 'maxlength="255" autocomplete="email" required'), $e('email'), 'The approval and payment details are sent here. Members get their discount automatically.')
        . bkField('notes', 'Anything we should know?', '<textarea class="bk-input bk-textarea" id="notes" name="notes" rows="3" maxlength="1000">' . escape($v['notes']) . '</textarea>', $e('notes'), '', true)
        . '</section>'
        . '<section class="bk-step bk-step--terms" aria-labelledby="bk-h-terms"><h2 id="bk-h-terms">Terms</h2>' . $terms
        . '<div class="bk-check"><input type="checkbox" id="terms" name="terms" value="1"' . ($v['terms'] === '1' ? ' checked' : '') . ' required><label for="terms">I have read and accept these terms.</label></div><p class="bk-error" id="err-terms">' . escape($e('terms')) . '</p></section>'
        . '<p class="bk-consent">This is a request. You pay only after we approve it.</p>'
        . '<button class="bk-btn bk-btn--primary" type="submit" id="bk-submit">Send booking request</button></form>';

    $day = DateTime::createFromFormat('!Y-m-d', $v['date']);
    $dateText = $day && $day->format('Y-m-d') === $v['date'] ? ($days > 1 ? bookingShortDate($v['date']) . ' to ' . bookingDateLabel(bookingEndDate($v['date'], $days)) . ' (' . bookingDaysText($days) . ')' : bookingDateLabel($v['date'])) : 'Not chosen yet';
    $timeText = bookingValidPair($v['slot'], $v['format']) ? bookingSlotLabel($v['slot'], $v['format']) : 'Not chosen yet';
    $payFor = $package && $package['per_team'] ? ((int) $v['teams_paying'] === 2 ? 'Both teams' : 'This team only') : ($package ? 'Flat price for the day' : '–');
    $summaryHtml = '<aside class="bk-summary" aria-labelledby="bk-sum-title"><h2 id="bk-sum-title">Your request</h2><dl class="bk-sum">'
        . '<div><dt>Package</dt><dd data-sum="package">' . escape($package ? bookingFormatLabel($v['format']) : 'Not chosen yet') . '</dd></div><div><dt>Dates</dt><dd data-sum="date">' . escape($dateText) . '</dd></div>'
        . '<div><dt>Time</dt><dd data-sum="time">' . escape($timeText) . '</dd></div><div><dt>Payment for</dt><dd data-sum="pay_for">' . escape($payFor) . '</dd></div></dl>'
        . '<div id="bk-money" aria-live="polite">' . bkMoneyBlockHtml($q) . '</div>'
        . (bookingStayRate() > 0 ? '' : '<p class="bk-sum__note" id="bk-stay-note"' . ($stayOn ? '' : ' hidden') . '>Overnight accommodation is not in this total. We quote it when we approve your request.</p>')
        . '<p class="bk-sum__note">Indicative price. The final amount is confirmed when we approve your request.</p>'
        . ($phone !== '' ? '<p class="bk-sum__help">Questions? Call or WhatsApp ' . escape($phone) . '</p>' : '') . '</aside>';

    return view('templates.booking', [
        'title' => 'Book ' . bookingGroundName() . ' | Pro HPCL', 'description' => 'Request ' . bookingGroundName() . ' for a single match, two matches or a full day. Choose a package, dates and time; pay the advance after we approve.',
        'url' => '{{BASE}}/book', 'breadcrumb' => autoBreadcrumbHtml($crumbs), 'ground_name' => escape(bookingGroundName()), 'ground_address' => escape(bookingGroundAddress()),
        'form' => $form, 'summary' => $summaryHtml, 'ground' => bkGroundSectionsHtml(), 'css_version' => BOOKING_ASSET_VERSION,
        'hero_image' => bookingPhotoUrl(BOOKING_PHOTOS['aerial'][0]), 'hero_alt' => escape(BOOKING_PHOTOS['aerial'][3]),
        'og_image' => absoluteUrl(bookingPhotoUrl(BOOKING_PHOTOS['aerial'][0])),
    ]);
}

// ---------------------------------------------------------------------------------------------------- endpoints

/** GET /book/availability: the time options of a package from a date for some days, or the price breakdown of the choices. */
function handleBookingAvailability(): never {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) { header('Allow: GET'); errorPage(405); }
    $get = fn (string $key, int $max = 64): string => is_string($_GET[$key] ?? null) ? substr(trim($_GET[$key]), 0, $max) : '';
    $format = $get('format', 12);
    if (!isset(BOOKING_FORMATS[$format])) bookingJson(['ok' => false, 'message' => 'Choose a package.'], 400);
    $daysText = $get('days', 2);
    $days = ctype_digit($daysText) && (int) $daysText >= 1 && (int) $daysText <= BOOKING_MAX_DAYS ? (int) $daysText : 1;
    if ($get('quote') === '1') {
        $email = $get('email', 255);
        if ($email !== '') {
            // The discount reveals whether an address belongs to a member, so lookups are limited per visitor.
            bookingSession();
            $_SESSION['bk_quotes'] = array_values(array_filter((array) ($_SESSION['bk_quotes'] ?? []), fn ($t) => $t > time() - 3600));
            if (count($_SESSION['bk_quotes']) >= 30) $email = '';
            else $_SESSION['bk_quotes'][] = time();
        }
        $stayPeople = ctype_digit($get('stay_people', 4)) ? (int) $get('stay_people', 4) : 0;
        $stayNights = ctype_digit($get('stay_nights', 3)) ? (int) $get('stay_nights', 3) : 0;
        $quote = bookingQuote($get('slot', 12), $format, $email, (int) $get('teams', 2), $days, $get('lunch', 1) !== '0', $get('tea', 1) !== '0', min($stayPeople, 200), min($stayNights, 14));
        $package = bookingPackage($format);
        bookingJson(['ok' => true, 'quote' => $quote, 'per_team' => $quote['per_team'], 'single_price' => $package['price'] ?? null, 'both_price' => $package && $package['price'] !== null && $package['per_team'] ? $package['price'] * 2 : null,
            'html' => bkMoneyBlockHtml($quote), 'meal_text' => bkMealText($quote), 'stay_text' => bkStayText($quote, min($stayPeople, 200), min($stayNights, 14))]);
    }
    $date = $get('date', 10);
    $day = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$day || $day->format('Y-m-d') !== $date) bookingJson(['ok' => false, 'message' => 'Choose a date.'], 400);
    [$earliest, $latest] = bookingDateWindow();
    $inWindow = $date >= $earliest && $date <= $latest;
    bookingJson(['ok' => true, 'date' => $date, 'days' => $days, 'format' => $format, 'in_window' => $inWindow, 'date_label' => bookingDateLabel($date), 'end_date' => bookingEndDate($date, $days),
        'options' => $inWindow ? bookingOptions($date, $format, $days) : [], 'html' => bkSlotOptionsHtml($date, $format, $get('slot', 12), $days)]);
}

/** POST /book: validates, saves the request and answers the form (JSON for the page script, otherwise a redirect or the form with errors). */
function handleBookingRequest(): never {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Allow: POST'); errorPage(405); }
    bookingSession();
    bookingPrivateHeaders();
    $input = array_map(fn ($value) => is_string($value) ? $value : '', $_POST);
    $form = fn () => renderBookingForm($input);
    $fail = function (array $errors) use ($input): never {
        formResponse($errors, '', '/book', fn () => renderBookingForm($input, $errors));
    };
    if (!accountCsrfValid()) $fail(['form' => 'Your session expired. Please reload the page and try again.']);
    // The hidden "website" field is for bots: a person never fills it in.
    if (trim($input['website'] ?? '') !== '') $fail(['form' => 'We could not accept this request.']);
    if (($input['terms'] ?? '') !== '1') {
        // Validate the rest too, so every problem is shown at once.
        [, $errors] = bookingValidate($input);
        $fail($errors + ['terms' => 'Please accept the terms to send your request.']);
    }
    $result = bookingCreate($input);
    if (isset($result['errors'])) $fail($result['errors']);
    $_SESSION['bk_last'] = $result['token'];
    formResponse([], 'Your request was sent.', '/booking/' . $result['token'], $form, ['token' => $result['token'], 'reference' => $result['reference']]);
}

// ------------------------------------------------------------------------------------------------ status page

const BOOKING_EVENT_LABELS = ['requested' => 'Request received', 'approved' => 'Approved', 'confirmed' => 'Confirmed', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled', 'expired' => 'Hold expired', 'modified' => 'Request changed'];

/** [label, value] rows describing the booking for the status page. */
function bkStatusFacts(array $b): array {
    $rows = [['Reference', $b['reference']], ['Ground', bookingGroundName() . ', ' . bookingGroundAddress()], [$b['days'] > 1 ? 'Dates' : 'Date', $b['date_label']],
        ['Time', $b['slot_label'] . ($b['days'] > 1 ? ' each day' : '')], ['Package', $b['format_label']]];
    if (trim((string) $b['organisation']) !== '') $rows[] = ['Team', $b['organisation']];
    if (trim((string) $b['opponent']) !== '') $rows[] = ['Opponent', $b['opponent']];
    $rows[] = ['Players per team', (string) $b['players_per_team'] . ($b['extra_players'] ? ' (extra players are charged separately)' : '')];
    if ($b['is_per_team']) $rows[] = ['Payment for', $b['teams_paying'] === 2 ? 'Both teams' : 'This team only'];
    $rows[] = ['Meals', $b['meals_label']];
    if ($b['stay_needed']) $rows[] = ['Overnight stay', $b['stay_label'] . (trim((string) $b['stay_notes']) !== '' ? ' (' . $b['stay_notes'] . ')' : '')];
    $rows[] = ['Booked by', $b['name']];
    return $rows;
}
function bkDl(array $rows, string $class = ''): string {
    return '<dl class="bk-facts ' . $class . '">' . implode('', array_map(fn ($r) => '<div' . (($r[2] ?? false) ? ' class="bk-facts__strong"' : '') . '><dt>' . escape($r[0]) . '</dt><dd>' . escape($r[1]) . '</dd></div>', $rows)) . '</dl>';
}
/** The money of a booking as a list: the price lines, then total, advance now and balance. */
function bkStatusMoney(array $b): string {
    $m = bookingMoneyLines($b);
    if ($m['total'] === null) return '';
    $rows = array_map(fn ($line) => [$line['label'], bookingMailSigned($line['amount'])], $m['lines']);
    $percent = bookingPercentText($m['advance_percent']);
    $rows = $m['indicative']
        ? [...$rows, ['Indicative total', bookingMoney($m['total'])], ['Advance to confirm (' . $percent . '%)', bookingMoney($m['advance'])], ['Balance at the ground', bookingMoney($m['balance'])]]
        : [...$rows, ['Total price', bookingMoney($m['total'])], ['Pay now (' . $percent . '% advance)', bookingMoney($m['advance']), $b['status'] === 'approved'], ['Balance, paid at the ground', bookingMoney($m['balance'])]];
    return bkDl($rows, 'bk-facts--money') . ($m['indicative'] ? '<p class="bk-hint">The final amount is confirmed when your request is approved.' . ($m['stay_pending'] ? ' Overnight accommodation is quoted then.' : '') . '</p>' : '');
}

/** GET /booking/<token> (and ?ics=1): the private page of a booking. Null for an unknown link. */
function renderBookingStatus(string $token): ?string {
    bookingExpireDue(true);
    $b = bookingGet($token);
    if (!$b) return null;
    bookingPrivateHeaders();
    if (($_GET['ics'] ?? '') === '1' && $b['status'] === 'confirmed') {
        header('Content-Type: text/calendar; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . strtolower($b['reference']) . '.ics"');
        echo bookingIcs($b);
        exit;
    }
    $status = $b['status'];
    $phone = bookingSetting('booking_support_phone');
    $advancePercent = bookingPercentText($b['advance_percent']);
    $note = trim((string) $b['customer_note']);
    $due = $b['payment_due_at'] ? date('l, j F Y, g:i A', strtotime($b['payment_due_at'])) : '';
    $actions = '';
    $extra = '';

    $headline = ['requested' => 'We have your request', 'approved' => 'Approved: pay the advance to confirm', 'confirmed' => 'Your booking is confirmed', 'rejected' => 'We could not approve this request',
        'cancelled' => 'This booking was cancelled', 'expired' => 'This hold has expired'][$status];
    $lead = match ($status) {
        'requested' => 'Our team reviews requests in the order they arrive and will email ' . $b['email'] . '. You do not pay anything yet.',
        'approved' => 'Pay ' . bookingMoney($b['amount']) . ' now (' . $advancePercent . '% advance) to confirm. ' . bookingMoney($b['balance_amount']) . ' is paid at the ground. The slot is held for you' . ($due !== '' ? ' until ' . $due : '') . '.',
        'confirmed' => 'We received your advance of ' . bookingMoney($b['amount']) . '. Please pay the balance of ' . bookingMoney($b['balance_amount']) . ' at the ground.',
        'rejected' => 'Sorry, we are not able to approve this request.' . ($note !== '' ? ' ' . $note : '') . ' You are welcome to request another date or time.',
        'cancelled' => 'This booking has been cancelled.' . ($note !== '' ? ' ' . $note : ''),
        'expired' => 'The advance was not paid before the deadline, so the slot was released. You can send a new request if you still want it.',
    };
    if ($status === 'approved') {
        $actions = '<p class="bk-actions"><a class="bk-btn bk-btn--primary" href="{{BASE}}/booking/' . $b['token'] . '/pay">Pay ' . escape(bookingMoney($b['amount'])) . ' now</a></p>';
        $lines = bookingPaymentNoteLines();
        if ($lines) $extra .= '<div class="bk-box"><h3>Prefer a bank transfer?</h3><p>' . implode('<br>', array_map('escape', $lines)) . '</p></div>';
        if ($note !== '') $extra = '<div class="bk-box"><h3>Note from the team</h3><p>' . escape($note) . '</p></div>' . $extra;
    } elseif ($status === 'confirmed') {
        $actions = '<p class="bk-actions"><a class="bk-btn bk-btn--primary" href="{{BASE}}/booking/' . $b['token'] . '?ics=1">Add to calendar</a><button type="button" class="bk-btn bk-btn--ghost" data-print>Print</button></p>';
    } elseif (in_array($status, ['rejected', 'cancelled', 'expired'], true)) {
        $actions = '<p class="bk-actions"><a class="bk-btn bk-btn--primary" href="{{BASE}}/book">Request another date</a></p>';
    }
    if (in_array($status, ['requested', 'approved', 'confirmed'], true)) $extra = bookingChangesHtml($b) . $extra;

    $events = '';
    foreach ($b['events'] as $event) {
        $events .= '<li><span class="bk-timeline__what">' . escape(BOOKING_EVENT_LABELS[$event['status']] ?? ucfirst($event['status'])) . '</span> <time datetime="' . escape(str_replace(' ', 'T', $event['created_at'])) . '">'
            . escape(date('j M Y, g:i A', strtotime($event['created_at']))) . '</time>' . ($event['note'] ? '<span class="bk-timeline__note">' . escape($event['note']) . '</span>' : '') . '</li>';
    }
    $crumbs = [['label' => 'Home', 'url' => '{{BASE}}'], ['label' => 'Book the ground', 'url' => '{{BASE}}/book'], ['label' => $b['reference'], 'url' => '{{BASE}}/booking/' . $b['token']]];
    return view('templates.booking-status', [
        'title' => 'Booking ' . $b['reference'] . ' | Pro HPCL', 'reference' => escape($b['reference']), 'breadcrumb' => autoBreadcrumbHtml($crumbs),
        'status' => $status, 'status_label' => escape($b['status_label']), 'headline' => escape($headline), 'lead' => escape($lead),
        'facts' => bkDl(bkStatusFacts($b)), 'money' => bkStatusMoney($b), 'actions' => $actions, 'extra' => $extra,
        'timeline' => $events !== '' ? '<ol class="bk-timeline">' . $events . '</ol>' : '',
        'terms' => '<ul class="bk-terms">' . implode('', array_map(fn ($t) => '<li>' . escape($t) . '</li>', bookingTerms())) . '</ul>',
        'help' => $phone !== '' ? '<p class="bk-sum__help">Questions? Call or WhatsApp ' . escape($phone) . '</p>' : '', 'css_version' => BOOKING_ASSET_VERSION,
    ]);
}
