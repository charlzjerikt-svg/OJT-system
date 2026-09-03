<?php

/**
 * Data-layer only. includes/attendance.php's do_time_in()/do_time_out() (written
 * before the Student Dashboard task) already call this to log attendance events
 * into the existing `notifications` table — this file just supplies the function
 * so that code can run. It intentionally does NOT add a notifications inbox/UI;
 * that's a separate module, out of scope here.
 */
function notify_user(int $userId, string $type, string $title, string $message): void {
    global $pdo;
    $pdo->prepare(
        'INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)'
    )->execute([$userId, $type, $title, $message]);
}
