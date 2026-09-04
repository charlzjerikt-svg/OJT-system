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

/**
 * A day is invalid for attendance if an explicit per-day ojt_schedules row marks
 * it inactive (admin-assigned day off — this always wins when present), or, absent
 * an explicit schedule for that day, if it matches the legacy single rest_day field
 * on student_profiles (kept for backward compatibility with existing profiles).
 */
function is_valid_working_day(int $userId, DateTime $date): bool {
    global $pdo;
    $dow = (int) $date->format('w');

    $stmt = $pdo->prepare('SELECT is_active FROM ojt_schedules WHERE user_id = ? AND day_of_week = ?');
    $stmt->execute([$userId, $dow]);
    $isActive = $stmt->fetchColumn();
    if ($isActive !== false) {
        return (bool) $isActive;
    }

    $stmt = $pdo->prepare('SELECT rest_day FROM student_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $restDay = $stmt->fetchColumn();

    if ($restDay === false || $restDay === null) {
        return true; // no fixed rest day configured — every day is valid
    }

    return $dow !== (int) $restDay;
}

/**
 * The student's expected schedule for $date — an explicit per-day ojt_schedules
 * row if the admin set one, else the app-wide defaults from config.php. This is
 * what the student is EXPECTED to follow; it must never be confused with or
 * overwritten by actual recorded attendance, which lives entirely in
 * attendance/breaks.
 */
function get_effective_schedule(int $userId, DateTime $date): array {
    global $pdo;
    $dow = (int) $date->format('w');

    $stmt = $pdo->prepare(
        'SELECT start_time, end_time, break_start, break_end, is_active
         FROM ojt_schedules WHERE user_id = ? AND day_of_week = ?'
    );
    $stmt->execute([$userId, $dow]);
    $row = $stmt->fetch();

    if ($row) {
        return [
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'break_start' => $row['break_start'],
            'break_end' => $row['break_end'],
            'is_active' => (bool) $row['is_active'],
            'source' => 'custom',
        ];
    }

    return [
        'start_time' => WORKDAY_START_TIME,
        'end_time' => WORKDAY_END_TIME,
        'break_start' => DEFAULT_BREAK_START,
        'break_end' => DEFAULT_BREAK_END,
        'is_active' => true,
        'source' => 'default',
    ];
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

/** The currently open (unended) break for one attendance row, if any. */
function get_active_break(int $attendanceId): ?array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM breaks WHERE attendance_id = ? AND break_end IS NULL LIMIT 1');
    $stmt->execute([$attendanceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** All breaks (completed and, if any, active) for one attendance row, oldest first. */
function get_breaks_for_attendance(int $attendanceId): array {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM breaks WHERE attendance_id = ? ORDER BY break_start ASC');
    $stmt->execute([$attendanceId]);
    return $stmt->fetchAll();
}

/**
 * Total break seconds for one attendance row. Only completed breaks
 * (break_end IS NOT NULL) count toward worked-hours deductions — an in-progress
 * break's "so far" duration is never subtracted from worked time until it ends.
 */
function sum_break_seconds(int $attendanceId): int {
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(duration_seconds), 0) FROM breaks WHERE attendance_id = ? AND break_end IS NOT NULL'
    );
    $stmt->execute([$attendanceId]);
    return (int) $stmt->fetchColumn();
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

    // Late/present is judged against THIS student's expected schedule for today
    // (an admin-assigned override, or the app-wide default) — never a single
    // hardcoded cutoff, since different students/companies can have different hours.
    $schedule = get_effective_schedule($userId, $now);
    $cutoff = new DateTime($now->format('Y-m-d') . ' ' . $schedule['start_time']);
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

/**
 * Starts a new break for the student's currently open attendance row. Supports
 * multiple breaks per day (a new row is inserted each time, not a single
 * fixed pair of columns). Race-safe via breaks.uq_breaks_one_active (see
 * migration_007_schedule_and_breaks.sql): two concurrent Start Break requests
 * can only ever have one succeed — the loser hits the constraint below.
 */
function do_start_break(int $userId): array {
    global $pdo;
    $now = new DateTime();

    $openRow = get_open_attendance_row($userId);
    if (!$openRow || !$openRow['time_in']) {
        return ['ok' => false, 'code' => 'NO_ACTIVE_SESSION', 'error' => 'You must Time In before starting a break.'];
    }

    if (get_active_break((int) $openRow['id'])) {
        return ['ok' => false, 'code' => 'ALREADY_ON_BREAK', 'error' => 'A break is already in progress.'];
    }

    try {
        $pdo->prepare('INSERT INTO breaks (attendance_id, break_start) VALUES (?, ?)')
            ->execute([$openRow['id'], $now->format('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000 || $e->getCode() === '23000') {
            return ['ok' => false, 'code' => 'ALREADY_ON_BREAK', 'error' => 'A break is already in progress.'];
        }
        error_log('do_start_break failed: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'SERVER_ERROR', 'error' => 'Something went wrong starting your break. Please try again.'];
    }

    notify_user($userId, 'break_start', 'Break Started', 'Your break started at ' . $now->format('g:i A') . '.');

    return [
        'ok' => true,
        'code' => 'OK',
        'message' => 'Break started at ' . $now->format('g:i A') . '.',
        'break_start' => $now->format('g:i:s A'),
    ];
}

/**
 * Ends the student's currently active break. Atomic via the same
 * "UPDATE ... WHERE ... AND break_end IS NULL, then check rowCount()" pattern
 * used by do_time_out() — row-level locking makes this race-safe under a
 * double-click or two tabs.
 */
function do_end_break(int $userId): array {
    global $pdo;
    $now = new DateTime();

    $openRow = get_open_attendance_row($userId);
    if (!$openRow) {
        return ['ok' => false, 'code' => 'NO_ACTIVE_SESSION', 'error' => 'You have not timed in today.'];
    }

    $activeBreak = get_active_break((int) $openRow['id']);
    if (!$activeBreak) {
        return ['ok' => false, 'code' => 'NO_ACTIVE_BREAK', 'error' => 'No active break to end.'];
    }

    $durationSeconds = max(0, $now->getTimestamp() - strtotime($activeBreak['break_start']));

    $stmt = $pdo->prepare('UPDATE breaks SET break_end = ?, duration_seconds = ? WHERE id = ? AND break_end IS NULL');
    $stmt->execute([$now->format('Y-m-d H:i:s'), $durationSeconds, $activeBreak['id']]);

    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'code' => 'NO_ACTIVE_BREAK', 'error' => 'Break has already ended.'];
    }

    $duration = format_minutes((int) round($durationSeconds / 60));
    notify_user($userId, 'break_end', 'Break Ended', 'Your break ended at ' . $now->format('g:i A') . " (duration {$duration}).");

    return [
        'ok' => true,
        'code' => 'OK',
        'message' => 'Break ended at ' . $now->format('g:i A') . ". Break duration: {$duration}.",
        'break_end' => $now->format('g:i:s A'),
        'duration' => $duration,
    ];
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

    if (get_active_break((int) $openRow['id'])) {
        return ['ok' => false, 'code' => 'BREAK_IN_PROGRESS', 'error' => 'Please end your break before timing out.'];
    }

    $ojtCompletedNow = false;
    $workedMinutes = 0;

    $pdo->beginTransaction();
    try {
        // Scoped to this exact row's primary key (captured above) plus the same
        // time_out IS NULL guard — row-level locking makes this atomic: under a true
        // race, only the first concurrent request can ever match and update it. The
        // NOT EXISTS guard re-checks for an active break at the moment of the write,
        // so a break started concurrently (after the pre-check above, before this
        // UPDATE runs) still can't be missed — the write simply fails, same as any
        // other race here, rather than timing out over an open break.
        $stmt = $pdo->prepare(
            'UPDATE attendance SET time_out = ?
             WHERE id = ? AND user_id = ? AND time_out IS NULL AND time_in IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM breaks WHERE breaks.attendance_id = attendance.id AND breaks.break_end IS NULL)'
        );
        $stmt->execute([$now->format('Y-m-d H:i:s'), $openRow['id'], $userId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            if (get_active_break((int) $openRow['id'])) {
                return ['ok' => false, 'code' => 'BREAK_IN_PROGRESS', 'error' => 'Please end your break before timing out.'];
            }
            return ['ok' => false, 'code' => 'ALREADY_TIMED_OUT', 'error' => 'You have already timed out today.'];
        }

        // TOTAL WORKED = Time Out − Time In − total completed break duration,
        // computed from the actual stored datetimes/durations (never a formatted
        // display string) — the same formula calculate_worked_minutes() uses
        // everywhere else, applied here directly since we're already inside the
        // transaction and know the exact end timestamp.
        $elapsedMinutes = max(0, (int) round(($now->getTimestamp() - strtotime($openRow['time_in'])) / 60));
        $breakMinutes = (int) round(sum_break_seconds((int) $openRow['id']) / 60);
        $workedMinutes = max(0, $elapsedMinutes - $breakMinutes);

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
 * Minutes worked for one attendance row — the single source of truth reused
 * identically by the Dashboard, Attendance History, OJT Progress, and Admin
 * Attendance. Requires $row['id'] (the attendance row's primary key) so it can
 * look up actual recorded breaks for that day.
 *
 * WORKED = (Time Out or now) − Time In − total completed break duration.
 *
 * While still clocked in (no time_out) and $liveIfOngoing, computes a live
 * "so far" figure using the current time — stopping at the active break's
 * start if one is in progress, since time spent on an unfinished break isn't
 * worked time yet. This live figure is never stored, only ever computed.
 */
function calculate_worked_minutes(array $row, bool $liveIfOngoing = true): int {
    if (!$row['time_in']) {
        return 0;
    }

    $attendanceId = (int) $row['id'];
    $start = strtotime($row['time_in']);
    $activeBreak = ($liveIfOngoing && !$row['time_out']) ? get_active_break($attendanceId) : null;

    $end = $row['time_out'] ? strtotime($row['time_out']) : ($liveIfOngoing ? time() : null);
    if ($activeBreak) {
        $end = strtotime($activeBreak['break_start']);
    }

    if ($end === null) {
        return 0;
    }

    $totalMinutes = max(0, (int) round(($end - $start) / 60));
    $breakMinutes = (int) round(sum_break_seconds($attendanceId) / 60);

    return max(0, $totalMinutes - $breakMinutes);
}

/** Total completed break minutes for one attendance row, for display purposes. */
function calculate_break_minutes_for_attendance(int $attendanceId): int {
    return (int) round(sum_break_seconds($attendanceId) / 60);
}

function format_minutes(int $minutes): string {
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return "{$h}h " . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . 'm';
}

/**
 * Derives the OJT status purely from calculate_ojt_summary()'s own numbers — never
 * from the stored student_profiles.ojt_status column. That column is still updated
 * as a side effect inside do_time_in()/do_time_out() (it drives the "OJT completed"
 * notification), but it must never be the thing a page *displays*: this function is
 * what guarantees the shown status can never disagree with the shown hours, even in
 * an edge case the stored column hasn't caught up to yet.
 */
function ojt_status_from_summary(array $summary): array {
    $labels = ['not_started' => 'Not Started', 'ongoing' => 'In Progress', 'completed' => 'Completed'];

    if ($summary['required_minutes'] <= 0 || $summary['completed_minutes'] <= 0) {
        $key = 'not_started';
    } elseif ($summary['completed_minutes'] >= $summary['required_minutes']) {
        $key = 'completed';
    } else {
        $key = 'ongoing';
    }

    return ['key' => $key, 'label' => $labels[$key]];
}

/**
 * Filtered, paginated attendance history for one student. Always scoped to $userId
 * (callers must derive this from the server-side session — see
 * student/attendance_history.php) — never accepts a caller-supplied identifier, so
 * there is no query shape that can leak another student's rows.
 *
 * $filters: from/to (Y-m-d, optional), status ('all'|'present'|'late'|'incomplete'),
 * page, per_page. Returns ['rows' => [...], 'total' => int].
 */
function get_attendance_history(int $userId, array $filters): array {
    global $pdo;

    $where = ['user_id = :user_id'];
    $params = ['user_id' => $userId];

    if (!empty($filters['from'])) {
        $where[] = 'attendance_date >= :from';
        $params['from'] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $where[] = 'attendance_date <= :to';
        $params['to'] = $filters['to'];
    }

    $status = $filters['status'] ?? 'all';
    if ($status === 'present') {
        $where[] = "status = 'present'";
    } elseif ($status === 'late') {
        $where[] = "status = 'late'";
    } elseif ($status === 'incomplete') {
        $where[] = 'time_out IS NULL';
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // LIMIT/OFFSET are interpolated (not bound as placeholders) because they are
    // validated PHP ints by this point, never raw request strings — PDO's native
    // prepares (this project runs EMULATE_PREPARES => false) require these as an
    // actual integer type, which execute()'s string-keyed array can't guarantee.
    $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
    $page = max(1, (int) ($filters['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT id, attendance_date, time_in, time_out, status
         FROM attendance
         WHERE {$whereSql}
         ORDER BY attendance_date DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'total' => $total];
}

/**
 * Lifetime day-count stats for one student. Present/Late reflect the punctuality
 * status fixed once at Time In (see do_time_in()) — independent of whether that
 * day's session has since been completed.
 */
function get_attendance_stats(int $userId): array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(status = 'present'), 0) AS days_present,
            COALESCE(SUM(status = 'late'), 0) AS late_days
         FROM attendance WHERE user_id = ?"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return [
        'days_present' => (int) $row['days_present'],
        'late_days' => (int) $row['late_days'],
    ];
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
        'SELECT id, time_in, time_out FROM attendance
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
