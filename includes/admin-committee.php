<?php
declare(strict_types=1);

// Admin pages for committee member applications (/admin/committee), sent with the form at
// /join-committee (committee.php): list and search them, read one with its Aadhaar card, mark it
// approved or rejected, and delete it with its documents; and turn payment of the plan on or off.
require_once __DIR__ . '/committee.php';

const COMMITTEE_PER_PAGE = 50;
const COMMITTEE_STATUS_BADGES = ['pending' => 'upcoming', 'approved' => 'ongoing', 'rejected' => 'completed'];
const COMMITTEE_PAYMENT_BADGES = ['not_required' => 'unknown', 'unpaid' => 'completed', 'paid' => 'ongoing'];

function committeeStatusBadge(string $status): string {
    return '<span class="admin-badge admin-badge--' . (COMMITTEE_STATUS_BADGES[$status] ?? 'unknown') . '">' . escape(COMMITTEE_STATUSES[$status] ?? ucfirst($status)) . '</span>';
}
function committeePaymentBadge(string $status): string {
    return '<span class="admin-badge admin-badge--' . (COMMITTEE_PAYMENT_BADGES[$status] ?? 'unknown') . '">' . escape(COMMITTEE_PAYMENT_STATUSES[$status] ?? ucfirst($status)) . '</span>';
}
/** The payment switch above the list, with why payments can't be taken yet when Razorpay isn't ready. */
function committeePaymentPanel(): string {
    $on = committeePaymentSwitchedOn();
    $ready = razorpayReady();
    $state = match (true) {
        $on && $ready => '<strong>On.</strong> Applicants pay their plan (' . implode(', ', array_map('committeePlanLabel', COMMITTEE_PLANS)) . ') with Razorpay in ' . escape(razorpayConfig()['mode']) . ' mode; an application is sent once it is paid.',
        $on => '<strong>On, but not working:</strong> Razorpay isn’t set up, so the form is sent without payment. The root user turns Razorpay on and saves its keys at Settings → Integrations.',
        default => '<strong>Off.</strong> Applications are sent without payment.',
    };
    return '<section class="admin-panel admin-section admin-committee-payment"><form method="post" action="{{BASE}}/admin/committee/payment">'
        . '<input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '"><input type="hidden" name="enabled" value="' . ($on ? '0' : '1') . '">'
        . '<div><h2>Payment</h2><p>' . $state . '</p></div>'
        . '<button type="submit"' . ($on ? ' class="admin-button-secondary"' : '') . '>' . ($on ? 'Turn payment off' : 'Turn payment on') . '</button></form></section>';
}
/** Turns payment of the committee form on or off. */
function saveCommitteePaymentSetting(array $admin): never {
    $on = ($_POST['enabled'] ?? '') === '1';
    if ($on) adminExecute('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [COMMITTEE_PAYMENT_SETTING, '1']);
    else adminExecute('DELETE FROM site_settings WHERE name = ?', [COMMITTEE_PAYMENT_SETTING]);
    adminFlash($on ? (razorpayReady() ? 'Payment is on: applicants now pay their plan when they apply.' : 'Payment is on, but Razorpay isn’t set up yet, so applicants can’t pay until it is.') : 'Payment is off: applications are sent without payment.');
    adminRedirect('/admin/committee');
}
function committeeApplication(int $id): array {
    return query('SELECT c.*, a.name AS reviewed_by_name FROM committee_applications c LEFT JOIN admins a ON a.id = c.reviewed_by WHERE c.id = ?', [$id])[0] ?? notFound();
}

function renderAdminCommittee(array $admin): string {
    $search = adminSearch();
    $status = is_string($_GET['status'] ?? null) && isset(COMMITTEE_STATUSES[$_GET['status']]) ? $_GET['status'] : '';
    $conditions = [];
    $params = [];
    if ($status !== '') {
        $conditions[] = 'status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $conditions[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
        array_push($params, ...array_fill(0, 3, likePattern($search)));
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $total = (int) query("SELECT COUNT(*) AS n FROM committee_applications $where", $params)[0]['n'];
    $lastPage = max(1, (int) ceil($total / COMMITTEE_PER_PAGE));
    $page = min($lastPage, max(1, ctype_digit((string) ($_GET['page'] ?? '')) ? (int) $_GET['page'] : 1));
    $applications = query("SELECT id, name, email, phone, plan, status, payment_status, created_at FROM committee_applications $where ORDER BY created_at DESC, id DESC LIMIT "
        . COMMITTEE_PER_PAGE . ' OFFSET ' . (($page - 1) * COMMITTEE_PER_PAGE), $params);
    $rows = implode('', array_map(fn ($row) => '<tr><td><a href="{{BASE}}/admin/committee/' . (int) $row['id'] . '">' . escape($row['name']) . '</a><br><span class="admin-muted admin-small">' . escape($row['email']) . '</span></td>'
        . '<td class="admin-wide-only">' . escape($row['phone']) . '</td><td class="admin-num">' . committeePlanLabel($row['plan']) . '</td>'
        . '<td>' . committeeStatusBadge($row['status']) . '</td><td>' . committeePaymentBadge($row['payment_status']) . '</td><td class="admin-wide-only">' . escape(date('j M Y', strtotime($row['created_at']))) . '</td>'
        . '<td class="admin-actions"><a href="{{BASE}}/admin/committee/' . (int) $row['id'] . '">View</a>'
        . (adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/committee/' . (int) $row['id'] . '/delete">Delete</a>' : '') . '</td></tr>', $applications));

    $counts = array_column(query('SELECT status, COUNT(*) AS n FROM committee_applications GROUP BY status'), 'n', 'status');
    $tabUrl = fn (string $key) => '{{BASE}}/admin/committee' . ($key !== '' || $search !== '' ? '?' . escape(http_build_query(array_filter(['status' => $key, 'q' => $search]))) : '');
    $tabs = '<nav class="admin-tabs" aria-label="Status">' . implode('', array_map(fn ($key, $label) => '<a href="' . $tabUrl($key) . '"' . ($key === $status ? ' aria-current="page"' : '') . '>'
        . $label . ' <span class="admin-muted">' . number_format($key === '' ? array_sum($counts) : (int) ($counts[$key] ?? 0)) . '</span></a>', ['', ...array_keys(COMMITTEE_STATUSES)], ['All', ...COMMITTEE_STATUSES])) . '</nav>';
    $pageUrl = fn (int $number) => '{{BASE}}/admin/committee?' . escape(http_build_query(array_filter(['status' => $status, 'q' => $search, 'page' => $number > 1 ? $number : null])));
    $pager = $lastPage > 1 ? '<nav class="admin-pager" aria-label="Pages">'
        . ($page > 1 ? '<a href="' . $pageUrl($page - 1) . '" rel="prev">&larr; Previous</a>' : '<span></span>')
        . '<span class="admin-muted">Page ' . $page . ' of ' . $lastPage . '</span>'
        . ($page < $lastPage ? '<a href="' . $pageUrl($page + 1) . '" rel="next">Next &rarr;</a>' : '<span></span>') . '</nav>' : '';
    $table = $applications ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Applicant</th><th class="admin-wide-only">Phone</th><th class="admin-num">Plan</th><th>Status</th><th>Payment</th><th class="admin-wide-only">Applied</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>'
        . $rows . '</tbody></table></div>' . $pager
        : '<p class="admin-empty">' . ($search === '' && $status === '' ? 'No applications yet. They arrive from the form at <a href="{{BASE}}/join-committee">/join-committee</a>.' : 'No applications match.') . '</p>';
    $summary = number_format($total) . ' application' . ($total === 1 ? '' : 's') . ($search !== '' ? ($total === 1 ? ' matches' : ' match') . ' “' . escape($search) . '”' : '');
    return adminView('templates.admin.committee', $admin, '/admin/committee', ['search' => escape($search), 'status' => escape($status), 'summary' => $summary, 'tabs' => $tabs, 'table' => $table,
        'payment' => committeePaymentPanel()]);
}

/** One application: the details, the Aadhaar card and the status form. */
function renderCommitteeApplication(array $admin, int $id): string {
    $application = committeeApplication($id);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $status = postedText('status');
        $note = postedText('admin_note');
        if (isset(COMMITTEE_STATUSES[$status]) && mb_strlen($note) <= 2000) {
            adminExecute('UPDATE committee_applications SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?', [$status, $note === '' ? null : $note, $admin['id'], $id]);
            adminFlash('Saved the application of ' . $application['name'] . '.');
            adminRedirect('/admin/committee/' . $id);
        }
        adminFlash('Choose a status, and keep the note to 2,000 characters or fewer.');
        adminRedirect('/admin/committee/' . $id);
    }
    $document = function (string $side) use ($application, $id): string {
        $url = '{{BASE}}/admin/committee/' . $id . '/aadhaar/' . $side;
        $label = 'Aadhaar ' . $side;
        return '<figure class="admin-committee-doc"><figcaption>' . ucfirst($side) . '</figcaption>'
            . (str_ends_with((string) $application['aadhaar_' . $side], '.pdf') ? '<a class="admin-button-secondary" href="' . $url . '" target="_blank" rel="noopener">Open PDF</a>'
                : '<a href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="' . $label . '"></a>') . '</figure>';
    };
    $statuses = implode('', array_map(fn ($key, $label) => '<option value="' . $key . '"' . ($application['status'] === $key ? ' selected' : '') . '>' . $label . '</option>', array_keys(COMMITTEE_STATUSES), COMMITTEE_STATUSES));
    $reviewed = $application['reviewed_at'] ? 'Last updated ' . escape(date('j M Y, g:i A', strtotime($application['reviewed_at']))) . ($application['reviewed_by_name'] ? ' by ' . escape($application['reviewed_by_name']) : '') : 'Not reviewed yet';
    return adminView('templates.admin.committee-application', $admin, '/admin/committee', [
        'title' => escape($application['name']), 'id' => $id, 'badge' => committeeStatusBadge($application['status']),
        'email' => escape($application['email']), 'phone' => escape($application['phone']), 'plan' => committeePlanLabel($application['plan']),
        'applied' => escape(date('j M Y, g:i A', strtotime($application['created_at']))), 'message' => nl2br(escape($application['message'])),
        'payment' => committeePaymentBadge($application['payment_status']) . match ($application['payment_status']) {
            'paid' => ' ' . committeePlanLabel((int) $application['amount']) . ($application['paid_at'] ? ' on ' . escape(date('j M Y, g:i A', strtotime($application['paid_at']))) : '')
                . '<br><a class="admin-small" href="https://dashboard.razorpay.com/app/payments/' . rawurlencode((string) $application['razorpay_payment_id']) . '" target="_blank" rel="noopener noreferrer">' . escape((string) $application['razorpay_payment_id']) . '</a>',
            'unpaid' => ' <span class="admin-small admin-muted">The applicant didn’t finish paying ' . committeePlanLabel((int) $application['amount']) . '.</span>',
            default => '',
        },
        'documents' => $document('front') . $document('back'), 'statuses' => $statuses, 'admin_note' => escape((string) $application['admin_note']), 'reviewed' => $reviewed,
        'delete' => adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/committee/' . $id . '/delete">Delete this application</a>' : '']);
}

/** Sends a side of the Aadhaar card, which is kept out of the public folders. */
function sendCommitteeDocument(array $admin, int $id, string $side): never {
    $file = committeeApplication($id)['aadhaar_' . $side];
    $path = $file ? committeeDocumentPath($file) : '';
    $type = array_search(pathinfo($path, PATHINFO_EXTENSION), COMMITTEE_DOCUMENT_TYPES, true);
    if (!$type || !is_file($path)) notFound();
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="aadhaar-' . $side . '-' . $id . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($path);
    exit;
}

function renderCommitteeDelete(array $admin, int $id): string {
    $application = committeeApplication($id);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // Applications are removed for good, with their documents: identity cards aren't kept in the trash.
        adminExecute('DELETE FROM committee_applications WHERE id = ?', [$id]);
        foreach (['aadhaar_front', 'aadhaar_back'] as $field) if ($application[$field]) @unlink(committeeDocumentPath($application[$field]));
        adminFlash('Deleted the application of ' . $application['name'] . '.');
        adminRedirect('/admin/committee');
    }
    return adminView('templates.admin.delete', $admin, '/admin/committee', ['title' => 'Delete the application of ' . escape($application['name']), 'image' => '',
        'back' => '<a href="{{BASE}}/admin/committee">&larr; All applications</a>',
        'body' => deleteConfirmation('application', $application['name'], '/admin/committee/' . $id . '/delete', '/admin/committee/' . $id, '<p>Its Aadhaar card files are deleted too.</p>')]);
}
