<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';

$user = require_role('student');
$navUser = $user;

global $pdo;

$stmt = $pdo->prepare(
    'SELECT first_name, course, year_level, company, ojt_status
     FROM student_profiles WHERE user_id = ?'
);
$stmt->execute([$user['id']]);
$profile = $stmt->fetch() ?: [];

$greetingName = $profile['first_name'] ?? explode(' ', $user['full_name'])[0];

$ojtStatusLabels = ['not_started' => 'Not Started', 'ongoing' => 'Ongoing', 'completed' => 'Completed'];
$ojtStatusKey = $profile['ojt_status'] ?? 'not_started';
$ojtStatusLabel = $ojtStatusLabels[$ojtStatusKey] ?? 'Not Started';

$today = get_today_attendance($user['id']);

if (!$today || !$today['time_in']) {
    $attendanceState = 'not_timed_in';
} elseif (!$today['time_out']) {
    $attendanceState = 'timed_in';
} else {
    $attendanceState = 'completed';
}

$summary = calculate_ojt_summary($user['id']);

$stmt = $pdo->prepare(
    'SELECT attendance_date, time_in, time_out, break_start, break_end
     FROM attendance WHERE user_id = ? ORDER BY attendance_date DESC LIMIT 5'
);
$stmt->execute([$user['id']]);
$recent = $stmt->fetchAll();

function fmt_time(?string $datetime): string {
    return $datetime ? date('g:i A', strtotime($datetime)) : '--';
}

function fmt_time_precise(?string $datetime): string {
    return $datetime ? date('g:i:s A', strtotime($datetime)) : '--';
}

$punctualityLabels = ['present' => 'Present', 'late' => 'Late'];
$punctuality = $today['status'] ?? null;

$pageTitle = 'Student Dashboard';
$extraScripts = [APP_URL . '/assets/js/dashboard.js'];
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card card-wide">
  <h1>Welcome back, <?= e($greetingName) ?>!</h1>
  <p class="subtitle">Student Dashboard</p>
  <dl class="info-grid">
    <div><dt>Student ID</dt><dd><?= e($user['student_id'] ?? '—') ?></dd></div>
    <div><dt>Course / Program</dt><dd><?= e($profile['course'] ?? 'Not set') ?></dd></div>
    <div><dt>Year Level</dt><dd><?= e($profile['year_level'] ?? 'Not set') ?></dd></div>
    <div><dt>Company</dt><dd><?= e($profile['company'] ?? 'Not set') ?></dd></div>
    <div><dt>OJT Status</dt><dd><span class="status-pill status-<?= e($ojtStatusKey) ?>"><?= e(strtoupper($ojtStatusLabel)) ?></span></dd></div>
  </dl>
</div>

<div class="card card-wide" style="margin-top:20px;">
  <h2 class="card-section-title">Today's Attendance</h2>
  <p class="subtitle" style="margin-bottom:20px;">Date: <?= date('F j, Y') ?></p>

  <div id="attendanceAlert" class="alert" style="display:none;" role="alert"></div>

  <div class="attendance-times">
    <div class="time-block">
      <span class="time-label">Time In</span>
      <span class="time-value" id="timeInValue"><?= fmt_time_precise($today['time_in'] ?? null) ?></span>
      <?php if ($punctuality): ?>
        <span class="status-pill status-<?= e($punctuality) ?>" id="punctualityPill"><?= e($punctualityLabels[$punctuality] ?? ucfirst($punctuality)) ?></span>
      <?php endif; ?>
    </div>
    <div class="time-block">
      <span class="time-label">Time Out</span>
      <span class="time-value" id="timeOutValue"><?= fmt_time($today['time_out'] ?? null) ?></span>
    </div>
    <?php if ($attendanceState === 'completed'): ?>
    <div class="time-block">
      <span class="time-label">Total Worked</span>
      <span class="time-value" id="totalWorkedValue"><?= e(format_minutes(calculate_worked_minutes($today, false))) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <p class="attendance-status">
    Status:
    <span class="status-pill status-<?= e($attendanceState) ?>" id="attendanceStatusPill">
      <?= [
        'not_timed_in' => 'NOT YET TIMED IN',
        'timed_in' => 'CURRENTLY WORKING',
        'completed' => 'COMPLETED',
      ][$attendanceState] ?>
    </span>
  </p>

  <?php if ($attendanceState === 'not_timed_in'): ?>
    <button type="button" class="btn" id="timeInBtn" data-action="time_in" data-csrf="<?= e(csrf_token()) ?>" data-action-url="<?= APP_URL ?>/student/attendance_action.php">TIME IN</button>
  <?php elseif ($attendanceState === 'timed_in'): ?>
    <button type="button" class="btn" id="timeOutBtn" data-action="time_out" data-csrf="<?= e(csrf_token()) ?>" data-action-url="<?= APP_URL ?>/student/attendance_action.php">TIME OUT</button>
  <?php else: ?>
    <p class="field-hint">You've completed today's attendance. See you next shift!</p>
  <?php endif; ?>
</div>

<div class="card card-wide" style="margin-top:20px;">
  <h2 class="card-section-title">OJT Progress</h2>

  <div class="stat-tile-row">
    <div class="stat-tile">
      <span class="stat-value"><?= e(number_format($summary['required_minutes'] / 60, 1)) ?> hrs</span>
      <span class="stat-label">Required Hours</span>
    </div>
    <div class="stat-tile">
      <span class="stat-value"><?= e(number_format($summary['completed_minutes'] / 60, 1)) ?> hrs</span>
      <span class="stat-label">Completed</span>
    </div>
    <div class="stat-tile">
      <span class="stat-value"><?= e(number_format($summary['remaining_minutes'] / 60, 1)) ?> hrs</span>
      <span class="stat-label">Remaining</span>
    </div>
  </div>

  <div class="progress-row">
    <div class="progress-bar-track">
      <div class="progress-bar-fill" style="width: <?= e((string) round($summary['percent'], 2)) ?>%;"></div>
    </div>
    <span class="progress-percent"><?= e(number_format($summary['percent'], 2)) ?>%</span>
  </div>
</div>

<div class="card card-wide" style="margin-top:20px;">
  <h2 class="card-section-title">Recent Attendance</h2>

  <?php if (!$recent): ?>
    <p class="field-hint">No attendance records yet.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Total Hours</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $row): ?>
        <tr>
          <td><?= e(date('M j', strtotime($row['attendance_date']))) ?></td>
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

  <a class="btn btn-secondary" href="<?= APP_URL ?>/student/attendance_history.php" style="margin-top:16px;">VIEW ATTENDANCE HISTORY</a>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
