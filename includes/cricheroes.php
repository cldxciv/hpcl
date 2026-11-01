<?php
declare(strict_types=1);

// A player's CricHeroes profile link (players.cricheroes_url). Only https links on cricheroes.in or
// cricheroes.com are accepted, so a typo or a pasted ad link can't end up on a public player page.

/** The cleaned link, or null when it isn't a CricHeroes https link. */
function cricheroesUrl(string $value): ?string {
    $value = trim($value);
    if ($value === '' || strlen($value) > 512 || preg_match('~[\s<>"\']~', $value)) return null;
    $parts = parse_url($value);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) return null;
    return preg_match('~^(?:www\.)?cricheroes\.(?:in|com)$~', $host) ? $value : null;
}
