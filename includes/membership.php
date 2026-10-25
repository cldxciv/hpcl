<?php
declare(strict_types=1);

// Pro HPCL Membership Programme: four annual business-membership tiers (Silver, Gold,
// Diamond, Platinum). This file is the single source of truth for the tiers — their prices,
// benefits and the graded Shimla Cricket Ground match-fee discounts — and it renders the
// public /membership page. The join form and payment live in membership-join.php (modelled
// on the committee flow, includes/committee.php) and member business profiles in members.php.
require_once __DIR__ . '/view.php';

// Each tier: display name, one-line positioning, annual fee in rupees, the graded match-fee
// discount at Shimla Cricket Ground, whether it is highlighted as the popular choice, and the
// full benefit list exactly as published. Keep this list the source of truth: the public page,
// the join form and the comparison table all read it.
const MEMBERSHIP_TIERS = [
    'silver' => [
        'name' => 'Silver',
        'tagline' => 'Business Presence & Community',
        'price' => 12000,
        'discount' => 5,
        'highlight' => false,
        'benefits' => [
            'Dedicated business profile on the official Pro HPCL website.',
            '2 professional, non-AI social media promotional posts per year.',
            'Participation in regular member meetings.',
            '5% discount on eligible Shimla Cricket Ground match fees and charges.',
            'Discounts on eligible Pro HPCL and DA Sports merchandise.',
            'Eligibility for selected member giveaways and rewards.',
            'Networking opportunities with other members.',
        ],
    ],
    'gold' => [
        'name' => 'Gold',
        'tagline' => 'Business Growth & Visibility',
        'price' => 25000,
        'discount' => 10,
        'highlight' => false,
        'benefits' => [
            'All Silver membership benefits.',
            '6 professional, non-AI social media promotional posts per year.',
            'Enhanced business showcase with promotional highlights.',
            '1 professionally produced business or personal PR video per year.',
            '1 Pro HPCL podcast or interview per year.',
            'Selected brand mentions during livestreams, subject to availability.',
            'Annual Pro HPCL merchandise kit.',
            'Invitations to selected networking and planning meetings.',
            'Opportunities for recognition at major Pro HPCL events.',
            '10% discount on eligible Shimla Cricket Ground match fees and charges.',
        ],
    ],
    'diamond' => [
        'name' => 'Diamond',
        'tagline' => 'Premium Branding & Personal PR',
        'price' => 50000,
        'discount' => 12,
        'highlight' => true,
        'benefits' => [
            'All Gold membership benefits.',
            '12 professional, non-AI social media promotional posts per year.',
            'Premium business profile and featured website showcase.',
            '3 professionally produced business or personal PR videos per year.',
            '2 podcast episodes or professional interviews per year.',
            '6 short promotional reels per year.',
            'Priority opportunities for selected livestream promotions.',
            'Premium merchandise package, including selected apparel and accessories.',
            'Invitations to designated core advisory and league-improvement meetings.',
            'Priority recognition and invitations at selected major events.',
            'Additional year-round member gifts and rewards.',
            '12% discount on eligible Shimla Cricket Ground match fees and charges.',
        ],
    ],
    'platinum' => [
        'name' => 'Platinum',
        'tagline' => 'Elite Business & Strategic Partnership',
        'price' => 100000,
        'discount' => 15,
        'highlight' => false,
        'benefits' => [
            'All Diamond membership benefits.',
            '24 professional, non-AI social media promotional posts per year.',
            'Premium business showcase and a dedicated promotional campaign.',
            '6 professionally produced business or personal PR videos per year.',
            '4 podcast episodes or professional interviews per year.',
            '12 short promotional reels per year.',
            'Enhanced livestream promotional package, subject to broadcast inventory.',
            'Premium merchandise and planned gifts throughout the year.',
            'Participation in designated core meetings and strategic advisory discussions.',
            'Premium recognition and invitations at major Pro HPCL events.',
            'Enhanced networking with franchise owners, sponsors and business leaders.',
            'Priority consideration for selected event activations and brand collaborations.',
            'Annual promotional planning meeting to discuss brand visibility and objectives.',
            '15% discount on eligible Shimla Cricket Ground match fees and charges.',
        ],
    ],
];

// The comparison-at-a-glance matrix: each row is a benefit with its value per tier, in the
// tier order of MEMBERSHIP_TIERS. "—" means the tier does not include it.
const MEMBERSHIP_COMPARISON = [
    'Annual fee' => ['₹12,000', '₹25,000', '₹50,000', '₹1,00,000'],
    'Website business profile' => ['Yes', 'Yes', 'Featured', 'Premium'],
    'Non-AI social media posts / year' => ['2', '6', '12', '24'],
    'Professional PR videos / year' => ['—', '1', '3', '6'],
    'Podcasts / interviews per year' => ['—', '1', '2', '4'],
    'Promotional reels / year' => ['—', '—', '6', '12'],
    'Merchandise' => ['Discounts', 'Annual kit', 'Premium kit', 'Elite kit + gifts'],
    'Meeting participation' => ['Member meetings', 'Selected meetings', 'Advisory invitations', 'Designated strategic meetings'],
    'Livestream promotion' => ['Eligible opportunities', 'Selected mentions', 'Priority package', 'Enhanced package'],
    'Shimla Cricket Ground match-fee discount' => ['5%', '10%', '12%', '15%'],
];

// "What members should know" — the terms shown under the comparison table.
const MEMBERSHIP_NOTES = [
    ['Real business promotion', 'Members receive a dedicated profile on the Pro HPCL website, plus the specified number of social media posts.'],
    ['Professional content production', "Videos, reels and podcasts can promote the member's business, personal journey, expertise or brand. Production quality, duration, revisions and publishing schedule are agreed in advance."],
    ['Decision-making participation', 'Members can contribute suggestions to improvement initiatives. Diamond and Platinum members receive defined advisory opportunities; final league decisions remain with management.'],
    ['Year-round merchandise and gifts', "Hoodies, T-shirts, track suits, caps, hats and other rewards are included according to each tier's package and published delivery schedule."],
    ['Business networking', 'Members can connect with entrepreneurs, franchise owners, sponsors and other stakeholders through selected meetings and events.'],
    ['Ground discount', 'The graded discount applies to eligible Shimla Cricket Ground match fees and charges, subject to the booking terms.'],
];

/** A valid tier key, or null. */
function membershipTier(string $key): ?array {
    return MEMBERSHIP_TIERS[$key] ?? null;
}
/** '₹12,000' from 12000. */
function membershipPriceLabel(int $price): string {
    // Indian digit grouping (1,00,000), which number_format does not do.
    $s = (string) $price;
    if (strlen($s) <= 3) return '₹' . $s;
    $last3 = substr($s, -3);
    $rest = substr($s, 0, -3);
    $rest = preg_replace('~\B(?=(\d{2})+(?!\d))~', ',', $rest);
    return '₹' . $rest . ',' . $last3;
}

/** The public /membership page. */
function renderMembershipPage(): string {
    // Tier cards.
    $cards = '';
    foreach (MEMBERSHIP_TIERS as $key => $tier) {
        $benefits = implode("\n", array_map(
            fn ($b) => '                        <li>' . escape($b) . '</li>', $tier['benefits']));
        $badge = $tier['highlight'] ? '<span class="mship-card__badge">Most popular</span>' : '';
        $cards .= '
                <article class="mship-card mship-card--' . $key . ($tier['highlight'] ? ' mship-card--featured' : '') . '">
                    ' . $badge . '
                    <header class="mship-card__head">
                        <h3 class="mship-card__name">' . escape($tier['name']) . '</h3>
                        <p class="mship-card__tagline">' . escape($tier['tagline']) . '</p>
                        <p class="mship-card__price">' . membershipPriceLabel($tier['price']) . '<span>/year</span></p>
                        <p class="mship-card__discount">' . $tier['discount'] . '% off ground match fees</p>
                    </header>
                    <ul class="mship-card__benefits">
' . $benefits . '
                    </ul>
                    <a class="mship-card__cta" href="{{BASE}}/membership/join?tier=' . $key . '">Join ' . escape($tier['name']) . '</a>
                </article>';
    }

    // Comparison table.
    $heads = '';
    foreach (MEMBERSHIP_TIERS as $tier) $heads .= '<th scope="col">' . escape($tier['name']) . '</th>';
    $rows = '';
    foreach (MEMBERSHIP_COMPARISON as $label => $values) {
        $cells = '';
        foreach ($values as $v) $cells .= '<td' . ($v === '—' ? ' class="mship-table__empty"' : '') . '>' . escape($v) . '</td>';
        $rows .= '
                        <tr><th scope="row">' . escape($label) . '</th>' . $cells . '</tr>';
    }

    // Member notes.
    $notes = '';
    foreach (MEMBERSHIP_NOTES as [$heading, $body]) {
        $notes .= '
                    <div class="mship-note"><h3>' . escape($heading) . '</h3><p>' . escape($body) . '</p></div>';
    }

    return view('templates.membership', [
        'title' => 'Membership Programme | Pro HPCL',
        'description' => 'Join the Pro HPCL Membership Programme: four annual tiers — Silver, Gold, Diamond and Platinum — with business promotion, professional content, networking, merchandise and graded Shimla Cricket Ground match-fee discounts.',
        'tier_cards' => $cards,
        'comparison_heads' => $heads,
        'comparison_rows' => $rows,
        'member_notes' => $notes,
    ]);
}
