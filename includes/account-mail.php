<?php
declare(strict_types=1);

// The welcome email of a new site user (sent from createAccount() in account-auth.php): a branded
// HTML version for mail apps, built with tables and inline CSS since that is what they support,
// and a plain-text version for the rest. Colours: navy #0b50a7 / #072c5e and orange #ff7a18.

/** One call-to-action button of the email: a table cell, so it renders as a button in Outlook too. */
function welcomeMailButton(string $label, string $url, bool $primary): string {
    $background = $primary ? '#ff7a18' : '#ffffff';
    $color = $primary ? '#072c5e' : '#0b50a7';
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 10px;width:100%;max-width:340px;"><tr>'
        . '<td align="center" bgcolor="' . $background . '" style="border-radius:10px;border:2px solid ' . ($primary ? '#ff7a18' : '#0b50a7') . ';">'
        . '<a href="' . escape($url) . '" style="display:block;padding:14px 22px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;letter-spacing:0.02em;color:' . $color . ';text-decoration:none;">'
        . escape($label) . '</a></td></tr></table>';
}

/** One "what's next" row: a numbered badge, a title and a line of text. */
function welcomeMailStep(int $number, string $title, string $text): string {
    return '<tr><td valign="top" width="44" style="padding:0 0 18px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td align="center" valign="middle" width="34" height="34" bgcolor="#0b50a7" style="width:34px;height:34px;border-radius:17px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;color:#ffffff;">' . $number . '</td></tr></table></td>'
        . '<td valign="top" style="padding:0 0 18px;font-family:Arial,Helvetica,sans-serif;"><div style="font-size:16px;font-weight:700;color:#072c5e;line-height:1.3;">' . escape($title) . '</div>'
        . '<div style="font-size:14px;color:#475569;line-height:1.55;margin-top:2px;">' . escape($text) . '</div></td></tr>';
}

/** [plain text, HTML] of the welcome email for someone whose first name is $first. */
function welcomeEmail(string $first): array {
    $teamUrl = absoluteUrl('{{BASE}}/login?next=' . rawurlencode('/account/team'));
    $fixturesUrl = absoluteUrl('{{BASE}}/fixtures');
    $membershipUrl = absoluteUrl('{{BASE}}/membership');
    $siteUrl = absoluteUrl('{{BASE}}/');
    $logoUrl = absoluteUrl('{{BASE}}/logo-email.jpeg');
    $contact = trim((string) setting('contact_email'));
    $name = escape($first);

    $text = "Hi $first,\n\nWelcome to Pro HPCL, the Himachal Premium Cricket League! Your account is ready.\n\n"
        . "What to do next:\n"
        . "1. Pick your favourite team: $teamUrl\n"
        . "2. See the Season 4 fixtures: $fixturesUrl\n"
        . "3. Explore Pro HPCL membership: $membershipUrl\n\n"
        . "If you didn’t create this account, reply to this email and we’ll sort it out.\n\nThe Pro HPCL team\n$siteUrl\n";

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>Welcome to Pro HPCL</title>'
        . '<style>@media only screen and (max-width:620px){.wm-card{width:100%!important}.wm-pad{padding-left:22px!important;padding-right:22px!important}.wm-h1{font-size:28px!important}}</style></head>'
        . '<body style="margin:0;padding:0;background:#eef3fb;">'
        // The preview line some mail apps show beside the subject.
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#eef3fb;">Your account is ready: pick your favourite team and follow Season 4.&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef3fb"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" class="wm-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(7,44,94,0.14);">'
        // Header: navy with the emblem on a white badge, and an orange stripe under it.
        . '<tr><td align="center" bgcolor="#0b50a7" style="background:#0b50a7;background-image:linear-gradient(135deg,#0b50a7 0%,#072c5e 100%);padding:34px 24px 30px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="#ffffff" style="border-radius:60px;padding:6px;">'
        . '<img src="' . escape($logoUrl) . '" width="108" height="108" alt="Pro HPCL" style="display:block;width:108px;height:108px;border-radius:54px;border:0;"></td></tr></table>'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;letter-spacing:0.22em;text-transform:uppercase;color:#ff9a4d;margin-top:16px;">Himachal Premium Cricket League</div></td></tr>'
        . '<tr><td height="6" bgcolor="#ff7a18" style="height:6px;line-height:6px;font-size:0;background:#ff7a18;">&nbsp;</td></tr>'
        // Welcome.
        . '<tr><td class="wm-pad" style="padding:34px 40px 8px;font-family:Arial,Helvetica,sans-serif;">'
        . '<h1 class="wm-h1" style="margin:0 0 12px;font-size:32px;line-height:1.15;color:#072c5e;font-weight:800;">Welcome to the family, ' . $name . '!</h1>'
        . '<p style="margin:0;font-size:16px;line-height:1.65;color:#334155;">Your Pro HPCL account is ready. Follow the teams, the fixtures and the stories of Himachal’s own cricket league, all in one place.</p></td></tr>'
        . '<tr><td class="wm-pad" align="center" style="padding:24px 40px 6px;">' . welcomeMailButton('Pick your favourite team', $teamUrl, true) . '</td></tr>'
        // What next.
        . '<tr><td class="wm-pad" style="padding:20px 40px 6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e2e8f0;"><tr><td style="padding:22px 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;color:#c2410c;">What to do next</td></tr></table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
        . welcomeMailStep(1, 'Back your team', 'Choose the team you’ll be cheering for. It shows on your account, and you can change it any time.')
        . welcomeMailStep(2, 'Follow Season 4', 'See who plays who, with every group match from 17 November to the final.')
        . welcomeMailStep(3, 'Become a member', 'Silver, Gold, Diamond and Platinum tiers, with business promotion and match-fee discounts.')
        . '</table></td></tr>'
        . '<tr><td class="wm-pad" align="center" style="padding:6px 40px 34px;">' . welcomeMailButton('View fixtures', $fixturesUrl, false) . welcomeMailButton('Explore membership', $membershipUrl, false) . '</td></tr>'
        // Footer.
        . '<tr><td class="wm-pad" bgcolor="#072c5e" align="center" style="background:#072c5e;padding:26px 40px;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="font-size:18px;font-weight:800;letter-spacing:0.06em;color:#ffffff;">PRO <span style="color:#ff7a18;">HPCL</span></div>'
        . '<div style="font-size:13px;line-height:1.7;color:#b8c8e4;margin-top:8px;"><a href="' . escape($siteUrl) . '" style="color:#ffffff;text-decoration:underline;">' . escape(preg_replace('~^https?://|/$~', '', $siteUrl)) . '</a>'
        . ($contact !== '' ? ' &nbsp;·&nbsp; <a href="mailto:' . escape($contact) . '" style="color:#ffffff;text-decoration:underline;">' . escape($contact) . '</a>' : '') . '</div>'
        . '<div style="font-size:12px;line-height:1.6;color:#8fa6cc;margin-top:14px;">You’re getting this email because an account was created on Pro HPCL with this address. If that wasn’t you, just reply to this email and we’ll sort it out.</div>'
        . '</td></tr></table></td></tr></table></body></html>';

    return [$text, $html];
}
