<?php

/**
 * Checks failed-attempt counts for both the submitted identifier (email/username)
 * and the requesting IP address over a rolling window.
 */
function too_many_attempts(string $identifier, string $ip, string $action, int $maxByIdentifier, int $maxByIp, int $windowMinutes): bool {
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM auth_attempts
         WHERE action = :action AND identifier = :identifier AND succeeded = 0
           AND created_at > (NOW() - INTERVAL :window MINUTE)'
    );
    $stmt->execute(['action' => $action, 'identifier' => $identifier, 'window' => $windowMinutes]);
    if ((int) $stmt->fetchColumn() >= $maxByIdentifier) {
        return true;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM auth_attempts
         WHERE action = :action AND ip_address = :ip AND succeeded = 0
           AND created_at > (NOW() - INTERVAL :window MINUTE)'
    );
    $stmt->execute(['action' => $action, 'ip' => $ip, 'window' => $windowMinutes]);
    return (int) $stmt->fetchColumn() >= $maxByIp;
}

function record_attempt(string $identifier, string $ip, string $action, bool $succeeded): void {
    global $pdo;
    $stmt = $pdo->prepare(
        'INSERT INTO auth_attempts (identifier, action, ip_address, succeeded) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$identifier, $action, $ip, $succeeded ? 1 : 0]);
}
