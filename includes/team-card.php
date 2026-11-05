<?php
declare(strict_types=1);

// Pieces shared by every team card (the season team lists and the home page Teams grid): the logo circle,
// with a branded initials badge for a team that has no logo of its own, and the CSS the badge needs.
require_once __DIR__ . '/site.php';

/** "Royal Rivals Sarkaghat" => "RR"; a short all-capitals first word is kept whole ("HP Police Shimla" => "HP", "TU Matiyana" => "TU"). */
function teamInitials(string $name): string {
    $words = preg_split('~[^\p{L}\p{N}]+~u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) return 'T';
    $first = $words[0];
    if (mb_strlen($first, 'UTF-8') >= 2 && mb_strlen($first, 'UTF-8') <= 3 && $first === mb_strtoupper($first, 'UTF-8') && !ctype_digit($first)) return $first;
    if (count($words) === 1) return mb_strtoupper(mb_substr($first, 0, 2, 'UTF-8'), 'UTF-8');
    $letters = array_map(fn ($word) => mb_substr($word, 0, 1, 'UTF-8'), array_slice($words, 0, 2));
    return mb_strtoupper(implode('', $letters), 'UTF-8');
}

/** The white logo circle of a team card: its logo image, or the initials badge when the team has no logo. */
function teamLogoHtml(array $team): string {
    if (trim((string) ($team['logo'] ?? '')) === '') {
        return '<div class="teams-section__logo-wrap teams-section__logo-wrap--initials" aria-hidden="true"><span class="teams-section__initials">' . escape(teamInitials((string) $team['name'])) . '</span></div>';
    }
    return '<div class="teams-section__logo-wrap"><img class="teams-section__logo" src="' . escape(mediaUrl($team['logo'], '/assets/media/logo.png')) . '" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'{{BASE}}/assets/media/logo.png\';"></div>';
}

/** The CSS of the initials badge and of the muted "not participating" card (inline where a page has no stylesheet of its own for it). */
function teamCardCss(): string {
    return '.teams-section__logo-wrap--initials { box-sizing: border-box; padding: 0; border: 5px solid #ff7a18; }'
        . '.teams-section__initials { font-family: "Bebas Neue", sans-serif; font-weight: 400; font-size: 54px; line-height: 1; letter-spacing: 0.04em; color: #0b2a5b; padding-left: 0.04em; }'
        . '@media (max-width: 768px) { .teams-section__initials { font-size: 44px; } }';
}
