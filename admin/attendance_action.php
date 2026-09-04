<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';

$admin = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/attendance.php');
}

csrf_verify();

$action = $_POST['action'] ?? '';
$reason = trim($_POST['reason'] ?? '');

if ($action === 'edit') {
    $attendanceId = (int) ($_POST['attendance_id'] ?? 0);
    $timeIn = trim($_POST['time_in'] ?? '');
    $timeOut = trim($_POST['time_out'] ?? '');
    $result = admin_edit_attendance($admin['id'], $attendanceId, $timeIn, $timeOut ?: null, $reason);
    $successMessage = 'Attendance record corrected.';
} elseif ($action === 'manual') {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $date = trim($_POST['attendance_date'] ?? '');
    $timeIn = trim($_POST['time_in'] ?? '');
    $timeOut = trim($_POST['time_out'] ?? '');
    $result = admin_manual_attendance($admin['id'], $studentId, $date, $timeIn, $timeOut ?: null, $reason);
    $successMessage = 'Manual attendance record created.';
} else {
    $result = ['ok' => false, 'error' => 'Unknown action.'];
    $successMessage = '';
}

if ($result['ok']) {
    set_flash('success', $successMessage);
} else {
    set_flash('error', $result['error']);
}

redirect('/admin/attendance.php');
