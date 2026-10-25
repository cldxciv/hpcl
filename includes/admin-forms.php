<?php
declare(strict_types=1);

// Helpers shared by the admin editing pages (admin-teams.php, admin-owners.php): posted values,
// image uploads, transactions and the search box.
require_once __DIR__ . '/site.php';

const ADMIN_IMAGE_MAX_BYTES = 2 * 1024 * 1024;
const ADMIN_IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

function postedText(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
}
/** The ?q= search of a list page, trimmed. */
function adminSearch(): string {
    return trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
}
/** A LIKE pattern that matches $text anywhere, with % and _ taken literally. */
function likePattern(string $text): string {
    return '%' . addcslashes($text, '%_\\') . '%';
}
function adminSeasons(): array {
    return query('SELECT id, route_key, name FROM seasons ORDER BY route_key DESC');
}

/**
 * Checks the image uploaded in file field $field: [temporary file, extension], null when none
 * was chosen, or an error message naming it $label ("logo", "photo").
 */
function uploadedImage(string $field, string $label): array|string|null {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > ADMIN_IMAGE_MAX_BYTES) return "The $label must be 2 MB or smaller.";
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return "The $label could not be uploaded. Please try again.";
    // The type is read from the file's content, not its name or the browser's claim.
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(ADMIN_IMAGE_TYPES[$type]) || !@getimagesize($file['tmp_name'])) return "The $label must be a PNG, JPEG or WebP image.";
    return [$file['tmp_name'], ADMIN_IMAGE_TYPES[$type]];
}
/** Whether a file was chosen in $field and arrived; used to ask for it again after other errors. */
function imageWasChosen(string $field): bool {
    return ($_FILES[$field]['error'] ?? null) === UPLOAD_ERR_OK;
}
/** Moves a checked upload into $folder (relative to the project) under a random name: its path, or null if the folder isn't writable. */
function saveUploadedImage(array $upload, string $folder): ?string {
    $path = $folder . '/' . bin2hex(random_bytes(20)) . '.' . $upload[1];
    $absolute = dirname(__DIR__) . '/' . $folder;
    if (is_dir($absolute) && is_writable($absolute) && move_uploaded_file($upload[0], dirname(__DIR__) . '/' . $path)) return $path;
    error_log("Could not save an upload: $folder is missing or not writable by the web server");
    return null;
}
/** A stored image as a small picture, or an empty box when there is none. */
function adminImageHtml(?string $path, string $class = 'admin-logo-thumb'): string {
    // Only the lists' small thumbnails are lazy-loaded.
    return $path ? '<img class="' . $class . '" src="' . escape(mediaUrl($path, '')) . '" alt=""' . ($class === 'admin-logo-thumb' ? ' loading="lazy"' : '') . '>'
        : '<span class="' . $class . ' admin-logo-thumb--empty" aria-hidden="true"></span>';
}

/** Runs $work in a database transaction, rolling back (and rethrowing) if it fails. */
function adminTransaction(callable $work): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $work();
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
/** The next id of a table whose ids are not AUTO_INCREMENT (copied from the old site). Call inside a transaction. */
function nextId(string $table): int {
    return (int) query("SELECT COALESCE(MAX(id), 0) + 1 AS id FROM $table FOR UPDATE")[0]['id'];
}
/** Error messages of a form: under a field, and the attributes that tie the field to it. */
function fieldError(array $errors, string $field): string {
    return isset($errors[$field]) ? '<p class="admin-field-error" id="' . $field . '-error">' . escape($errors[$field]) . '</p>' : '';
}
function fieldInvalid(array $errors, string $field): string {
    return isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="' . $field . '-error"' : '';
}
/** The message above a form with errors; $field is the file field whose upload must be chosen again. */
function formErrorSummary(array $errors, string $field): string {
    if (!$errors) return '';
    // A file chosen with the form is not kept when the form is shown again with errors.
    return '<p class="admin-alert" role="alert">Please correct the highlighted fields'
        . (!isset($errors[$field]) && imageWasChosen($field) ? ', and choose the ' . ($field === 'logo' ? 'logo' : 'photo') . ' again' : '') . '.</p>';
}

// The trash: deleting a team, player or owner marks it deleted (deleted_at, deleted_by) instead of
// removing it. It is hidden from the site and the admin until the root user restores it or
// deletes it for good (admin-trash.php).

/** Marks a row of teams, players or owners as deleted by the signed-in admin. */
function moveToTrash(string $table, int $id): void {
    adminExecute("UPDATE $table SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL", [signedInAdmin()['id'], $id]);
}
/**
 * The confirmation form for deleting a record. It reads as an ordinary delete: the trash is not
 * mentioned to admins, only the root user knows of it (and sees it on the Trash page).
 */
function deleteConfirmation(string $what, string $name, string $action, string $cancel, string $extra = ''): string {
    return '<p>Delete <strong>' . escape($name) . '</strong>? This can’t be undone.</p>' . $extra
        . '<form method="post" action="{{BASE}}' . $action . '" class="admin-form-actions"><input type="hidden" name="csrf_token" value="' . adminCsrfToken() . '">'
        . '<button type="submit" class="admin-button-danger">Delete ' . $what . '</button><a class="admin-button-secondary" href="{{BASE}}' . $cancel . '">Cancel</a></form>';
}
