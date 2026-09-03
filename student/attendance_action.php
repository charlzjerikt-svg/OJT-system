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
// so a student can never act on another student's attendance record.
$result = match ($action) {
    'time_in' => do_time_in($user['id']),
    'time_out' => do_time_out($user['id']),
    default => ['ok' => false, 'error' => 'Unknown action.'],
};

if (!$result['ok']) {
    json_response(['success' => false, 'message' => $result['error']], 422);
}

set_flash('success', $result['message']);
json_response(['success' => true, 'message' => $result['message']]);
