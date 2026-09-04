<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';

$admin = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/students.php');
}

csrf_verify();

$studentId = (int) ($_POST['student_id'] ?? 0);
$action = $_POST['action'] ?? '';
$reason = trim($_POST['reason'] ?? '');

$result = match ($action) {
    'approve' => admin_approve_student($admin['id'], $studentId),
    'reject' => $reason === ''
        ? ['ok' => false, 'error' => 'A reason is required to reject a registration.']
        : admin_reject_student($admin['id'], $studentId, $reason),
    'activate' => admin_activate_student($admin['id'], $studentId),
    'deactivate' => admin_deactivate_student($admin['id'], $studentId, $reason !== '' ? $reason : null),
    'reset_password' => admin_trigger_password_reset($admin['id'], $studentId),
    default => ['ok' => false, 'error' => 'Unknown action.'],
};

if ($result['ok']) {
    $messages = [
        'approve' => 'Registration approved.',
        'reject' => 'Registration rejected.',
        'activate' => 'Student account activated.',
        'deactivate' => 'Student account deactivated.',
        'reset_password' => ($result['email_sent'] ?? false)
            ? 'Password reset link sent to the student\'s email.'
            : 'Password reset was created, but the email could not be sent — check SMTP configuration.',
    ];
    set_flash('success', $messages[$action] ?? 'Done.');
} else {
    set_flash('error', $result['error']);
}

$returnTo = $_POST['return_to'] ?? '';
if ($returnTo === 'profile') {
    redirect('/admin/student_profile.php?id=' . $studentId);
}
redirect('/admin/students.php');
