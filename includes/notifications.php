<?php

/**
 * Data-layer only — no dedicated notifications inbox page. do_time_in()/
 * do_time_out()/do_start_break()/do_end_break() already call this to log
 * attendance events into the `notifications` table on every action; the two
 * read functions below let the Dashboard show a compact recent-activity widget
 * from that same, already-populated table.
 */
function notify_user(int $userId, string $type, string $title, string $message): void {
    global $pdo;
    $pdo->prepare(
        'INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)'
    )->execute([$userId, $type, $title, $message]);
}

/** The student's own most recent notifications, newest first. Always scoped to $userId. */
function get_recent_notifications(int $userId, int $limit = 5): array {
    global $pdo;
    $limit = max(1, min(20, $limit));
    $stmt = $pdo->prepare(
        "SELECT id, type, title, message, is_read, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT {$limit}"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Marks every currently-unread notification for $userId as read (e.g. once the dashboard has shown them). */
function mark_notifications_read(int $userId): void {
    global $pdo;
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$userId]);
}

/** Every notification for $userId, newest first, no cap — backs the full notification center. */
function get_all_notifications(int $userId): array {
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT id, type, title, message, is_read, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Marks one specific notification as read — always scoped to $userId, so one student can never mark another's. */
function mark_notification_read(int $userId, int $notificationId): void {
    global $pdo;
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$notificationId, $userId]);
}
