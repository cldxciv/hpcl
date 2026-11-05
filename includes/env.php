<?php
declare(strict_types=1);

// Loads KEY=VALUE lines from the project's .env file into getenv(), as Laravel does.
// Variables already set by the server or shell win over the file. Copy .env.example to start.
(function (string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (!preg_match('~^\s*(?:export\s+)?([A-Z_][A-Z0-9_]*)\s*=\s*(.*?)\s*$~i', $line, $m)) continue;
        [, $name, $value] = $m;
        if (preg_match('~^"((?:[^"\\\\]|\\\\.)*)"~', $value, $quoted)) $value = stripcslashes($quoted[1]);
        elseif (preg_match("~^'([^']*)'~", $value, $quoted)) $value = $quoted[1];
        else $value = preg_replace('~\s+#.*$~', '', $value);
        if (getenv($name) !== false) continue;
        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
})(__DIR__ . '/../.env');

/** APP_ENV: "production" (the default when unset) or "local". */
function appEnv(): string {
    return getenv('APP_ENV') ?: 'production';
}
/** APP_DEBUG: show error details in the browser. Defaults to on outside production. */
function appDebug(): bool {
    $debug = getenv('APP_DEBUG');
    return $debug === false || $debug === '' ? appEnv() !== 'production' : filter_var($debug, FILTER_VALIDATE_BOOLEAN);
}
/** Ground booking is public when the admin switched it on (site_settings.booking_enabled = 1) or BOOKING_ENABLED=true in .env. Off by default. */
function bookingEnabled(): bool {
    static $on = null;
    if ($on !== null) return $on;
    if (filter_var(getenv('BOOKING_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)) return $on = true;
    if (!function_exists('query')) return false;
    try {
        return $on = (query("SELECT value FROM site_settings WHERE name = 'booking_enabled'")[0]['value'] ?? '') === '1';
    } catch (Throwable) {
        return $on = false;
    }
}
/** The shop is public when SHOP_ENABLED is on in .env or the admin switched it on (site_settings.shop_enabled = 1). Off by default. */
function shopEnabled(): bool {
    static $on = null;
    if ($on !== null) return $on;
    if (filter_var(getenv('SHOP_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)) return $on = true;
    if (!function_exists('query')) return false;
    try {
        return $on = (query("SELECT value FROM site_settings WHERE name = 'shop_enabled'")[0]['value'] ?? '') === '1';
    } catch (Throwable) {
        return $on = false;
    }
}
