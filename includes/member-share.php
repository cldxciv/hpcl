<?php
declare(strict_types=1);

// What a member gets once their membership is complete (paid, or approved by the team): a public
// profile at /member/<slug>, a social-media creative (member-creative.php) and a private share-kit
// page at /membership/share/<token> with the picture to download and buttons to share their
// profile link on every major platform. The token in the page's address is the member's key: it is
// sent to them by email and shown after payment.
require_once __DIR__ . '/membership.php';
require_once __DIR__ . '/member-creative.php';

/** A unique members.slug from a business name, optionally excluding one member id. */
function memberSlug(string $name, ?int $excludeId = null): string {
    $base = trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($name)), '-') ?: 'member';
    $taken = array_column(query('SELECT slug FROM members WHERE (slug = ? OR slug LIKE ?)' . ($excludeId ? ' AND id <> ?' : ''),
        $excludeId ? [$base, likePattern($base . '-'), $excludeId] : [$base, likePattern($base . '-')]), 'slug', 'slug');
    for ($n = 1, $slug = $base; isset($taken[$slug]); $slug = $base . '-' . ++$n);
    return $slug;
}

function memberProfileUrl(array $member): string {
    return absoluteUrl('{{BASE}}/member/' . rawurlencode((string) $member['slug']));
}
function membershipShareUrl(string $token): string {
    return absoluteUrl('{{BASE}}/membership/share/' . $token);
}
/** The member's creative as a link on this site; the key changes with the profile so shared previews refresh. */
function memberCreativeUrl(array $member, bool $download = false): string {
    return '{{BASE}}/member/' . rawurlencode((string) $member['slug']) . '/creative.png?' . ($download ? 'download=1&' : '') . 'v=' . memberCreativeKey($member);
}

/**
 * Completes a membership: makes sure the application has a share-kit token and a published member
 * profile (made from the application: name, tier, city, website, contact, photo; the applicant's
 * private message is not copied), and approves a pending application. A profile the team has
 * unpublished stays unpublished. Returns [application, member], or null for an unknown application.
 */
function membershipActivateMember(int $applicationId): ?array {
    $app = query('SELECT * FROM membership_applications WHERE id = ?', [$applicationId])[0] ?? null;
    if (!$app) return null;
    if (!$app['share_token']) {
        db()->prepare('UPDATE membership_applications SET share_token = ? WHERE id = ? AND share_token IS NULL')->execute([bin2hex(random_bytes(16)), $applicationId]);
        $app = query('SELECT * FROM membership_applications WHERE id = ?', [$applicationId])[0];
    }
    $member = query('SELECT * FROM members WHERE application_id = ?', [$applicationId])[0] ?? null;
    if (!$member) {
        db()->prepare('INSERT INTO members (slug, business_name, tier, photo, website, phone, email, city, published, application_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())')
            ->execute([memberSlug($app['business_name']), $app['business_name'], $app['tier'], $app['photo'] ?: null, $app['website'] ?: null, $app['phone'], $app['email'], $app['city'] ?: null, $applicationId]);
        $member = query('SELECT * FROM members WHERE id = ?', [(int) db()->lastInsertId()])[0];
    } elseif (!$member['photo'] && $app['photo']) {
        db()->prepare('UPDATE members SET photo = ?, updated_at = NOW() WHERE id = ?')->execute([$app['photo'], $member['id']]);
        $member['photo'] = $app['photo'];
    }
    db()->prepare("UPDATE membership_applications SET status = 'approved', reviewed_at = NOW() WHERE id = ? AND status = 'pending'")->execute([$applicationId]);
    return [$app, $member];
}

/** The text a member posts with their creative. */
function memberShareCaption(array $member): string {
    $tier = MEMBERSHIP_TIERS[strtolower((string) $member['tier'])]['name'] ?? ucfirst((string) $member['tier']);
    return 'Proud to be a ' . $tier . ' member of Pro HPCL, the Himachal Premium Cricket League! 🏏 See our business profile: ' . memberProfileUrl($member);
}

/** The email that gives a member their share kit. Returns whether it was sent (or logged, outside production). */
function membershipSendShareKit(array $app, array $member): bool {
    $first = trim(explode(' ', (string) $app['contact_name'])[0]);
    $subject = 'Your Pro HPCL membership is active: share it with your network';
    $text = "Hi $first,\n\nWelcome to the Pro HPCL family! Your " . (MEMBERSHIP_TIERS[$app['tier']]['name'] ?? ucfirst($app['tier'])) . " membership for {$app['business_name']} is active.\n\n"
        . "Your public profile on the official website:\n" . memberProfileUrl($member) . "\n\n"
        . "Your share kit has a ready-made picture for Instagram, Facebook, WhatsApp, LinkedIn and X, and one-tap share buttons:\n" . membershipShareUrl($app['share_token']) . "\n\n"
        . "Keep that link to yourself: it is your own page.\n\nPro HPCL";
    if (appEnv() !== 'production') {
        file_put_contents(__DIR__ . '/../private/mail.log', '[' . date('c') . "] To: {$app['email']}\nSubject: $subject\n\n$text\n" . str_repeat('-', 60) . "\n", FILE_APPEND | LOCK_EX);
        return true;
    }
    require_once __DIR__ . '/mailer.php';
    try {
        sendMail($app['email'], $subject, $text);
        return true;
    } catch (RuntimeException $error) {
        error_log('Share kit email to ' . json_encode($app['email']) . ' failed: ' . $error->getMessage());
        return false;
    }
}

/** /membership/share/<token>: the member's share kit. Returns null (a 404) for an unknown or unpublished kit. */
function renderMembershipSharePage(string $token): ?string {
    if (!preg_match('~^[a-f0-9]{32}$~', $token)) return null;
    $app = query('SELECT * FROM membership_applications WHERE share_token = ?', [$token])[0] ?? null;
    $member = $app ? (query('SELECT * FROM members WHERE application_id = ? AND published = 1', [$app['id']])[0] ?? null) : null;
    if (!$member) return null;
    $profile = memberProfileUrl($member);
    $caption = memberShareCaption($member);
    $enc = fn (string $value) => rawurlencode($value);
    $short = 'Proud to be a Pro HPCL member! 🏏';
    $share = [
        'WhatsApp' => 'https://wa.me/?text=' . $enc($caption),
        'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $enc($profile),
        'X' => 'https://twitter.com/intent/tweet?text=' . $enc($short) . '&url=' . $enc($profile),
        'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $enc($profile),
        'Telegram' => 'https://t.me/share/url?url=' . $enc($profile) . '&text=' . $enc($short),
        'Email' => 'mailto:?subject=' . $enc('I am a Pro HPCL member') . '&body=' . $enc($caption),
    ];
    $buttons = implode('', array_map(fn ($label, $href) => '<a class="mshare-btn" href="' . escape($href) . '" target="_blank" rel="noopener">' . $label . '<span class="visually-hidden"> (opens in a new tab)</span></a>', array_keys($share), $share));
    return view('templates.member-share', [
        'title' => 'Share your Pro HPCL membership',
        'name' => escape($member['business_name']),
        'creative' => memberCreativeUrl($member),
        'creative_download' => memberCreativeUrl($member, true),
        'profile_url' => escape($profile),
        'profile_path' => '{{BASE}}/member/' . escape(rawurlencode((string) $member['slug'])),
        'caption' => escape($caption),
        'share_buttons' => $buttons,
    ]);
}
