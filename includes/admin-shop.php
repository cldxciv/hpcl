<?php
declare(strict_types=1);

// Admin pages for the shop (/admin/shop/...): settings with the Razorpay status, categories, and the
// helpers the products (admin-shop-products.php) and orders (admin-shop-orders.php) pages share.
// The Razorpay keys themselves are entered by the root user at /admin/integrations.
require_once __DIR__ . '/admin-forms.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/razorpay.php';

const SHOP_ADMIN_LOW_STOCK = 5;
const SHOP_ADMIN_PER_PAGE = 25;
const SHOP_ADMIN_PRODUCT_DIR = 'storage/uploads/shop/products';
const SHOP_ADMIN_GALLERY_DIR = 'storage/uploads/shop/products/gallery';
const SHOP_ADMIN_CATEGORY_DIR = 'storage/uploads/shop/categories';
// Shown for a product saved without a photo, so the shop never has a broken picture.
const SHOP_ADMIN_PLACEHOLDER_IMAGE = 'assets/media/shop-placeholder.svg';
const SHOP_ADMIN_PHOTO_MAX_BYTES = 8 * 1024 * 1024;
const SHOP_ADMIN_PHOTO_MAX_SIDE = 1600;
const SHOP_ADMIN_DEFAULT_SHIPPING = 60;

// Order statuses: status => [label, badge class]. The next steps an order can take are in SHOP_ADMIN_ORDER_NEXT.
const SHOP_ADMIN_ORDER_STATUSES = [
    'pending' => ['Awaiting payment', 'pending'], 'paid' => ['Paid: to pack', 'paid'], 'packed' => ['Packed', 'packed'], 'shipped' => ['Shipped', 'shipped'],
    'delivered' => ['Delivered', 'delivered'], 'cancelled' => ['Cancelled', 'cancelled'], 'failed' => ['Payment failed', 'failed'], 'refunded' => ['Refunded', 'refunded'],
];
const SHOP_ADMIN_ORDER_NEXT = [
    'pending' => ['paid', 'cancelled'], 'failed' => ['paid', 'cancelled'], 'paid' => ['packed', 'shipped', 'cancelled', 'refunded'], 'packed' => ['shipped', 'cancelled', 'refunded'],
    'shipped' => ['delivered', 'refunded'], 'delivered' => ['refunded'], 'cancelled' => [], 'refunded' => [],
];

/** A shop setting from site_settings, or $default. */
function shopAdminSetting(string $name, string $default = ''): string {
    $row = query('SELECT value FROM site_settings WHERE name = ?', [$name])[0] ?? null;
    return $row ? (string) $row['value'] : $default;
}
/** Saves a shop setting; null or '' removes it so the default applies. */
function shopAdminSaveSetting(string $name, ?string $value): void {
    if ($value === null || $value === '') adminExecute('DELETE FROM site_settings WHERE name = ?', [$name]);
    else adminExecute('INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
}

/** "Some Name 2" as a url slug: lower case letters, digits and hyphens. */
function shopAdminSlug(string $text): string {
    $text = strtolower(trim(preg_replace('~[^a-z0-9]+~i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text), '-'));
    return substr($text !== '' ? $text : 'item', 0, 120);
}
/** A slug not yet used in $table (other than by row $excludeId). */
function shopAdminUniqueSlug(string $table, string $base, ?int $excludeId = null): string {
    $slug = $base;
    for ($i = 2; query("SELECT 1 FROM $table WHERE slug = ?" . ($excludeId ? ' AND id <> ?' : ''), $excludeId ? [$slug, $excludeId] : [$slug]); $i++) $slug = substr($base, 0, 110) . '-' . $i;
    return $slug;
}
/** "₹1,234" or "₹1,234.50", as HTML. */
function shopAdminRupees(float|string|null $amount): string {
    $amount = (float) $amount;
    return '&#8377;' . number_format($amount, floor($amount) === $amount ? 0 : 2);
}
/** A non-negative amount typed in a form as a float, or null when it isn't a number in range. */
function shopAdminAmount(string $text, float $max = 10000000): ?float {
    $text = str_replace([',', '₹', ' '], '', $text);
    return preg_match('~^\d{1,9}(\.\d{1,2})?$~', $text) && (float) $text <= $max ? (float) $text : null;
}
function shopAdminBadge(string $status): string {
    [$label, $class] = SHOP_ADMIN_ORDER_STATUSES[$status] ?? [ucfirst($status), 'pending'];
    return '<span class="admin-badge admin-badge--shop-' . $class . '">' . escape($label) . '</span>';
}
/** Creates $folder (relative to the project) when it is missing. */
function shopAdminEnsureDir(string $folder): void {
    $absolute = dirname(__DIR__) . '/' . $folder;
    if (!is_dir($absolute)) @mkdir($absolute, 0775, true);
}
/** A "page n of m" pager like the other admin lists; $url builds the link of a page number. */
function shopAdminPager(int $page, int $lastPage, callable $url): string {
    return $lastPage > 1 ? '<nav class="admin-pager" aria-label="Pages">'
        . ($page > 1 ? '<a href="' . $url($page - 1) . '" rel="prev">&larr; Previous</a>' : '<span></span>')
        . '<span class="admin-muted">Page ' . $page . ' of ' . $lastPage . '</span>'
        . ($page < $lastPage ? '<a href="' . $url($page + 1) . '" rel="next">Next &rarr;</a>' : '<span></span>') . '</nav>' : '';
}
/** The ?page= of a list, at least 1. */
function shopAdminPage(): int {
    return max(1, (int) (is_string($_GET['page'] ?? null) ? $_GET['page'] : 1));
}
/** A product or category photo as a small picture; the branded placeholder when it has none. */
function shopAdminThumb(?string $path, string $class = 'admin-logo-thumb'): string {
    if (!$path || $path === SHOP_ADMIN_PLACEHOLDER_IMAGE) return '<img class="' . $class . ' admin-shop-placeholder" src="{{BASE}}/' . SHOP_ADMIN_PLACEHOLDER_IMAGE . '" alt="" loading="lazy">';
    return adminImageHtml($path, $class);
}

/**
 * Checks a photo uploaded through $_FILES[$field] (or one entry of a multiple upload, $index): null when
 * none was chosen, an error message, or [temporary file, type]. Up to 8 MB; the type is read from the content.
 */
function shopAdminPhotoCheck(string $field, ?int $index = null, string $label = 'photo'): array|string|null {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file)) return null;
    $get = fn (string $key) => $index === null ? ($file[$key] ?? null) : ($file[$key][$index] ?? null);
    $error = $get('error');
    if (!is_int($error) || $error === UPLOAD_ERR_NO_FILE) return null;
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $get('size') > SHOP_ADMIN_PHOTO_MAX_BYTES) return "The $label must be 8 MB or smaller.";
    $tmp = (string) $get('tmp_name');
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) return "The $label could not be uploaded. Please try again.";
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $size = @getimagesize($tmp);
    if (!isset(ADMIN_IMAGE_TYPES[$type]) || !$size) return "The $label must be a PNG, JPEG or WebP image.";
    if ($size[0] * $size[1] > 40000000) return "The $label is too large in pixels. Please choose a smaller one.";
    return [$tmp, $type];
}
/**
 * Re-encodes a checked photo (upright, at most 1600 px on its longest side, no metadata) into $folder
 * under a random name: its path relative to the project, or null when it can't be saved.
 */
function shopAdminPhotoSave(array $upload, string $folder): ?string {
    [$tmp, $type] = $upload;
    $image = match ($type) { 'image/jpeg' => @imagecreatefromjpeg($tmp), 'image/png' => @imagecreatefrompng($tmp), 'image/webp' => @imagecreatefromwebp($tmp), default => false };
    if (!$image) return null;
    if ($type === 'image/jpeg' && function_exists('exif_read_data')) {
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) (@exif_read_data($tmp)['Orientation'] ?? 1)] ?? 0;
        if ($angle && ($turned = imagerotate($image, $angle, 0))) $image = $turned;
    }
    [$width, $height] = [imagesx($image), imagesy($image)];
    $scale = min(1, SHOP_ADMIN_PHOTO_MAX_SIDE / max($width, $height));
    $canvas = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
    $png = $type === 'image/png';
    if ($png) { imagealphablending($canvas, false); imagesavealpha($canvas, true); imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127)); }
    else imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);
    shopAdminEnsureDir($folder);
    $path = $folder . '/' . bin2hex(random_bytes(20)) . ($png ? '.png' : '.jpg');
    $saved = $png ? imagepng($canvas, dirname(__DIR__) . '/' . $path, 7) : imagejpeg($canvas, dirname(__DIR__) . '/' . $path, 86);
    if (!$saved) { error_log("Could not save a shop photo to $folder"); return null; }
    return $path;
}
/** Deletes a stored shop photo file, only when it lives under storage/uploads/shop. */
function shopAdminPhotoDelete(?string $path): void {
    if ($path && str_starts_with($path, 'storage/uploads/shop/') && !str_contains($path, '..')) @unlink(dirname(__DIR__) . '/' . $path);
}

/** One labelled field: $control is the finished input/select/textarea HTML with id $id. */
function shopAdminField(string $id, string $label, string $control, array $errors, string $hint = '', bool $optional = false): string {
    return '<div class="admin-field"><label for="' . $id . '">' . escape($label) . ($optional ? ' <span class="admin-optional">optional</span>' : '') . '</label>' . $control
        . ($hint !== '' ? '<p class="admin-hint">' . $hint . '</p>' : '') . fieldError($errors, $id) . '</div>';
}
function shopAdminInput(string $id, string $type, string $value, array $errors, string $extra = ''): string {
    return '<input id="' . $id . '" name="' . $id . '" type="' . $type . '" value="' . escape($value) . '"' . $extra . fieldInvalid($errors, $id) . '>';
}

/** What the Razorpay panel shows: [state ('ready', 'off', 'missing'), headline, detail]. */
function shopAdminPaymentStatus(): array {
    $config = razorpayConfig();
    $mode = $config['mode'] === 'live' ? 'live' : 'test';
    if ($config['key_id'] === '' || $config['key_secret'] === '') return ['missing', 'Razorpay is not set up', 'No API keys are saved yet, so the shop cannot take payments.'];
    if (!$config['enabled']) return ['off', 'Razorpay keys are saved but payments are switched off', 'Turn on “Accept payments” in the Razorpay settings. Mode: ' . $mode . '.'];
    return ['ready', 'Razorpay is ready (' . $mode . ' mode)', $mode === 'test' ? 'Test mode takes no real money. Switch to live keys when you are ready to sell.' : 'Live keys are saved: customers pay for real.'];
}
/** The Razorpay panel, with a link to the page where the keys are entered (root user only). */
function shopAdminPaymentPanel(): string {
    [$state, $headline, $detail] = shopAdminPaymentStatus();
    $link = adminCan('root')
        ? '<a class="admin-button' . ($state === 'ready' ? '-secondary' : '') . '" href="{{BASE}}/admin/integrations">' . ($state === 'ready' ? 'Razorpay settings' : 'Enter the Razorpay keys') . '</a>'
        : '<span class="admin-muted">Only the root user can enter the Razorpay keys (Settings, Integrations).</span>';
    return '<div class="admin-shop-payments admin-shop-payments--' . $state . '"><div><p class="admin-shop-payments__label">Payments</p>'
        . '<p class="admin-shop-payments__headline"><span class="admin-status' . ($state === 'ready' ? ' admin-status--ok' : '') . '">' . escape($headline) . '</span></p>'
        . '<p class="admin-hint">' . escape($detail) . ' The same keys take membership, committee and shop payments.</p></div><div>' . $link . '</div></div>';
}

// ---------------------------------------------------------------- settings

function renderAdminShopSettings(array $admin): string {
    $stored = ['shop_enabled' => shopAdminSetting('shop_enabled') === '1' ? '1' : '0', 'shop_shipping_fee' => shopAdminSetting('shop_shipping_fee', (string) SHOP_ADMIN_DEFAULT_SHIPPING),
        'shop_free_shipping_over' => shopAdminSetting('shop_free_shipping_over'), 'shop_notify_email' => shopAdminSetting('shop_notify_email'), 'shop_support_phone' => shopAdminSetting('shop_support_phone')];
    $input = $stored;
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $input = ['shop_enabled' => ($_POST['shop_enabled'] ?? '') === '1' ? '1' : '0', 'shop_shipping_fee' => postedText('shop_shipping_fee'), 'shop_free_shipping_over' => postedText('shop_free_shipping_over'),
            'shop_notify_email' => postedText('shop_notify_email'), 'shop_support_phone' => postedText('shop_support_phone')];
        if ($input['shop_shipping_fee'] === '' || shopAdminAmount($input['shop_shipping_fee'], 5000) === null) $errors['shop_shipping_fee'] = 'Enter the shipping fee in rupees, from 0 to 5000 (0 for free shipping).';
        if ($input['shop_free_shipping_over'] !== '' && (shopAdminAmount($input['shop_free_shipping_over']) ?? 0) <= 0) $errors['shop_free_shipping_over'] = 'Enter an amount in rupees above 0, or leave it empty for no free-shipping offer.';
        if ($input['shop_notify_email'] !== '' && (!filter_var($input['shop_notify_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($input['shop_notify_email']) > 255)) $errors['shop_notify_email'] = 'Enter a valid email address, or leave it empty.';
        if ($input['shop_support_phone'] !== '' && !preg_match('~^\+?[0-9][0-9 ()-]{6,18}$~', $input['shop_support_phone'])) $errors['shop_support_phone'] = 'Enter a phone number, such as +91 98765 43210, or leave it empty.';
        if (!$errors) {
            shopAdminSaveSetting('shop_enabled', $input['shop_enabled'] === '1' ? '1' : null);
            shopAdminSaveSetting('shop_shipping_fee', (string) shopAdminAmount($input['shop_shipping_fee']));
            shopAdminSaveSetting('shop_free_shipping_over', $input['shop_free_shipping_over'] === '' ? null : (string) shopAdminAmount($input['shop_free_shipping_over']));
            shopAdminSaveSetting('shop_notify_email', $input['shop_notify_email'] ?: null);
            shopAdminSaveSetting('shop_support_phone', $input['shop_support_phone'] ?: null);
            adminFlash('Shop settings saved.');
            adminRedirect('/admin/shop/settings');
        }
    }
    $counts = query('SELECT (SELECT COUNT(*) FROM shop_products WHERE is_active = 1) AS products, (SELECT COUNT(*) FROM shop_categories WHERE is_active = 1) AS categories')[0];
    [$payState] = shopAdminPaymentStatus();
    $check = fn (bool $ok, string $label, string $hint) => '<li class="' . ($ok ? 'is-ok' : 'is-warning') . '"><span>' . escape($label) . '<br><small>' . escape($hint) . '</small></span><strong>' . ($ok ? 'Done' : 'To do') . '</strong></li>';
    $checklist = '<ul class="admin-checklist">'
        . $check((int) $counts['categories'] > 0, 'At least one category', (int) $counts['categories'] . ' active')
        . $check((int) $counts['products'] > 0, 'At least one visible product', (int) $counts['products'] . ' visible')
        . $check($payState === 'ready', 'Razorpay keys saved and payments on', 'Needed to take payments')
        . $check($stored['shop_notify_email'] !== '', 'Order notification email', $stored['shop_notify_email'] !== '' ? $stored['shop_notify_email'] : 'Not set: the site contact email is used')
        . '</ul>';
    $envOn = filter_var(getenv('SHOP_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    $field = fn (string $key) => ['value' => escape($input[$key]), 'error' => fieldError($errors, $key), 'invalid' => fieldInvalid($errors, $key)];
    return adminView('templates.admin.shop-settings', $admin, '/admin/shop/settings', [
        'summary' => $errors ? '<p class="admin-alert" role="alert">Please correct the highlighted fields.</p>' : '',
        'payments' => shopAdminPaymentPanel(), 'checklist' => $checklist,
        'enabled' => $input['shop_enabled'] === '1' ? ' checked' : '', 'env_note' => $envOn ? '<p class="admin-hint">The shop is also switched on by <code>SHOP_ENABLED=true</code> in the server’s .env file, so it stays public whatever you choose here.</p>' : '',
        'fee' => $field('shop_shipping_fee')['value'], 'fee_error' => $field('shop_shipping_fee')['error'], 'fee_invalid' => $field('shop_shipping_fee')['invalid'],
        'free' => $field('shop_free_shipping_over')['value'], 'free_error' => $field('shop_free_shipping_over')['error'], 'free_invalid' => $field('shop_free_shipping_over')['invalid'],
        'email' => $field('shop_notify_email')['value'], 'email_error' => $field('shop_notify_email')['error'], 'email_invalid' => $field('shop_notify_email')['invalid'],
        'phone' => $field('shop_support_phone')['value'], 'phone_error' => $field('shop_support_phone')['error'], 'phone_invalid' => $field('shop_support_phone')['invalid'],
        'shop_url' => escape(absoluteUrl('{{BASE}}/shop')),
    ]);
}

// ---------------------------------------------------------------- categories

/** Every category as [row + product_count + children_count], top-level ones followed by their children. */
function shopAdminCategoryTree(): array {
    $rows = query('SELECT c.*, (SELECT COUNT(*) FROM shop_products p WHERE p.category_id = c.id) AS product_count,
        (SELECT COUNT(*) FROM shop_categories k WHERE k.parent_id = c.id) AS children_count FROM shop_categories c ORDER BY c.position, c.name');
    $byParent = [];
    foreach ($rows as $row) $byParent[(int) $row['parent_id']][] = $row;
    $tree = [];
    foreach ($byParent[0] ?? [] as $parent) {
        $tree[] = $parent + ['depth' => 0];
        foreach ($byParent[(int) $parent['id']] ?? [] as $child) $tree[] = $child + ['depth' => 1];
    }
    // A category whose parent is gone is still listed, at the top.
    $seen = array_column($tree, 'id');
    foreach ($rows as $row) if (!in_array($row['id'], $seen, true)) $tree[] = $row + ['depth' => 0];
    return $tree;
}

function renderAdminShopCategories(array $admin): string {
    $tree = shopAdminCategoryTree();
    $rows = implode('', array_map(fn ($c) => '<tr><td class="admin-cell-team">' . shopAdminThumb($c['image'] ?? null) . ($c['depth'] ? '<span class="admin-muted" aria-hidden="true">&mdash;</span> ' : '')
        . '<a href="{{BASE}}/admin/shop/categories/' . (int) $c['id'] . '">' . escape($c['name']) . '</a></td>'
        . '<td class="admin-wide-only"><code>' . escape($c['slug']) . '</code></td><td class="admin-num">' . (int) $c['product_count'] . '</td>'
        . '<td>' . ($c['is_active'] ? '<span class="admin-badge admin-badge--ongoing">Shown</span>' : '<span class="admin-badge admin-badge--unknown">Hidden</span>') . '</td>'
        . '<td class="admin-actions"><a href="{{BASE}}/admin/shop/categories/' . (int) $c['id'] . '">Edit</a>'
        . (adminCan('delete') ? '<a class="admin-danger-link" href="{{BASE}}/admin/shop/categories/' . (int) $c['id'] . '/delete">Delete</a>' : '') . '</td></tr>', $tree));
    $table = $tree ? '<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Category</th><th class="admin-wide-only">Web address</th><th class="admin-num">Products</th><th>Status</th><th><span class="admin-visually-hidden">Actions</span></th></tr></thead><tbody>'
        . $rows . '</tbody></table></div>' : '<p class="admin-empty">No categories yet. Add one such as Jerseys, Caps or Cricket gear.</p>';
    return adminView('templates.admin.shop-categories', $admin, '/admin/shop/categories', ['summary' => number_format(count($tree)) . ' categories', 'table' => $table]);
}

function renderAdminShopCategoryForm(array $admin, ?int $id): string {
    $blank = ['id' => null, 'parent_id' => null, 'slug' => '', 'name' => '', 'image' => null, 'position' => 0, 'is_active' => 1];
    $category = $id === null ? $blank : (query('SELECT * FROM shop_categories WHERE id = ?', [$id])[0] ?? notFound());
    $children = $id === null ? 0 : (int) query('SELECT COUNT(*) AS n FROM shop_categories WHERE parent_id = ?', [$id])[0]['n'];
    $errors = [];
    $input = $category;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') [$input, $errors] = saveAdminShopCategory($category, $children);
    $parents = query('SELECT id, name FROM shop_categories WHERE parent_id IS NULL' . ($id ? ' AND id <> ' . (int) $id : '') . ' ORDER BY position, name');
    $options = '<option value="">None: a main category</option>' . implode('', array_map(fn ($p) => '<option value="' . (int) $p['id'] . '"' . ((int) $input['parent_id'] === (int) $p['id'] ? ' selected' : '') . '>' . escape($p['name']) . '</option>', $parents));
    $isNew = $category['id'] === null;
    return adminView('templates.admin.shop-category-form', $admin, '/admin/shop/categories', [
        'title' => $isNew ? 'Add a category' : 'Edit ' . escape($category['name']), 'action' => '{{BASE}}/admin/shop/categories/' . ($isNew ? 'new' : (int) $category['id']),
        'summary' => formErrorSummary($errors, 'image'),
        'name' => escape((string) $input['name']), 'name_error' => fieldError($errors, 'name'), 'name_invalid' => fieldInvalid($errors, 'name'),
        'slug' => escape((string) $input['slug']), 'slug_error' => fieldError($errors, 'slug'), 'slug_invalid' => fieldInvalid($errors, 'slug'),
        'parent_options' => $options, 'parent_error' => fieldError($errors, 'parent_id'), 'parent_invalid' => fieldInvalid($errors, 'parent_id'),
        'parent_hint' => $children ? '<p class="admin-hint">This category has ' . $children . ' sub-categories, so it stays a main category.</p>' : '',
        'position' => escape((string) $input['position']), 'position_error' => fieldError($errors, 'position'), 'position_invalid' => fieldInvalid($errors, 'position'),
        'active' => $input['is_active'] ? ' checked' : '',
        'image_current' => $category['image'] ? '<div class="admin-logo-current">' . shopAdminThumb($category['image'], 'admin-logo-preview') . '<label class="admin-check"><input type="checkbox" name="remove_image" value="1">Remove the picture</label></div>' : '',
        'image_error' => fieldError($errors, 'image'), 'image_invalid' => fieldInvalid($errors, 'image'),
        'submit' => $isNew ? 'Add category' : 'Save changes',
        'delete' => $isNew || !adminCan('delete') ? '' : '<a class="admin-danger-link" href="{{BASE}}/admin/shop/categories/' . (int) $category['id'] . '/delete">Delete this category</a>',
    ]);
}

/** Validates and saves the posted category; redirects on success, else returns [input, errors]. */
function saveAdminShopCategory(array $category, int $children): array {
    $input = ['id' => $category['id'], 'name' => postedText('name'), 'slug' => postedText('slug'), 'parent_id' => postedText('parent_id'), 'position' => postedText('position'),
        'is_active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0, 'image' => $category['image']];
    $errors = [];
    if ($input['name'] === '') $errors['name'] = 'Enter the category name.';
    elseif (mb_strlen($input['name']) > 255) $errors['name'] = 'The name must be 255 characters or fewer.';
    if ($input['slug'] !== '' && !preg_match('~^[a-z0-9]+(-[a-z0-9]+)*$~', $input['slug'])) $errors['slug'] = 'Use lower case letters, digits and hyphens only, or leave it empty to make it from the name.';
    $parent = null;
    if ($input['parent_id'] !== '') {
        $parent = ctype_digit($input['parent_id']) ? (query('SELECT id FROM shop_categories WHERE id = ? AND parent_id IS NULL', [(int) $input['parent_id']])[0] ?? null) : null;
        if (!$parent || (int) $parent['id'] === (int) $category['id']) $errors['parent_id'] = 'Choose a main category from the list.';
        elseif ($children) $errors['parent_id'] = 'A category with sub-categories must stay a main category.';
    }
    if ($input['position'] === '') $input['position'] = '0';
    if (!ctype_digit($input['position']) || (int) $input['position'] > 999) $errors['position'] = 'Position must be a whole number from 0 to 999.';
    $upload = uploadedImage('image', 'picture');
    if (is_string($upload)) $errors['image'] = $upload;
    if ($errors) return [$input, $errors];

    shopAdminEnsureDir(SHOP_ADMIN_CATEGORY_DIR);
    $newImage = null;
    if (is_array($upload)) {
        $newImage = saveUploadedImage($upload, SHOP_ADMIN_CATEGORY_DIR);
        if (!$newImage) return [$input, ['image' => 'The picture could not be saved: the folder ' . SHOP_ADMIN_CATEGORY_DIR . ' isn’t writable by the web server.']];
        $input['image'] = $newImage;
    } elseif (($_POST['remove_image'] ?? null) === '1') {
        $input['image'] = null;
    }
    $slug = $input['slug'] !== '' ? $input['slug'] : shopAdminSlug($input['name']);
    if ($input['slug'] !== '' && query('SELECT 1 FROM shop_categories WHERE slug = ?' . ($category['id'] ? ' AND id <> ' . (int) $category['id'] : ''), [$slug])) {
        if ($newImage) @unlink(dirname(__DIR__) . '/' . $newImage);
        return [$input, ['slug' => 'Another category already uses this web address.']];
    }
    $slug = shopAdminUniqueSlug('shop_categories', $slug, $category['id'] ? (int) $category['id'] : null);
    $parentId = $parent ? (int) $parent['id'] : null;
    if ($category['id'] === null) {
        adminExecute('INSERT INTO shop_categories (parent_id, slug, name, image, position, is_active) VALUES (?, ?, ?, ?, ?, ?)', [$parentId, $slug, $input['name'], $input['image'], (int) $input['position'], $input['is_active']]);
    } else {
        adminExecute('UPDATE shop_categories SET parent_id = ?, slug = ?, name = ?, image = ?, position = ?, is_active = ? WHERE id = ?', [$parentId, $slug, $input['name'], $input['image'], (int) $input['position'], $input['is_active'], $category['id']]);
    }
    adminFlash(($category['id'] === null ? 'Added ' : 'Saved ') . $input['name'] . '.');
    adminRedirect('/admin/shop/categories');
}

/** Deleting is blocked while the category still has products or sub-categories: hide it instead, or move them first. */
function renderAdminShopCategoryDelete(array $admin, int $id): string {
    $category = query('SELECT * FROM shop_categories WHERE id = ?', [$id])[0] ?? notFound();
    $products = (int) query('SELECT COUNT(*) AS n FROM shop_products WHERE category_id = ?', [$id])[0]['n'];
    $children = (int) query('SELECT COUNT(*) AS n FROM shop_categories WHERE parent_id = ?', [$id])[0]['n'];
    $blocked = $products > 0 || $children > 0;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$blocked) {
        adminExecute('DELETE FROM shop_categories WHERE id = ?', [$id]);
        shopAdminPhotoDelete($category['image'] ?? null);
        adminFlash('Deleted ' . $category['name'] . '.');
        adminRedirect('/admin/shop/categories');
    }
    $body = $blocked
        ? '<p class="admin-alert" role="alert"><strong>' . escape($category['name']) . '</strong> can’t be deleted yet: it still has ' . ($products ? $products . ' product' . ($products === 1 ? '' : 's') : '')
            . ($products && $children ? ' and ' : '') . ($children ? $children . ' sub-categor' . ($children === 1 ? 'y' : 'ies') : '') . '. Move or delete those first, or hide the category instead (untick “Show in the shop” on its edit page).</p>'
            . '<p><a class="admin-button" href="{{BASE}}/admin/shop/categories/' . $id . '">Edit the category</a></p>'
        : deleteConfirmation('category', $category['name'], '/admin/shop/categories/' . $id . '/delete', '/admin/shop/categories/' . $id);
    return adminView('templates.admin.delete', $admin, '/admin/shop/categories', ['title' => 'Delete ' . escape($category['name']), 'image' => shopAdminThumb($category['image'] ?? null, 'admin-logo-preview'),
        'back' => '<a href="{{BASE}}/admin/shop/categories">&larr; All categories</a>', 'body' => $body]);
}

/** /admin/shop: the orders page is the shop's front desk. */
function shopAdminHome(array $admin): never {
    adminRedirect('/admin/shop/orders');
}
