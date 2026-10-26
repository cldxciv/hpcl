<?php
declare(strict_types=1);

// Admin pages for the Membership Programme (/admin/membership and /admin/members): review
// membership applications sent from /membership/join, approve or reject them, turn payment on or
// off, and manage the public member business profiles shown at /members. Mirrors the committee
// (admin-committee.php) and owners (admin-owners.php) admin modules.
require_once __DIR__ . '/admin-forms.php';
require_once __DIR__ . '/membership.php';
require_once __DIR__ . '/razorpay.php';
require_once __DIR__ . '/membership-join.php'; // membershipPaymentReceived()

const MEMBERSHIP_PER_PAGE = 50;
const MEMBERSHIP_APP_STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
const MEMBERSHIP_APP_STATUS_BADGES = ['pending' => 'upcoming', 'approved' => 'ongoing', 'rejected' => 'completed'];
const MEMBERSHIP_PAYMENT_STATUSES = ['not_required' => 'No payment', 'unpaid' => 'Awaiting payment', 'paid' => 'Paid'];
const MEMBERSHIP_PAYMENT_BADGES = ['not_required' => 'unknown', 'unpaid' => 'completed', 'paid' => 'ongoing'];
const MEMBER_LOGO_DIR = 'storage/uploads/members';
const MEMBERSHIP_PAYMENT_SETTING_ADMIN = 'membership_payment_enabled';

function membershipAppStatusBadge(string $status): string {
    return '<span class="admin-badge admin-badge--' . (MEMBERSHIP_APP_STATUS_BADGES[$status] ?? 'unknown') . '">' . escape(MEMBERSHIP_APP_STATUSES[$status] ?? ucfirst($status)) . '</span>';
}
function membershipAppPaymentBadge(string $status): string {
    return '<span class="admin-badge admin-badge--' . (MEMBERSHIP_PAYMENT_BADGES[$status] ?? 'unknown') . '">' . escape(MEMBERSHIP_PAYMENT_STATUSES[$status] ?? ucfirst($status)) . '</span>';
}
function membershipTierLabel(string $tier): string {
    return isset(MEMBERSHIP_TIERS[$tier]) ? MEMBERSHIP_TIERS[$tier]['name'] . ' · ' . membershipPriceLabel(MEMBERSHIP_TIERS[$tier]['price']) : ucfirst($tier);
}

/** A unique members.slug from a business name, optionally excluding one member id. */
function memberSlug(string $name, ?int $excludeId = null): string {
    $base = trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($name)), '-') ?: 'member';
    $taken = array_column(query('SELECT slug FROM members WHERE (slug = ? OR slug LIKE ?)' . ($excludeId ? ' AND id <> ?' : ''),
        $excludeId ? [$base, likePattern($base . '-'), $excludeId] : [$base, likePattern($base . '-')]), 'slug', 'slug');
    for ($n = 1, $slug = $base; isset($taken[$slug]); $slug = $base . '-' . ++$n);
    return $slug;
}

// ---- Membership applications -------------------------------------------------

function membershipPaymentPanel(): string {
    $on = (query('SELECT value FROM site_settings WHERE name = ?', [MEMBERSHIP_PAYMENT_SETTING_ADMIN])[0]['value'] ?? '') === '1';
    $ready = razorpayReady();
    $state = match (true) {
        $on && $ready => '<strong>On.</strong> Applicants pay their tier fee with Razorpay in ' . escape(razorpayConfig()['mode']) . ' mode; a membership is confirmed once it is paid.',
        $on => '<strong>On, but not working:</strong> Razorpay isn’t set up, so the form is saved without payment. The root user turns Razorpay on at Settings → Integrations.',
        default => '<strong>Off.</strong> Requests are saved without payment and your team follows up.',
    };
    return '<section class="admin-panel admin-section admin-committee-payment"><form method="post" action="{{BASE}}/admin/membership/payment">'
        . '<input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '"><input type="hidden" name="enabled" value="' . ($on ? '0' : '1') . '">'
        . '<div><h2>Payment</h2><p>' . $state . '</p></div>'
        . '<button type="submit"' . ($on ? ' class="admin-button-secondary"' : '') . '>' . ($on ? 'Turn payment off' : 'Turn payment on') . '</button></form></section>';
}
function saveMembershipPaymentSetting(array $admin): never {
    $on = ($_POST['enabled'] ?? '') === '1';
    if ($on) adminExecute('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [MEMBERSHIP_PAYMENT_SETTING_ADMIN, '1']);
    else adminExecute('DELETE FROM site_settings WHERE name = ?', [MEMBERSHIP_PAYMENT_SETTING_ADMIN]);
    adminFlash($on ? (razorpayReady() ? 'Payment is on: applicants now pay their tier fee when they apply.' : 'Payment is on, but Razorpay isn’t set up yet, so applicants can’t pay until it is.') : 'Payment is off: requests are saved without payment.');
    adminRedirect('/admin/membership');
}
function membershipApplication(int $id): array {
    return query('SELECT c.*, a.name AS reviewed_by_name FROM membership_applications c LEFT JOIN admins a ON a.id = c.reviewed_by WHERE c.id = ?', [$id])[0] ?? notFound();
}

function renderAdminMembership(array $admin): string {
    $search = adminSearch();
    $status = is_string($_GET['status'] ?? null) && isset(MEMBERSHIP_APP_STATUSES[$_GET['status']]) ? $_GET['status'] : '';
    $conditions = [];
    $params = [];
    if ($status !== '') { $conditions[] = 'status = ?'; $params[] = $status; }
    if ($search !== '') { $conditions[] = '(business_name LIKE ? OR contact_name LIKE ? OR email LIKE ? OR phone LIKE ?)'; array_push($params, ...array_fill(0, 4, likePattern($search))); }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $total = (int) query("SELECT COUNT(*) AS n FROM membership_applications $where", $params)[0]['n'];
    $lastPage = max(1, (int) ceil($total / MEMBERSHIP_PER_PAGE));
    $page = min($lastPage, max(1, ctype_digit((string) ($_GET['page'] ?? '')) ? (int) $_GET['page'] : 1));
    $apps = query("SELECT id, business_name, contact_name, email, phone, tier, status, payment_status, created_at FROM membership_applications $where ORDER BY created_at DESC, id DESC LIMIT "
        . MEMBERSHIP_PER_PAGE . ' OFFSET ' . (($page - 1) * MEMBERSHIP_PER_PAGE), $params);
    $rows = implode('', array_map(fn ($r) => '<tr><td><a href="{{BASE}}/admin/membership/' . (int) $r['id'] . '">' . escape($r['business_name']) . '</a><br><span class="admin-muted admin-small">' . escape($r['contact_name'] . ' · ' . $r['email']) . '</span></td>'
        . '<td class="admin-wide-only">' . escape($r['phone']) . '</td><td>' . escape(MEMBERSHIP_TIERS[$r['tier']]['name'] ?? $r['tier']) . '</td>'
        . '<td>' . membershipAppStatusBadge($r['status']) . '</td><td>' . membershipAppPaymentBadge($r['payment_status']) . '</td><td class="admin-wide-only">' . escape(date('j M Y', strtotime($r['created_at']))) . '</td>'
        . '<td class="admin-actions"><a href="{{BASE}}/admin/membership/' . (int) $r['id'] . '">View</a>'
        . (adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/membership/' . (int) $r['id'] . '/delete">Delete</a>' : '') . '</td></tr>', $apps));

    $counts = array_column(query('SELECT status, COUNT(*) AS n FROM membership_applications GROUP BY status'), 'n', 'status');
    $tabUrl = fn (string $key) => '{{BASE}}/admin/membership' . ($key !== '' || $search !== '' ? '?' . escape(http_build_query(array_filter(['status' => $key, 'q' => $search]))) : '');
    $tabs = '<nav class="admin-tabs" aria-label="Status">' . implode('', array_map(fn ($key, $label) => '<a href="' . $tabUrl($key) . '"' . ($key === $status ? ' aria-current="page"' : '') . '>'
        . $label . ' <span class="admin-muted">' . number_format($key === '' ? array_sum($counts) : (int) ($counts[$key] ?? 0)) . '</span></a>', ['', ...array_keys(MEMBERSHIP_APP_STATUSES)], ['All', ...MEMBERSHIP_APP_STATUSES])) . '</nav>';
    $pageUrl = fn (int $number) => '{{BASE}}/admin/membership?' . escape(http_build_query(array_filter(['status' => $status, 'q' => $search, 'page' => $number > 1 ? $number : null])));
    $pager = $lastPage > 1 ? '<nav class="admin-pager" aria-label="Pages">'
        . ($page > 1 ? '<a href="' . $pageUrl($page - 1) . '" rel="prev">&larr; Previous</a>' : '<span></span>')
        . '<span class="admin-muted">Page ' . $page . ' of ' . $lastPage . '</span>'
        . ($page < $lastPage ? '<a href="' . $pageUrl($page + 1) . '" rel="next">Next &rarr;</a>' : '<span></span>') . '</nav>' : '';
    $table = $apps ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Business</th><th class="admin-wide-only">Phone</th><th>Tier</th><th>Status</th><th>Payment</th><th class="admin-wide-only">Applied</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>'
        . $rows . '</tbody></table></div>' . $pager
        : '<p class="admin-empty">' . ($search === '' && $status === '' ? 'No membership requests yet. They arrive from <a href="{{BASE}}/membership/join">/membership/join</a>.' : 'No requests match.') . '</p>';
    $summary = number_format($total) . ' request' . ($total === 1 ? '' : 's') . ($search !== '' ? ($total === 1 ? ' matches' : ' match') . ' “' . escape($search) . '”' : '');
    return adminView('templates.admin.membership', $admin, '/admin/membership', ['search' => escape($search), 'status' => escape($status), 'summary' => $summary, 'tabs' => $tabs, 'table' => $table, 'payment' => membershipPaymentPanel()]);
}

/** One application: details and the status form; can create a member profile from it. */
function renderMembershipApplication(array $admin, int $id): string {
    $app = membershipApplication($id);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $status = postedText('status');
        $note = postedText('admin_note');
        if (isset(MEMBERSHIP_APP_STATUSES[$status]) && mb_strlen($note) <= 2000) {
            adminExecute('UPDATE membership_applications SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?', [$status, $note === '' ? null : $note, $admin['id'], $id]);
            adminFlash('Saved the request from ' . $app['business_name'] . '.');
            adminRedirect('/admin/membership/' . $id);
        }
        adminFlash('Choose a status, and keep the note to 2,000 characters or fewer.');
        adminRedirect('/admin/membership/' . $id);
    }
    $statuses = implode('', array_map(fn ($key, $label) => '<option value="' . $key . '"' . ($app['status'] === $key ? ' selected' : '') . '>' . $label . '</option>', array_keys(MEMBERSHIP_APP_STATUSES), MEMBERSHIP_APP_STATUSES));
    $reviewed = $app['reviewed_at'] ? 'Last updated ' . escape(date('j M Y, g:i A', strtotime($app['reviewed_at']))) . ($app['reviewed_by_name'] ? ' by ' . escape($app['reviewed_by_name']) : '') : 'Not reviewed yet';
    $website = $app['website'] ? '<a href="' . escape(preg_match('~^https?://~i', $app['website']) ? $app['website'] : 'https://' . $app['website']) . '" target="_blank" rel="noopener nofollow">' . escape($app['website']) . '</a>' : '<span class="admin-muted">—</span>';
    return adminView('templates.admin.membership-application', $admin, '/admin/membership', [
        'title' => escape($app['business_name']), 'id' => $id, 'badge' => membershipAppStatusBadge($app['status']),
        'contact' => escape($app['contact_name']), 'email' => escape($app['email']), 'phone' => escape($app['phone']),
        'tier' => escape(membershipTierLabel($app['tier'])), 'city' => $app['city'] ? escape($app['city']) : '<span class="admin-muted">—</span>', 'website' => $website,
        'applied' => escape(date('j M Y, g:i A', strtotime($app['created_at']))), 'message' => nl2br(escape($app['message'])),
        'payment' => membershipAppPaymentBadge($app['payment_status']) . match ($app['payment_status']) {
            'paid' => ' ' . membershipPriceLabel((int) $app['amount']) . ($app['paid_at'] ? ' on ' . escape(date('j M Y, g:i A', strtotime($app['paid_at']))) : '')
                . ($app['razorpay_payment_id'] ? ' <span class="admin-small admin-muted">Payment ID ' . escape($app['razorpay_payment_id']) . '</span>' : ''),
            'unpaid' => ' <span class="admin-small admin-muted">The applicant didn’t finish paying ' . membershipPriceLabel((int) $app['amount']) . '.</span>',
            default => '',
        },
        'payment_check' => $app['razorpay_order_id'] ? '<form method="post" action="{{BASE}}/admin/membership/' . $id . '/verify"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">'
            . '<button type="submit" class="admin-button-secondary">Check payment with Razorpay</button>'
            . '<p class="admin-hint">Asks Razorpay about order ' . escape($app['razorpay_order_id']) . ': whether it was paid, failed or is still waiting. A successful payment it finds is recorded here.</p></form>' : '',
        'statuses' => $statuses, 'admin_note' => escape((string) $app['admin_note']), 'reviewed' => $reviewed,
        'create_profile' => '<form method="post" action="{{BASE}}/admin/membership/' . $id . '/profile"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '"><button type="submit" class="admin-button">Create a member profile from this</button></form>',
        'delete' => adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/membership/' . $id . '/delete">Delete this request</a>' : '']);
}

/**
 * Asks Razorpay about the request's order and says what it has. A captured payment of the tier
 * fee is recorded (for a payer who closed the page before the payment was confirmed, when the
 * webhook isn't set up); anything else is only reported.
 */
function verifyMembershipPayment(array $admin, int $id): never {
    $app = membershipApplication($id);
    $back = '/admin/membership/' . $id;
    if (!$app['razorpay_order_id']) {
        adminFlash('This request has no Razorpay order: it was sent while payment was off.');
        adminRedirect($back);
    }
    try {
        $payments = razorpayOrderPayments($app['razorpay_order_id']);
    } catch (RuntimeException $error) {
        adminFlash('Couldn’t check with Razorpay: ' . $error->getMessage());
        adminRedirect($back);
    }
    $paise = (int) $app['amount'] * 100;
    foreach ($payments as $payment) {
        if (($payment['status'] ?? '') !== 'captured' || (int) ($payment['amount'] ?? 0) !== $paise || !is_string($payment['id'] ?? null)) continue;
        $recorded = $app['payment_status'] !== 'paid' && membershipPaymentReceived($app['razorpay_order_id'], $payment['id'], $paise);
        adminFlash('Razorpay confirms payment ' . $payment['id'] . ' of ' . membershipPriceLabel((int) $app['amount']) . '.' . ($recorded ? ' The request is now marked paid.' : ''));
        adminRedirect($back);
    }
    if (!$payments) adminFlash('Razorpay has no payment attempts for this order: the applicant hasn’t paid.');
    else {
        $counts = array_count_values(array_map(fn ($payment) => (string) ($payment['status'] ?? 'unknown'), $payments));
        $failure = current(array_filter(array_column($payments, 'error_description')));
        adminFlash('Razorpay has no successful payment for this order. Attempts: ' . implode(', ', array_map(fn ($status, $n) => "$n $status", array_keys($counts), $counts)) . '.'
            . ($failure ? ' Last failure: ' . $failure : '')
            . (isset($counts['authorized']) ? ' An authorized payment isn’t captured yet: capture it in the Razorpay Dashboard, or turn on automatic capture there.' : ''));
    }
    adminRedirect($back);
}

/** Creates a draft member profile prefilled from an application, then opens it for editing. */
function createMemberFromApplication(array $admin, int $id): never {
    $app = membershipApplication($id);
    $slug = memberSlug($app['business_name']);
    adminExecute('INSERT INTO members (slug, business_name, tier, website, phone, email, city, about, published, application_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())',
        [$slug, $app['business_name'], $app['tier'], $app['website'] ?: null, $app['phone'], $app['email'], $app['city'] ?: null, $app['message'] ?: null, $id]);
    $memberId = (int) db()->lastInsertId();
    adminFlash('Created a draft profile for ' . $app['business_name'] . '. Add a logo and tagline, then publish it.');
    adminRedirect('/admin/members/' . $memberId);
}

function renderMembershipDelete(array $admin, int $id): string {
    $app = membershipApplication($id);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        adminExecute('DELETE FROM membership_applications WHERE id = ?', [$id]);
        adminFlash('Deleted the request from ' . $app['business_name'] . '.');
        adminRedirect('/admin/membership');
    }
    return adminView('templates.admin.delete', $admin, '/admin/membership', ['title' => 'Delete the request from ' . escape($app['business_name']), 'image' => '',
        'back' => '<a href="{{BASE}}/admin/membership">&larr; All requests</a>',
        'body' => deleteConfirmation('request', $app['business_name'], '/admin/membership/' . $id . '/delete', '/admin/membership/' . $id)]);
}

// ---- Member business profiles -----------------------------------------------

function renderAdminMembers(array $admin): string {
    $search = adminSearch();
    $where = $search === '' ? '' : 'WHERE business_name LIKE ?';
    $params = $search === '' ? [] : [likePattern($search)];
    $members = query("SELECT id, slug, business_name, tier, logo, city, featured, published FROM members $where ORDER BY featured DESC, FIELD(tier,'platinum','diamond','gold','silver'), position, business_name", $params);
    $rows = implode('', array_map(fn ($m) => '<tr><td class="admin-cell-team">' . adminImageHtml($m['logo'])
        . '<a href="{{BASE}}/admin/members/' . (int) $m['id'] . '">' . escape($m['business_name']) . '</a>' . ($m['featured'] ? ' <span class="admin-badge admin-badge--ongoing">Featured</span>' : '') . '</td>'
        . '<td>' . escape(MEMBERSHIP_TIERS[$m['tier']]['name'] ?? $m['tier']) . '</td><td class="admin-wide-only">' . ($m['city'] ? escape($m['city']) : '<span class="admin-muted">—</span>') . '</td>'
        . '<td>' . ($m['published'] ? '<span class="admin-badge admin-badge--ongoing">Published</span>' : '<span class="admin-badge admin-badge--unknown">Draft</span>') . '</td>'
        . '<td class="admin-actions"><a href="{{BASE}}/member/' . escape(rawurlencode($m['slug'])) . '" target="_blank" rel="noopener">View</a><a href="{{BASE}}/admin/members/' . (int) $m['id'] . '">Edit</a>'
        . (adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/members/' . (int) $m['id'] . '/delete">Delete</a>' : '') . '</td></tr>', $members));
    $total = (int) query('SELECT COUNT(*) AS n FROM members')[0]['n'];
    $summary = $search === '' ? number_format($total) . ' member profile' . ($total === 1 ? '' : 's') : number_format(count($members)) . ' of ' . number_format($total) . ' match “' . escape($search) . '”';
    $table = $members ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Business</th><th>Tier</th><th class="admin-wide-only">City</th><th>Status</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        : '<p class="admin-empty">' . ($search === '' ? 'No member profiles yet. Add one, or create one from an approved <a href="{{BASE}}/admin/membership">request</a>.' : 'No profiles match.') . '</p>';
    return adminView('templates.admin.members', $admin, '/admin/members', ['search' => escape($search), 'summary' => $summary, 'table' => $table]);
}

function renderMemberForm(array $admin, ?int $id): string {
    $member = $id === null
        ? ['id' => null, 'slug' => null, 'business_name' => '', 'tier' => 'silver', 'logo' => null, 'tagline' => '', 'about' => '', 'website' => '', 'phone' => '', 'email' => '', 'city' => '', 'instagram' => '', 'facebook' => '', 'featured' => 0, 'published' => 1]
        : query('SELECT * FROM members WHERE id = ?', [$id])[0] ?? notFound();
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') [$member, $errors] = saveMember($member);
    return memberForm($admin, $member, $errors);
}

function saveMember(array $member): array {
    $input = $member;
    foreach (['business_name', 'tier', 'tagline', 'about', 'website', 'phone', 'email', 'city', 'instagram', 'facebook'] as $f) $input[$f] = postedText($f);
    $input['featured'] = ($_POST['featured'] ?? '') === '1' ? 1 : 0;
    $input['published'] = ($_POST['published'] ?? '') === '1' ? 1 : 0;
    $errors = [];
    if ($input['business_name'] === '') $errors['business_name'] = 'Enter the business name.';
    elseif (mb_strlen($input['business_name']) > 255) $errors['business_name'] = 'The name must be 255 characters or fewer.';
    if (!isset(MEMBERSHIP_TIERS[$input['tier']])) $errors['tier'] = 'Choose a tier.';
    if (mb_strlen($input['tagline']) > 255) $errors['tagline'] = 'The tagline must be 255 characters or fewer.';
    if (mb_strlen($input['about']) > 5000) $errors['about'] = 'The description must be 5,000 characters or fewer.';
    foreach (['website' => 255, 'city' => 120, 'instagram' => 255, 'facebook' => 255, 'email' => 255, 'phone' => 32] as $f => $max) {
        if (mb_strlen($input[$f]) > $max) $errors[$f] = 'This value is too long.';
    }
    if ($input['email'] !== '' && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address, or leave it blank.';

    $upload = uploadedImage('logo', 'logo');
    if (is_string($upload)) $errors['logo'] = $upload;
    if ($errors) return [$input, $errors];

    $newLogo = null;
    if (is_array($upload)) {
        $newLogo = saveUploadedImage($upload, MEMBER_LOGO_DIR);
        if (!$newLogo) return [$input, ['logo' => 'The logo could not be saved: the folder ' . MEMBER_LOGO_DIR . ' isn’t writable by the web server.']];
        $input['logo'] = $newLogo;
    } elseif (($_POST['remove_logo'] ?? null) === '1') {
        $input['logo'] = null;
    }

    $empty = fn (string $v) => $v === '' ? null : $v;
    if ($input['id'] === null) {
        $slug = memberSlug($input['business_name']);
        adminExecute('INSERT INTO members (slug, business_name, tier, logo, tagline, about, website, phone, email, city, instagram, facebook, featured, published, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$slug, $input['business_name'], $input['tier'], $input['logo'], $empty($input['tagline']), $empty($input['about']), $empty($input['website']),
             $empty($input['phone']), $empty($input['email']), $empty($input['city']), $empty($input['instagram']), $empty($input['facebook']), $input['featured'], $input['published']]);
        adminFlash('Added ' . $input['business_name'] . '.');
    } else {
        adminExecute('UPDATE members SET business_name = ?, tier = ?, logo = ?, tagline = ?, about = ?, website = ?, phone = ?, email = ?, city = ?, instagram = ?, facebook = ?, featured = ?, published = ?, updated_at = NOW() WHERE id = ?',
            [$input['business_name'], $input['tier'], $input['logo'], $empty($input['tagline']), $empty($input['about']), $empty($input['website']),
             $empty($input['phone']), $empty($input['email']), $empty($input['city']), $empty($input['instagram']), $empty($input['facebook']), $input['featured'], $input['published'], $input['id']]);
        adminFlash('Saved ' . $input['business_name'] . '.');
    }
    adminRedirect('/admin/members');
}

function memberForm(array $admin, array $member, array $errors): string {
    $isNew = $member['id'] === null;
    $tierOptions = implode('', array_map(fn ($key, $t) => '<option value="' . $key . '"' . (($member['tier'] ?? '') === $key ? ' selected' : '') . '>' . escape($t['name'] . ' · ' . membershipPriceLabel($t['price'])) . '</option>', array_keys(MEMBERSHIP_TIERS), MEMBERSHIP_TIERS));
    $field = fn (string $f) => ['val' => escape((string) ($member[$f] ?? '')), 'err' => fieldError($errors, $f), 'inv' => fieldInvalid($errors, $f)];
    $data = [
        'title' => $isNew ? 'Add a member' : 'Edit ' . escape($member['business_name']),
        'action' => '{{BASE}}/admin/members/' . ($isNew ? 'new' : (int) $member['id']),
        'summary' => formErrorSummary($errors, 'logo'),
        'tier_options' => $tierOptions, 'tier_error' => fieldError($errors, 'tier'), 'tier_invalid' => fieldInvalid($errors, 'tier'),
        'featured_checked' => !empty($member['featured']) ? ' checked' : '', 'published_checked' => !empty($member['published']) ? ' checked' : '',
        'logo_current' => !empty($member['logo']) ? '<div class="admin-logo-current">' . adminImageHtml($member['logo'], 'admin-logo-preview') . '<label class="admin-check"><input type="checkbox" name="remove_logo" value="1">Remove the logo</label></div>' : '',
        'logo_error' => fieldError($errors, 'logo'), 'logo_invalid' => fieldInvalid($errors, 'logo'),
        'view_link' => $isNew || empty($member['slug']) ? '' : '<a class="admin-button-secondary" href="{{BASE}}/member/' . escape(rawurlencode($member['slug'])) . '" target="_blank" rel="noopener">View profile</a>',
        'submit' => $isNew ? 'Add member' : 'Save changes',
        'delete' => $isNew || !adminCan('delete') ? '' : '<a class="admin-danger-link" href="{{BASE}}/admin/members/' . (int) $member['id'] . '/delete">Delete this member</a>',
    ];
    foreach (['business_name', 'tagline', 'about', 'website', 'phone', 'email', 'city', 'instagram', 'facebook'] as $f) {
        $d = $field($f);
        $data[$f] = $d['val']; $data[$f . '_error'] = $d['err']; $data[$f . '_invalid'] = $d['inv'];
    }
    return adminView('templates.admin.member-form', $admin, '/admin/members', $data);
}

function renderMemberDelete(array $admin, int $id): string {
    $member = query('SELECT * FROM members WHERE id = ?', [$id])[0] ?? notFound();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        adminExecute('DELETE FROM members WHERE id = ?', [$id]);
        adminFlash('Deleted ' . $member['business_name'] . '.');
        adminRedirect('/admin/members');
    }
    return adminView('templates.admin.delete', $admin, '/admin/members', ['title' => 'Delete ' . escape($member['business_name']), 'image' => adminImageHtml($member['logo'], 'admin-logo-preview'),
        'back' => '<a href="{{BASE}}/admin/members">&larr; All members</a>',
        'body' => deleteConfirmation('member profile', $member['business_name'], '/admin/members/' . $id . '/delete', '/admin/members/' . $id, '<p>The public profile at /member/' . escape($member['slug']) . ' will be removed.</p>')]);
}
