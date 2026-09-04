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

$now = new DateTime();
$todaySchedule = get_effective_schedule($user['id'], $now);

$today = get_today_attendance($user['id']);
$activeBreak = null;
$completedBreaks = [];

if (!$today || !$today['time_in']) {
    $attendanceState = 'not_timed_in';
} elseif ($today['time_out']) {
    $attendanceState = 'completed';
} else {
    $activeBreak = get_active_break((int) $today['id']);
    $attendanceState = $activeBreak ? 'on_break' : 'timed_in';
}

if ($today) {
    $completedBreaks = array_filter(get_breaks_for_attendance((int) $today['id']), fn($b) => $b['break_end'] !== null);
}

// The displayed OJT status is derived live from the same numbers shown in the
// Progress card below — never read from the stored student_profiles.ojt_status
// column — so it can never disagree with the hours on screen.
$summary = calculate_ojt_summary($user['id']);
$ojtStatus = ojt_status_from_summary($summary);

$stmt = $pdo->prepare(
    'SELECT id, attendance_date, time_in, time_out
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

/** Formats a TIME-type schedule value ('08:00:00') as '8:00 AM'. */
function fmt_schedule_time(?string $time): string {
    return $time ? date('g:i A', strtotime($time)) : '--';
}

$punctualityLabels = ['present' => 'Present', 'late' => 'Late'];
$punctuality = $today['status'] ?? null;

$pageTitle = 'Student Dashboard';
$extraScripts = ['/assets/js/dashboard.js'];
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
    <div><dt>OJT Status</dt><dd><span class="status-pill status-<?= e($ojtStatus['key']) ?>"><?= e(strtoupper($ojtStatus['label'])) ?></span></dd></div>
  </dl>
</div>

<div class="card card-wide" style="margin-top:20px;">
  <h2 class="card-section-title">Today's OJT Schedule</h2>
  <?php if (!$todaySchedule['is_active']): ?>
    <p class="field-hint">No OJT schedule for today — this is your designated day off.</p>
  <?php else: ?>
  <div class="attendance-times">
    <div class="time-block">
      <span class="time-label">Expected Time In</span>
      <span class="time-value"><?= e(fmt_schedule_time($todaySchedule['start_time'])) ?></span>
    </div>
    <div class="time-block">
      <span class="time-label">Expected Break</span>
      <span class="time-value" style="font-size:16px;">
        <?= $todaySchedule['break_start'] ? e(fmt_schedule_time($todaySchedule['break_start']) . ' – ' . fmt_schedule_time($todaySchedule['break_end'])) : 'None' ?>
      </span>
    </div>
    <div class="time-block">
      <span class="time-label">Expected Time Out</span>
      <span class="time-value"><?= e(fmt_schedule_time($todaySchedule['end_time'])) ?></span>
    </div>
  </div>
  <?php endif; ?>
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
      <span class="time-label"><?= $attendanceState === 'on_break' ? 'Break Started' : 'Break' ?></span>
      <?php if ($attendanceState === 'on_break'): ?>
        <span class="time-value" id="breakValue"><?= fmt_time_precise($activeBreak['break_start']) ?></span>
      <?php elseif ($completedBreaks): ?>
        <span class="time-value" id="breakValue" style="font-size:15px;">
          <?php foreach ($completedBreaks as $b): ?>
            <?= e(fmt_time($b['break_start']) . ' – ' . fmt_time($b['break_end'])) ?><br>
          <?php endforeach; ?>
        </span>
      <?php else: ?>
        <span class="time-value" id="breakValue">--</span>
      <?php endif; ?>
    </div>
    <div class="time-block">
      <span class="time-label">Time Out</span>
      <span class="time-value" id="timeOutValue"><?= fmt_time($today['time_out'] ?? null) ?></span>
    </div>
    <?php if ($attendanceState === 'completed'): ?>
    <div class="time-block">
      <span class="time-label">Break Total</span>
      <span class="time-value" id="breakTotalValue"><?= e(format_minutes(calculate_break_minutes_for_attendance((int) $today['id']))) ?></span>
    </div>
    <div class="time-block">
      <span class="time-label">Worked Hours</span>
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
        'on_break' => 'ON BREAK',
        'completed' => 'COMPLETED',
      ][$attendanceState] ?>
    </span>
  </p>

  <?php if ($attendanceState === 'not_timed_in'): ?>
    <button type="button" class="btn" id="timeInBtn" data-action="time_in" data-csrf="<?= e(csrf_token()) ?>" data-action-url="<?= APP_URL ?>/student/attendance_action.php">TIME IN</button>
  <?php elseif ($attendanceState === 'timed_in'): ?>
    <div class="admin-row-actions">
      <button type="button" class="btn-secondary" id="startBreakBtn" data-action="start_break" data-csrf="<?= e(csrf_token()) ?>" data-action-url="<?= APP_URL ?>/student/attendance_action.php">START BREAK</button>
      <button type="button" class="btn" id="timeOutBtn" data-action="time_out" data-csrf="<?= e(csrf_token()) ?>" data-action-url="<?= APP_URL ?>/student/attendance_action.php" style="width:auto;padding:11px 24px;">TIME OUT</button>
    </div>
  <?php elseif ($attendanceState === 'on_break'): ?>
    <button type="button" class="btn" id="endBreakBtn" data-action="end_break" data-csrf="<?= e(csrf_token()) ?>" data-action-url="<?= APP_URL ?>/student/attendance_action.php">END BREAK</button>
  <?php else: ?>
    <div class="alert alert-success" style="margin:0;">&#10003; WORK DAY COMPLETED — see you next shift!</div>
  <?php endif; ?>
</div>

<div class="card card-wide" style="margin-top:20px;">
  <h2 class="card-section-title">OJT Progress</h2>

  <div class="stat-tile-row">
    <div class="stat-tile">
      <span class="stat-value"><?= e(number_format($summary['required_minutes'] / 60, 2)) ?> hrs</span>
      <span class="stat-label">Required Hours</span>
    </div>
    <div class="stat-tile">
      <span class="stat-value"><?= e(number_format($summary['completed_minutes'] / 60, 2)) ?> hrs</span>
      <span class="stat-label">Completed</span>
    </div>
    <div class="stat-tile">
      <span class="stat-value"><?= e(number_format($summary['remaining_minutes'] / 60, 2)) ?> hrs</span>
      <span class="stat-label">Remaining</span>
    </div>
  </div>

  <div class="progress-row">
    <div class="progress-bar-track">
      <div class="progress-bar-fill" style="width: <?= e((string) round($summary['percent'], 2)) ?>%;"></div>
    </div>
    <span class="progress-percent"><?= e(number_format($summary['percent'], 2)) ?>%</span>
  </div>

  <p class="attendance-status" style="margin:16px 0 0;">
    Status: <span class="status-pill status-<?= e($ojtStatus['key']) ?>"><?= e(strtoupper($ojtStatus['label'])) ?></span>
  </p>
</div>

<div class="card card-wide" style="margin-top:20px;">
  <h2 class="card-section-title">Recent Attendance</h2>

  <?php if (!$recent): ?>
    <p class="field-hint">No attendance records yet.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>Date</th><th>Time In</th><th>Break</th><th>Time Out</th><th>Worked</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $row): $breakMin = calculate_break_minutes_for_attendance((int) $row['id']); ?>
        <tr>
          <td><?= e(date('M j', strtotime($row['attendance_date']))) ?></td>
          <td><?= fmt_time($row['time_in']) ?></td>
          <td><?= $breakMin > 0 ? e(format_minutes($breakMin)) : '--' ?></td>
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
