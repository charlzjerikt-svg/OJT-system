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
 * a cached session value for authorization decisions.
 */
function current_user(): ?array {
    static $cached = 'unset';
    if ($cached !== 'unset') {
        return $cached === null ? null : $cached;
    }

    if (empty($_SESSION['user_id'])) {
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
 * Destroys the session completely. Used by both the logout page and the
 * automatic kill-path inside current_user() when status/password go stale.
 */
function logout_user(): void {
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
 * Attempts to authenticate by email-or-username + password.
 * Returns ['ok' => true, 'user' => [...]] on success, or ['ok' => false, 'error' => '...'].
 * Does not itself handle rate limiting or session creation — callers do that.
 */
function attempt_login(string $identifier, string $password): array {
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT id, role, status, full_name, email, username, student_id, password_hash, password_changed_at
         FROM users WHERE email = ? OR username = ? LIMIT 1'
    );
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid email/username or password.'];
    }

    if ($user['status'] !== 'active') {
        return ['ok' => false, 'error' => 'Your account is inactive. Please contact the administrator.'];
    }

    return ['ok' => true, 'user' => $user];
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['pwd_changed_snapshot'] = strtotime($user['password_changed_at']);
}
