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
        'carousel' => membershipCarouselHtml(),
        'comparison_heads' => $heads,
        'comparison_rows' => $rows,
        'member_notes' => $notes,
    ]);
}

/** A swipeable brand carousel embedded on the membership page. Tier slide is built from
 * MEMBERSHIP_TIERS so it stays in sync; the other slides are fixed promotional copy. */
function membershipCarouselHtml(): string {
    $check = '<svg viewBox="0 0 24 24" fill="none" stroke="#39d98a" stroke-width="2.8"><path d="M20 6 9 17l-5-5"/></svg>';
    $benefitItems = [
        'A business profile on the official Pro HPCL website',
        'Professional, non-AI social media promotion',
        'PR videos, podcasts &amp; reels for your brand',
        'Merchandise, gifts &amp; networking with owners',
        'Graded Shimla Cricket Ground match-fee discount',
        'Recognition at selected Pro HPCL events',
    ];
    $benefits = implode("\n", array_map(fn ($b) => '<li>' . $check . '<span>' . $b . '</span></li>', $benefitItems));

    $tiers = '';
    foreach (MEMBERSHIP_TIERS as $key => $t) {
        $flag = $t['highlight'] ? '<span class="mshipcar-tier__flag">Most popular</span>' : '';
        $tiers .= '<div class="mshipcar-tier mshipcar-tier--' . $key . '">' . $flag
            . '<span class="mshipcar-tier__name">' . escape($t['name']) . '</span>'
            . '<span class="mshipcar-tier__price">' . membershipPriceLabel($t['price']) . '</span>'
            . '<span class="mshipcar-tier__sub">' . escape($t['tagline']) . '</span>'
            . '<span class="mshipcar-tier__disc">' . $t['discount'] . '% off match fees</span></div>';
    }

    $stats = [['4', 'Seasons since 2022'], ['20', 'Franchise teams'], ['300+', 'Players on the field'], ['47', 'Matches last season']];
    $statHtml = implode('', array_map(fn ($s) => '<div class="mshipcar-stat"><b>' . $s[0] . '</b><span>' . $s[1] . '</span></div>', $stats));
    $arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

    return '<section class="mshipcar-wrap" aria-label="Membership highlights">
        <div class="mshipcar" id="mshipcar">
            <div class="mshipcar__track" tabindex="0" aria-label="Membership highlights, 5 slides">
                <article class="mshipcar-slide mshipcar-slide--hook">
                    <span class="mshipcar-wm"><span>PRO</span><b>HPCL</b></span>
                    <div class="mshipcar-col mshipcar-col--half">
                        <p class="mshipcar-kicker">Membership Programme</p>
                        <h3 class="mshipcar-display">Grow your business with Himachal\'s <span>biggest cricket league</span></h3>
                        <p class="mshipcar-lede">Put your brand in front of a cricket-loving Himachal — and back local talent while you do it.</p>
                        <a class="mshipcar-chip" href="{{BASE}}/membership/join">Become a member ' . $arrow . '</a>
                    </div>
                </article>
                <article class="mshipcar-slide mshipcar-slide--why">
                    <span class="mshipcar-wm"><span>PRO</span><b>HPCL</b></span>
                    <div class="mshipcar-col mshipcar-col--full">
                        <p class="mshipcar-kicker">More than a membership</p>
                        <h3 class="mshipcar-display mshipcar-display--sm">What your membership gives you</h3>
                        <ul class="mshipcar-benefits">
' . $benefits . '
                        </ul>
                    </div>
                </article>
                <article class="mshipcar-slide mshipcar-slide--tiers">
                    <span class="mshipcar-wm"><span>PRO</span><b>HPCL</b></span>
                    <div class="mshipcar-col mshipcar-col--full">
                        <p class="mshipcar-kicker">Four annual tiers</p>
                        <h3 class="mshipcar-display mshipcar-display--sm">Choose your tier</h3>
                        <div class="mshipcar-tiers">' . $tiers . '</div>
                    </div>
                </article>
                <article class="mshipcar-slide mshipcar-slide--proof">
                    <span class="mshipcar-wm"><span>PRO</span><b>HPCL</b></span>
                    <div class="mshipcar-col mshipcar-col--full">
                        <p class="mshipcar-kicker">A real audience</p>
                        <h3 class="mshipcar-display mshipcar-display--sm">Your brand on the big stage</h3>
                        <div class="mshipcar-stats">' . $statHtml . '</div>
                    </div>
                </article>
                <article class="mshipcar-slide mshipcar-slide--cta">
                    <span class="mshipcar-wm"><span>PRO</span><b>HPCL</b></span>
                    <div class="mshipcar-col mshipcar-col--center">
                        <p class="mshipcar-kicker">Become part of the family</p>
                        <h3 class="mshipcar-display">Become a Pro HPCL member</h3>
                        <a class="mshipcar-chip" href="{{BASE}}/membership/join">Join now ' . $arrow . '</a>
                    </div>
                </article>
            </div>
            <div class="mshipcar__controls">
                <div class="mshipcar__dots" role="tablist" aria-label="Choose slide"></div>
                <div class="mshipcar__arrows">
                    <button type="button" class="mshipcar__arrow" data-dir="-1" aria-label="Previous slide"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M15 6l-6 6 6 6"/></svg></button>
                    <button type="button" class="mshipcar__arrow" data-dir="1" aria-label="Next slide"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M9 6l6 6-6 6"/></svg></button>
                </div>
            </div>
        </div>
    </section>';
}

/** The membership popup shown on public pages a few seconds after load (partials/membership-popup.html): tier, name, email and mobile number only. */
function membershipPopupHtml(): string {
    $tiers = '';
    foreach (MEMBERSHIP_TIERS as $key => $tier) {
        $tiers .= '<label class="mpop__tier"><input type="radio" name="tier" value="' . $key . '" data-msg-required="Choose a membership tier." required>'
            . '<span class="mpop__tier-card"><span class="mpop__tier-name">' . escape($tier['name']) . '</span>'
            . '<span class="mpop__tier-price">' . membershipPriceLabel($tier['price']) . '<small>/yr</small></span></span></label>';
    }
    return '<div id="mpop" class="mpop" data-base="{{BASE}}" hidden>
    <div class="mpop__backdrop" data-mpop-close></div>
    <div class="mpop__dialog" role="dialog" aria-modal="true" aria-labelledby="mpop-title" tabindex="-1">
        <button type="button" class="mpop__close" data-mpop-close aria-label="Close">&times;</button>
        <div id="mpop-form-wrap">
            <h2 id="mpop-title">Become a Pro HPCL member</h2>
            <p class="mpop__lead">Choose a tier and leave your details. Our team will contact you.</p>
            <form method="post" action="{{BASE}}/membership/quick" data-ajax-form data-loading-text="Sending…" data-success-show="#mpop-thanks" data-success-hide="#mpop-form-wrap">
                <div data-form-alert class="alert alert-danger" role="alert" hidden></div>
                <fieldset class="mpop__fieldset">
                    <legend>Membership tier</legend>
                    <div class="mpop__tiers" data-field-group="tier">' . $tiers . '</div>
                </fieldset>
                <div class="mpop__field">
                    <label for="mpop-name">Your name</label>
                    <input id="mpop-name" name="contact_name" type="text" class="form-control" minlength="3" maxlength="100" autocomplete="name" required
                        data-validate="person-name" data-msg-required="Enter your name." aria-describedby="contact_name-error">
                </div>
                <div class="mpop__row">
                    <div class="mpop__field">
                        <label for="mpop-email">Email</label>
                        <input id="mpop-email" name="email" type="email" class="form-control" maxlength="254" autocomplete="email" required
                            data-msg-required="Enter your email address." aria-describedby="email-error">
                    </div>
                    <div class="mpop__field">
                        <label for="mpop-phone">Mobile number</label>
                        <input id="mpop-phone" name="phone" type="tel" class="form-control" maxlength="16" autocomplete="tel" inputmode="tel" placeholder="98160 12345" required
                            data-validate="phone-in" data-msg-required="Enter your mobile number." data-msg-validate="Enter a valid 10-digit Indian mobile number, like 98160 12345." aria-describedby="phone-error">
                    </div>
                </div>
                <div class="mpop__trap" aria-hidden="true"><label for="mpop-company">Leave this field empty</label><input id="mpop-company" name="company_url" type="text" tabindex="-1" autocomplete="off"></div>
                <button type="submit" class="mpop__submit">Request membership</button>
            </form>
        </div>
        <div id="mpop-thanks" class="mpop__thanks" role="status" hidden>
            <h2>Thank you!</h2>
            <p>Your membership request has been received. Our team will contact you shortly to complete the next steps.</p>
            <button type="button" class="mpop__submit" data-mpop-close>Close</button>
        </div>
    </div>
</div>';
}
