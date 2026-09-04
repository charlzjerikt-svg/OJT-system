<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';

$admin = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/students.php');
}

csrf_verify();

$studentId = (int) ($_POST['student_id'] ?? 0);
$days = $_POST['days'] ?? [];

// Sanitize into the exact shape save_student_schedule() expects — never trust
// the raw POST array structure or its time-string formats beyond what's needed.
$sanitized = [];
for ($dow = 0; $dow <= 6; $dow++) {
    $raw = is_array($days[$dow] ?? null) ? $days[$dow] : [];
    $sanitized[$dow] = [
        'is_active' => !empty($raw['is_active']),
        'start_time' => preg_match('/^\d{2}:\d{2}$/', $raw['start_time'] ?? '') ? $raw['start_time'] . ':00' : null,
        'end_time' => preg_match('/^\d{2}:\d{2}$/', $raw['end_time'] ?? '') ? $raw['end_time'] . ':00' : null,
        'break_start' => preg_match('/^\d{2}:\d{2}$/', $raw['break_start'] ?? '') ? $raw['break_start'] . ':00' : null,
        'break_end' => preg_match('/^\d{2}:\d{2}$/', $raw['break_end'] ?? '') ? $raw['break_end'] . ':00' : null,
    ];
}

$result = save_student_schedule($admin['id'], $studentId, $sanitized);

if ($result['ok']) {
    set_flash('success', 'Schedule updated.');
} else {
    set_flash('error', $result['error']);
}

redirect('/admin/student_schedule.php?id=' . $studentId);
