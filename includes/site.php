<?php
declare(strict_types=1);

// Shared helpers for the pages rendered from the database.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/view.php';

function replaceInnerHtml(DOMDocument $dom, DOMNode $node, string $html): void {
    while ($node->firstChild) $node->removeChild($node->firstChild);
    $fragment = new DOMDocument();
    $fragment->loadHTML('<?xml encoding="UTF-8"><div id="fragment">' . $html . '</div>');
    $wrapper = (new DOMXPath($fragment))->query('//*[@id="fragment"]')->item(0);
    foreach (iterator_to_array($wrapper->childNodes) as $child) $node->appendChild($dom->importNode($child, true));
}
function loadPage(string $html): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    return [$dom, new DOMXPath($dom)];
}
function savePage(DOMDocument $dom): string {
    return preg_replace('~<\?xml[^>]+>~', '', $dom->saveHTML());
}
/** Local image URL for a stored photo/logo path, or the given placeholder. */
function mediaUrl(?string $path, string $fallback): string {
    if (!$path) return '{{BASE}}' . $fallback;
    return preg_match('~^https?://~', $path) ? $path : '{{BASE}}/' . ltrim($path, '/');
}
function query(string $sql, array $params = []): array {
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

/** Laravel's Str::limit(): cut to $limit display columns and add "...". */
function strLimit(string $value, int $limit): string {
    return mb_strwidth($value, 'UTF-8') <= $limit ? $value : rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')) . '...';
}
function jsonLd(array $data): string {
    return str_replace('</', '<\/', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
/** Whether the request was sent by assets/js/forms.js (AppForm), which asks for JSON. */
function wantsJson(): bool {
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}
/**
 * Answers a submitted public form. $errors are messages by field name, and "form" for one about
 * the whole form. AJAX requests get JSON: {ok, message, errors, redirect}, with status 422 when
 * there are errors, plus $extra (such as the details of a payment to make). Without JavaScript
 * the browser is redirected to $redirect, or $form() (the page with the errors) is shown.
 */
function formResponse(array $errors, string $message, string $redirect, callable $form, array $extra = []): never {
    global $base;
    if (wantsJson()) {
        $formError = $errors['form'] ?? null;
        unset($errors['form']);
        http_response_code($errors || $formError ? 422 : 200);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => !$errors && !$formError, 'message' => $formError ?? $message, 'errors' => (object) $errors,
            'redirect' => $errors || $formError ? null : $base . $redirect] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$errors) {
        header('Location: ' . $base . $redirect, true, 303);
        exit;
    }
    http_response_code(422);
    header('Content-Type: text/html; charset=UTF-8');
    echo render($form());
    exit;
}
/** Whole floats print without decimals, as on prohpcl.com. */
function jsonNumber(float|int|string|null $value): float|int {
    $value = (float) $value;
    return floor($value) === $value ? (int) $value : $value;
}

/**
 * Builds a page from a view in includes/templates/ (an exported page of that type):
 * sets title, description, canonical URL and share image, replaces the inner HTML of the
 * given XPath regions (the element itself with an "outer:" prefix) and swaps the JSON-LD blocks whose @type is a key of $schemas.
 */
function renderLayout(string $layout, array $meta, array $regions, array $schemas = []): string {
    [$dom, $xpath] = loadPage(view('templates.' . basename($layout, '.html')));
    // libxml's serializer entity-encodes UTF-8 inside <script> and skips escaping "&amp;" in
    // attributes, so values go in as tokens and are substituted after saving.
    $values = [];
    $token = function (string $value) use (&$values): string {
        $key = '@@VALUE' . count($values) . '@@';
        $values[$key] = $value;
        return $key;
    };
    $set = function (string $path, string $attribute, ?string $value) use ($xpath, $token) {
        if ($value !== null) foreach ($xpath->query($path) as $node) $node->setAttribute($attribute, $token(escape($value)));
    };
    if (isset($meta['title'])) foreach ($xpath->query('//title') as $node) $node->textContent = $token(escape($meta['title']));
    $set('//meta[@property="og:title" or @name="twitter:title"]', 'content', $meta['title'] ?? null);
    $set('//meta[@name="description" or @property="og:description" or @name="twitter:description"]', 'content', $meta['description'] ?? null);
    $set('//link[@rel="canonical"]', 'href', $meta['url'] ?? null);
    $set('//meta[@property="og:url"]', 'content', $meta['url'] ?? null);
    $set('//meta[@property="og:image" or @property="og:image:secure_url" or @name="twitter:image"]', 'content', $meta['image'] ?? null);
    foreach ($regions as $path => $html) {
        // "outer:" replaces the element itself instead of its contents.
        $outer = str_starts_with($path, 'outer:');
        $node = $xpath->query($outer ? substr($path, 6) : $path)->item(0);
        if (!$node) throw new RuntimeException("Layout $layout has no region $path");
        if ($outer) {
            $node->parentNode->replaceChild($dom->createTextNode($token($html)), $node);
            continue;
        }
        while ($node->firstChild) $node->removeChild($node->firstChild);
        $node->appendChild($dom->createTextNode($token($html)));
    }
    foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
        $type = json_decode($script->textContent, true)['@type'] ?? null;
        if (is_string($type) && array_key_exists($type, $schemas)) $script->textContent = $token(jsonLd($schemas[$type]));
    }
    return strtr(savePage($dom), $values);
}

/** Schema.org event status and registration availability for a season, as on prohpcl.com. */
function seasonSchemaState(array $season): array {
    $now = time();
    $start = $season['start_date'] ? strtotime($season['start_date'] . ' 00:00:00') : null;
    $end = $season['end_date'] ? strtotime($season['end_date'] . ' 23:59:59') : null;
    $registrationStart = $season['registration_starts_at'] ? strtotime($season['registration_starts_at']) : $start;
    $registrationEnd = $season['registration_ends_at'] ? strtotime($season['registration_ends_at']) : $end;
    return [
        'status' => 'https://schema.org/' . ($end && $now > $end ? 'EventCompleted' : ($start && $now >= $start ? 'EventInProgress' : 'EventScheduled')),
        'availability' => 'https://schema.org/' . ($registrationEnd && $now > $registrationEnd ? 'SoldOut' : ($registrationStart && $now < $registrationStart ? 'PreOrder' : 'InStock')),
        'valid_from' => $registrationStart ? date('c', $registrationStart) : null,
        'valid_through' => $registrationEnd ? date('c', $registrationEnd) : null,
    ];
}
function hpPlace(): array {
    return ['@type' => 'Place', 'name' => 'Pro HPCL, Himachal Pradesh', 'address' => ['@type' => 'PostalAddress', 'addressRegion' => 'Himachal Pradesh', 'addressCountry' => 'IN']];
}
/** The SportsEvent block used in team and season JSON-LD. */
function seasonEvent(array $season, string $description, array $images, array|null $performer): array {
    $state = seasonSchemaState($season);
    return ['@type' => 'SportsEvent', 'name' => $season['name'], 'sport' => 'Cricket', 'description' => $description,
        'startDate' => $season['start_date'], 'endDate' => $season['end_date'], 'eventStatus' => $state['status'],
        'url' => '{{BASE}}/teams/season/' . $season['route_key'], 'image' => $images, 'location' => hpPlace(), 'performer' => $performer,
        'organizer' => ['@type' => 'SportsOrganization', 'name' => 'Pro HPCL', 'url' => '{{BASE}}'],
        'offers' => ['@type' => 'Offer', 'url' => '{{BASE}}/player/registration', 'priceCurrency' => 'INR', 'price' => jsonNumber($season['registration_fee'] ?? 0),
            'validFrom' => $state['valid_from'], 'validThrough' => $state['valid_through'], 'availability' => $state['availability']]];
}

/** Items of the layout's automatic breadcrumb (partials/seo/auto_breadcrumbs.blade.php). */
function autoBreadcrumbItems(string $path): array {
    $items = [['label' => 'Home', 'url' => '{{BASE}}']];
    $segments = array_values(array_filter(explode('/', trim($path, '/')), fn ($segment) => $segment !== ''));
    $current = '';
    foreach ($segments as $index => $segment) {
        $current .= '/' . $segment;
        $decoded = urldecode($segment);
        $previous = strtolower($segments[$index - 1] ?? '');
        $label = is_numeric($decoded) && in_array($previous, ['season', 'seasons'], true) ? 'Season ' . $decoded : mb_convert_case(str_replace(['-', '_'], ' ', $decoded), MB_CASE_TITLE, 'UTF-8');
        if ($label !== '') $items[] = ['label' => $label, 'url' => '{{BASE}}' . $current];
    }
    return $items;
}
function autoBreadcrumbHtml(array $items): string {
    $html = '<nav aria-label="breadcrumb" class="my-1"><ol class="breadcrumb team-breadcrumb mb-3">';
    foreach ($items as $index => $item) {
        $html .= $index < count($items) - 1
            ? '<li class="breadcrumb-item"><a href="' . escape($item['url']) . '">' . escape($item['label']) . '</a></li>'
            : '<li class="breadcrumb-item active fw-semibold" aria-current="page">' . escape($item['label']) . '</li>';
    }
    return $html . '</ol></nav>';
}
function autoBreadcrumbSchema(array $items): array {
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn ($item, $index) => ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $item['label'], 'item' => $item['url']], $items, array_keys($items))];
}

/**
 * Laravel's bootstrap-5 pagination view with onEachSide(1). $url builds a page URL. Returns
 * an empty string for a single page.
 */
function paginationHtml(int $page, int $lastPage, int $total, int $perPage, callable $url): string {
    if ($lastPage <= 1) return '';
    $range = fn (int $from, int $to) => range($from, $to);
    // Illuminate\Pagination\UrlWindow with onEachSide = 1.
    if ($lastPage < 10) $elements = [$range(1, $lastPage)];
    elseif ($page <= 5) $elements = [$range(1, 6), '...', $range($lastPage - 1, $lastPage)];
    elseif ($page > $lastPage - 5) $elements = [$range(1, 2), '...', $range($lastPage - 5, $lastPage)];
    else $elements = [$range(1, 2), '...', $range($page - 1, $page + 1), '...', $range($lastPage - 1, $lastPage)];
    $previous = $page > 1;
    $next = $page < $lastPage;
    $html = '<nav class="d-flex justify-items-center justify-content-between"><div class="d-flex justify-content-between flex-fill d-sm-none"><ul class="pagination">'
        . ($previous ? '<li class="page-item"><a class="page-link" href="' . $url($page - 1) . '" rel="prev">&laquo; Previous</a></li>' : '<li class="page-item disabled" aria-disabled="true"><span class="page-link">&laquo; Previous</span></li>')
        . ($next ? '<li class="page-item"><a class="page-link" href="' . $url($page + 1) . '" rel="next">Next &raquo;</a></li>' : '<li class="page-item disabled" aria-disabled="true"><span class="page-link">Next &raquo;</span></li>')
        . '</ul></div><div class="d-none flex-sm-fill d-sm-flex align-items-sm-center justify-content-sm-between"><div><p class="small text-muted">Showing <span class="fw-semibold">' . (($page - 1) * $perPage + 1) . '</span> to <span class="fw-semibold">' . min($total, $page * $perPage) . '</span> of <span class="fw-semibold">' . $total . '</span> results</p></div><div><ul class="pagination">'
        . ($previous ? '<li class="page-item"><a class="page-link" href="' . $url($page - 1) . '" rel="prev" aria-label="&laquo; Previous">&lsaquo;</a></li>' : '<li class="page-item disabled" aria-disabled="true" aria-label="&laquo; Previous"><span class="page-link" aria-hidden="true">&lsaquo;</span></li>');
    foreach ($elements as $element) {
        if (is_string($element)) { $html .= '<li class="page-item disabled" aria-disabled="true"><span class="page-link">' . $element . '</span></li>'; continue; }
        foreach ($element as $number) {
            $html .= $number === $page ? '<li class="page-item active" aria-current="page"><span class="page-link">' . $number . '</span></li>' : '<li class="page-item"><a class="page-link" href="' . $url($number) . '">' . $number . '</a></li>';
        }
    }
    return $html . ($next ? '<li class="page-item"><a class="page-link" href="' . $url($page + 1) . '" rel="next" aria-label="Next &raquo;">&rsaquo;</a></li>' : '<li class="page-item disabled" aria-disabled="true" aria-label="Next &raquo;"><span class="page-link" aria-hidden="true">&rsaquo;</span></li>')
        . '</ul></div></div></nav>';
}

/** Full URL for a {{BASE}} path (share links): SITE_URL when set, else the current host. */
function absoluteUrl(string $path): string {
    global $base;
    $site = rtrim(getenv('SITE_URL') ?: ((($_SERVER['HTTPS'] ?? 'off') !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base), '/');
    return str_replace('{{BASE}}', $site, $path);
}
