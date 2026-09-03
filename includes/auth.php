<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/rate_limit.php';

/**
 * Returns the current user's fresh row from the database, or null.
 * Re-checked from the DB on every call (memoized once per request) so that
 * role, status, and password changes take effect immediately — never trusts
 * a cached session value for authorization decisions. $_SESSION['role'] and
 * $_SESSION['authenticated'] are set at login for convenience/logging only;
 * every actual authorization check below re-derives from this DB row.
 */
function current_user(): ?array {
    static $cached = 'unset';
    if ($cached !== 'unset') {
        return $cached === null ? null : $cached;
    }

    if (empty($_SESSION['user_id'])) {
        $remembered = attempt_remember_login();
        if ($remembered) {
            login_user($remembered);
            $cached = $remembered;
            return $cached;
        }
        $cached = null;
        return null;
    }

    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT id, role, status, full_name, email, username, student_id, password_changed_at
         FROM users WHERE id = ?'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        logout_user();
        $cached = null;
        return null;
    }

    $liveChangedTs = strtotime($user['password_changed_at']);
    if (($_SESSION['pwd_changed_snapshot'] ?? null) !== $liveChangedTs) {
        logout_user();
        $cached = null;
        return null;
    }

    $cached = $user;
    return $user;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        redirect('/auth/login.php');
    }
    return $user;
}

function require_role(string $role): array {
    $user = require_login();
    if ($user['role'] !== $role) {
        http_response_code(403);
        exit('403 Forbidden — you do not have access to this page.');
    }
    return $user;
}

/**
 * Destroys the session completely and revokes any remember-me token this
 * browser presented. Used by both the logout page and the automatic
 * kill-path inside current_user() when status/password go stale.
 */
function logout_user(): void {
    $cookie = $_COOKIE[REMEMBER_COOKIE_NAME] ?? '';
    if (is_string($cookie) && str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        revoke_remember_token($selector);
    }
    clear_remember_cookie();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Attempts to authenticate by email/username/student-ID + password.
 * Returns ['ok' => true, 'user' => [...]] on success, or ['ok' => false, 'error' => '...'].
 * Does not itself handle rate limiting or session creation — callers do that.
 *
 * Password is checked BEFORE status: an account-status message is only ever
 * returned once the password has already proven the caller owns the account,
 * so a wrong password never leaks whether — or in what state — an account exists.
 */
function attempt_login(string $identifier, string $password): array {
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT id, role, status, full_name, email, username, student_id, password_hash, password_changed_at
         FROM users WHERE email = ? OR username = ? OR student_id = ? LIMIT 1'
    );
    $stmt->execute([$identifier, $identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid email/student ID or password.'];
    }

    $statusMessages = [
        'pending'  => 'Your account is still pending admin approval. Please wait for your account to be approved.',
        'inactive' => 'Your account is currently inactive. Please contact the administrator.',
        'rejected' => 'Your registration has been rejected. Please contact the administrator.',
    ];

    if ($user['status'] !== 'active') {
        return [
            'ok' => false,
            'error' => $statusMessages[$user['status']] ?? 'Your account cannot log in right now. Please contact the administrator.',
        ];
    }

    return ['ok' => true, 'user' => $user];
}

/**
 * Establishes an authenticated session for $user. Regenerates the session ID
 * first (prevents session fixation) and stores only non-secret identifiers —
 * never the password hash. $_SESSION['role']/['authenticated'] mirror the DB
 * row for convenience; they are not trusted for authorization (see current_user()).
 */
function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['authenticated'] = true;
    $_SESSION['pwd_changed_snapshot'] = strtotime($user['password_changed_at']);
}

/**
 * Issues a fresh "remember me" cookie + DB row for $userId, and sends the
 * cookie to the browser. See migration_004_login.sql for the selector/validator
 * rationale — only validator's hash is ever persisted.
 */
function issue_remember_token(int $userId): void {
    global $pdo;

    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expiresAt = time() + REMEMBER_ME_DAYS * 86400;

    $pdo->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)'
    )->execute([$userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expiresAt)]);

    set_remember_cookie($selector . ':' . $validator, $expiresAt);
}

function set_remember_cookie(string $value, int $expiresAt): void {
    setcookie(REMEMBER_COOKIE_NAME, $value, [
        'expires' => $expiresAt,
        'path' => '/',
        'httponly' => true,
        'secure' => APP_ENV === 'production',
        'samesite' => 'Lax',
    ]);
}

function clear_remember_cookie(): void {
    setcookie(REMEMBER_COOKIE_NAME, '', [
        'expires' => time() - 42000,
        'path' => '/',
        'httponly' => true,
        'secure' => APP_ENV === 'production',
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[REMEMBER_COOKIE_NAME]);
}

function revoke_remember_token(string $selector): void {
    global $pdo;
    $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
}

/**
 * Revokes every remember-me token for a user, on every device. Called on
 * password change/reset so a stolen or old device can't stay signed in
 * forever off a token that predates the new password.
 */
function revoke_all_remember_tokens(int $userId): void {
    global $pdo;
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
}

/**
 * Validates the remember-me cookie, if any, rotating it on success (a fresh
 * selector/validator is issued so a captured-then-replayed old cookie stops
 * working). Returns the user row (already confirmed 'active') or null.
 *
 * A selector that resolves but whose validator hash doesn't match is treated
 * as a possibly-stolen cookie: every token for that user is revoked as a
 * precaution, not just the one presented.
 */
function attempt_remember_login(): ?array {
    global $pdo;

    $cookie = $_COOKIE[REMEMBER_COOKIE_NAME] ?? '';
    if (!is_string($cookie) || !str_contains($cookie, ':')) {
        return null;
    }

    [$selector, $validator] = explode(':', $cookie, 2);

    $stmt = $pdo->prepare(
        'SELECT rt.user_id, rt.validator_hash, rt.expires_at,
                u.id, u.role, u.status, u.full_name, u.email, u.username, u.student_id, u.password_changed_at
         FROM remember_tokens rt
         JOIN users u ON u.id = rt.user_id
         WHERE rt.selector = ?
         LIMIT 1'
    );
    $stmt->execute([$selector]);
    $row = $stmt->fetch();

    if (!$row) {
        clear_remember_cookie();
        return null;
    }

    if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        revoke_all_remember_tokens((int) $row['user_id']);
        clear_remember_cookie();
        return null;
    }

    // The presented token is single-use regardless of outcome below.
    revoke_remember_token($selector);

    if (strtotime($row['expires_at']) < time() || $row['status'] !== 'active') {
        clear_remember_cookie();
        return null;
    }

    issue_remember_token((int) $row['user_id']);

    return [
        'id' => $row['id'],
        'role' => $row['role'],
        'status' => $row['status'],
        'full_name' => $row['full_name'],
        'email' => $row['email'],
        'username' => $row['username'],
        'student_id' => $row['student_id'],
        'password_changed_at' => $row['password_changed_at'],
    ];
}
