<?php
declare(strict_types=1);

// Error pages in the site's design (includes/templates/error.html). They never show technical
// details; the error itself is written to the PHP error log, and shown only with APP_DEBUG on.
require_once __DIR__ . '/view.php';

const ERROR_PAGES = [
    400 => ['Invalid link', 'The link you followed isn\'t quite right. Please check it, or start again from the home page.'],
    404 => ['Page not found', 'Sorry, we couldn\'t find that page. It may have moved, or the link may be out of date.'],
    405 => ['Not available', 'This website can\'t accept that request. To get in touch with us, please use the contact page.'],
    500 => ['Something went wrong', 'We\'re having trouble loading this page right now. Please try again in a few minutes.'],
];

/** Sends the error page for an HTTP status (400, 404, 405 or 500) and stops. */
function errorPage(int $status): never {
    if (!isset(ERROR_PAGES[$status])) $status = 500;
    [$heading, $message] = ERROR_PAGES[$status];
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        if ($status === 405) header('Allow: GET, HEAD');
    }

    $button = fn (string $url, string $label, string $style) => '<a class="btn btn-' . $style . '" href="' . $url . '">' . $label . '</a>';
    $actions = $button('{{BASE}}/', 'Go to home page', 'primary') . "\n" . match ($status) {
        500 => $button('', 'Try again', 'outline-primary'),
        405 => $button('{{BASE}}/contact', 'Contact us', 'outline-primary'),
        default => '<button type="button" class="btn btn-outline-primary" onclick="history.back()">Go back</button>',
    };
    $suggestions = '';
    if ($status === 404 || $status === 400) {
        $links = ['Teams' => '/teams', 'Stats' => '/stats', 'Players' => '/players/search', 'Videos' => '/videos', 'Blog' => '/blogs'];
        $suggestions = '<div class="error-links"><p>Popular pages</p><ul>'
            . implode('', array_map(fn ($label, $path) => '<li><a href="{{BASE}}' . $path . '">' . $label . '</a></li>', array_keys($links), $links)) . '</ul></div>';
    }
    $data = ['code' => $status, 'heading' => escape($heading), 'message' => escape($message), 'actions' => $actions, 'suggestions' => $suggestions, 'year' => date('Y')];
    // Errors raised before includes/content.php is loaded have no header data.
    if (!viewComposer('partials.header')) $data['shop_nav'] = '';

    // A server error is often the database being unavailable, and the full footer reads from the
    // database, so 500 uses the lighter layout without it; the others fall back to it if needed.
    $html = null;
    if ($status < 500) {
        try {
            $html = view('templates.error', $data);
        } catch (Throwable $error) {
            error_log((string) $error);
        }
    }
    $html ??= view('templates.error-minimal', $data);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo render($html);
    exit;
}

/** Error details for APP_DEBUG: the exception and its stack trace. */
function debugErrorPage(Throwable|array $error): never {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $text = $error instanceof Throwable ? (string) $error : "{$error['message']} in {$error['file']}:{$error['line']}";
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Error | Pro HPCL (debug)</title>'
        . '<body style="margin:0;background:#0f172a;color:#e2e8f0"><main style="max-width:1100px;margin:0 auto;padding:32px 20px;font:14px/1.6 ui-monospace,monospace">'
        . '<p style="color:#fd6f3b;font-weight:700">APP_DEBUG is on: this page is only shown in development.</p>'
        . '<pre style="white-space:pre-wrap">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre></main></body></html>';
    exit;
}
