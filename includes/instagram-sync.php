<?php
declare(strict_types=1);

// Instagram API sync for the home page reels (includes/social.php). Uses the official
// "Instagram API with Instagram Login": a long-lived access token for the @prohpcl professional
// account, saved encrypted at Admin > Integrations (instagram_access_token).
//
// instagramSync() reads the latest media of the account, keeps the reels, copies each new reel's
// cover to our server (socialSaveCover) and stores it with socialReelUpsert (source 'api'). It runs
// every hour from the server's cron job (bin/sync-instagram.php) and from the "Sync now" button at
// Admin > Instagram reels. The token is renewed every 7 days (long-lived tokens last 60 days).
//
// State kept in site_settings: instagram_last_sync (Y-m-d H:i:s), instagram_last_sync_result
// (short text), instagram_token_refreshed_at (Y-m-d H:i:s, set when a token is saved or renewed).
// The token itself is never logged, printed or shown.
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/social.php';

const INSTAGRAM_GRAPH = 'https://graph.instagram.com';
const INSTAGRAM_MEDIA_FIELDS = 'id,caption,media_type,media_product_type,permalink,thumbnail_url,timestamp';
const INSTAGRAM_PAGE_SIZE = 50;
const INSTAGRAM_REFRESH_AFTER = 7 * 86400;
const INSTAGRAM_JSON_MAX_BYTES = 2 * 1024 * 1024;
// Covers are only fetched from Instagram's own image hosts.
const INSTAGRAM_IMAGE_HOSTS = '~(?:^|\.)(?:cdninstagram\.com|fbcdn\.net)$~i';

function instagramToken(): string {
    return integrationSetting('instagram_access_token');
}
function instagramConfigured(): bool {
    return instagramToken() !== '';
}

/** A sync state value from site_settings ('' when unset); read fresh, not cached. */
function instagramState(string $name): string {
    return (string) (query('SELECT value FROM site_settings WHERE name = ?', [$name])[0]['value'] ?? '');
}
function instagramSaveState(string $name, string $value): void {
    db()->prepare('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$name, $value]);
}

/**
 * GETs an https URL: [HTTP status, body]. Bodies larger than $maxBytes are cut off and refused.
 * Error messages never contain the URL (it carries the access token).
 */
function instagramHttpGet(string $url, int $maxBytes, int $timeout = 20): array {
    if (!str_starts_with($url, 'https://')) throw new RuntimeException('Only https addresses are fetched.');
    $body = '';
    $tooLarge = false;
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'ProHPCL-site/1.0',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json, image/*'],
        CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes) {
            if (strlen($body) + strlen($chunk) > $maxBytes) { $tooLarge = true; return 0; }
            $body .= $chunk;
            return strlen($chunk);
        }]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($curl);
    curl_close($curl);
    if ($tooLarge) throw new RuntimeException('The response was larger than ' . round($maxBytes / 1048576) . ' MB.');
    if ($ok === false) throw new RuntimeException('Couldn’t reach Instagram (network error ' . $errno . ').');
    return [$status, $body];
}

/** A cover image from Instagram's image servers, or null (other hosts are refused, nothing is fetched). */
function instagramFetchImage(string $url): ?string {
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (!str_starts_with($url, 'https://') || !preg_match(INSTAGRAM_IMAGE_HOSTS, $host)) return null;
    try {
        [$status, $body] = instagramHttpGet($url, SOCIAL_REEL_MAX_COVER_BYTES, 25);
    } catch (RuntimeException) {
        return null;
    }
    return $status === 200 && $body !== '' ? $body : null;
}

/** Instagram's ISO 8601 time ("2026-11-17T08:15:30+0000") in the site's time zone, or null. */
function instagramLocalTime(?string $timestamp): ?string {
    if (!is_string($timestamp) || trim($timestamp) === '') return null;
    try {
        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    } catch (Exception) {
        return null;
    }
}

/** Whether a media item from the API is a reel (a video, which is always a reel on Instagram now). */
function instagramIsReel(array $item): bool {
    return ($item['media_product_type'] ?? '') === 'REELS' || ($item['media_type'] ?? '') === 'VIDEO';
}

/**
 * Stores the reels of one page of /me/media. $fetchImage(string $url): ?string returns the bytes
 * of a cover image. A cover is downloaded only for reels that don't have one on disk yet.
 * Returns counts: media (items read), reels, added, updated, covers (saved), skipped (not reels),
 * and errors (short messages).
 */
function instagramProcessMedia(array $payload, callable $fetchImage): array {
    $report = ['media' => 0, 'reels' => 0, 'added' => 0, 'updated' => 0, 'covers' => 0, 'skipped' => 0, 'errors' => []];
    foreach (is_array($payload['data'] ?? null) ? $payload['data'] : [] as $item) {
        if (!is_array($item)) continue;
        $report['media']++;
        if (!instagramIsReel($item)) { $report['skipped']++; continue; }
        $code = socialReelShortcode((string) ($item['permalink'] ?? ''));
        if ($code === null) { $report['errors'][] = 'A reel without a valid link was skipped.'; continue; }
        $report['reels']++;
        $existing = query("SELECT id, cover FROM social_reels WHERE platform = 'instagram' AND shortcode = ?", [$code])[0] ?? null;
        $cover = null;
        $hasCover = $existing && $existing['cover'] && is_file(dirname(__DIR__) . '/' . $existing['cover']);
        if (!$hasCover) {
            $thumbnail = (string) ($item['thumbnail_url'] ?? '');
            $bytes = $thumbnail !== '' ? $fetchImage($thumbnail) : null;
            $cover = is_string($bytes) ? socialSaveCover($code, $bytes) : null;
            if ($cover !== null) $report['covers']++;
            else $report['errors'][] = "No cover could be saved for reel $code.";
        }
        socialReelUpsert(['shortcode' => $code, 'permalink' => socialReelPermalink($code), 'caption' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
            'cover' => $cover, 'media_id' => preg_match('~^\d{1,40}$~', (string) ($item['id'] ?? '')) ? (string) $item['id'] : null,
            'posted_at' => instagramLocalTime($item['timestamp'] ?? null), 'source' => 'api']);
        $existing ? $report['updated']++ : $report['added']++;
    }
    return $report;
}

/** The readable reason for a failed API call; never contains the token. */
function instagramApiError(int $status, ?array $json): string {
    $error = is_array($json['error'] ?? null) ? $json['error'] : [];
    $code = (int) ($error['code'] ?? 0);
    if ($code === 190 || $status === 401) return 'Instagram refused the access token (it may have expired or been revoked). Generate a new one and save it at Admin > Integrations.';
    if (in_array($code, [4, 17, 32, 613], true) || $status === 429) return 'Instagram is limiting requests for now; the next sync will try again.';
    $message = is_string($error['message'] ?? null) ? mb_substr(preg_replace('~\s+~', ' ', $error['message']), 0, 160) : '';
    return 'Instagram answered with an error (HTTP ' . $status . ($code ? ', code ' . $code : '') . ')' . ($message !== '' ? ': ' . $message : '.');
}

/** Whether the token is due for renewal: last renewal (or save) more than 7 days before $now, or unknown. */
function instagramRefreshDue(string $refreshedAt, int $now): bool {
    $time = $refreshedAt !== '' ? strtotime($refreshedAt) : false;
    return $time === false || $now - $time >= INSTAGRAM_REFRESH_AFTER;
}

/**
 * Renews the long-lived token when it is due and stores the new one encrypted. Returns the token to
 * use now. A failed renewal is noted and the current token is kept (it stays valid for 60 days).
 */
function instagramRefreshTokenIfDue(string $token, array &$notes): string {
    $refreshedAt = instagramState('instagram_token_refreshed_at');
    if ($refreshedAt === '') {
        // First run with this token: count from now (Instagram only renews tokens older than 24 hours).
        instagramSaveState('instagram_token_refreshed_at', date('Y-m-d H:i:s'));
        return $token;
    }
    if (!instagramRefreshDue($refreshedAt, time())) return $token;
    try {
        [$status, $body] = instagramHttpGet(INSTAGRAM_GRAPH . '/refresh_access_token?' . http_build_query(['grant_type' => 'ig_refresh_token', 'access_token' => $token]), INSTAGRAM_JSON_MAX_BYTES);
        $json = json_decode($body, true);
        $fresh = is_array($json) && is_string($json['access_token'] ?? null) ? trim($json['access_token']) : '';
        if ($status !== 200 || $fresh === '' || !preg_match('~^\S{20,1024}$~', $fresh)) {
            $notes[] = 'The access token could not be renewed: ' . str_replace($token, '[token]', instagramApiError($status, is_array($json) ? $json : null));
            return $token;
        }
        if (!appKey()) { $notes[] = 'The renewed token could not be saved because APP_KEY is not set.'; return $fresh; }
        instagramSaveState('instagram_access_token', encryptSecret($fresh));
        instagramSaveState('instagram_token_refreshed_at', date('Y-m-d H:i:s'));
        if (is_numeric($json['expires_in'] ?? null)) instagramSaveState('instagram_token_expires_at', date('Y-m-d H:i:s', time() + (int) $json['expires_in']));
        $notes[] = 'Access token renewed.';
        return $fresh;
    } catch (RuntimeException $error) {
        $notes[] = 'The access token could not be renewed: ' . $error->getMessage();
        return $token;
    }
}

/** One line for the admin and the cron log, such as "12 reels checked: 2 new, 10 updated, 2 covers saved." */
function instagramSyncSummary(array $report): string {
    if (!$report['ok']) return 'Sync failed: ' . $report['message'];
    $text = $report['reels'] . ' reel' . ($report['reels'] === 1 ? '' : 's') . ' checked: ' . $report['added'] . ' new, ' . $report['updated'] . ' updated, '
        . $report['covers'] . ' cover' . ($report['covers'] === 1 ? '' : 's') . ' saved.';
    if ($report['errors']) $text .= ' ' . count($report['errors']) . ' problem' . (count($report['errors']) === 1 ? '' : 's') . ': ' . $report['errors'][0];
    foreach ($report['notes'] as $note) $text .= ' ' . $note;
    return mb_substr($text, 0, 480);
}

/**
 * Reads the latest $pages pages (50 media each) of @prohpcl's media and stores the reels.
 * Returns ['ok' => bool, 'message' => string (on failure), 'summary' => string, counts..., 'notes' => [...]].
 */
function instagramSync(int $pages = 1): array {
    $report = ['ok' => false, 'message' => '', 'media' => 0, 'reels' => 0, 'added' => 0, 'updated' => 0, 'covers' => 0, 'skipped' => 0, 'errors' => [], 'notes' => []];
    $token = instagramToken();
    if ($token === '') {
        $report['message'] = 'No Instagram access token is saved (Admin > Integrations).';
        $report['summary'] = instagramSyncSummary($report);
        return $report;
    }
    @set_time_limit(180);
    try {
        $token = instagramRefreshTokenIfDue($token, $report['notes']);
        $url = INSTAGRAM_GRAPH . '/me/media?' . http_build_query(['fields' => INSTAGRAM_MEDIA_FIELDS, 'limit' => INSTAGRAM_PAGE_SIZE, 'access_token' => $token]);
        for ($page = 0; $page < max(1, min(10, $pages)) && $url !== null; $page++) {
            [$status, $body] = instagramHttpGet($url, INSTAGRAM_JSON_MAX_BYTES);
            $json = json_decode($body, true);
            if ($status !== 200 || !is_array($json) || !is_array($json['data'] ?? null)) throw new RuntimeException(instagramApiError($status, is_array($json) ? $json : null));
            $one = instagramProcessMedia($json, 'instagramFetchImage');
            foreach (['media', 'reels', 'added', 'updated', 'covers', 'skipped'] as $key) $report[$key] += $one[$key];
            $report['errors'] = array_merge($report['errors'], $one['errors']);
            $next = $json['paging']['next'] ?? null;
            $url = is_string($next) && str_starts_with($next, INSTAGRAM_GRAPH . '/') ? $next : null;
        }
        $report['ok'] = true;
    } catch (Throwable $error) {
        $report['message'] = str_replace($token, '[token]', $error->getMessage());
        error_log('Instagram sync: ' . $report['message']);
    }
    $report['summary'] = instagramSyncSummary($report);
    instagramSaveState('instagram_last_sync', date('Y-m-d H:i:s'));
    instagramSaveState('instagram_last_sync_result', ($report['ok'] ? 'ok: ' : 'error: ') . $report['summary']);
    return $report;
}
