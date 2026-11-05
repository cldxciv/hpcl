<?php
declare(strict_types=1);

// Ground booking emails: request received and the team's copy of a new request, approval with the payment details,
// rejection, confirmation (the receipt), cancellation and expiry. HTML built with tables and inline CSS, as the shop and
// welcome emails, plus a plain-text version. Sent through accountSendMail(), which writes to private/mail.log (and
// private/mail-html/) outside production, so nothing is emailed from a local copy.
// Phone numbers and the bank-transfer instructions come from the settings (booking_support_phone,
// booking_payment_note); nothing of the kind is written in code.
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/account-mail.php';

/** A line with the phone numbers from the settings and the contact email, for the footer of customer emails. */
function bookingMailSupportLine(): string {
    $phone = bookingSetting('booking_support_phone');
    $email = bookingSetting('contact_email');
    return trim(($phone !== '' ? "Call or WhatsApp: $phone" : '') . ($phone !== '' && $email !== '' ? ' · ' : '') . ($email !== '' ? "email $email" : ''));
}
/** Where the team's emails go: booking_notify_email, else the contact email, else MAIL_FROM_ADDRESS. */
function bookingNotifyAddress(): string {
    foreach ([bookingSetting('booking_notify_email'), bookingSetting('contact_email'), (string) getenv('MAIL_FROM_ADDRESS')] as $address) {
        if (filter_var(trim((string) $address), FILTER_VALIDATE_EMAIL)) return trim((string) $address);
    }
    return '';
}

/** The page shell: brand header, heading, the given body HTML and a footer. */
function bookingMailShell(string $title, string $preview, string $heading, string $bodyHtml, string $footerNote): string {
    $logoUrl = absoluteUrl('{{BASE}}/logo-email.jpeg');
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . escape($title) . '</title>'
        . '<style>@media only screen and (max-width:620px){.bk-card{width:100%!important}.bk-pad{padding-left:20px!important;padding-right:20px!important}.bk-h1{font-size:26px!important}}</style></head>'
        . '<body style="margin:0;padding:0;background:#eef3fb;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#eef3fb;">' . escape($preview) . '&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef3fb"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" class="bk-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #dbe4f3;">'
        . '<tr><td align="center" bgcolor="#0b50a7" style="background:#0b50a7;background-image:linear-gradient(135deg,#0b50a7 0%,#072c5e 100%);padding:26px 24px 22px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="#ffffff" style="border-radius:44px;padding:5px;">'
        . '<img src="' . escape($logoUrl) . '" width="72" height="72" alt="Pro HPCL" style="display:block;width:72px;height:72px;border-radius:36px;border:0;"></td></tr></table>'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;letter-spacing:0.22em;text-transform:uppercase;color:#ff9a4d;margin-top:12px;">Ground booking</div></td></tr>'
        . '<tr><td height="6" bgcolor="#ff7a18" style="height:6px;line-height:6px;font-size:0;background:#ff7a18;">&nbsp;</td></tr>'
        . '<tr><td class="bk-pad" style="padding:30px 36px 8px;font-family:Arial,Helvetica,sans-serif;">'
        . '<h1 class="bk-h1" style="margin:0 0 12px;font-size:28px;line-height:1.2;color:#072c5e;font-weight:800;">' . $heading . '</h1>' . $bodyHtml . '</td></tr>'
        . '<tr><td bgcolor="#072c5e" align="center" style="background:#072c5e;padding:22px 24px;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="font-size:16px;font-weight:800;letter-spacing:0.12em;color:#ffffff;">PRO <span style="color:#ff7a18;">HPCL</span></div>'
        . ($footerNote !== '' ? '<div style="font-size:12px;line-height:1.6;color:#aebbd6;margin-top:8px;">' . escape($footerNote) . '</div>' : '')
        . '<div style="font-size:12px;color:#aebbd6;margin-top:8px;"><a href="' . escape(absoluteUrl('{{BASE}}/')) . '" style="color:#ffffff;">prohpcl.com</a></div></td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** A small orange label above a block. */
function bookingMailLabel(string $text): string {
    return '<p style="margin:20px 0 8px;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;color:#ff7a18;font-weight:700;">' . escape($text) . '</p>';
}
function bookingMailParagraph(string $text): string {
    return '<p style="margin:0 0 16px;font-size:16px;line-height:1.65;color:#334155;">' . escape($text) . '</p>';
}
/** [label, value] rows describing the booking. */
function bookingMailFacts(array $b, bool $forTeam = false): array {
    $rows = [['Reference', $b['reference']], ['Ground', bookingGroundName() . ', ' . bookingGroundAddress()], [$b['days'] > 1 ? 'Dates' : 'Date', $b['date_label']], ['Time', $b['slot_label'] . ($b['days'] > 1 ? ' each day' : '')],
        ['Package', $b['format_label']]];
    $team = trim((string) $b['organisation']);
    $rows[] = ['Booked by', $b['name'] . ($team !== '' ? ' (' . $team . ')' : '')];
    if (trim((string) $b['opponent']) !== '') $rows[] = ['Opponent', $b['opponent']];
    $rows[] = ['Players per team', (string) $b['players_per_team'] . ($b['extra_players'] ? ' (extra players are charged separately)' : '')];
    if ($b['is_per_team']) $rows[] = ['Payment for', $b['teams_paying'] === 2 ? 'Both teams' : 'This team only'];
    $rows[] = ['Meals', $b['meals_label']];
    if ($b['stay_needed']) $rows[] = ['Overnight stay', $b['stay_label'] . (trim((string) $b['stay_notes']) !== '' ? ' (' . $b['stay_notes'] . ')' : '')];
    if ($forTeam) $rows[] = ['Contact', $b['phone'] . ' · ' . $b['email']];
    return $rows;
}
/** "₹7,500" or, for a saving, "− ₹150". */
function bookingMailSigned(?float $amount): string {
    if ($amount === null) return 'Quoted on approval';
    return $amount < 0 ? '− ' . bookingMoney(-$amount) : bookingMoney($amount);
}
/** [label, value, strong] money rows: the price lines, then total, advance and balance (indicative until the team approves). */
function bookingMailMoneyRows(array $b): array {
    $m = bookingMoneyLines($b);
    if ($m['total'] === null) return [['Price', 'Confirmed when your request is approved', false]];
    $rows = array_map(fn ($line) => [$line['label'], bookingMailSigned($line['amount']), false], $m['lines']);
    $percent = bookingPercentText($m['advance_percent']);
    if ($m['indicative']) {
        return [...$rows, ['Indicative total', bookingMoney($m['total']), false], ['Advance to confirm (' . $percent . '%)', bookingMoney($m['advance']), false], ['Balance at the ground', bookingMoney($m['balance']), false]];
    }
    return [...$rows, ['Total price', bookingMoney($m['total']), false], ['Pay now (' . $percent . '% advance)', bookingMoney($m['advance']), true], ['Balance, paid at the ground', bookingMoney($m['balance']), false]];
}
/** The "We adjusted your request" block for emails ('' when nothing was changed). */
function bookingMailChangesHtml(array $b): string {
    $changes = bookingChangeList($b);
    if (!$changes) return '';
    $items = implode('', array_map(fn ($c) => '<li style="margin:0 0 4px;"><strong>' . escape($c['label']) . ':</strong> <span style="text-decoration:line-through;color:#475569;">' . escape($c['from']) . '</span> &rarr; <strong>' . escape($c['to']) . '</strong></li>', $changes));
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px;"><tr><td bgcolor="#eef5ff" style="background:#eef5ff;border:1px solid #bcd4f6;border-radius:10px;padding:14px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#334155;">'
        . '<strong style="color:#072c5e;font-size:15px;">We adjusted your request</strong><br>Our team changed some details. Here is what you asked for and what is approved:<ul style="margin:8px 0 0;padding:0 0 0 20px;">' . $items . '</ul></td></tr></table>';
}
function bookingMailChangesText(array $b): string {
    $changes = bookingChangeList($b);
    return $changes ? "We adjusted your request:\n" . implode("\n", array_map(fn ($c) => '- ' . $c['label'] . ': ' . $c['from'] . ' -> ' . $c['to'], $changes)) . "\n" : '';
}
function bookingMailTable(array $rows, bool $money = false): string {
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 6px;">';
    foreach ($rows as $row) {
        $strong = $money && ($row[2] ?? false);
        $html .= '<tr><td valign="top" style="padding:7px 12px 7px 0;border-bottom:1px solid #e5eaf3;font-family:Arial,Helvetica,sans-serif;font-size:' . ($strong ? '16' : '14') . 'px;color:' . ($strong ? '#072c5e' : '#64748b') . ';' . ($strong ? 'font-weight:800;' : '') . 'width:38%;">' . escape($row[0]) . '</td>'
            . '<td valign="top" style="padding:7px 0;border-bottom:1px solid #e5eaf3;font-family:Arial,Helvetica,sans-serif;font-size:' . ($strong ? '17' : '15') . 'px;color:#0f172a;' . ($strong ? 'font-weight:800;color:#072c5e;' : '') . '">' . escape($row[1]) . '</td></tr>';
    }
    return $html . '</table>';
}
function bookingMailTextRows(array $rows): string {
    return implode("\n", array_map(fn ($row) => $row[0] . ': ' . $row[1], $rows)) . "\n";
}
function bookingMailTermsHtml(): string {
    return '<ul style="margin:0 0 16px;padding:0 0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.65;color:#475569;">'
        . implode('', array_map(fn ($term) => '<li style="margin:0 0 4px;">' . escape($term) . '</li>', bookingTerms())) . '</ul>';
}
/** The bank-transfer instructions from the settings as lines (the stored text separates them with " | "). */
function bookingPaymentNoteLines(): array {
    return array_values(array_filter(array_map('trim', preg_split('~\s*\|\s*|\n~', bookingSetting('booking_payment_note')))));
}
function bookingMailNote(string $html): string {
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px;"><tr><td bgcolor="#fff4ea" style="background:#fff4ea;border:1px solid #ffd9b8;border-radius:10px;padding:14px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#334155;">'
        . $html . '</td></tr></table>';
}

/** [subject, text, HTML] of the email to the person who booked, for $kind: requested, approved, rejected, confirmed, cancelled or expired. */
function bookingEmail(array $b, string $kind): array {
    $first = accountFirstName($b['name']);
    $ref = $b['reference'];
    $status = $b['status_url'];
    $support = bookingMailSupportLine();
    $facts = bookingMailFacts($b);
    $money = bookingMailMoneyRows($b);
    $due = $b['payment_due_at'] ? date('l, j F Y, g:i A', strtotime($b['payment_due_at'])) : '';
    $note = trim((string) $b['customer_note']);
    $button = fn (string $label, string $url) => welcomeMailButton($label, $url, true) . '<div style="height:14px;line-height:14px;">&nbsp;</div>';

    switch ($kind) {
        case 'requested':
            $subject = "We have your ground booking request ($ref)";
            $heading = 'We have your request, ' . $first;
            $intro = 'Thank you for requesting ' . bookingGroundName() . '. Our team will review it and email you. You pay nothing until your request is approved.';
            $body = bookingMailParagraph($intro) . bookingMailLabel('Your request') . bookingMailTable($facts)
                . bookingMailLabel('Price') . bookingMailTable($money, true)
                . bookingMailParagraph('This is the package price' . ($b['stay_needed'] ? ' without the overnight stay, which we quote when we approve your request' : '') . '. The final amount is confirmed when your request is approved.')
                . $button('View your request', $status);
            $text = "Hi $first,\n\n$intro\n\nYour request\n" . bookingMailTextRows($facts) . "\nPrice\n" . bookingMailTextRows($money) . "\nView your request: $status\n";
            $preview = 'We will review your request and email you.';
            break;
        case 'approved':
            $subject = "Booking approved: pay the advance to confirm ($ref)";
            $heading = 'Approved! Pay the advance to confirm';
            $intro = 'Good news, ' . $first . ': your request for ' . bookingGroundName() . ' is approved' . ($b['admin_modified'] ? ' with a few changes' : '') . '. Pay the advance' . ($due !== '' ? ' by ' . $due : '') . ' to confirm your booking. Until then the slot is held for you; if the advance is not paid in time it is released.';
            $lines = bookingPaymentNoteLines();
            $bank = $lines ? bookingMailNote('<strong>Prefer a bank transfer?</strong><br>' . implode('<br>', array_map('escape', $lines))) : '';
            $body = bookingMailParagraph($intro) . ($note !== '' ? bookingMailNote('<strong>Note from the team:</strong> ' . escape($note)) : '') . bookingMailChangesHtml($b)
                . bookingMailLabel('Payment') . bookingMailTable($money, true)
                . '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#475569;">Pay ' . escape(bookingMoney($b['amount'])) . ' now to confirm. Pay the balance of ' . escape(bookingMoney($b['balance_amount'])) . ' at the ground.</p>'
                . $button('Pay ' . bookingMoney($b['amount']) . ' now', $b['pay_url']) . $bank
                . bookingMailLabel('Your booking') . bookingMailTable($facts)
                . bookingMailLabel('Terms') . bookingMailTermsHtml();
            $text = "Hi $first,\n\n$intro\n" . ($note !== '' ? "\nNote from the team: $note\n" : '') . ($b['admin_modified'] ? "\n" . bookingMailChangesText($b) : '') . "\nPayment\n" . bookingMailTextRows($money)
                . "\nPay now: {$b['pay_url']}\n" . ($lines ? "\nPrefer a bank transfer?\n" . implode("\n", $lines) . "\n" : '')
                . "\nYour booking\n" . bookingMailTextRows($facts) . "\nTerms\n- " . implode("\n- ", bookingTerms()) . "\n";
            $preview = 'Pay ' . bookingMoney($b['amount']) . ' to confirm your booking.';
            break;
        case 'rejected':
            $subject = "About your ground booking request ($ref)";
            $heading = 'We could not approve this request';
            $intro = 'Sorry, ' . $first . ': we are not able to approve your request for ' . $b['date_label'] . ' (' . $b['slot_label'] . ').' . ($note !== '' ? ' ' . $note : '') . ' You are welcome to request another date or time.';
            $body = bookingMailParagraph($intro) . bookingMailLabel('Your request') . bookingMailTable($facts) . $button('Request another date', absoluteUrl('{{BASE}}/book'));
            $text = "Hi $first,\n\n$intro\n\nYour request\n" . bookingMailTextRows($facts) . "\nRequest another date: " . absoluteUrl('{{BASE}}/book') . "\n";
            $preview = 'We could not approve this request.';
            break;
        case 'confirmed':
            $subject = "Booking confirmed ($ref)";
            $heading = 'Your booking is confirmed';
            $intro = 'Thank you, ' . $first . '! We have received your advance of ' . bookingMoney($b['amount']) . '. ' . bookingGroundName() . ' is yours on ' . $b['date_label'] . ' (' . $b['time_range'] . '). Please pay the balance of ' . bookingMoney($b['balance_amount']) . ' at the ground.';
            $ics = $b['status_url'] . '?ics=1';
            $body = bookingMailParagraph($intro) . bookingMailLabel('Your booking') . bookingMailTable($facts) . bookingMailLabel('Receipt') . bookingMailTable([
                ['Total price', bookingMoney($b['total_amount']), false], ['Advance paid', bookingMoney($b['amount']), true], ['Balance at the ground', bookingMoney($b['balance_amount']), false]], true)
                . $button('View your booking', $status)
                . '<p style="margin:0 0 16px;font-size:14px;text-align:center;"><a href="' . escape($ics) . '" style="color:#0b50a7;">Add it to your calendar</a></p>'
                . bookingMailLabel('Terms') . bookingMailTermsHtml();
            $text = "Hi $first,\n\n$intro\n\nYour booking\n" . bookingMailTextRows($facts) . "\nAdvance paid: " . bookingMoney($b['amount']) . "\nBalance at the ground: " . bookingMoney($b['balance_amount'])
                . "\n\nView your booking: $status\nAdd to calendar: $ics\n\nTerms\n- " . implode("\n- ", bookingTerms()) . "\n";
            $preview = 'Your advance is received and the ground is yours.';
            break;
        case 'cancelled':
            $subject = "Booking cancelled ($ref)";
            $heading = 'Your booking was cancelled';
            $intro = 'Your booking ' . $ref . ' for ' . $b['date_label'] . ' (' . $b['slot_label'] . ') has been cancelled.' . ($note !== '' ? ' ' . $note : '') . ' If you already paid an advance, we will contact you about it.';
            $body = bookingMailParagraph($intro) . bookingMailTable($facts) . $button('Book another date', absoluteUrl('{{BASE}}/book'));
            $text = "Hi $first,\n\n$intro\n\n" . bookingMailTextRows($facts) . "\nBook another date: " . absoluteUrl('{{BASE}}/book') . "\n";
            $preview = 'Your booking was cancelled.';
            break;
        case 'expired':
            $subject = "Booking hold expired ($ref)";
            $heading = 'We released your slot';
            $intro = 'We did not receive the advance for booking ' . $ref . ' by the deadline, so the slot for ' . $b['date_label'] . ' (' . $b['slot_label'] . ') has been released. You can send a new request if you still want it.';
            $body = bookingMailParagraph($intro) . bookingMailTable($facts) . $button('Request again', absoluteUrl('{{BASE}}/book'));
            $text = "Hi $first,\n\n$intro\n\n" . bookingMailTextRows($facts) . "\nRequest again: " . absoluteUrl('{{BASE}}/book') . "\n";
            $preview = 'The advance was not received in time.';
            break;
        default:
            throw new InvalidArgumentException("Unknown booking email: $kind");
    }
    $text .= ($support !== '' ? "\nNeed help? $support\n" : '') . "\nThe Pro HPCL team\n";
    return [$subject, $text, bookingMailShell($subject, $preview, escape($heading), $body, $support)];
}

/** Sends the email to the person who booked (see bookingEmail()). Never throws for a mail failure. */
function bookingMail(int $bookingId, string $kind): bool {
    try {
        $b = bookingGet($bookingId);
        if (!$b || !filter_var($b['email'], FILTER_VALIDATE_EMAIL)) return false;
        [$subject, $text, $html] = bookingEmail($b, $kind);
        return accountSendMail($b['email'], $subject, $text, $html);
    } catch (Throwable $error) {
        error_log('Booking email ' . $kind . ' for ' . $bookingId . ' failed: ' . $error->getMessage());
        return false;
    }
}

/** The team's email: 'new' (a request waiting for a decision) or 'confirmed' (the advance was paid). */
function bookingMailAdmin(int $bookingId, string $kind): bool {
    try {
        $to = bookingNotifyAddress();
        $b = bookingGet($bookingId);
        if ($to === '' || !$b) return false;
        $adminUrl = absoluteUrl('{{BASE}}/admin/bookings/' . $b['id']);
        $facts = bookingMailFacts($b, true);
        $notes = trim((string) $b['notes']);
        if ($kind === 'new') {
            $subject = 'New ground booking request ' . $b['reference'] . ' · ' . $b['date_label'];
            $money = bookingMailMoneyRows($b);
            $body = bookingMailParagraph($b['name'] . ' asked for ' . $b['format_label'] . ' on ' . $b['date_label'] . ' (' . $b['slot_label'] . '). It is waiting for your decision.')
                . bookingMailTable($facts) . bookingMailLabel('Price') . bookingMailTable($money, true)
                . ($notes !== '' ? bookingMailLabel('Their message') . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">' . nl2br(escape($notes)) . '</p>' : '')
                . welcomeMailButton('Review in admin', $adminUrl, true) . '<div style="height:14px;line-height:14px;">&nbsp;</div>';
            $text = "New ground booking request {$b['reference']}\n\n" . bookingMailTextRows($facts) . "\n" . bookingMailTextRows($money) . ($notes !== '' ? "\nTheir message: $notes\n" : '') . "\nAdmin: $adminUrl\n";
            $heading = 'New booking request';
        } elseif ($kind === 'confirmed') {
            $subject = 'Booking confirmed ' . $b['reference'] . ' · ' . $b['date_label'];
            $body = bookingMailParagraph($b['name'] . ' paid the advance of ' . bookingMoney($b['amount']) . ' (' . ($b['payment_method'] === 'offline' ? 'offline' : 'online') . '). The balance of ' . bookingMoney($b['balance_amount']) . ' is due at the ground.')
                . bookingMailTable($facts) . welcomeMailButton('Open in admin', $adminUrl, true) . '<div style="height:14px;line-height:14px;">&nbsp;</div>';
            $text = "Booking {$b['reference']} is confirmed. Advance " . bookingMoney($b['amount']) . ", balance " . bookingMoney($b['balance_amount']) . ".\n\n" . bookingMailTextRows($facts) . "\nAdmin: $adminUrl\n";
            $heading = 'Booking confirmed';
        } else {
            return false;
        }
        return accountSendMail($to, $subject, $text, bookingMailShell($subject, $subject, escape($heading), $body, ''));
    } catch (Throwable $error) {
        error_log('Booking team email ' . $kind . ' for ' . $bookingId . ' failed: ' . $error->getMessage());
        return false;
    }
}
