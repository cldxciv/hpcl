<?php
declare(strict_types=1);

// The optional photo of a membership applicant (membership join form). It is shown on the member's
// public profile and on their social-media creative, so it is public: stored under
// storage/uploads/members/photos. The upload is checked by content, upright by its EXIF
// orientation, shrunk and saved again as a plain JPEG, so nothing the visitor sent is kept as is.

const MEMBER_PHOTO_DIR = 'storage/uploads/members/photos';
const MEMBER_PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const MEMBER_PHOTO_MAX_SIDE = 1200;
const MEMBER_PHOTO_MAX_PIXELS = 36000000;
const MEMBER_PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/** The posted photo's problem as a message, or null when there is none or it is fine. */
function memberPhotoCheck(string $field = 'photo'): ?string {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $file['size'] > MEMBER_PHOTO_MAX_BYTES) return 'The photo must be 5 MB or smaller.';
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return 'The photo could not be uploaded. Please try again.';
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $size = @getimagesize($file['tmp_name']);
    if (!in_array($type, MEMBER_PHOTO_TYPES, true) || !$size) return 'The photo must be a JPEG, PNG or WebP image.';
    if ($size[0] * $size[1] > MEMBER_PHOTO_MAX_PIXELS) return 'The photo is too large in pixels. Please choose a smaller one.';
    return null;
}

/** Saves the checked photo; returns its path (relative to the project), or null when none was sent or it can't be saved. */
function memberPhotoSave(string $field = 'photo'): ?string {
    if (memberPhotoCheck($field) !== null || ($_FILES[$field]['error'] ?? null) !== UPLOAD_ERR_OK) return null;
    $tmp = $_FILES[$field]['tmp_name'];
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $image = match ($type) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png' => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
        default => false,
    };
    if (!$image) return null;
    // Phone photos are stored sideways with an orientation flag: turn them upright.
    if ($type === 'image/jpeg' && function_exists('exif_read_data')) {
        $orientation = (int) (@exif_read_data($tmp)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle && ($turned = imagerotate($image, $angle, 0))) $image = $turned;
    }
    [$width, $height] = [imagesx($image), imagesy($image)];
    $scale = min(1, MEMBER_PHOTO_MAX_SIDE / max($width, $height));
    $canvas = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // a transparent PNG gets a white background
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);

    $folder = dirname(__DIR__) . '/' . MEMBER_PHOTO_DIR;
    if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
        error_log('Could not create ' . MEMBER_PHOTO_DIR . ' for a member photo');
        return null;
    }
    $path = MEMBER_PHOTO_DIR . '/' . bin2hex(random_bytes(20)) . '.jpg';
    if (!imagejpeg($canvas, dirname(__DIR__) . '/' . $path, 86)) {
        error_log('Could not save a member photo to ' . MEMBER_PHOTO_DIR);
        return null;
    }
    return $path;
}

/** Removes a photo saved by memberPhotoSave() (when its application is not kept). */
function memberPhotoDelete(?string $path): void {
    if ($path && str_starts_with($path, MEMBER_PHOTO_DIR . '/') && !str_contains($path, '..')) @unlink(dirname(__DIR__) . '/' . $path);
}
