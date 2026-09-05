<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';

$user = require_role('student');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/student/notifications.php');
}

csrf_verify();

$action = $_POST['action'] ?? '';

if ($action === 'mark_read') {
    $notificationId = (int) ($_POST['notification_id'] ?? 0);
    mark_notification_read($user['id'], $notificationId);
} elseif ($action === 'mark_all_read') {
    mark_notifications_read($user['id']);
}

redirect('/student/notifications.php');
