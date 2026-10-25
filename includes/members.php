<?php
declare(strict_types=1);

// Public member business directory. /members lists published member profiles (the core benefit
// of membership — a dedicated business profile on the official site); /member/<slug> is one
// profile. Profiles live in the members table, created from approved applications or in the admin.
require_once __DIR__ . '/membership.php';

/** Tier display name for a member's tier key. */
function memberTierName(string $tier): string {
    return MEMBERSHIP_TIERS[$tier]['name'] ?? ucfirst($tier);
}

/** A member's logo, or a coloured monogram when there is none. */
function memberLogoHtml(array $m, string $class): string {
    if (!empty($m['logo'])) {
        return '<img class="' . $class . '" src="' . escape(mediaUrl($m['logo'], '')) . '" alt="' . escape($m['business_name']) . ' logo" loading="lazy" decoding="async">';
    }
    $initial = mb_strtoupper(mb_substr(trim($m['business_name']), 0, 1));
    return '<span class="' . $class . ' member-mono" aria-hidden="true">' . escape($initial) . '</span>';
}

/** /members — the directory, featured and higher tiers first. */
function renderMembersIndex(): string {
    $members = query("SELECT * FROM members WHERE published = 1 ORDER BY featured DESC, FIELD(tier,'platinum','diamond','gold','silver'), position, business_name");
    $cards = '';
    foreach ($members as $m) {
        $tier = strtolower($m['tier']);
        $cards .= '
                <a class="member-card member-card--' . escape($tier) . ($m['featured'] ? ' member-card--featured' : '') . '" href="{{BASE}}/member/' . escape(rawurlencode($m['slug'])) . '">
                    <div class="member-card__logo-wrap">' . memberLogoHtml($m, 'member-card__logo') . '</div>
                    <h3 class="member-card__name">' . escape($m['business_name']) . '</h3>
                    ' . ($m['tagline'] ? '<p class="member-card__tagline">' . escape(strLimit($m['tagline'], 70)) . '</p>' : '') . '
                    <div class="member-card__meta">
                        <span class="member-card__tier">' . escape(memberTierName($tier)) . ' Member</span>
                        ' . ($m['city'] ? '<span class="member-card__city">' . escape($m['city']) . '</span>' : '') . '
                    </div>
                </a>';
    }
    $empty = $members ? '' : '<div class="members-empty"><p>Our member directory is just getting started. Be one of the first businesses featured on the official Pro HPCL website.</p><a class="members-empty__cta" href="{{BASE}}/membership/join">Become a member</a></div>';
    return view('templates.members', [
        'title' => 'Our Members | Pro HPCL',
        'description' => 'Businesses that are part of the Pro HPCL family, featured on the official Himachal Premium Cricket League website.',
        'member_cards' => $cards,
        'empty_state' => $empty,
        'count_label' => $members ? count($members) . ' member' . (count($members) === 1 ? '' : 's') . ' and growing' : 'Join the Pro HPCL family',
    ]);
}

/** /member/<slug> — one business profile. Returns null for a 404. */
function renderMemberPage(string $slug): ?string {
    $m = query('SELECT * FROM members WHERE slug = ? AND published = 1', [rawurldecode($slug)])[0] ?? null;
    if (!$m) return null;
    $tier = strtolower($m['tier']);

    $links = '';
    if (!empty($m['website'])) {
        $url = preg_match('~^https?://~i', $m['website']) ? $m['website'] : 'https://' . $m['website'];
        $links .= '<a class="member-link" href="' . escape($url) . '" target="_blank" rel="noopener nofollow">Visit website</a>';
    }
    foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook'] as $field => $label) {
        if (!empty($m[$field])) {
            $url = preg_match('~^https?://~i', $m[$field]) ? $m[$field] : 'https://' . $m[$field];
            $links .= '<a class="member-link" href="' . escape($url) . '" target="_blank" rel="noopener nofollow">' . $label . '</a>';
        }
    }

    $contact = '';
    if (!empty($m['phone'])) $contact .= '<li><strong>Phone:</strong> ' . phoneLink($m['phone']) . '</li>';
    if (!empty($m['email'])) $contact .= '<li><strong>Email:</strong> <a href="mailto:' . escape($m['email']) . '">' . escape($m['email']) . '</a></li>';
    if (!empty($m['city'])) $contact .= '<li><strong>Location:</strong> ' . escape($m['city']) . '</li>';

    $about = trim((string) $m['about']);
    $aboutHtml = $about !== '' ? implode("\n", array_map(fn ($p) => '<p>' . escape($p) . '</p>', preg_split('~\n{2,}~', $about))) : '';

    return view('templates.member', [
        'title' => escape($m['business_name']) . ' | Pro HPCL Member',
        'description' => escape(strLimit($m['tagline'] ?: ($m['business_name'] . ' — a Pro HPCL member business.'), 160)),
        'canonical' => '{{BASE}}/member/' . escape(rawurlencode($m['slug'])),
        'name' => escape($m['business_name']),
        'tier_name' => escape(memberTierName($tier)),
        'tier_key' => escape($tier),
        'tagline' => $m['tagline'] ? escape($m['tagline']) : '',
        'logo' => memberLogoHtml($m, 'member-profile__logo'),
        'about' => $aboutHtml,
        'about_hidden' => $aboutHtml === '' ? ' hidden' : '',
        'links' => $links,
        'links_hidden' => $links === '' ? ' hidden' : '',
        'contact' => $contact,
        'contact_hidden' => $contact === '' ? ' hidden' : '',
    ]);
}
