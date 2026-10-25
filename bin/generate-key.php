<?php
declare(strict_types=1);
// Adds APP_KEY to .env: the key that encrypts the passwords and secret keys saved at
// /admin/integrations. Run it once per site: php bin/generate-key.php. It never replaces an
// existing key, since secrets saved with it couldn't be read any more.
$file = dirname(__DIR__) . '/.env';
$env = is_file($file) ? file_get_contents($file) : '';
if (preg_match('~^\s*APP_KEY\s*=\s*\S~m', $env)) {
    echo "APP_KEY is already set in .env; it was left unchanged.\n";
    exit;
}
$line = 'APP_KEY=base64:' . base64_encode(random_bytes(32));
$env = preg_match('~^\s*APP_KEY\s*=.*$~m', $env) ? preg_replace('~^\s*APP_KEY\s*=.*$~m', $line, $env, 1) : rtrim($env, "\n") . ($env === '' ? '' : "\n\n") . "# Encrypts the secrets saved at /admin/integrations. Keep it; don't share it.\n$line\n";
if (file_put_contents($file, $env) === false) {
    fwrite(STDERR, "Could not write $file.\n");
    exit(1);
}
echo "APP_KEY added to .env.\n";
