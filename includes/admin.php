<?php
require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/mailer.php';

/**
 * Live dashboard KPIs. Everything here is computed fresh from `users`/`attendance`/
 * `student_profiles` on every call — nothing is cached or stored, so these numbers
 * can never drift from the underlying records.
 */
function get_admin_dashboard_stats(): array {
    global $pdo;
    $today = date('Y-m-d');

    $stats = [];

    $stats['total_students'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'student'"
    )->fetchColumn();

    $stats['active_students'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'"
    )->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT user_id) FROM attendance
         WHERE attendance_date = ? AND time_in IS NOT NULL AND time_out IS NULL
           AND NOT (break_start IS NOT NULL AND break_end IS NULL)"
    );
    $stmt->execute([$today]);
    $stats['currently_timed_in'] = (int) $stmt->fetchColumn();

    // Reuses the existing (currently unused-by-any-flow) break_start/break_end
    // columns rather than inferring "on break" from a missing time_out — this
    // stat is honestly 0 today since no break feature exists yet, and will
    // start reporting correctly the moment one does, with no code change here.
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT user_id) FROM attendance
         WHERE attendance_date = ? AND break_start IS NOT NULL AND break_end IS NULL"
    );
    $stmt->execute([$today]);
    $stats['currently_on_break'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT user_id) FROM attendance WHERE attendance_date = ? AND time_in IS NOT NULL"
    );
    $stmt->execute([$today]);
    $stats['today_present'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT user_id) FROM attendance WHERE attendance_date = ? AND status = 'late'"
    );
    $stmt->execute([$today]);
    $stats['today_late'] = (int) $stmt->fetchColumn();

    // ojt_status is kept in sync on every mutation path (do_time_in/do_time_out,
    // and the admin edit/manual-entry functions below) — safe & fast to read
    // directly here rather than running calculate_ojt_summary() per student.
    $stats['completed_ojt'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM student_profiles WHERE ojt_status = 'completed'"
    )->fetchColumn();

    return $stats;
}

/**
 * Filtered, sorted, paginated student list for the admin Student Management table.
 * Each row is annotated with its live OJT summary (required/completed/remaining/percent),
 * computed the same way as everywhere else in the app — never a second calculation.
 */
function get_students_list(array $filters): array {
    global $pdo;

    $where = ["u.role = 'student'"];
    $params = [];

    if (!empty($filters['search'])) {
        // Native prepares (EMULATE_PREPARES => false) require a distinct placeholder
        // per occurrence — the same :name can't be reused multiple times in one query.
        $where[] = '(u.full_name LIKE :search1 OR u.email LIKE :search2 OR u.student_id LIKE :search3)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search1'] = $searchTerm;
        $params['search2'] = $searchTerm;
        $params['search3'] = $searchTerm;
    }
    if (!empty($filters['account_status'])) {
        $where[] = 'u.status = :account_status';
        $params['account_status'] = $filters['account_status'];
    }
    if (!empty($filters['course'])) {
        $where[] = 'sp.course = :course';
        $params['course'] = $filters['course'];
    }
    if (!empty($filters['company'])) {
        $where[] = 'sp.company = :company';
        $params['company'] = $filters['company'];
    }
    if (!empty($filters['ojt_status'])) {
        $where[] = 'sp.ojt_status = :ojt_status';
        $params['ojt_status'] = $filters['ojt_status'];
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE {$whereSql}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sortMap = [
        'name' => 'u.full_name',
        'status' => 'u.status',
        'ojt_status' => 'sp.ojt_status',
        'created' => 'u.created_at',
    ];
    $sortCol = $sortMap[$filters['sort'] ?? ''] ?? 'u.full_name';
    $sortDir = ($filters['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

    $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
    $page = max(1, (int) ($filters['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT u.id, u.full_name, u.email, u.student_id, u.status AS account_status, u.created_at,
                sp.course, sp.company, sp.ojt_status, sp.required_minutes
         FROM users u
         LEFT JOIN student_profiles sp ON sp.user_id = u.id
         WHERE {$whereSql}
         ORDER BY {$sortCol} {$sortDir}
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $summary = calculate_ojt_summary((int) $row['id']);
        $row['summary'] = $summary;
        $row['ojt_status_derived'] = ojt_status_from_summary($summary);
    }
    unset($row);

    return ['rows' => $rows, 'total' => $total];
}

/** Full profile detail for one student — admin view. Null if no such student exists. */
function get_student_admin_profile(int $userId): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT u.id, u.full_name, u.email, u.student_id, u.status AS account_status, u.created_at,
                sp.first_name, sp.middle_name, sp.last_name, sp.contact_number, sp.school, sp.course,
                sp.major, sp.year_level, sp.section, sp.company, sp.department, sp.position,
                sp.ojt_start_date, sp.ojt_end_date, sp.required_minutes, sp.ojt_status
         FROM users u
         LEFT JOIN student_profiles sp ON sp.user_id = u.id
         WHERE u.id = ? AND u.role = 'student'"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Attendance-derived summary stats for one student's admin profile view. */
function get_student_attendance_summary(int $userId): array {
    global $pdo;

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_days,
            COALESCE(SUM(status = 'late'), 0) AS late_count
         FROM attendance WHERE user_id = ? AND time_out IS NOT NULL"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return [
        'total_days' => (int) $row['total_days'],
        'late_count' => (int) $row['late_count'],
    ];
}

/**
 * Filtered, sorted, paginated attendance list across ALL students — the admin-scope
 * counterpart to the student-side get_attendance_history() (which is hard-scoped to
 * one user_id). Every filter is bound as a parameter or drawn from a whitelist;
 * $filters['page']/['per_page'] are validated ints before being interpolated into
 * LIMIT/OFFSET, same rationale as get_attendance_history().
 */
function get_admin_attendance_list(array $filters): array {
    global $pdo;

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['search'])) {
        $where[] = '(u.full_name LIKE :search1 OR u.student_id LIKE :search2)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search1'] = $searchTerm;
        $params['search2'] = $searchTerm;
    }
    if (!empty($filters['from'])) {
        $where[] = 'a.attendance_date >= :from';
        $params['from'] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $where[] = 'a.attendance_date <= :to';
        $params['to'] = $filters['to'];
    }
    $status = $filters['status'] ?? 'all';
    if ($status === 'present') {
        $where[] = "a.status = 'present'";
    } elseif ($status === 'late') {
        $where[] = "a.status = 'late'";
    } elseif ($status === 'incomplete') {
        $where[] = 'a.time_out IS NULL';
    }
    if (!empty($filters['course'])) {
        $where[] = 'sp.course = :course';
        $params['course'] = $filters['course'];
    }
    if (!empty($filters['company'])) {
        $where[] = 'sp.company = :company';
        $params['company'] = $filters['company'];
    }

    $whereSql = implode(' AND ', $where);
    $baseFrom = 'FROM attendance a
                  JOIN users u ON u.id = a.user_id
                  LEFT JOIN student_profiles sp ON sp.user_id = u.id
                  WHERE ' . $whereSql;

    $countStmt = $pdo->prepare("SELECT COUNT(*) {$baseFrom}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 20)));
    $page = max(1, (int) ($filters['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT a.id, a.user_id, a.attendance_date, a.time_in, a.time_out, a.status, a.source, a.break_start, a.break_end,
                u.full_name, u.student_id
         {$baseFrom}
         ORDER BY a.attendance_date DESC, u.full_name ASC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'total' => $total];
}

/**
 * Writes one row to admin_audit_log. $oldValue/$newValue are arrays, JSON-encoded
 * for storage — keeps every audited action in one consistent, queryable shape.
 */
function log_admin_action(int $adminId, string $action, ?int $targetUserId, ?array $oldValue, ?array $newValue, ?string $reason = null): void {
    global $pdo;
    $pdo->prepare(
        'INSERT INTO admin_audit_log (admin_user_id, action, target_user_id, old_value, new_value, reason)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $adminId,
        $action,
        $targetUserId,
        $oldValue !== null ? json_encode($oldValue) : null,
        $newValue !== null ? json_encode($newValue) : null,
        $reason,
    ]);
}

/** Recomputes and persists ojt_status from the live summary — call after any attendance write. */
function sync_ojt_status(int $userId): void {
    global $pdo;
    $summary = calculate_ojt_summary($userId);
    $derived = ojt_status_from_summary($summary);
    $stmt = $pdo->prepare('SELECT ojt_status FROM student_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $current = $stmt->fetchColumn();
    if ($current !== false && $current !== $derived['key']) {
        $pdo->prepare('UPDATE student_profiles SET ojt_status = ? WHERE user_id = ?')
            ->execute([$derived['key'], $userId]);
    }
}

/**
 * The following student-account actions all: verify the target is actually a
 * student (never lets an admin act on another admin account), log to the audit
 * table, and return ['ok' => bool, 'error' => ?string] uniformly.
 */

function admin_approve_student(int $adminId, int $studentId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$studentId]);
    $old = $stmt->fetchColumn();
    if ($old === false) {
        return ['ok' => false, 'error' => 'Student not found.'];
    }
    if ($old !== 'pending') {
        return ['ok' => false, 'error' => 'Only pending registrations can be approved.'];
    }
    $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$studentId]);
    log_admin_action($adminId, 'approve_registration', $studentId, ['status' => $old], ['status' => 'active']);
    notify_user($studentId, 'account_approved', 'Registration Approved', 'Your account has been approved. You can now log in.');
    return ['ok' => true];
}

function admin_reject_student(int $adminId, int $studentId, string $reason): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$studentId]);
    $old = $stmt->fetchColumn();
    if ($old === false) {
        return ['ok' => false, 'error' => 'Student not found.'];
    }
    if ($old !== 'pending') {
        return ['ok' => false, 'error' => 'Only pending registrations can be rejected.'];
    }
    $pdo->prepare("UPDATE users SET status = 'rejected' WHERE id = ?")->execute([$studentId]);
    log_admin_action($adminId, 'reject_registration', $studentId, ['status' => $old], ['status' => 'rejected'], $reason);
    notify_user($studentId, 'account_rejected', 'Registration Rejected', 'Your registration was not approved. Please contact the administrator.');
    return ['ok' => true];
}

function admin_activate_student(int $adminId, int $studentId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$studentId]);
    $old = $stmt->fetchColumn();
    if ($old === false) {
        return ['ok' => false, 'error' => 'Student not found.'];
    }
    $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$studentId]);
    log_admin_action($adminId, 'activate_student', $studentId, ['status' => $old], ['status' => 'active']);
    return ['ok' => true];
}

function admin_deactivate_student(int $adminId, int $studentId, ?string $reason = null): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$studentId]);
    $old = $stmt->fetchColumn();
    if ($old === false) {
        return ['ok' => false, 'error' => 'Student not found.'];
    }
    // Deactivation only flips status — attendance, profile, and history rows are
    // never touched, so nothing about the student's record is lost.
    $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$studentId]);
    log_admin_action($adminId, 'deactivate_student', $studentId, ['status' => $old], ['status' => 'inactive'], $reason);
    return ['ok' => true];
}

/**
 * Reuses the exact same token-generation + email path as auth/forgot_password.php
 * (see that file) rather than inventing a second reset mechanism or an
 * admin-visible password — the student still sets their own new password via the
 * existing reset_password.php link.
 */
function admin_trigger_password_reset(int $adminId, int $studentId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        return ['ok' => false, 'error' => 'Student not found.'];
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + 45 * 60);

    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([$student['id'], $tokenHash, $expiresAt]);

    $resetLink = APP_URL . '/auth/reset_password.php?token=' . $rawToken;
    $sent = send_reset_email($student['email'], $student['full_name'], $resetLink);

    log_admin_action($adminId, 'admin_reset_password', $studentId, null, null, null);

    return ['ok' => true, 'email_sent' => $sent];
}

/**
 * Admin correction of an existing attendance row. Every field is validated
 * server-side (never trusts a client-calculated total); the before/after state
 * is captured for the audit log; ojt_status is re-synced afterward so the
 * change is reflected everywhere immediately.
 */
function admin_edit_attendance(int $adminId, int $attendanceId, ?string $timeIn, ?string $timeOut, string $reason): array {
    global $pdo;

    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'A reason is required for every attendance correction.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE id = ?');
    $stmt->execute([$attendanceId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'Attendance record not found.'];
    }

    $newTimeIn = $timeIn !== null && $timeIn !== '' ? $row['attendance_date'] . ' ' . $timeIn . ':00' : null;
    $newTimeOut = $timeOut !== null && $timeOut !== '' ? $row['attendance_date'] . ' ' . $timeOut . ':00' : null;

    if ($newTimeIn === null) {
        return ['ok' => false, 'error' => 'Time In is required.'];
    }
    if ($newTimeOut !== null && strtotime($newTimeOut) < strtotime($newTimeIn)) {
        return ['ok' => false, 'error' => 'Time Out cannot be earlier than Time In.'];
    }

    $old = ['time_in' => $row['time_in'], 'time_out' => $row['time_out']];
    $new = ['time_in' => $newTimeIn, 'time_out' => $newTimeOut];

    if ($old === $new) {
        return ['ok' => false, 'error' => 'No changes were made.'];
    }

    $pdo->prepare('UPDATE attendance SET time_in = ?, time_out = ? WHERE id = ?')
        ->execute([$newTimeIn, $newTimeOut, $attendanceId]);

    log_admin_action($adminId, 'edit_attendance', (int) $row['user_id'], $old, $new, $reason);
    sync_ojt_status((int) $row['user_id']);

    return ['ok' => true];
}

/**
 * Creates a new attendance row on the student's behalf (e.g. "forgot to time in").
 * Marked source='manual' so it's always distinguishable from a normal system-recorded
 * row. Subject to the exact same UNIQUE(user_id, attendance_date) constraint as every
 * other attendance row — cannot silently create a duplicate for a date that already
 * has one.
 */
function admin_manual_attendance(int $adminId, int $studentId, string $date, string $timeIn, ?string $timeOut, string $reason): array {
    global $pdo;

    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'A reason is required for manual attendance entry.'];
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return ['ok' => false, 'error' => 'Invalid date.'];
    }

    $fullTimeIn = $date . ' ' . $timeIn . ':00';
    $fullTimeOut = $timeOut ? $date . ' ' . $timeOut . ':00' : null;
    if ($fullTimeOut !== null && strtotime($fullTimeOut) < strtotime($fullTimeIn)) {
        return ['ok' => false, 'error' => 'Time Out cannot be earlier than Time In.'];
    }

    $cutoff = new DateTime($date . ' ' . WORKDAY_START_TIME);
    $cutoff->modify('+' . LATE_GRACE_MINUTES . ' minutes');
    $status = new DateTime($fullTimeIn) > $cutoff ? 'late' : 'present';

    try {
        $pdo->prepare(
            "INSERT INTO attendance (user_id, attendance_date, time_in, time_out, status, source)
             VALUES (?, ?, ?, ?, ?, 'manual')"
        )->execute([$studentId, $date, $fullTimeIn, $fullTimeOut, $status]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return ['ok' => false, 'error' => 'An attendance record already exists for this student on this date.'];
        }
        error_log('admin_manual_attendance failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Something went wrong creating the record.'];
    }

    log_admin_action($adminId, 'manual_attendance', $studentId, null, [
        'attendance_date' => $date, 'time_in' => $fullTimeIn, 'time_out' => $fullTimeOut,
    ], $reason);
    sync_ojt_status($studentId);

    return ['ok' => true];
}
