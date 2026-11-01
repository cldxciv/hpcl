<?php
// Development server: php -S 127.0.0.1:8080 router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = realpath(__DIR__ . $path);
if ($file && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file)
    && preg_match('~\.(css|js|png|jpe?g|webp|gif|svg|ico|woff2?|ttf|mp4|pdf|webmanifest|xsl)$~i', $file)
    && !preg_match('~^/(pages|includes|database|bin|private)/~', $path)) return false;
// For URLs that look like files (e.g. /thumb/480/x.jpg) the built-in server sets SCRIPT_NAME to the URL itself.
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
