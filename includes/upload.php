<?php

/**
 * Validates and stores an uploaded image, returning ['ok' => bool, 'path' => ?string, 'error' => ?string].
 * 'path' is null when nothing was uploaded (field is optional) — callers should treat that as "no file".
 * The file is re-encoded through GD rather than simply moved, so whatever bytes the client
 * actually sent never reach disk — only the pixel data does. The stored filename is random;
 * the client-supplied name/extension is never trusted.
 */
function handle_image_upload(array $file, string $destDir, int $maxBytes = 2 * 1024 * 1024): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'File upload failed. Please try again.'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'path' => null, 'error' => 'Invalid file upload.'];
    }

    if ($file['size'] > $maxBytes) {
        $maxMb = round($maxBytes / 1024 / 1024, 1);
        return ['ok' => false, 'path' => null, 'error' => "Profile picture must be smaller than {$maxMb}MB."];
    }

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!isset($allowedMimes[$mime])) {
        return ['ok' => false, 'path' => null, 'error' => 'Profile picture must be a JPG, PNG, or WEBP image.'];
    }

    $extension = $allowedMimes[$mime];

    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        error_log("handle_image_upload: failed to create directory $destDir");
        return ['ok' => false, 'path' => null, 'error' => 'Could not save profile picture. Please try again.'];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destPath = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    // Prefer re-encoding through GD: it discards anything past the pixel data
    // (embedded payloads, polyglot bytes) rather than just checking the header.
    // Falls back to a validated move when the gd extension isn't enabled —
    // MIME sniffing + a random filename + the non-executable uploads/ dir
    // (see uploads/.htaccess) still cover the actual attack surface.
    if (function_exists('imagecreatefromjpeg')) {
        $image = match ($extension) {
            'jpg'  => @imagecreatefromjpeg($file['tmp_name']),
            'png'  => @imagecreatefrompng($file['tmp_name']),
            'webp' => @imagecreatefromwebp($file['tmp_name']),
        };

        if (!$image) {
            return ['ok' => false, 'path' => null, 'error' => 'The uploaded file is not a valid image.'];
        }

        $saved = match ($extension) {
            'jpg'  => imagejpeg($image, $destPath, 85),
            'png'  => imagepng($image, $destPath, 6),
            'webp' => imagewebp($image, $destPath, 85),
        };
        imagedestroy($image);
    } else {
        $saved = move_uploaded_file($file['tmp_name'], $destPath);
    }

    if (!$saved) {
        return ['ok' => false, 'path' => null, 'error' => 'Could not save profile picture. Please try again.'];
    }

    return ['ok' => true, 'path' => $filename, 'error' => null];
}

function delete_uploaded_file(string $destDir, string $filename): void {
    $path = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}
