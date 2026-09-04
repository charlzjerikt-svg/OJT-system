<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';

$user = require_role('student');

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

csrf_verify();

$action = $_POST['action'] ?? '';

// The authenticated student's own ID (from the server-side session, established at
// login) is the only source of "whose attendance" — never a client-supplied ID —
// so a student can never act on another student's attendance record. Nothing here
// reads a date, time, or student identifier from the request body.
$result = match ($action) {
    'time_in' => do_time_in($user['id']),
    'start_break' => do_start_break($user['id']),
    'end_break' => do_end_break($user['id']),
    'time_out' => do_time_out($user['id']),
    default => ['ok' => false, 'code' => 'UNKNOWN_ACTION', 'error' => 'Unknown action.'],
};

if (!$result['ok']) {
    json_response([
        'success' => false,
        'code' => $result['code'] ?? 'ERROR',
        'message' => $result['error'],
    ], 422);
}

set_flash('success', $result['message']);

$response = ['success' => true, 'message' => $result['message']];
if ($action === 'time_in') {
    $response['data'] = [
        'attendance_date' => $result['attendance_date'],
        'time_in' => $result['time_in'],
        'status' => strtoupper($result['status']),
    ];
} elseif ($action === 'time_out') {
    $response['data'] = [
        'attendance_date' => $result['attendance_date'],
        'time_in' => $result['time_in'],
        'time_out' => $result['time_out'],
        'total_hours' => $result['total_hours'],
        'status' => $result['status'],
    ];
} elseif ($action === 'start_break') {
    $response['data'] = ['break_start' => $result['break_start']];
} elseif ($action === 'end_break') {
    $response['data'] = ['break_end' => $result['break_end'], 'duration' => $result['duration']];
}
json_response($response);
