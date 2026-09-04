<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';

$admin = require_role('admin');
global $pdo;

function valid_date_export(?string $v): ?string {
    if (!$v) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    return ($dt && $dt->format('Y-m-d') === $v) ? $v : null;
}

// Same filter semantics as admin/attendance.php's list view, but unpaginated
// (a bulk export, not a browsable table) and capped at a generous hard limit as
// a safety net against an unbounded query, not a UX pagination concern.
$search = trim($_GET['search'] ?? '');
$from = valid_date_export($_GET['from'] ?? null);
$to = valid_date_export($_GET['to'] ?? null);
$allowedStatuses = ['all', 'present', 'late', 'incomplete'];
$status = in_array($_GET['status'] ?? '', $allowedStatuses, true) ? $_GET['status'] : 'all';
$course = trim($_GET['course'] ?? '');
$company = trim($_GET['company'] ?? '');

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(u.full_name LIKE :search1 OR u.student_id LIKE :search2)';
    $searchTerm = '%' . $search . '%';
    $params['search1'] = $searchTerm;
    $params['search2'] = $searchTerm;
}
if ($from) {
    $where[] = 'a.attendance_date >= :from';
    $params['from'] = $from;
}
if ($to) {
    $where[] = 'a.attendance_date <= :to';
    $params['to'] = $to;
}
if ($status === 'present') {
    $where[] = "a.status = 'present'";
} elseif ($status === 'late') {
    $where[] = "a.status = 'late'";
} elseif ($status === 'incomplete') {
    $where[] = 'a.time_out IS NULL';
}
if ($course !== '') {
    $where[] = 'sp.course = :course';
    $params['course'] = $course;
}
if ($company !== '') {
    $where[] = 'sp.company = :company';
    $params['company'] = $company;
}

$stmt = $pdo->prepare(
    "SELECT a.id, u.student_id, u.full_name, a.attendance_date, a.time_in, a.time_out, a.status
     FROM attendance a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN student_profiles sp ON sp.user_id = u.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY a.attendance_date DESC, u.full_name ASC
     LIMIT 5000"
);
$stmt->execute($params);

log_admin_action($admin['id'], 'export_attendance', null, null, null, null);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="attendance_export_' . date('Y-m-d_His') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fputcsv($out, ['Student ID', 'Student Name', 'Date', 'Time In', 'Break', 'Time Out', 'Worked Hours', 'Status']);

while ($row = $stmt->fetch()) {
    $breakMinutes = calculate_break_minutes_for_attendance((int) $row['id']);
    $worked = $row['time_out'] ? format_minutes(calculate_worked_minutes($row, false)) : 'In Progress';
    fputcsv($out, [
        $row['student_id'] ?? '',
        $row['full_name'],
        $row['attendance_date'],
        $row['time_in'] ? date('g:i A', strtotime($row['time_in'])) : '',
        $breakMinutes > 0 ? format_minutes($breakMinutes) : '',
        $row['time_out'] ? date('g:i A', strtotime($row['time_out'])) : '',
        $worked,
        $row['time_out'] ? ucfirst($row['status']) : 'Incomplete',
    ]);
}
fclose($out);
exit;
