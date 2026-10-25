<?php
declare(strict_types=1);

// Admin site settings page (/admin/settings): the rows of site_settings that the site reads with
// setting() (content.php, videos.php). An emptied setting is deleted, so the site falls back to
// its default or leaves the item out. Rows with other names aren't used and can be removed.
require_once __DIR__ . '/admin-forms.php';

// name => [group, label, kind (email, url, phones, lines, text), hint]. Kinds decide the input and its checks.
const SITE_SETTINGS = [
    'contact_email' => ['contact', 'Email address', 'email', 'Shown in the footer and on the contact page.'],
    'contact_phones' => ['contact', 'Phone numbers', 'phones', 'One number per line, for example +91 98826 00007.'],
    'contact_address' => ['contact', 'Postal address', 'lines', 'One line per row, as it should appear in the footer.'],
    'social_instagram' => ['social', 'Instagram', 'url', 'The full profile address, starting with https://.'],
    'social_facebook' => ['social', 'Facebook', 'url', 'The full page address, starting with https://.'],
    'youtube_channel_url' => ['social', 'YouTube', 'url', 'Also used for “Visit channel” on the video pages. When empty, they link to youtube.com/@prohpcl.'],
    'home_highlights_season' => ['home', 'Highlights season', 'text', 'Named above Top Performers and Stats, for example Season 3.'],
];
// The page's sections: key => [title, description].
const SETTING_GROUPS = [
    'contact' => ['Contact details', 'How visitors reach the league. Shown in the site footer and on the contact page.'],
    'social' => ['Social media', 'The icons in the site footer. Leave a link empty to hide its icon.'],
    'home' => ['Home page', 'Text shown in the highlights on the home page.'],
];
const SETTING_MAX_LENGTH = 1000;
const SETTING_MAX_LINES = 6;

/** The error for a setting's value, or null when it is fine. An empty value is always fine. */
function settingError(string $kind, string $label, string $value): ?string {
    if ($value === '') return null;
    if (mb_strlen($value) > SETTING_MAX_LENGTH) return "$label must be " . number_format(SETTING_MAX_LENGTH) . ' characters or fewer.';
    $lines = explode("\n", $value);
    return match ($kind) {
        'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Enter an email address, such as contact@prohpcl.com.',
        // Only web addresses: the value becomes a link, so javascript: and other schemes are refused.
        'url' => preg_match('~^https?://~i', $value) && filter_var($value, FILTER_VALIDATE_URL) ? null : 'Enter a full web address starting with https://.',
        'phones' => count($lines) > SETTING_MAX_LINES ? 'Enter at most ' . SETTING_MAX_LINES . ' phone numbers.'
            : (array_filter($lines, fn ($line) => !preg_match('~^\+?[0-9][0-9 ()-]{5,19}$~', $line)) ? 'Enter one phone number per line, using digits, spaces, + ( ) and -.' : null),
        'lines' => count($lines) > SETTING_MAX_LINES ? 'Enter at most ' . SETTING_MAX_LINES . ' lines.' : null,
        default => str_contains($value, "\n") ? "$label must be a single line." : null,
    };
}
/** A posted multi-line value with blank lines and spaces around lines removed. */
function postedLines(string $key): string {
    return implode("\n", array_filter(array_map('trim', explode("\n", postedText($key))), fn ($line) => $line !== ''));
}

function renderSettings(array $admin): string {
    $stored = array_column(query('SELECT name, value FROM site_settings ORDER BY name'), 'value', 'name');
    $values = array_map(fn ($name) => (string) ($stored[$name] ?? ''), array_combine(array_keys(SITE_SETTINGS), array_keys(SITE_SETTINGS)));
    $others = array_diff_key($stored, SITE_SETTINGS, array_flip(['hero_autoplay', 'hero_interval', 'hero_pause', 'hero_wrap', 'hero_controls', 'hero_indicators', 'hero_fade']));
    // SMTP and Razorpay settings belong to the Integrations page (root user only), the committee
    // form's payment switch to the Committee page.
    $others = array_filter($others, fn ($name) => !preg_match('~^(?:smtp|razorpay|committee)_~', (string) $name), ARRAY_FILTER_USE_KEY);
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach (SITE_SETTINGS as $name => [, $label, $kind]) {
            $values[$name] = in_array($kind, ['phones', 'lines'], true) ? postedLines($name) : postedText($name);
            if ($error = settingError($kind, $label, $values[$name])) $errors[$name] = $error;
        }
        $remove = array_values(array_intersect(array_keys($others), is_array($_POST['remove'] ?? null) ? array_filter($_POST['remove'], 'is_string') : []));
        if (!$errors) {
            adminTransaction(function () use ($values, $remove) {
                foreach ($values as $name => $value) {
                    if ($value === '') adminExecute('DELETE FROM site_settings WHERE name = ?', [$name]);
                    else adminExecute('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
                }
                foreach ($remove as $name) adminExecute('DELETE FROM site_settings WHERE name = ?', [$name]);
            });
            adminFlash('Saved the site settings.');
            adminRedirect('/admin/settings');
        }
    }

    $seasons = array_column(query('SELECT name FROM seasons ORDER BY route_key DESC'), 'name');
    $groups = [];
    foreach (SITE_SETTINGS as $name => [$group, $label, $kind, $hint]) {
        $value = escape($values[$name]);
        $attributes = ' id="' . $name . '" name="' . $name . '"' . fieldInvalid($errors, $name);
        $input = match ($kind) {
            'phones', 'lines' => '<textarea' . $attributes . ' rows="3" maxlength="' . SETTING_MAX_LENGTH . '">' . $value . '</textarea>',
            'email' => '<input' . $attributes . ' type="email" value="' . $value . '" maxlength="255" autocomplete="off">',
            'url' => '<div class="admin-input-group"><input' . $attributes . ' type="url" value="' . $value . '" maxlength="' . SETTING_MAX_LENGTH . '" placeholder="https://" spellcheck="false">'
                . ($values[$name] !== '' && !isset($errors[$name]) ? '<a class="admin-button-secondary admin-button-small" href="' . $value . '" target="_blank" rel="noopener noreferrer" aria-label="Open the ' . escape($label) . ' link">Open</a>' : '') . '</div>',
            default => '<input' . $attributes . ' type="text" value="' . $value . '" maxlength="255"' . ($name === 'home_highlights_season' ? ' list="season-names" autocomplete="off"' : '') . '>'
                . ($name === 'home_highlights_season' ? '<datalist id="season-names">' . implode('', array_map(fn ($season) => '<option value="' . escape($season) . '">', $seasons)) . '</datalist>' : ''),
        };
        $groups[$group] = ($groups[$group] ?? '') . '<div class="admin-field"><label for="' . $name . '">' . escape($label) . '</label>'
            . $input . '<p class="admin-hint">' . escape($hint) . '</p>' . fieldError($errors, $name) . '</div>';
    }
    $sections = implode('', array_map(fn ($group, $fields) => '<section class="admin-settings-section" aria-labelledby="settings-' . $group . '"><div class="admin-settings-section__intro">'
        . '<h2 id="settings-' . $group . '">' . escape(SETTING_GROUPS[$group][0]) . '</h2><p>' . escape(SETTING_GROUPS[$group][1]) . '</p></div>'
        . '<div class="admin-panel admin-form">' . $fields . '</div></section>', array_keys($groups), $groups));
    $otherRows = implode('', array_map(fn ($name, $value) => '<tr><td><code>' . escape($name) . '</code></td><td class="admin-cell-wrap">' . nl2br(escape((string) $value)) . '</td>'
        . '<td><label class="admin-check admin-danger-link"><input type="checkbox" name="remove[]" value="' . escape($name) . '" aria-label="Remove ' . escape($name) . '">Remove</label></td></tr>', array_keys($others), $others));
    $other = $others ? '<section class="admin-settings-section"><div class="admin-settings-section__intro"><h2>Unused settings</h2><p>Rows of the site_settings table that the site doesn’t read. Tick to remove them when you save.</p></div>'
        . '<div class="admin-panel"><div class="admin-table-wrap"><table class="admin-table"><tbody>' . $otherRows . '</tbody></table></div></div></section>' : '';
    return adminView('templates.admin.settings', $admin, '/admin/settings', ['summary' => formErrorSummary($errors, 'none'), 'sections' => $sections, 'other' => $other]);
}
