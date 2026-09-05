<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';

$user = require_role('student');
$navUser = $user;

global $pdo;
$stmt = $pdo->prepare('SELECT company, department, position, ojt_start_date, ojt_end_date FROM student_profiles WHERE user_id = ?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch() ?: [];

$summary = calculate_ojt_summary($user['id']);
$ojtStatus = ojt_status_from_summary($summary);

$daysLeft = null;
if (!empty($profile['ojt_end_date'])) {
    $end = new DateTime($profile['ojt_end_date']);
    $today = new DateTime('today');
    $daysLeft = $today <= $end ? (int) $today->diff($end)->days : 0;
}

// Circular ring geometry — a plain SVG circle, percent expressed via
// stroke-dasharray/offset; purely a different rendering of the same
// calculate_ojt_summary() numbers already shown as a linear bar on the dashboard.
$radius = 80;
$circumference = 2 * M_PI * $radius;
$percentClamped = max(0, min(100, $summary['percent']));
$dashOffset = $circumference * (1 - $percentClamped / 100);

$history = get_attendance_history($user['id'], ['status' => 'all', 'page' => 1, 'per_page' => 15]);

$pageTitle = 'OJT Progress';
$activeNav = 'progress';
include __DIR__ . '/../includes/partials/app_header.php';
?>
<div class="dashboard-layout">
<div class="dashboard-main">
  <div class="card card-fluid" style="text-align:center;">
    <h2 class="card-section-title" style="text-align:left;">OJT Progress</h2>
    <svg width="200" height="200" viewBox="0 0 200 200" style="margin:12px 0;">
      <circle cx="100" cy="100" r="<?= $radius ?>" fill="none" stroke="var(--color-border)" stroke-width="16"/>
      <circle cx="100" cy="100" r="<?= $radius ?>" fill="none" stroke="var(--mbc-blue-500)" stroke-width="16"
        stroke-linecap="round" stroke-dasharray="<?= e((string) round($circumference, 2)) ?>"
        stroke-dashoffset="<?= e((string) round($dashOffset, 2)) ?>"
        transform="rotate(-90 100 100)"/>
      <text x="100" y="94" text-anchor="middle" font-size="30" font-weight="700" fill="var(--color-text)" font-family="var(--font)"><?= e(number_format($percentClamped, 2)) ?>%</text>
      <text x="100" y="118" text-anchor="middle" font-size="13" fill="var(--color-text-muted)" font-family="var(--font)"><?= e(number_format($summary['completed_minutes'] / 60, 1)) ?> / <?= e(number_format($summary['required_minutes'] / 60, 1)) ?> hrs</text>
    </svg>
    <p><span class="status-pill status-<?= e($ojtStatus['key']) ?>"><?= e(strtoupper($ojtStatus['label'])) ?></span></p>

    <div class="stat-tile-row">
      <div class="stat-tile"><span class="stat-value"><?= e(number_format($summary['required_minutes'] / 60, 2)) ?> hrs</span><span class="stat-label">Required</span></div>
      <div class="stat-tile"><span class="stat-value"><?= e(number_format($summary['completed_minutes'] / 60, 2)) ?> hrs</span><span class="stat-label">Completed</span></div>
      <div class="stat-tile"><span class="stat-value"><?= e(number_format($summary['remaining_minutes'] / 60, 2)) ?> hrs</span><span class="stat-label">Remaining</span></div>
      <div class="stat-tile"><span class="stat-value"><?= $daysLeft !== null ? e((string) $daysLeft) : '—' ?></span><span class="stat-label">Days Left</span></div>
    </div>
  </div>

  <div class="card card-fluid">
    <h2 class="card-section-title">Progress History</h2>
    <?php if (!$history['rows']): ?>
      <p class="field-hint">No attendance records yet.</p>
    <?php else: ?>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Date</th><th>Worked Hours</th></tr></thead>
        <tbody>
          <?php foreach ($history['rows'] as $row): ?>
          <tr>
            <td><?= e(date('M j, Y', strtotime($row['attendance_date']))) ?></td>
            <td><?= $row['time_out'] ? e(format_minutes(calculate_worked_minutes($row, false))) : 'In Progress' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <a class="btn btn-secondary" href="<?= APP_URL ?>/student/attendance_history.php" style="margin-top:16px;">VIEW FULL ATTENDANCE &rarr;</a>
  </div>
</div>
<div class="dashboard-side">
  <div class="card card-fluid">
    <h2 class="card-section-title">OJT Assignment</h2>
    <div class="detail-list">
      <div class="detail-row"><span class="detail-label">Company</span><span class="detail-value"><?= e($profile['company'] ?? 'Not assigned') ?></span></div>
      <div class="detail-row"><span class="detail-label">Department</span><span class="detail-value"><?= e($profile['department'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">Position</span><span class="detail-value"><?= e($profile['position'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">Start Date</span><span class="detail-value"><?= !empty($profile['ojt_start_date']) ? e(date('M j, Y', strtotime($profile['ojt_start_date']))) : 'Not set' ?></span></div>
      <div class="detail-row"><span class="detail-label">Expected Completion</span><span class="detail-value"><?= !empty($profile['ojt_end_date']) ? e(date('M j, Y', strtotime($profile['ojt_end_date']))) : 'Not set' ?></span></div>
    </div>
  </div>
</div>
</div>
<?php include __DIR__ . '/../includes/partials/app_footer.php'; ?>
