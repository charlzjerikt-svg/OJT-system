<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';

$user = require_role('student');
$navUser = $user;

global $pdo;

// Scoped to the authenticated student's own user_id (from the session) only —
// never a client-supplied ID — so a student can never view another student's
// attendance by tampering with the URL.
$stmt = $pdo->prepare(
    'SELECT attendance_date, time_in, time_out, break_start, break_end
     FROM attendance WHERE user_id = ? ORDER BY attendance_date DESC LIMIT 365'
);
$stmt->execute([$user['id']]);
$records = $stmt->fetchAll();

function fmt_time(?string $datetime): string {
    return $datetime ? date('g:i A', strtotime($datetime)) : '--';
}

$pageTitle = 'Attendance History';
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card card-wide">
  <h1>Attendance History</h1>
  <p class="subtitle">Your complete Time In / Time Out record.</p>

  <?php if (!$records): ?>
    <p class="field-hint">No attendance records yet.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Total Hours</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($records as $row): ?>
        <tr>
          <td><?= e(date('M j, Y', strtotime($row['attendance_date']))) ?></td>
          <td><?= fmt_time($row['time_in']) ?></td>
          <td><?= fmt_time($row['time_out']) ?></td>
          <td><?= $row['time_out'] ? e(format_minutes(calculate_worked_minutes($row, false))) : '--' ?></td>
          <td><span class="status-pill status-<?= $row['time_out'] ? 'completed' : 'timed_in' ?>"><?= $row['time_out'] ? 'Completed' : 'In Progress' ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="form-footer">
    <a href="<?= APP_URL ?>/student/dashboard.php">&larr; Back to Dashboard</a>
  </div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
