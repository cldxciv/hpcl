<?php
declare(strict_types=1);
require_once __DIR__ . '/admin-forms.php';

require_once __DIR__ . '/hero.php';

/** Report an outstanding deployment migration instead of failing when a slide is saved. */
function heroSchemaNotice(array $admin): ?string {
    try {
        query('SELECT options FROM home_slides LIMIT 0');
        return null;
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1054) throw $error;
        error_log('Hero slider: missing home_slides.options column. Run php bin/upgrade-hero.php.');
        return adminView('templates.admin.hero', $admin, '/admin/hero', ['content' =>
            '<div class="admin-alert" role="alert"><strong>The hero slider needs a database update.</strong>'
            . '<p>Run <code>php bin/upgrade-hero.php</code> from the website folder on this server, then reload this page. Existing slides and images will be preserved.</p></div>']);
    }
}

function heroField(string $key, string $label, string $value, array $errors = [], string $type = 'text'): string {
    return '<div class="admin-field"><label for="' . $key . '">' . escape($label) . '</label><input type="' . $type . '" id="' . $key . '" name="' . $key . '" value="' . escape($value) . '" maxlength="1000"' . fieldInvalid($errors, $key) . '>' . fieldError($errors, $key) . '</div>';
}
function heroSelect(string $key, string $label, string $value, array $choices): string {
    $html = '<div class="admin-field"><label for="' . $key . '">' . escape($label) . '</label><select id="' . $key . '" name="' . $key . '">';
    foreach ($choices as $v => $name) $html .= '<option value="' . escape((string) $v) . '"' . ((string) $v === $value ? ' selected' : '') . '>' . escape($name) . '</option>';
    return $html . '</select></div>';
}
function heroPathValid(string $value): bool {
    return $value === '' || (strlen($value) <= 512 && (preg_match('~^https?://~i', $value) ? (bool) filter_var($value, FILTER_VALIDATE_URL) : (bool) preg_match('~^(?:/?[a-zA-Z0-9_-]+)(?:/[a-zA-Z0-9_. -]+)*$~D', $value) && !str_contains($value, '..')));
}
function renderAdminHero(array $admin): string {
    if ($notice = heroSchemaNotice($admin)) return $notice;
    $error = '';
    $stored = array_column(query("SELECT name, value FROM site_settings WHERE name LIKE 'hero_%'"), 'value', 'name');
    $config = ['hero_autoplay' => '1', 'hero_interval' => '3000', 'hero_pause' => '1', 'hero_wrap' => '1', 'hero_controls' => '1', 'hero_indicators' => '0', 'hero_fade' => '0'];
    $config = array_replace($config, array_intersect_key($stored, $config));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach ($config as $key => $value) $config[$key] = postedText($key);
        if (!ctype_digit($config['hero_interval']) || (int) $config['hero_interval'] < 1000 || (int) $config['hero_interval'] > 60000) $error = '<p class="admin-alert">Slide duration must be between 1000 and 60000 milliseconds.</p>';
        foreach ($config as $key => $value) if ($key !== 'hero_interval') $config[$key] = $value === '1' ? '1' : '0';
        if (!$error) {
            adminTransaction(function () use ($config) {
                foreach ($config as $key => $value) adminExecute('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $value]);
            });
            adminFlash('Saved carousel options.'); adminRedirect('/admin/hero');
        }
    }
    $html = '<p><a class="admin-button" href="{{BASE}}/admin/hero/new">Add slide</a> <a class="admin-button-secondary" href="{{BASE}}/" target="_blank" rel="noopener">View home page</a></p><div class="admin-panel admin-table-wrap"><table class="admin-table"><thead><tr><th>Preview</th><th>Heading</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    foreach (query('SELECT * FROM home_slides ORDER BY position, id') as $slide) {
        $o = heroOptions($slide);
        $html .= '<tr><td>' . adminImageHtml($slide['image']) . '</td><td>' . escape($slide['title'] ?: 'Image slide #' . $slide['id']) . '</td><td>' . (int) $slide['position'] . '</td><td>' . ($o['enabled'] === '1' ? 'Visible' : 'Hidden') . '</td><td><a href="{{BASE}}/admin/hero/' . $slide['id'] . '">Edit</a>' . (adminCan('delete') ? ' · <a class="admin-danger-link" href="{{BASE}}/admin/hero/' . $slide['id'] . '/delete">Delete</a>' : '') . '</td></tr>';
    }
    $html .= '</tbody></table></div>' . $error . '<h2>Carousel options</h2><form class="admin-form admin-panel" method="post"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">';
    foreach (['hero_autoplay' => 'Autoplay', 'hero_pause' => 'Pause on hover', 'hero_wrap' => 'Loop slides', 'hero_controls' => 'Show arrows', 'hero_indicators' => 'Show navigation dots', 'hero_fade' => 'Fade transition'] as $key => $label) $html .= heroSelect($key, $label, $config[$key], ['1' => 'Yes', '0' => 'No']);
    $html .= heroField('hero_interval', 'Default slide duration (milliseconds)', $config['hero_interval'], [], 'number') . '<button type="submit">Save carousel options</button></form>';
    return adminView('templates.admin.hero', $admin, '/admin/hero', ['content' => $html]);
}
function renderHeroForm(array $admin, ?int $id): string {
    if ($notice = heroSchemaNotice($admin)) return $notice;
    $slide = $id === null ? ['image' => '', 'mobile_image' => '', 'title' => '', 'position' => (string) query('SELECT COALESCE(MAX(position), -1) + 1 AS position FROM home_slides')[0]['position']] : (query('SELECT * FROM home_slides WHERE id = ?', [$id])[0] ?? notFound());
    $o = heroOptions($slide); $errors = []; $uploads = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach (['title', 'position'] as $key) $slide[$key] = postedText($key);
        foreach (HERO_DEFAULTS as $key => $default) $o[$key] = postedText($key);
        foreach (['enabled', 'new_tab', 'overlay'] as $key) $o[$key] = $o[$key] === '1' ? '1' : '0';
        foreach (['button_url'] as $key) if (!heroPathValid((string) ($slide[$key] ?? $o[$key]))) $errors[$key] = 'Enter a button destination such as /about or https://example.com.';
        if (!ctype_digit($slide['position']) || (int) $slide['position'] > 65535) $errors['position'] = 'Display order must be a whole number from 0 to 65535. Lower numbers appear first.';
        if ($o['interval'] !== '' && (!ctype_digit($o['interval']) || (int) $o['interval'] < 1000 || (int) $o['interval'] > 60000)) $errors['interval'] = 'Slide duration must be between 1000 and 60000 milliseconds (1–60 seconds). Leave it empty to use the carousel default.';
        if (!in_array($o['content_position'], HERO_POSITIONS, true)) $errors['content_position'] = 'Choose a valid text position.';
        if (!in_array($o['image_position'], ['left', 'center', 'right', 'top', 'bottom'], true)) $errors['image_position'] = 'Choose a valid image position.';
        foreach (['title' => 255, 'alt' => 255, 'subheading' => 255, 'description' => 1000, 'button_label' => 100] as $key => $max) if (mb_strlen((string) ($slide[$key] ?? $o[$key])) > $max) $errors[$key] = "Use $max characters or fewer.";
        if (($o['button_label'] === '') !== ($o['button_url'] === '')) $errors['button_url'] = 'Enter both a button label and destination, or leave both empty.';
        foreach (['image', 'mobile_image'] as $key) {
            $uploads[$key] = uploadedImage($key . '_upload', $key === 'image' ? 'desktop image' : 'mobile image');
            if (is_string($uploads[$key])) $errors[$key] = $uploads[$key];
        }
        if ($slide['image'] === '' && $uploads['image'] === null) $errors['image'] = 'A desktop image is required for a new slide. Click Choose File under Desktop image and select a PNG, JPEG or WebP image up to 2 MB.';
        if (postedText('remove_mobile_image') === '1') $slide['mobile_image'] = '';
        if (!$errors) {
            foreach ($uploads as $key => $upload) if (is_array($upload)) {
                $folder = 'storage/uploads/home-sliders';
                if (!is_dir(dirname(__DIR__) . '/' . $folder)) mkdir(dirname(__DIR__) . '/' . $folder, 0775, true);
                $path = saveUploadedImage($upload, $folder);
                if ($path === null) $errors[$key] = 'The image could not be saved on the server. Ask the site administrator to check the upload folder permissions, then select the image and try again.';
                else $slide[$key] = $path;
            }
            if (!$errors) {
                $params = [(int) $slide['position'], $slide['image'], $slide['mobile_image'] ?: null, $slide['title'] ?: null, json_encode($o, JSON_THROW_ON_ERROR)];
                if ($id === null) adminExecute('INSERT INTO home_slides (position, image, mobile_image, title, options) VALUES (?, ?, ?, ?, ?)', $params);
                else adminExecute('UPDATE home_slides SET position = ?, image = ?, mobile_image = ?, title = ?, options = ? WHERE id = ?', [...$params, $id]);
                adminFlash('Saved hero slide.'); adminRedirect('/admin/hero');
            }
        }
    }
    $summary = '';
    if ($errors) {
        $summary = '<div class="admin-alert" role="alert"><strong>The slide was not saved.</strong><ul>';
        foreach (array_unique($errors) as $message) $summary .= '<li>' . escape($message) . '</li>';
        $summary .= '</ul>' . (imageWasChosen('image_upload') || imageWasChosen('mobile_image_upload') ? '<p>Please select your image files again before saving.</p>' : '') . '</div>';
    }
    $html = '<p><a href="{{BASE}}/admin/hero">← All slides</a></p><h2>' . ($id === null ? 'Add slide' : 'Edit slide') . '</h2>' . $summary . '<form class="admin-form admin-panel" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">';
    foreach (['image' => 'Desktop image', 'mobile_image' => 'Mobile image (optional)'] as $key => $label) {
        if ($slide[$key]) $html .= adminImageHtml($slide[$key], 'admin-hero-preview');
        $html .= '<div class="admin-field"><label for="' . $key . '_upload">' . escape($label) . '</label><input id="' . $key . '_upload" type="file" name="' . $key . '_upload" accept="image/png,image/jpeg,image/webp"' . fieldInvalid($errors, $key) . '><p class="admin-hint">PNG, JPEG or WebP, up to 2 MB. Leave blank to keep the current image. Without a mobile image, the desktop image is used.</p>' . fieldError($errors, $key) . '</div>';
        if ($key === 'mobile_image' && $slide[$key]) $html .= '<label class="admin-check"><input type="checkbox" name="remove_mobile_image" value="1"> Remove mobile image and use desktop image</label>';

    }
    foreach (['title' => 'Heading (optional)', 'position' => 'Display order'] as $key => $label) $html .= heroField($key, $label, (string) $slide[$key], $errors, $key === 'position' ? 'number' : 'text');
    foreach (['alt' => 'Image alternative text', 'subheading' => 'Subheading', 'description' => 'Description', 'button_label' => 'Button label', 'button_url' => 'Button destination', 'interval' => 'Slide duration (milliseconds; empty uses carousel default)'] as $key => $label) $html .= heroField($key, $label, $o[$key], $errors, $key === 'interval' ? 'number' : 'text');
    $html .= heroSelect('content_position', 'Text position', $o['content_position'], array_combine(HERO_POSITIONS, array_map(fn ($p) => ucwords(str_replace('-', ' ', $p)), HERO_POSITIONS)));
    $html .= heroSelect('image_position', 'Image focus', $o['image_position'], array_combine(['left', 'center', 'right', 'top', 'bottom'], ['Left', 'Center', 'Right', 'Top', 'Bottom']));
    foreach (['enabled' => 'Visible on home page', 'overlay' => 'Dark overlay behind text', 'new_tab' => 'Open button in new tab'] as $key => $label) $html .= heroSelect($key, $label, $o[$key], ['1' => 'Yes', '0' => 'No']);
    $html .= '<div class="admin-form-actions"><button type="submit">Save slide</button><a class="admin-button-secondary" href="{{BASE}}/admin/hero">Cancel</a></div></form>';
    return adminView('templates.admin.hero', $admin, '/admin/hero', ['content' => $html]);
}
function renderHeroDelete(array $admin, int $id): string {
    $slide = query('SELECT * FROM home_slides WHERE id = ?', [$id])[0] ?? notFound();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        adminExecute('DELETE FROM home_slides WHERE id = ?', [$id]);
        adminFlash('Deleted hero slide.'); adminRedirect('/admin/hero');
    }
    return adminView('templates.admin.hero', $admin, '/admin/hero', ['content' => '<div class="admin-panel">' . deleteConfirmation('slide', $slide['title'] ?: 'Slide #' . $id, '/admin/hero/' . $id . '/delete', '/admin/hero') . '</div>']);
}
