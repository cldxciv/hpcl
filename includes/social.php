<?php
declare(strict_types=1);

// Instagram reels on the home page: the "Latest from Instagram" section (a row of reel covers;
// a click plays the reel in Instagram's own player inside a dialog). Rows live in social_reels
// (database/social.sql, php bin/migrate-social.php). They come from:
//   - the admin, Admin > Instagram reels (includes/admin-social.php): add a reel by its link,
//     hide, pin or remove it;
//   - the Instagram API sync (includes/instagram-sync.php), once an access token is saved;
//   - a one-off import (bin/import-instagram-reels.php).
// Covers are copied to storage/uploads/social/reels/<shortcode>.jpg: Instagram's image links
// expire after a few days, so the page never points at them.
require_once __DIR__ . '/site.php';

const SOCIAL_REEL_DIR = 'storage/uploads/social/reels';
const SOCIAL_REEL_MAX_COVER_BYTES = 8 * 1024 * 1024;

/** The shortcode in an Instagram post/reel link (instagram.com/reel/<code>/, /reels/, /p/, /tv/), or null. */
function socialReelShortcode(string $url): ?string {
    $url = trim($url);
    if (preg_match('~^[A-Za-z0-9_-]{5,64}$~D', $url)) return $url;
    return preg_match('~^https?://(?:www\.|m\.)?instagram\.com/(?:[A-Za-z0-9_.]+/)?(?:reels?|p|tv)/([A-Za-z0-9_-]{5,64})(?:[/?#]|$)~i', $url, $m) ? $m[1] : null;
}
function socialReelPermalink(string $shortcode): string {
    return 'https://www.instagram.com/reel/' . $shortcode . '/';
}
/**
 * When a post went up on Instagram, read from its shortcode: the shortcode is the media id in
 * base 64, and the id's top bits count milliseconds since Instagram's epoch (2011-08-24 21:07:01.721
 * UTC). Returns local time (Y-m-d H:i:s), or null for a code that doesn't decode to a sane date.
 */
function socialShortcodePostedAt(string $shortcode): ?string {
    if (!preg_match('~^[A-Za-z0-9_-]{6,11}$~D', $shortcode)) return null;
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $id = 0;
    foreach (str_split($shortcode) as $char) {
        $digit = strpos($alphabet, $char);
        if ($id > intdiv(PHP_INT_MAX - $digit, 64)) return null;
        $id = $id * 64 + $digit;
    }
    $seconds = intdiv(($id >> 23) + 1314220021721, 1000);
    if ($seconds < 1314220022 || $seconds > time() + 86400) return null;
    return (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

/** Tidies a caption read from Instagram: trailing "View all comments", runs of blank lines. */
function socialCleanCaption(?string $caption): string {
    $caption = str_replace("\r", '', (string) $caption);
    $caption = preg_replace('~\s*View all \d* ?comments\s*$~i', '', $caption);
    return trim((string) preg_replace("~\n{3,}~", "\n\n", (string) $caption));
}
/** First sentence or line of a caption without hashtags, cut at $max characters, for the card. */
function socialCaptionSnippet(?string $caption, int $max = 90): string {
    $text = socialCleanCaption($caption);
    $text = trim((string) preg_replace('~(?:^|\s)#[\p{L}\p{N}_]+~u', ' ', $text));
    $first = trim((string) strtok($text, "\n"));
    $first = trim((string) preg_replace('~\s+~u', ' ', $first));
    if (mb_strlen($first) <= $max) return $first;
    $cut = mb_substr($first, 0, $max);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-–") . '…';
}

/**
 * Saves a cover image for a reel: checks it really is a JPEG/PNG/WebP, re-encodes it as a JPEG no
 * larger than 720×1280 (dropping anything else in the file) and returns its path, or null.
 */
function socialSaveCover(string $shortcode, string $bytes): ?string {
    if (!preg_match('~^[A-Za-z0-9_-]{5,64}$~D', $shortcode) || $bytes === '' || strlen($bytes) > SOCIAL_REEL_MAX_COVER_BYTES) return null;
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 50 || $info[1] < 50 || $info[0] * $info[1] > 40000000) return null;
    $source = @imagecreatefromstring($bytes);
    if (!$source) return null;
    [$w, $h] = [imagesx($source), imagesy($source)];
    $scale = min(1, 720 / $w, 1280 / $h);
    [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    $canvas = imagecreatetruecolor($nw, $nh);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 11, 42, 91));
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $folder = dirname(__DIR__) . '/' . SOCIAL_REEL_DIR;
    if (!is_dir($folder)) @mkdir($folder, 0755, true);
    $path = SOCIAL_REEL_DIR . '/' . $shortcode . '.jpg';
    $ok = is_dir($folder) && is_writable($folder) && imagejpeg($canvas, dirname(__DIR__) . '/' . $path, 84);
    imagedestroy($canvas);
    imagedestroy($source);
    return $ok ? $path : null;
}

/**
 * Inserts or updates a reel by shortcode and returns its id. $reel: shortcode (required), and any of
 * permalink, caption, cover, media_id, posted_at (Y-m-d H:i:s), source. Visibility and pinning set
 * in the admin are kept; empty values never overwrite stored ones. Without a posted_at, the date
 * comes from the shortcode, so the home page row is newest first however a reel was added.
 */
function socialReelUpsert(array $reel): int {
    $code = socialReelShortcode((string) ($reel['shortcode'] ?? ''));
    if ($code === null) throw new InvalidArgumentException('Not an Instagram reel link.');
    $existing = query("SELECT * FROM social_reels WHERE platform = 'instagram' AND shortcode = ?", [$code])[0] ?? null;
    $value = fn (string $key) => isset($reel[$key]) && trim((string) $reel[$key]) !== '' ? trim((string) $reel[$key]) : null;
    $caption = $value('caption') !== null ? socialCleanCaption($reel['caption']) : null;
    $decoded = socialShortcodePostedAt($code);
    if ($existing) {
        db()->prepare('UPDATE social_reels SET permalink = COALESCE(?, permalink), caption = COALESCE(?, caption), cover = COALESCE(?, cover),
            media_id = COALESCE(?, media_id), posted_at = COALESCE(?, posted_at, ?) WHERE id = ?')
            ->execute([$value('permalink'), $caption, $value('cover'), $value('media_id'), $value('posted_at'), $decoded, $existing['id']]);
        return (int) $existing['id'];
    }
    db()->prepare("INSERT INTO social_reels (platform, shortcode, permalink, caption, cover, media_id, posted_at, source) VALUES ('instagram', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$code, $value('permalink') ?? socialReelPermalink($code), $caption, $value('cover'), $value('media_id'), $value('posted_at') ?? $decoded, $value('source') ?? 'manual']);
    return (int) db()->lastInsertId();
}

/** Visible reels with a cover, pinned first, then newest. Empty when the table doesn't exist yet. */
function socialReelsForHome(int $limit = 12): array {
    try {
        return query('SELECT * FROM social_reels WHERE is_active = 1 AND cover IS NOT NULL AND cover <> \'\'
            ORDER BY pinned DESC, COALESCE(posted_at, created_at) DESC, id DESC LIMIT ' . max(1, min(30, $limit)));
    } catch (PDOException $error) {
        error_log('Instagram reels: ' . $error->getMessage() . ' (run php bin/migrate-social.php)');
        return [];
    }
}

const SOCIAL_INSTAGRAM_GLYPH = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7.75 2h8.5A5.75 5.75 0 0 1 22 7.75v8.5A5.75 5.75 0 0 1 16.25 22h-8.5A5.75 5.75 0 0 1 2 16.25v-8.5A5.75 5.75 0 0 1 7.75 2Zm0 1.8A3.95 3.95 0 0 0 3.8 7.75v8.5A3.95 3.95 0 0 0 7.75 20.2h8.5a3.95 3.95 0 0 0 3.95-3.95v-8.5a3.95 3.95 0 0 0-3.95-3.95Zm8.75 2.4a1.35 1.35 0 1 1 0 2.7 1.35 1.35 0 0 1 0-2.7ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 1.8A3.2 3.2 0 1 0 12 15.2 3.2 3.2 0 0 0 12 8.8Z"/></svg>';

/** The home page section, or '' when it is switched off or there is nothing to show. */
function socialReelsSectionHtml(): string {
    if (setting('social_reels_enabled', '1') !== '1') return '';
    $reels = socialReelsForHome((int) (setting('social_reels_limit', '12') ?: 12));
    if (!$reels) return '';
    $profile = setting('social_instagram') ?: 'https://www.instagram.com/prohpcl/';
    $handle = preg_match('~instagram\.com/([A-Za-z0-9_.]+)~i', $profile, $m) ? '@' . $m[1] : '@prohpcl';
    $title = setting('social_reels_title', 'Latest from Instagram') ?: 'Latest from Instagram';
    $cards = '';
    foreach ($reels as $i => $reel) {
        $snippet = socialCaptionSnippet($reel['caption']);
        $label = $snippet !== '' ? $snippet : 'Pro HPCL reel ' . ($i + 1);
        $cards .= '<li class="ig-reels__item"><a class="ig-reels__card" href="' . escape($reel['permalink']) . '" target="_blank" rel="noopener"'
            . ' data-reel="' . escape($reel['shortcode']) . '" aria-label="Play reel: ' . escape($label) . '">'
            . '<img class="ig-reels__cover" src="' . escape(linkUrl($reel['cover'])) . '" alt="" width="270" height="480" loading="lazy" decoding="async">'
            . '<span class="ig-reels__play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z"/></svg></span>'
            . '<span class="ig-reels__glyph">' . SOCIAL_INSTAGRAM_GLYPH . '</span>'
            . ($snippet !== '' ? '<span class="ig-reels__caption">' . escape($snippet) . '</span>' : '')
            . "</a></li>\n";
    }
    return '<section class="ig-reels" aria-labelledby="ig-reels-title" data-ig-reels>'
        . '<div class="ig-reels__inner"><div class="ig-reels__head"><div>'
        . '<p class="ig-reels__kicker">' . SOCIAL_INSTAGRAM_GLYPH . '<span>' . escape($handle) . '</span></p>'
        . '<h2 class="ig-reels__title" id="ig-reels-title">' . escape($title) . '</h2></div>'
        . '<div class="ig-reels__actions">'
        . '<button type="button" class="ig-reels__nav" data-ig-prev aria-label="Previous reels"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></button>'
        . '<button type="button" class="ig-reels__nav" data-ig-next aria-label="More reels"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></button>'
        . '<a class="ig-reels__follow" href="' . escape($profile) . '" target="_blank" rel="noopener">Follow ' . escape($handle) . '</a></div></div>'
        . '<ul class="ig-reels__track" data-ig-track>' . "\n" . $cards . '</ul></div>'
        . '<dialog class="ig-reels__dialog" data-ig-dialog aria-label="Instagram reel">'
        . '<div class="ig-reels__frame" data-ig-frame></div>'
        . '<div class="ig-reels__dialog-bar"><button type="button" class="ig-reels__dialog-btn" data-ig-step="-1" aria-label="Previous reel"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></button>'
        . '<a class="ig-reels__dialog-open" data-ig-open href="' . escape($profile) . '" target="_blank" rel="noopener">Open on Instagram</a>'
        . '<button type="button" class="ig-reels__dialog-btn" data-ig-step="1" aria-label="Next reel"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></button></div>'
        . '<button type="button" class="ig-reels__close" data-ig-close aria-label="Close">&times;</button></dialog>'
        . '</section>';
}
