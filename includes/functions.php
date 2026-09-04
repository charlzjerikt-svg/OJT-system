<?php

function redirect(string $path): void {
    header('Location: ' . APP_URL . $path);
    exit;
}

function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Builds a static asset URL with a cache-busting query string derived from the
 * file's own last-modified time — so a browser that already cached an old
 * version of e.g. dashboard.js is guaranteed to fetch the new one the moment
 * the file changes on disk, with no manual "hard refresh" required. $path is
 * relative to the project root, e.g. '/assets/js/dashboard.js'.
 */
function asset_url(string $path): string {
    $fsPath = __DIR__ . '/..' . $path;
    $version = is_file($fsPath) ? filemtime($fsPath) : time();
    return APP_URL . $path . '?v=' . $version;
}

/**
 * Returns an array of validation error messages, empty if the password is strong enough.
 */
function validate_password_strength(string $password): array {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    return $errors;
}

function is_valid_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
