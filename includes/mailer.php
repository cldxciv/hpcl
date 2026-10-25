<?php
declare(strict_types=1);

// Sends email through the SMTP server set at /admin/integrations (smtpConfig() in
// integrations.php). A small SMTP client, since the site has no Composer packages: SSL (port
// 465) or STARTTLS (587), AUTH LOGIN, and a plain text body with an optional HTML version.
require_once __DIR__ . '/integrations.php';

const SMTP_TIMEOUT = 15;

/** Text for a mail header: line breaks removed (no header injection), non-ASCII encoded. */
function mailHeaderText(string $text): string {
    $text = trim(preg_replace('~[\r\n]+~', ' ', $text));
    return preg_match('~[^\x20-\x7e]~', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
}

/** Reads one SMTP reply; throws unless its code is one of $expect. */
function smtpExpect($socket, array $expect, string $step): string {
    $reply = '';
    while (($line = fgets($socket, 1024)) !== false) {
        $reply .= $line;
        // The last line of a reply has a space after the code ("250 OK", not "250-SIZE").
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    if ($reply === '') throw new RuntimeException("The mail server stopped answering ($step).");
    if (!in_array((int) substr($reply, 0, 3), $expect, true)) throw new RuntimeException("The mail server refused $step: " . trim($reply));
    return $reply;
}
function smtpCommand($socket, string $command, array $expect, string $step): string {
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expect, $step);
}

/**
 * Sends an email; throws RuntimeException with the server's reason when it fails. $config is
 * smtpConfig() unless given (the admin's test email uses it to try settings).
 */
function sendMail(string $to, string $subject, string $text, ?string $html = null, ?array $config = null): void {
    $config ??= smtpConfig();
    if (!$config['enabled']) throw new RuntimeException('Sending email is turned off (Admin → Integrations).');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The recipient address isn’t valid.');
    if (!$config['host'] || !filter_var($config['from_address'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Set the SMTP server and the from address first.');

    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $config['host']]]);
    $address = ($config['encryption'] === 'ssl' ? 'ssl://' : 'tcp://') . $config['host'] . ':' . $config['port'];
    $socket = @stream_socket_client($address, $errno, $error, SMTP_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException("Couldn’t connect to {$config['host']}:{$config['port']} ($error). Check the server, port and encryption.");
    stream_set_timeout($socket, SMTP_TIMEOUT);
    try {
        $hostname = parse_url((string) getenv('SITE_URL'), PHP_URL_HOST) ?: (gethostname() ?: 'localhost');
        smtpExpect($socket, [220], 'the connection');
        $features = smtpCommand($socket, "EHLO $hostname", [250], 'EHLO');
        if ($config['encryption'] === 'tls') {
            if (!str_contains(strtoupper($features), 'STARTTLS')) throw new RuntimeException('The mail server doesn’t offer STARTTLS. Try SSL on port 465.');
            smtpCommand($socket, 'STARTTLS', [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('The secure connection (STARTTLS) failed.');
            smtpCommand($socket, "EHLO $hostname", [250], 'EHLO');
        }
        if ($config['username'] !== '') {
            smtpCommand($socket, 'AUTH LOGIN', [334], 'sign-in');
            smtpCommand($socket, base64_encode($config['username']), [334], 'the username');
            smtpCommand($socket, base64_encode($config['password']), [235], 'the username or password');
        }
        smtpCommand($socket, 'MAIL FROM:<' . $config['from_address'] . '>', [250], 'the from address');
        smtpCommand($socket, "RCPT TO:<$to>", [250, 251], 'the recipient');
        smtpCommand($socket, 'DATA', [354], 'the message');

        $headers = ['Date: ' . date('r'), 'From: ' . mailHeaderText($config['from_name']) . ' <' . $config['from_address'] . '>', "To: <$to>",
            'Subject: ' . mailHeaderText($subject), 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . substr(strrchr($config['from_address'], '@'), 1) . '>', 'MIME-Version: 1.0'];
        $part = fn (string $type, string $body) => "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body));
        if ($html === null) $body = $part('text/plain', $text);
        else {
            $boundary = 'b' . bin2hex(random_bytes(12));
            $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
            $body = "\r\n--$boundary\r\n" . $part('text/plain', $text) . "--$boundary\r\n" . $part('text/html', $html) . "--$boundary--\r\n";
        }
        // Base64 lines never start with a dot, so the body needs no dot-stuffing.
        // A plain message's part headers continue the mail headers; a multipart body starts with an empty line.
        fwrite($socket, implode("\r\n", $headers) . "\r\n" . $body . "\r\n.\r\n");
        smtpExpect($socket, [250], 'the message');
        fwrite($socket, "QUIT\r\n");
    } finally {
        fclose($socket);
    }
}
