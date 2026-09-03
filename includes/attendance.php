<?php
require_once __DIR__ . '/notifications.php';

/**
 * Returns the student's currently "open" attendance row (time_in set, time_out
 * not yet set) if one exists, else today's row, else null. Scoping "open"
 * sessions by time_out rather than attendance_date means a student who timed
 * in before midnight can still end their shift after midnight.
 */
function get_today_attendance(int $userId): ?array {
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT * FROM attendance WHERE user_id = ? AND time_out IS NULL ORDER BY attendance_date DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $open = $stmt->fetch();
    if ($open) {
        return $open;
    }

    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1');
    $stmt->execute([$userId, date('Y-m-d')]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_attendance_by_id(int $id): ?array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The student's currently open session (time_in set, time_out not yet set), if any. */
function get_open_attendance_row(int $userId): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT * FROM attendance WHERE user_id = ? AND time_out IS NULL ORDER BY attendance_date DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function is_valid_working_day(int $userId, DateTime $date): bool {
    global $pdo;
    $stmt = $pdo->prepare('SELECT rest_day FROM student_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $restDay = $stmt->fetchColumn();

    if ($restDay === false || $restDay === null) {
        return true; // no fixed rest day configured — every day is valid
    }

    return (int) $date->format('w') !== (int) $restDay;
}

/**
 * Fires a deduped "missing time out" notification if the student has an
 * older open session (time_in set, time_out null) from a previous day.
 * Never auto-fills a time_out — this only warns.
 */
function check_missing_time_out(int $userId): void {
    global $pdo;

    $stmt = $pdo->prepare(
        "SELECT id, attendance_date, time_in FROM attendance
         WHERE user_id = ? AND time_out IS NULL AND attendance_date < ?
         ORDER BY attendance_date DESC LIMIT 1"
    );
    $stmt->execute([$userId, date('Y-m-d')]);
    $stale = $stmt->fetch();
    if (!$stale) {
        return;
    }

    $dedupe = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications
         WHERE user_id = ? AND type = 'missing_time_out' AND created_at >= ?"
    );
    $dedupe->execute([$userId, $stale['time_in']]);
    if ((int) $dedupe->fetchColumn() > 0) {
        return; // already notified for this stale session
    }

    $formattedDate = (new DateTime($stale['attendance_date']))->format('F j, Y');
    notify_user(
        $userId,
        'missing_time_out',
        'Missing Time Out',
        "You have no recorded Time Out for {$formattedDate}. Please submit a correction request."
    );
}

/**
 * Result shape used by all four actions: ['ok' => bool, 'code' => string, 'message'|'error' => string, ...].
 * 'code' is a machine-readable reason (e.g. ALREADY_TIMED_IN) for callers that want to
 * branch on it; 'error'/'message' are the safe, user-facing text.
 *
 * Time In is authenticated and authorized entirely from $userId, which callers must
 * derive from the server-side session (see student/attendance_action.php) — never from
 * client input. The timestamp is always server-generated (`new DateTime()`), never
 * accepted from the caller, so a student can neither time in for someone else nor
 * manipulate the recorded time/date.
 */
function do_time_in(int $userId): array {
    global $pdo;

    $stmt = $pdo->prepare("SELECT role, status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $user['role'] !== 'student' || $user['status'] !== 'active') {
        return ['ok' => false, 'code' => 'FORBIDDEN', 'error' => 'Your account is not eligible to record attendance.'];
    }

    $now = new DateTime();
    if (!is_valid_working_day($userId, $now)) {
        return ['ok' => false, 'code' => 'REST_DAY', 'error' => 'Today is your designated rest day. Time In is not available.'];
    }

    // Fast, friendly pre-check for an already-open session — including one from a prior
    // day (e.g. an unclosed overnight shift), which the UNIQUE(user_id, attendance_date)
    // constraint alone would NOT catch, since that new INSERT would target a different
    // date. This is a UX nicety only; the constraint below (inside the transaction) is
    // what actually guarantees no duplicate/overlapping row under concurrent requests.
    if (get_open_attendance_row($userId)) {
        return ['ok' => false, 'code' => 'ALREADY_ACTIVE', 'error' => 'You are already timed in.'];
    }

    check_missing_time_out($userId);

    $cutoff = new DateTime(date('Y-m-d') . ' ' . WORKDAY_START_TIME);
    $cutoff->modify('+' . LATE_GRACE_MINUTES . ' minutes');
    $status = $now > $cutoff ? 'late' : 'present';

    // Atomic: the row is only ever committed once the ojt_status transition has also
    // succeeded, and the UNIQUE constraint makes the INSERT itself race-safe under
    // concurrent requests (double-click, two tabs, simultaneous retries) — only one
    // can ever win; every other caller lands in the catch below.
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO attendance (user_id, attendance_date, time_in, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $now->format('Y-m-d'), $now->format('Y-m-d H:i:s'), $status]);

        $profileStmt = $pdo->prepare('SELECT ojt_status FROM student_profiles WHERE user_id = ?');
        $profileStmt->execute([$userId]);
        if ($profileStmt->fetchColumn() === 'not_started') {
            $pdo->prepare('UPDATE student_profiles SET ojt_status = ? WHERE user_id = ?')
                ->execute(['ongoing', $userId]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((int) $e->getCode() === 23000 || $e->getCode() === '23000') {
            return ['ok' => false, 'code' => 'ALREADY_TIMED_IN', 'error' => 'You have already timed in today.'];
        }
        error_log('do_time_in failed: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'SERVER_ERROR', 'error' => 'Something went wrong recording your Time In. Please try again.'];
    }

    // Best-effort side effects, deliberately outside the transaction: a notification
    // insert failing here must never roll back an already-committed, legitimate Time In.
    notify_user($userId, 'time_in', 'Time In Recorded', 'Your Time In was recorded at ' . $now->format('g:i:s A') . '.');
    if ($status === 'late') {
        notify_user($userId, 'late', 'Late Time In', 'Your Time In today was marked Late.');
    }

    return [
        'ok' => true,
        'code' => 'OK',
        'message' => 'Time In recorded at ' . $now->format('g:i:s A') . '.',
        'attendance_date' => $now->format('Y-m-d'),
        'time_in' => $now->format('g:i:s A'),
        'status' => $status,
    ];
}

function do_start_break(int $userId): array {
    global $pdo;
    $now = new DateTime();

    $stmt = $pdo->prepare(
        'UPDATE attendance SET break_start = ?
         WHERE user_id = ? AND time_out IS NULL AND time_in IS NOT NULL AND break_start IS NULL'
    );
    $stmt->execute([$now->format('Y-m-d H:i:s'), $userId]);

    if ($stmt->rowCount() === 0) {
        $row = get_today_attendance($userId);
        if (!$row || !$row['time_in']) {
            return ['ok' => false, 'error' => 'You must Time In before starting a break.'];
        }
        if ($row['time_out']) {
            return ['ok' => false, 'error' => 'You have already timed out; breaks are no longer available.'];
        }
        return ['ok' => false, 'error' => 'A break is already in progress.'];
    }

    notify_user($userId, 'break_start', 'Break Started', 'Your break started at ' . $now->format('g:i A') . '.');
    return ['ok' => true, 'message' => 'Break started at ' . $now->format('g:i A') . '.'];
}

function do_end_break(int $userId): array {
    global $pdo;
    $now = new DateTime();

    $stmt = $pdo->prepare(
        'UPDATE attendance SET break_end = ?
         WHERE user_id = ? AND time_out IS NULL AND break_start IS NOT NULL AND break_end IS NULL'
    );
    $stmt->execute([$now->format('Y-m-d H:i:s'), $userId]);

    if ($stmt->rowCount() === 0) {
        $row = get_today_attendance($userId);
        if (!$row || !$row['break_start']) {
            return ['ok' => false, 'error' => 'No active break to end.'];
        }
        return ['ok' => false, 'error' => 'Break has already ended.'];
    }

    $row = get_today_attendance($userId);
    $breakMinutes = (int) round((strtotime($row['break_end']) - strtotime($row['break_start'])) / 60);
    $h = intdiv($breakMinutes, 60);
    $m = $breakMinutes % 60;
    $duration = ($h > 0 ? "{$h}h " : '') . "{$m}m";

    notify_user($userId, 'break_end', 'Break Ended', 'Your break ended at ' . $now->format('g:i A') . " (duration {$duration}).");
    return ['ok' => true, 'message' => 'Break ended at ' . $now->format('g:i A') . ". Break duration: {$duration}."];
}

/**
 * Time Out is authenticated/authorized entirely from $userId (server-side session —
 * see student/attendance_action.php), never from a client-supplied attendance ID or
 * student ID. The timestamp is always server-generated. "COMPLETED" (returned in
 * 'status' below) is a computed workflow state, not a stored value — attendance.status
 * stays the punctuality flag ('present'/'late') set once at Time In; conflating the two
 * would be a schema regression, not a fix.
 */
function do_time_out(int $userId): array {
    global $pdo;
    $now = new DateTime();

    // Pre-check outside the transaction: cheap, and gives a precise NO_ACTIVE_SESSION
    // vs ALREADY_TIMED_OUT message. The atomic UPDATE below (scoped to this exact row's
    // id) is what actually guarantees correctness under a race — this pre-check can go
    // stale between here and the UPDATE, and that's fine, it's just handled below.
    $openRow = get_open_attendance_row($userId);
    if (!$openRow) {
        $stmtCheck = $pdo->prepare(
            'SELECT 1 FROM attendance WHERE user_id = ? AND attendance_date = ? AND time_out IS NOT NULL LIMIT 1'
        );
        $stmtCheck->execute([$userId, $now->format('Y-m-d')]);
        if ($stmtCheck->fetchColumn()) {
            return ['ok' => false, 'code' => 'ALREADY_TIMED_OUT', 'error' => 'You have already timed out today.'];
        }
        return ['ok' => false, 'code' => 'NO_ACTIVE_SESSION', 'error' => 'You cannot Time Out because you have not Timed In today.'];
    }

    if ($openRow['break_start'] && !$openRow['break_end']) {
        return ['ok' => false, 'code' => 'BREAK_IN_PROGRESS', 'error' => 'Please end your break before timing out.'];
    }

    $ojtCompletedNow = false;
    $workedMinutes = 0;

    $pdo->beginTransaction();
    try {
        // Scoped to this exact row's primary key (captured above) plus the same
        // time_out IS NULL guard — row-level locking makes this atomic: under a true
        // race, only the first concurrent request can ever match and update it.
        $stmt = $pdo->prepare(
            'UPDATE attendance SET time_out = ?
             WHERE id = ? AND user_id = ? AND time_out IS NULL AND time_in IS NOT NULL
               AND (break_start IS NULL OR break_end IS NOT NULL)'
        );
        $stmt->execute([$now->format('Y-m-d H:i:s'), $openRow['id'], $userId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 'ALREADY_TIMED_OUT', 'error' => 'You have already timed out today.'];
        }

        // TOTAL WORKED = Time Out − Time In, computed from the actual stored
        // datetimes (never a formatted display string). No break deduction: this
        // version has no break functionality, and break_start/break_end are never set.
        $workedMinutes = max(0, (int) round(($now->getTimestamp() - strtotime($openRow['time_in'])) / 60));

        $summary = calculate_ojt_summary($userId);
        // Same connection/transaction sees its own uncommitted write above, so this
        // already reflects today's just-recorded hours.
        if ($summary['remaining_minutes'] <= 0) {
            $profileStmt = $pdo->prepare('SELECT ojt_status FROM student_profiles WHERE user_id = ?');
            $profileStmt->execute([$userId]);
            if ($profileStmt->fetchColumn() !== 'completed') {
                $pdo->prepare('UPDATE student_profiles SET ojt_status = ? WHERE user_id = ?')
                    ->execute(['completed', $userId]);
                $ojtCompletedNow = true;
            }
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('do_time_out failed: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'SERVER_ERROR', 'error' => 'Something went wrong recording your Time Out. Please try again.'];
    }

    $hoursText = format_minutes($workedMinutes);

    // Best-effort side effects, deliberately outside the transaction — a notification
    // insert failing here must never roll back an already-committed Time Out.
    notify_user($userId, 'time_out', 'Time Out Recorded', 'Your Time Out was recorded at ' . $now->format('g:i:s A') . ". Working hours: {$hoursText}.");
    if ($ojtCompletedNow) {
        notify_user($userId, 'ojt_completed', 'OJT Requirement Completed', 'Congratulations! You have completed your required OJT hours.');
    }

    return [
        'ok' => true,
        'code' => 'OK',
        'message' => 'Time Out recorded at ' . $now->format('g:i:s A') . ". Today's working hours: {$hoursText}.",
        'attendance_date' => $openRow['attendance_date'],
        'time_in' => date('g:i:s A', strtotime($openRow['time_in'])),
        'time_out' => $now->format('g:i:s A'),
        'total_hours' => $hoursText,
        'status' => 'COMPLETED',
    ];
}

/**
 * Minutes worked for one attendance row. While still clocked in (no time_out),
 * computes a live "so far" figure using the current time, stopping at
 * break_start if currently on break — this value is never stored.
 */
function calculate_worked_minutes(array $row, bool $liveIfOngoing = true): int {
    if (!$row['time_in']) {
        return 0;
    }

    $start = strtotime($row['time_in']);
    $end = $row['time_out'] ? strtotime($row['time_out']) : ($liveIfOngoing ? time() : null);

    if ($row['time_out'] === null && $row['break_start'] && !$row['break_end'] && $liveIfOngoing) {
        $end = strtotime($row['break_start']);
    }

    if ($end === null) {
        return 0;
    }

    $totalMinutes = max(0, (int) round(($end - $start) / 60));

    $breakMinutes = 0;
    if ($row['break_start'] && $row['break_end']) {
        $breakMinutes = max(0, (int) round((strtotime($row['break_end']) - strtotime($row['break_start'])) / 60));
    }

    return max(0, $totalMinutes - $breakMinutes);
}

function calculate_break_minutes(array $row): ?int {
    if (!$row['break_start'] || !$row['break_end']) {
        return null;
    }
    return max(0, (int) round((strtotime($row['break_end']) - strtotime($row['break_start'])) / 60));
}

function format_minutes(int $minutes): string {
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return "{$h}h " . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . 'm';
}

/**
 * Required/completed/remaining minutes + percent, derived live from completed
 * (time_out IS NOT NULL) attendance rows only — never a stored/editable total.
 */
function calculate_ojt_summary(int $userId): array {
    global $pdo;

    $profileStmt = $pdo->prepare('SELECT required_minutes FROM student_profiles WHERE user_id = ?');
    $profileStmt->execute([$userId]);
    $requiredMinutes = (int) ($profileStmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare(
        'SELECT time_in, break_start, break_end, time_out FROM attendance
         WHERE user_id = ? AND time_out IS NOT NULL'
    );
    $stmt->execute([$userId]);

    $completedMinutes = 0;
    foreach ($stmt->fetchAll() as $row) {
        $completedMinutes += calculate_worked_minutes($row, false);
    }

    $remainingMinutes = max(0, $requiredMinutes - $completedMinutes);
    $percent = $requiredMinutes > 0 ? min(100, ($completedMinutes / $requiredMinutes) * 100) : 0;

    return [
        'required_minutes' => $requiredMinutes,
        'completed_minutes' => $completedMinutes,
        'remaining_minutes' => $remainingMinutes,
        'percent' => $percent,
    ];
}
