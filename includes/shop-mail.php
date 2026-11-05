<?php
declare(strict_types=1);

// Shop emails (order confirmed, shipped, delivered, cancelled, refunded, and the team's copy of a paid
// order): HTML built with tables and inline CSS, as the welcome email (account-mail.php), plus a plain-text
// version. Sent through accountSendMail(), which writes to private/mail.log (and private/mail-html/) outside
// production, so nothing is emailed from a local copy.
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/account-mail.php';

/** ₹ amount for an email: whole rupees without decimals. */
function shopMailMoney(float|string $amount): string {
    $amount = (float) $amount;
    return '₹' . number_format($amount, floor($amount) === $amount ? 0 : 2);
}
/** A line of the shop's contact details for the footer of customer emails. */
function shopMailSupportLine(): string {
    $phone = trim(setting('shop_support_phone'));
    $email = trim(setting('contact_email'));
    return trim(($phone !== '' ? "Call or WhatsApp $phone" : '') . ($phone !== '' && $email !== '' ? ' · ' : '') . ($email !== '' ? "email $email" : ''));
}
/** Where the team's copy of a paid order goes: shop_notify_email, else the contact email, else MAIL_FROM_ADDRESS. */
function shopNotifyAddress(): string {
    foreach ([setting('shop_notify_email'), setting('contact_email'), (string) getenv('MAIL_FROM_ADDRESS')] as $address) {
        if (filter_var(trim((string) $address), FILTER_VALIDATE_EMAIL)) return trim((string) $address);
    }
    return '';
}

/** The order's lines as an HTML table. */
function shopMailItemsTable(array $order): string {
    $rows = '';
    foreach ($order['items'] as $item) {
        $rows .= '<tr><td valign="top" style="padding:10px 0;border-bottom:1px solid #e5eaf3;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#0f172a;line-height:1.4;">'
            . '<strong>' . escape($item['product_name']) . '</strong>'
            . ($item['variant_label'] ? '<div style="font-size:13px;color:#64748b;">' . escape($item['variant_label']) . '</div>' : '')
            . '<div style="font-size:13px;color:#64748b;">Qty ' . (int) $item['qty'] . ' × ' . shopMailMoney($item['unit_price']) . '</div></td>'
            . '<td valign="top" align="right" style="padding:10px 0 10px 12px;border-bottom:1px solid #e5eaf3;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#072c5e;white-space:nowrap;">'
            . shopMailMoney($item['line_total']) . '</td></tr>';
    }
    $total = fn (string $label, string $value, bool $strong = false) => '<tr><td style="padding:6px 0;font-family:Arial,Helvetica,sans-serif;font-size:' . ($strong ? '17' : '14')
        . 'px;color:' . ($strong ? '#072c5e' : '#475569') . ';' . ($strong ? 'font-weight:800;' : '') . '">' . $label . '</td><td align="right" style="padding:6px 0;font-family:Arial,Helvetica,sans-serif;font-size:'
        . ($strong ? '17' : '14') . 'px;color:' . ($strong ? '#072c5e' : '#475569') . ';' . ($strong ? 'font-weight:800;' : '') . '">' . $value . '</td></tr>';
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">'
        . $total('Subtotal', shopMailMoney($order['subtotal']))
        . $total('Shipping', (float) $order['shipping'] > 0 ? shopMailMoney($order['shipping']) : 'Free')
        . $total('Total paid (incl. GST)', shopMailMoney($order['total']), true) . '</table>';
}
function shopMailAddress(array $order, string $separator): string {
    return implode($separator, array_filter([$order['name'], $order['address_line1'], $order['address_line2'], $order['city'] . ', ' . $order['state'] . ' ' . $order['pincode'], 'Phone: ' . $order['phone']]));
}
/** The delivery address escaped, with <br> between its lines. */
function shopMailAddressHtml(array $order): string {
    return implode('<br>', array_map('escape', array_filter([$order['name'], $order['address_line1'], $order['address_line2'], $order['city'] . ', ' . $order['state'] . ' ' . $order['pincode'], 'Phone: ' . $order['phone']])));
}
function shopMailItemsText(array $order): string {
    $text = '';
    foreach ($order['items'] as $item) {
        $text .= '- ' . $item['product_name'] . ($item['variant_label'] ? ' (' . $item['variant_label'] . ')' : '') . ' × ' . (int) $item['qty'] . ': ' . shopMailMoney($item['line_total']) . "\n";
    }
    return $text . 'Subtotal: ' . shopMailMoney($order['subtotal']) . "\nShipping: " . ((float) $order['shipping'] > 0 ? shopMailMoney($order['shipping']) : 'Free')
        . "\nTotal (incl. GST): " . shopMailMoney($order['total']) . "\n";
}

/** The page shell shared by the shop emails: brand header, heading, the given body HTML and a footer. */
function shopMailShell(string $title, string $preview, string $heading, string $bodyHtml, string $footerNote): string {
    $logoUrl = absoluteUrl('{{BASE}}/logo-email.jpeg');
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . escape($title) . '</title>'
        . '<style>@media only screen and (max-width:620px){.sm-card{width:100%!important}.sm-pad{padding-left:20px!important;padding-right:20px!important}.sm-h1{font-size:26px!important}}</style></head>'
        . '<body style="margin:0;padding:0;background:#eef3fb;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#eef3fb;">' . escape($preview) . '&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef3fb"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" class="sm-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #dbe4f3;">'
        . '<tr><td align="center" bgcolor="#0b50a7" style="background:#0b50a7;background-image:linear-gradient(135deg,#0b50a7 0%,#072c5e 100%);padding:26px 24px 22px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="#ffffff" style="border-radius:44px;padding:5px;">'
        . '<img src="' . escape($logoUrl) . '" width="72" height="72" alt="Pro HPCL" style="display:block;width:72px;height:72px;border-radius:36px;border:0;"></td></tr></table>'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;letter-spacing:0.22em;text-transform:uppercase;color:#ff9a4d;margin-top:12px;">Pro HPCL Shop</div></td></tr>'
        . '<tr><td height="6" bgcolor="#ff7a18" style="height:6px;line-height:6px;font-size:0;background:#ff7a18;">&nbsp;</td></tr>'
        . '<tr><td class="sm-pad" style="padding:30px 36px 8px;font-family:Arial,Helvetica,sans-serif;">'
        . '<h1 class="sm-h1" style="margin:0 0 12px;font-size:28px;line-height:1.2;color:#072c5e;font-weight:800;">' . $heading . '</h1>' . $bodyHtml . '</td></tr>'
        . '<tr><td bgcolor="#072c5e" align="center" style="background:#072c5e;padding:22px 24px;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="font-size:16px;font-weight:800;letter-spacing:0.12em;color:#ffffff;">PRO <span style="color:#ff7a18;">HPCL</span></div>'
        . ($footerNote !== '' ? '<div style="font-size:12px;line-height:1.6;color:#aebbd6;margin-top:8px;">' . escape($footerNote) . '</div>' : '')
        . '<div style="font-size:12px;color:#aebbd6;margin-top:8px;"><a href="' . escape(absoluteUrl('{{BASE}}/')) . '" style="color:#ffffff;">prohpcl.com</a></div></td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** [subject, text, HTML] of the customer email for $kind: paid, shipped, delivered, cancelled or refunded. */
function shopCustomerEmail(array $order, string $kind): array {
    $first = accountFirstName($order['name']);
    $url = absoluteUrl('{{BASE}}/shop/order/' . $order['token']);
    $number = $order['order_number'];
    $copy = [
        'paid' => ['Order ' . $number . ' confirmed', 'Thank you, ' . $first . '! Your order is confirmed',
            'We have received your payment and are getting your order ready. We will email you again when it ships.'],
        'shipped' => ['Order ' . $number . ' has shipped', 'Your order is on its way',
            'Good news, ' . $first . ': your order has been shipped.'],
        'delivered' => ['Order ' . $number . ' delivered', 'Your order was delivered',
            'Your order has been marked as delivered. We hope you enjoy it! If anything is wrong, just reply to this email.'],
        'cancelled' => ['Order ' . $number . ' cancelled', 'Your order was cancelled',
            'Your order has been cancelled. If you already paid, the amount will be refunded to your original payment method; this can take 5 to 7 working days.'],
        'refunded' => ['Order ' . $number . ' refunded', 'Your order was refunded',
            'We have refunded your order. The money returns to your original payment method within 5 to 7 working days.'],
    ][$kind];
    [$subject, $heading, $intro] = $copy;
    $tracking = '';
    $trackingText = '';
    if ($kind === 'shipped' && ($order['tracking_number'] ?? '') !== '') {
        $carrier = trim((string) $order['tracking_carrier']);
        $tracking = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px;"><tr><td bgcolor="#fff4ea" style="background:#fff4ea;border:1px solid #ffd9b8;border-radius:10px;padding:14px 16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#072c5e;">'
            . '<strong>Tracking</strong><br>' . ($carrier !== '' ? escape($carrier) . ': ' : '') . '<strong>' . escape($order['tracking_number']) . '</strong></td></tr></table>';
        $trackingText = 'Tracking: ' . ($carrier !== '' ? $carrier . ' ' : '') . $order['tracking_number'] . "\n\n";
    }
    $support = shopMailSupportLine();
    $body = '<p style="margin:0 0 18px;font-size:16px;line-height:1.65;color:#334155;">' . escape($intro) . '</p>'
        . '<p style="margin:0 0 6px;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;color:#ff7a18;font-weight:700;">Order ' . escape($number) . '</p>'
        . $tracking . shopMailItemsTable($order)
        . '<p style="margin:20px 0 6px;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;color:#ff7a18;font-weight:700;">Delivering to</p>'
        . '<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#334155;">' . shopMailAddressHtml($order) . '</p>'
        . welcomeMailButton('View your order', $url, true) . '<div style="height:18px;line-height:18px;">&nbsp;</div>';
    $text = "Hi $first,\n\n$intro\n\nOrder $number\n$trackingText" . shopMailItemsText($order) . "\nDelivering to:\n" . shopMailAddress($order, "\n") . "\n\nView your order: $url\n"
        . ($support !== '' ? "\nNeed help? $support\n" : '') . "\nThe Pro HPCL team\n";
    $html = shopMailShell($subject, $heading, escape($heading), $body, $support);
    return [$subject, $text, $html];
}

/** Sends the customer email for $kind (see shopCustomerEmail()). Never throws for a mail failure. */
function shopMailOrder(int $orderId, string $kind): bool {
    $order = shopOrderGet($orderId);
    if (!$order || !filter_var($order['email'], FILTER_VALIDATE_EMAIL)) return false;
    [$subject, $text, $html] = shopCustomerEmail($order, $kind);
    return accountSendMail($order['email'], $subject, $text, $html);
}

/** The team's email about a paid order ('paid') or one that needs a decision ('attention'). */
function shopMailAdmin(int $orderId, string $kind): bool {
    $to = shopNotifyAddress();
    $order = shopOrderGet($orderId);
    if ($to === '' || !$order) return false;
    $adminUrl = absoluteUrl('{{BASE}}/admin/shop/orders/' . (int) $order['id']);
    $flags = array_values(array_filter(array_map(fn ($event) => str_starts_with((string) $event['note'], 'NEEDS ATTENTION') ? $event['note'] : null, $order['events'])));
    $subject = ($kind === 'attention' ? '[Needs attention] ' : '') . 'Shop order ' . $order['order_number'] . ' · ' . shopMailMoney($order['total']);
    $notes = trim((string) $order['notes']);
    $body = '<p style="margin:0 0 14px;font-size:16px;line-height:1.6;color:#334155;">'
        . escape($order['name']) . ' (' . escape($order['email']) . ', ' . escape($order['phone']) . ') placed and paid order <strong>' . escape($order['order_number']) . '</strong>.</p>'
        . ($flags ? '<p style="margin:0 0 14px;padding:12px 14px;background:#fff1f0;border:1px solid #ffc9c4;border-radius:10px;font-size:15px;color:#9b1c10;"><strong>' . implode('<br>', array_map('escape', $flags)) . '</strong></p>' : '')
        . shopMailItemsTable($order)
        . ($notes !== '' ? '<p style="margin:16px 0 4px;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;color:#ff7a18;font-weight:700;">Customer note</p><p style="margin:0;font-size:15px;line-height:1.6;color:#334155;">' . nl2br(escape($notes)) . '</p>' : '')
        . '<p style="margin:16px 0 4px;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;color:#ff7a18;font-weight:700;">Ship to</p><p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#334155;">'
        . implode('<br>', array_map('escape', array_filter([$order['name'], $order['address_line1'], $order['address_line2'], $order['city'] . ', ' . $order['state'] . ' ' . $order['pincode'], 'Phone: ' . $order['phone']]))) . '</p>'
        . welcomeMailButton('Open in admin', $adminUrl, true) . '<div style="height:18px;line-height:18px;">&nbsp;</div>';
    $text = "Shop order {$order['order_number']} (" . shopMailMoney($order['total']) . ")\n\n" . ($flags ? implode("\n", $flags) . "\n\n" : '') . shopMailItemsText($order)
        . ($notes !== '' ? "\nCustomer note: $notes\n" : '') . "\nShip to:\n" . shopMailAddress($order, "\n") . "\n\nAdmin: $adminUrl\n";
    return accountSendMail($to, $subject, $text, shopMailShell($subject, 'New paid shop order from ' . $order['name'], 'New shop order', $body, ''));
}
