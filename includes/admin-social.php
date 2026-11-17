<?php
declare(strict_types=1);

// Admin > Instagram reels (/admin/social; permission "settings"): the reels of the home page's
// "Latest from Instagram" section (includes/social.php). Add a reel by its link and a cover image,
// hide, pin, edit or delete reels, set the section's heading and size, and see the Instagram API
// sync (includes/instagram-sync.php) with a "Sync now" button.
require_once __DIR__ . '/admin-forms.php';
require_once __DIR__ . '/social.php';
require_once __DIR__ . '/instagram-sync.php';

const SOCIAL_SETTING_DEFAULTS = ['social_reels_enabled' => '1', 'social_reels_title' => 'Latest from Instagram', 'social_reels_limit' => '12'];
const SOCIAL_SOURCES = ['manual' => 'Added here', 'import' => 'Imported', 'api' => 'Instagram sync'];
const SOCIAL_CAPTION_MAX = 2200;

/** The page to show instead when database/social.sql hasn't been run yet, or null. */
function socialSchemaNotice(array $admin): ?string {
    try {
        query('SELECT shortcode FROM social_reels LIMIT 0');
        return null;
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
        return adminView('templates.admin.social', $admin, '/admin/social', ['content' =>
            '<div class="admin-alert" role="alert"><strong>Instagram reels need a database update.</strong>'
            . '<p>Run <code>php bin/migrate-social.php</code> from the website folder on this server, then reload this page.</p></div>']);
    }
}
function socialSettings(): array {
    $rows = array_column(query("SELECT name, value FROM site_settings WHERE name IN ('social_reels_enabled', 'social_reels_title', 'social_reels_limit')"), 'value', 'name');
    return array_replace(SOCIAL_SETTING_DEFAULTS, array_map('strval', $rows));
}
/** The stored cover's URL with its file time, so a replaced cover isn't served from the browser cache. */
function socialCoverUrl(?string $cover): ?string {
    if (!$cover || !is_file(dirname(__DIR__) . '/' . $cover)) return null;
    return escape(mediaUrl($cover, '')) . '?v=' . dechex((int) filemtime(dirname(__DIR__) . '/' . $cover));
}
function socialReelLabel(array $reel): string {
    $snippet = socialCaptionSnippet($reel['caption'], 80);
    return $snippet !== '' ? $snippet : 'Reel ' . $reel['shortcode'];
}
function socialDate(?string $value): string {
    $time = $value ? strtotime($value) : false;
    return $time ? date('j M Y', $time) : '';
}

function renderAdminSocial(array $admin): string {
    if ($notice = socialSchemaNotice($admin)) return $notice;
    $settings = socialSettings();
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $settings = ['social_reels_enabled' => postedText('social_reels_enabled') === '1' ? '1' : '0', 'social_reels_title' => postedText('social_reels_title'),
            'social_reels_limit' => postedText('social_reels_limit')];
        if ($settings['social_reels_title'] === '') $errors['social_reels_title'] = 'Enter a heading for the section, such as Latest from Instagram.';
        elseif (mb_strlen($settings['social_reels_title']) > 80 || str_contains($settings['social_reels_title'], "\n")) $errors['social_reels_title'] = 'Use one line of 80 characters or fewer.';
        if (!ctype_digit($settings['social_reels_limit']) || (int) $settings['social_reels_limit'] < 4 || (int) $settings['social_reels_limit'] > 24) $errors['social_reels_limit'] = 'Choose a number from 4 to 24.';
        if (!$errors) {
            adminTransaction(function () use ($settings) {
                foreach ($settings as $name => $value) adminExecute('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
            });
            adminFlash('Saved the home page section settings.');
            adminRedirect('/admin/social');
        }
    }

    $total = (int) query('SELECT COUNT(*) AS n FROM social_reels')[0]['n'];
    $rows = query('SELECT * FROM social_reels ORDER BY pinned DESC, COALESCE(posted_at, created_at) DESC, id DESC LIMIT 200');
    $onHome = $settings['social_reels_enabled'] === '1' ? array_map('intval', array_column(socialReelsForHome((int) $settings['social_reels_limit']), 'id')) : [];
    $csrf = '<input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">';

    $html = '<p class="admin-inline-actions"><a class="admin-button" href="{{BASE}}/admin/social/new">Add a reel</a> <a class="admin-button-secondary" href="{{BASE}}/" target="_blank" rel="noopener">View home page</a></p>';
    if (!$rows) {
        $html .= '<div class="admin-panel"><p class="admin-empty">No reels yet. Add one with its Instagram link and a cover image'
            . (instagramConfigured() ? ', or press Sync now below.' : ', or connect the Instagram API so new reels are added by themselves.') . '</p></div>';
    } else {
        $html .= '<div class="admin-panel admin-table-wrap"><table class="admin-table admin-table--reels"><caption class="admin-visually-hidden">Instagram reels, pinned first, then newest</caption>'
            . '<thead><tr><th scope="col">Cover</th><th scope="col">Reel</th><th scope="col">Source</th><th scope="col">Posted</th><th scope="col">Status</th><th scope="col"><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>';
        foreach ($rows as $reel) {
            $id = (int) $reel['id'];
            $label = socialReelLabel($reel);
            $cover = socialCoverUrl($reel['cover']);
            $badges = [];
            if (!$cover) $badges[] = '<span class="admin-badge admin-badge--reel-nocover">No cover: not shown</span>';
            if ((int) $reel['is_active'] !== 1) $badges[] = '<span class="admin-badge admin-badge--reel-hidden">Hidden</span>';
            elseif (in_array($id, $onHome, true)) $badges[] = '<span class="admin-badge admin-badge--reel-home">On home page</span>';
            elseif ($cover) $badges[] = '<span class="admin-badge">Visible</span>';
            if ((int) $reel['pinned'] === 1) $badges[] = '<span class="admin-badge admin-badge--reel-pinned">Pinned</span>';
            $posted = $reel['posted_at'] ? socialDate($reel['posted_at']) : 'Added ' . socialDate($reel['created_at']);
            $named = '<span class="admin-visually-hidden"> reel ' . escape($reel['shortcode']) . '</span>';
            $html .= '<tr' . ((int) $reel['is_active'] !== 1 ? ' class="is-inactive"' : '') . '>'
                . '<td>' . ($cover ? '<img class="admin-reel-thumb" src="' . $cover . '" alt="" width="54" height="96" loading="lazy">' : '<span class="admin-reel-thumb admin-reel-thumb--empty" aria-hidden="true"></span>') . '</td>'
                . '<td class="admin-reel-caption"><a href="{{BASE}}/admin/social/' . $id . '">' . escape($label) . '</a><br><a class="admin-small" href="' . escape($reel['permalink']) . '" target="_blank" rel="noopener">Open on Instagram<span class="admin-visually-hidden"> (' . escape($reel['shortcode']) . ', opens in a new tab)</span></a></td>'
                . '<td>' . escape(SOCIAL_SOURCES[$reel['source']] ?? $reel['source']) . '</td>'
                . '<td>' . escape($posted) . '</td>'
                . '<td><span class="admin-reel-badges">' . implode('', $badges) . '</span></td>'
                . '<td class="admin-actions"><span class="admin-reel-actions"><a href="{{BASE}}/admin/social/' . $id . '">Edit' . $named . '</a>'
                . '<form class="admin-inline-form" method="post" action="{{BASE}}/admin/social/' . $id . '/toggle">' . $csrf . '<button type="submit" class="admin-button-secondary admin-button-small">' . ((int) $reel['is_active'] === 1 ? 'Hide' : 'Show') . $named . '</button></form>'
                . '<form class="admin-inline-form" method="post" action="{{BASE}}/admin/social/' . $id . '/pin">' . $csrf . '<button type="submit" class="admin-button-secondary admin-button-small">' . ((int) $reel['pinned'] === 1 ? 'Unpin' : 'Pin') . $named . '</button></form>'
                . (adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/social/' . $id . '/delete">Delete' . $named . '</a>' : '')
                . '</span></td></tr>';
        }
        $html .= '</tbody></table>' . ($total > count($rows) ? '<p class="admin-hint">Showing the newest ' . count($rows) . ' of ' . $total . ' reels.</p>' : '') . '</div>';
        $html .= '<p class="admin-hint">The home page shows the visible reels that have a cover: pinned reels first, then the newest, up to the number set below.</p>';
    }

    // The home page section.
    $html .= '<section class="admin-settings-section" aria-labelledby="social-section"><div class="admin-settings-section__intro"><h2 id="social-section">Home page section</h2>'
        . '<p>The row of reels on the home page. Visitors play a reel on the page; it opens in Instagram’s own player.</p></div>'
        . formErrorSummary($errors, 'none')
        . '<form class="admin-panel admin-form" method="post" action="{{BASE}}/admin/social">' . $csrf
        . '<div class="admin-field"><p class="admin-field-label">Show on the home page</p><label class="admin-check"><input type="checkbox" name="social_reels_enabled" value="1"' . ($settings['social_reels_enabled'] === '1' ? ' checked' : '') . '>Show the Instagram reels section</label></div>'
        . '<div class="admin-field"><label for="social_reels_title">Heading</label><input id="social_reels_title" name="social_reels_title" type="text" maxlength="80" value="' . escape($settings['social_reels_title']) . '"' . fieldInvalid($errors, 'social_reels_title') . '>' . fieldError($errors, 'social_reels_title') . '</div>'
        . '<div class="admin-field"><label for="social_reels_limit">How many reels</label><input id="social_reels_limit" name="social_reels_limit" type="number" min="4" max="24" class="admin-input-small" value="' . escape($settings['social_reels_limit']) . '"' . fieldInvalid($errors, 'social_reels_limit') . '>'
        . '<p class="admin-hint">From 4 to 24. Visitors scroll sideways through them.</p>' . fieldError($errors, 'social_reels_limit') . '</div>'
        . '<div class="admin-form-actions"><button type="submit">Save section settings</button></div></form></section>';

    // The Instagram API sync.
    $connected = instagramConfigured();
    $lastSync = instagramState('instagram_last_sync');
    $result = instagramState('instagram_last_sync_result');
    $sync = $connected
        ? '<p><span class="admin-status admin-status--ok">Connected to the Instagram API</span></p>'
            . '<p>New reels from @prohpcl are added every hour with their cover and caption, by the server’s scheduled job (<code>bin/sync-instagram.php</code>).</p>'
            . ($lastSync !== '' ? '<p><strong>Last sync:</strong> ' . escape(date('j M Y, g:i A', strtotime($lastSync) ?: time())) . '</p>' : '<p>No sync has run yet.</p>')
            . ($result !== '' ? '<p class="' . (str_starts_with($result, 'error:') ? 'admin-alert' : 'admin-muted') . '">' . escape(preg_replace('~^(ok|error): ~', '', $result)) . '</p>' : '')
            . ($lastSync !== '' && strtotime($lastSync) < time() - 3 * 3600 ? '<p class="admin-hint">The last sync was more than 3 hours ago: check that the hourly cron job is set up in hPanel.</p>' : '')
            . '<form method="post" action="{{BASE}}/admin/social/sync">' . $csrf . '<button type="submit">Sync now</button></form>'
        : '<p><span class="admin-status">Not connected</span></p><p>Without the Instagram API, new reels are added on this page with their link and a cover image. '
            . 'Connected, the site adds them by itself every hour.</p>'
            . (adminCan('root') ? '<p><a class="admin-button-secondary" href="{{BASE}}/admin/integrations#integrations-instagram">Connect Instagram in Integrations</a></p>'
                : '<p class="admin-hint">Ask the root user to add an Instagram access token at Admin → Integrations.</p>');
    $html .= '<section class="admin-settings-section" aria-labelledby="social-sync"><div class="admin-settings-section__intro"><h2 id="social-sync">Instagram sync</h2>'
        . '<p>Reads @prohpcl’s latest reels through Instagram’s official API.</p></div><div class="admin-panel">' . $sync . '</div></section>';
    return adminView('templates.admin.social', $admin, '/admin/social', ['content' => $html]);
}

function renderSocialReelForm(array $admin, ?int $id): string {
    if ($notice = socialSchemaNotice($admin)) return $notice;
    $reel = $id === null ? ['shortcode' => '', 'permalink' => '', 'caption' => '', 'cover' => null, 'is_active' => 1, 'pinned' => 0, 'source' => 'manual']
        : (query('SELECT * FROM social_reels WHERE id = ?', [$id])[0] ?? notFound());
    $errors = [];
    $link = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $reel['caption'] = postedText('caption');
        $reel['is_active'] = postedText('is_active') === '1' ? 1 : 0;
        $reel['pinned'] = postedText('pinned') === '1' ? 1 : 0;
        if ($id === null) {
            $link = postedText('link');
            $code = socialReelShortcode($link);
            if ($link === '') $errors['link'] = 'Paste the reel’s link, such as https://www.instagram.com/reel/AbC123xYz/.';
            elseif ($code === null) $errors['link'] = 'This isn’t an Instagram reel link. Open the reel on Instagram, choose Share > Copy link, and paste it here.';
            elseif (query("SELECT 1 FROM social_reels WHERE platform = 'instagram' AND shortcode = ?", [$code])) $errors['link'] = 'This reel is already on the list. Edit it there to change its cover or caption.';
            else $reel['shortcode'] = $code;
        }
        if (mb_strlen($reel['caption']) > SOCIAL_CAPTION_MAX) $errors['caption'] = 'Use ' . SOCIAL_CAPTION_MAX . ' characters or fewer (Instagram’s own limit).';
        $upload = uploadedImage('cover_upload', 'cover');
        if (is_string($upload)) $errors['cover'] = $upload;
        if (!$errors && is_array($upload)) {
            $saved = socialSaveCover($reel['shortcode'], (string) file_get_contents($upload[0]));
            if ($saved === null) $errors['cover'] = 'The cover could not be saved. Use a JPEG, PNG or WebP image of at least 50 × 50 pixels; if it still fails, ask the site administrator to check that storage/uploads/social/reels is writable.';
            else $reel['cover'] = $saved;
        }
        if (!$errors) {
            if ($id === null) $id = socialReelUpsert(['shortcode' => $reel['shortcode'], 'permalink' => socialReelPermalink($reel['shortcode']), 'source' => 'manual']);
            adminExecute('UPDATE social_reels SET caption = ?, cover = ?, is_active = ?, pinned = ? WHERE id = ?',
                [$reel['caption'] !== '' ? socialCleanCaption($reel['caption']) : null, $reel['cover'] ?: null, $reel['is_active'], $reel['pinned'], $id]);
            adminFlash('Saved the reel.' . (!$reel['cover'] ? ' It will show on the home page once it has a cover.' : ($reel['is_active'] ? '' : ' It is hidden from the home page.')));
            adminRedirect('/admin/social');
        }
    }

    $cover = socialCoverUrl($reel['cover']);
    $synced = instagramConfigured();
    $summary = '';
    if ($errors) {
        $summary = '<div class="admin-alert" role="alert"><strong>The reel was not saved.</strong><ul>';
        foreach (array_unique($errors) as $message) $summary .= '<li>' . escape($message) . '</li>';
        $summary .= '</ul>' . (!isset($errors['cover']) && imageWasChosen('cover_upload') ? '<p>Please choose the cover image again before saving.</p>' : '') . '</div>';
    }
    $html = '<p><a href="{{BASE}}/admin/social">← All reels</a></p><h2>' . ($id === null ? 'Add a reel' : 'Edit reel') . '</h2>' . $summary
        . '<form class="admin-form admin-panel admin-reel-form" method="post" enctype="multipart/form-data" action="{{BASE}}/admin/social/' . ($id === null ? 'new' : $id) . '">'
        . '<input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">';
    if ($id === null) {
        $html .= '<div class="admin-field"><label for="link">Instagram link</label><input id="link" name="link" type="url" inputmode="url" maxlength="255" required placeholder="https://www.instagram.com/reel/…" value="' . escape($link) . '"' . fieldInvalid($errors, 'link') . '>'
            . '<p class="admin-hint">On Instagram, open the reel, choose Share, then Copy link.</p>' . fieldError($errors, 'link') . '</div>';
    } else {
        $html .= '<div class="admin-field"><p class="admin-field-label">Instagram link</p><p><a href="' . escape($reel['permalink']) . '" target="_blank" rel="noopener">' . escape($reel['permalink']) . '<span class="admin-visually-hidden"> (opens in a new tab)</span></a></p>'
            . '<p class="admin-hint">Added by: ' . escape(SOCIAL_SOURCES[$reel['source']] ?? $reel['source']) . ($reel['posted_at'] ? '. Posted on Instagram on ' . escape(socialDate($reel['posted_at'])) : '') . '.</p></div>';
    }
    $html .= '<div class="admin-field"><label for="cover_upload">Cover image' . ($cover ? ' (choose a file to replace it)' : '') . '</label>'
        . ($cover ? '<img class="admin-reel-preview" src="' . $cover . '" alt="Current cover" width="180" height="320">' : '')
        . '<input id="cover_upload" name="cover_upload" type="file" accept="image/png,image/jpeg,image/webp"' . (isset($errors['cover']) ? ' aria-invalid="true"' : '') . ' aria-describedby="cover-hint' . (isset($errors['cover']) ? ' cover-error' : '') . '">'
        . '<p class="admin-hint" id="cover-hint">JPEG, PNG or WebP, up to 2 MB, portrait like the reel (for example 1080 × 1920). The site can’t read a reel’s cover from Instagram by itself, so add one here: '
        . 'a screenshot of the reel’s opening frame works. A reel without a cover is kept but not shown on the home page.'
        . ($synced ? ' Reels found by the Instagram sync get their cover automatically.' : '') . '</p>' . fieldError($errors, 'cover') . '</div>'
        . '<div class="admin-field"><label for="caption">Caption <span class="admin-optional">(optional)</span></label><textarea id="caption" name="caption" rows="5" maxlength="' . SOCIAL_CAPTION_MAX . '"' . fieldInvalid($errors, 'caption') . '>' . escape((string) $reel['caption']) . '</textarea>'
        . '<p class="admin-hint">The first line, without hashtags, is shown on the card and read out to screen reader users.</p>' . fieldError($errors, 'caption') . '</div>'
        . '<div class="admin-field"><p class="admin-field-label">On the home page</p>'
        . '<label class="admin-check"><input type="checkbox" name="is_active" value="1"' . ((int) $reel['is_active'] === 1 ? ' checked' : '') . '>Visible</label>'
        . '<label class="admin-check"><input type="checkbox" name="pinned" value="1"' . ((int) $reel['pinned'] === 1 ? ' checked' : '') . '>Pin to the front of the row</label></div>'
        . '<div class="admin-form-actions"><button type="submit">Save reel</button><a class="admin-button-secondary" href="{{BASE}}/admin/social">Cancel</a>'
        . ($id !== null && adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/social/' . $id . '/delete">Delete reel</a>' : '') . '</div></form>';
    return adminView('templates.admin.social', $admin, '/admin/social', ['content' => $html]);
}

/** POST /admin/social/{id}/toggle (hide/show) and /pin (pin/unpin). */
function socialReelQuickAction(array $admin, int $id, string $action): never {
    $reel = query('SELECT * FROM social_reels WHERE id = ?', [$id])[0] ?? notFound();
    if ($action === 'toggle') {
        adminExecute('UPDATE social_reels SET is_active = 1 - is_active WHERE id = ?', [$id]);
        adminFlash(((int) $reel['is_active'] === 1 ? 'Hid' : 'Showed') . ' the reel “' . socialReelLabel($reel) . '”.');
    } else {
        adminExecute('UPDATE social_reels SET pinned = 1 - pinned WHERE id = ?', [$id]);
        adminFlash(((int) $reel['pinned'] === 1 ? 'Unpinned' : 'Pinned') . ' the reel “' . socialReelLabel($reel) . '”.');
    }
    adminRedirect('/admin/social');
}

function renderSocialReelDelete(array $admin, int $id): string {
    $reel = query('SELECT * FROM social_reels WHERE id = ?', [$id])[0] ?? notFound();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        adminExecute('DELETE FROM social_reels WHERE id = ?', [$id]);
        // The cover file goes too, but only from the reels' own folder.
        $cover = (string) $reel['cover'];
        if (str_starts_with($cover, SOCIAL_REEL_DIR . '/') && !str_contains($cover, '..') && is_file(dirname(__DIR__) . '/' . $cover)) @unlink(dirname(__DIR__) . '/' . $cover);
        adminFlash('Deleted the reel. If the Instagram sync is connected and the reel is still on Instagram, it comes back at the next sync: hide it instead to keep it off the home page.');
        adminRedirect('/admin/social');
    }
    return adminView('templates.admin.social', $admin, '/admin/social', ['content' => '<div class="admin-panel">'
        . deleteConfirmation('reel', socialReelLabel($reel), '/admin/social/' . $id . '/delete', '/admin/social',
            '<p class="admin-hint">This removes the reel and its cover from the website only; it stays on Instagram.</p>') . '</div>']);
}

/** POST /admin/social/sync: runs the Instagram sync now. */
function syncSocialReels(array $admin): never {
    if (!instagramConfigured()) {
        adminFlash('Instagram isn’t connected yet: an access token has to be saved at Admin → Integrations first.');
        adminRedirect('/admin/social');
    }
    $report = instagramSync();
    adminFlash($report['summary']);
    adminRedirect('/admin/social');
}
