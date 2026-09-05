<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';

$user = require_role('student');
$navUser = $user;

global $pdo;

$stmt = $pdo->prepare(
    'SELECT first_name, course, year_level, company, department, position,
            ojt_start_date, ojt_end_date, ojt_status
     FROM student_profiles WHERE user_id = ?'
);
$stmt->execute([$user['id']]);
$profile = $stmt->fetch() ?: [];

$greetingName = $profile['first_name'] ?? explode(' ', $user['full_name'])[0];

$now = new DateTime();
$todaySchedule = get_effective_schedule($user['id'], $now);
$requiredDailyMinutes = schedule_duration_minutes($todaySchedule);

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

// Live "so far" figures for the ticking display — seconds precision, purely for
// UI smoothness between page loads. The server/database numbers (calculate_worked_minutes(),
// re-fetched fresh on every reload after an action) remain the only authoritative source;
// nothing computed here is ever sent back to the server.
$liveWorkedSeconds = 0;
$liveBreakSeconds = 0;
if ($attendanceState === 'timed_in' || $attendanceState === 'on_break') {
    $elapsed = time() - strtotime($today['time_in']);
    $completedBreakSeconds = 0;
    foreach ($completedBreaks as $b) {
        $completedBreakSeconds += (int) $b['duration_seconds'];
    }
    if ($attendanceState === 'on_break') {
        $liveWorkedSeconds = max(0, (strtotime($activeBreak['break_start']) - strtotime($today['time_in'])) - $completedBreakSeconds);
        $liveBreakSeconds = max(0, time() - strtotime($activeBreak['break_start']));
    } else {
        $liveWorkedSeconds = max(0, $elapsed - $completedBreakSeconds);
    }
}

$todayWorkedMinutes = $today ? calculate_worked_minutes($today, true) : 0;
$todayBreakMinutes = $today ? calculate_break_minutes_for_attendance((int) $today['id']) : 0;
$remainingTodayMinutes = max(0, $requiredDailyMinutes - $todayWorkedMinutes);

$dailyCompletion = null;
if ($attendanceState === 'completed') {
    $dailyCompletion = get_daily_completion_status($todayWorkedMinutes, $requiredDailyMinutes, $today['status']);
}

$stmt = $pdo->prepare(
    'SELECT id, attendance_date, time_in, time_out, status
     FROM attendance WHERE user_id = ? ORDER BY attendance_date DESC LIMIT 6'
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

$stateLabels = [
    'not_timed_in' => 'Not Yet Timed In',
    'timed_in' => 'Currently Working',
    'on_break' => 'Currently On Break',
    'completed' => 'Work Session Ended',
];

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$extraScripts = ['/assets/js/dashboard.js'];
include __DIR__ . '/../includes/partials/app_header.php';
?>
<div class="card card-fluid" id="overview" style="max-width:1080px;">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <span class="avatar-lg"><?= e(nav_initials($user['full_name'])) ?></span>
    <div style="flex:1;min-width:200px;">
      <h1 style="margin:0;font-size:18px;"><?= e($user['full_name']) ?></h1>
      <p class="subtitle" style="margin:2px 0 0;">Student ID: <?= e($user['student_id'] ?? '—') ?> &middot; <?= e($profile['course'] ?? 'Program not set') ?> &middot; <?= e($profile['year_level'] ?? 'Year not set') ?></p>
    </div>
    <div style="text-align:right;">
      <div class="detail-label" style="font-size:11px;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:4px;">Company</div>
      <div style="font-weight:600;margin-bottom:6px;"><?= e($profile['company'] ?? 'Not assigned') ?></div>
      <span class="status-pill status-<?= e($ojtStatus['key']) ?>"><?= e(strtoupper($ojtStatus['label'])) ?></span>
    </div>
  </div>
  <a href="#assignment" style="display:inline-block;margin-top:14px;font-size:13px;color:var(--color-primary);text-decoration:none;">View OJT Details &rarr;</a>
</div>

<div class="dashboard-layout" style="margin-top:20px;">
<div class="dashboard-main">

  <!-- TODAY'S ATTENDANCE — hero card -->
  <div class="card card-fluid hero-attendance state-<?= e($attendanceState) ?>">
    <div class="hero-status-row">
      <div>
        <p class="subtitle" style="margin:0 0 6px;">Today's Attendance &middot; <?= date('F j, Y') ?></p>
        <div class="hero-status-badge">
          <span class="hero-status-dot"></span>
          <?= e(strtoupper($stateLabels[$attendanceState])) ?>
          <?php if ($punctuality && $attendanceState !== 'not_timed_in'): ?>
            <span class="status-pill status-<?= e($punctuality) ?>"><?= e($punctualityLabels[$punctuality]) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div id="attendanceAlert" class="alert" style="display:none;" role="alert"></div>

    <?php if ($attendanceState === 'not_timed_in'): ?>
      <p class="field-hint" style="font-size:14px;">Expected Time In: <strong><?= e(fmt_schedule_time($todaySchedule['start_time'])) ?></strong></p>
    <?php elseif ($attendanceState === 'timed_in'): ?>
      <div class="hero-live-label">Worked Today (live)</div>
      <div class="hero-live-counter" id="liveWorkedCounter" data-base-seconds="<?= $liveWorkedSeconds ?>" data-anchor="<?= time() ?>">00h 00m 00s</div>
    <?php elseif ($attendanceState === 'on_break'): ?>
      <div class="hero-live-label">Break Duration (live)</div>
      <div class="hero-live-counter" id="liveBreakCounter" data-base-seconds="<?= $liveBreakSeconds ?>" data-anchor="<?= time() ?>" style="color:var(--color-break);">00h 00m 00s</div>
    <?php else: ?>
      <div class="hero-live-label">Today's Worked Hours</div>
      <div class="hero-live-counter"><?= e(format_minutes($todayWorkedMinutes)) ?></div>
    <?php endif; ?>

    <div class="attendance-times" style="margin-top:20px;">
      <div class="time-block">
        <span class="time-label">Time In</span>
        <span class="time-value" id="timeInValue" style="font-size:18px;"><?= fmt_time_precise($today['time_in'] ?? null) ?></span>
      </div>
      <div class="time-block">
        <span class="time-label"><?= $attendanceState === 'on_break' ? 'Break Started' : 'Break' ?></span>
        <?php if ($attendanceState === 'on_break'): ?>
          <span class="time-value" id="breakValue" style="font-size:18px;"><?= fmt_time_precise($activeBreak['break_start']) ?></span>
        <?php elseif ($completedBreaks): ?>
          <span class="time-value" id="breakValue" style="font-size:14px;">
            <?php foreach ($completedBreaks as $b): ?>
              <?= e(fmt_time($b['break_start']) . ' – ' . fmt_time($b['break_end'])) ?><br>
            <?php endforeach; ?>
          </span>
        <?php else: ?>
          <span class="time-value" id="breakValue" style="font-size:18px;">--</span>
        <?php endif; ?>
      </div>
      <div class="time-block">
        <span class="time-label">Time Out</span>
        <span class="time-value" id="timeOutValue" style="font-size:18px;"><?= fmt_time($today['time_out'] ?? null) ?></span>
      </div>
    </div>

    <?php if ($attendanceState === 'not_timed_in'): ?>
      <div class="hero-btn-row">
        <button type="button" class="btn" id="timeInBtn" data-action="time_in" data-csrf="<?= e(csrf_token()) ?>" data-action-url="<?= APP_URL ?>/student/attendance_action.php">TIME IN</button>
      </div>
    <?php elseif ($attendanceState === 'timed_in'): ?>
      <div class="hero-btn-row">
        <button type="button" class="btn-secondary" id="startBreakBtn" data-action="start_break" data-csrf="<?= e(csrf_token()) ?>" data-action-url="<?= APP_URL ?>/student/attendance_action.php">START BREAK</button>
        <button type="button" class="btn" id="timeOutOpenBtn">TIME OUT</button>
      </div>
    <?php elseif ($attendanceState === 'on_break'): ?>
      <div class="hero-btn-row">
        <button type="button" class="btn" id="endBreakBtn" data-action="end_break" data-csrf="<?= e(csrf_token()) ?>" data-action-url="<?= APP_URL ?>/student/attendance_action.php">END BREAK</button>
      </div>
    <?php else: ?>
      <div class="alert alert-success" style="margin:20px 0 0;">
        &#10003; WORK DAY COMPLETED<?= $dailyCompletion ? ' — ' . e($dailyCompletion['label']) : '' ?> — see you next shift!
      </div>
    <?php endif; ?>
  </div>

  <!-- TODAY'S SUMMARY -->
  <div class="card card-fluid">
    <h2 class="card-section-title">Today's Summary</h2>
    <div class="metric-row">
      <div class="metric-cell"><span class="stat-value"><?= e(format_minutes($requiredDailyMinutes)) ?></span><span class="stat-label">Expected</span></div>
      <div class="metric-cell"><span class="stat-value"><?= e(format_minutes($todayWorkedMinutes)) ?></span><span class="stat-label">Worked</span></div>
      <div class="metric-cell"><span class="stat-value"><?= e(format_minutes($todayBreakMinutes)) ?></span><span class="stat-label">Break</span></div>
      <div class="metric-cell"><span class="stat-value"><?= e(format_minutes($remainingTodayMinutes)) ?></span><span class="stat-label">Remaining</span></div>
    </div>
  </div>

  <!-- OJT PROGRESS -->
  <div class="card card-fluid">
    <h2 class="card-section-title">OJT Progress</h2>
    <p class="subtitle" style="margin:0 0 16px;"><?= number_format($summary['completed_minutes'] / 60, 1) ?> / <?= number_format($summary['required_minutes'] / 60, 1) ?> hours completed</p>
    <div class="progress-row">
      <div class="progress-bar-track">
        <div class="progress-bar-fill" style="width: <?= e((string) round($summary['percent'], 2)) ?>%;"></div>
      </div>
      <span class="progress-percent"><?= e(number_format($summary['percent'], 2)) ?>%</span>
    </div>
    <div class="detail-list" style="margin-top:16px;">
      <div class="detail-row"><span class="detail-label">Remaining</span><span class="detail-value"><?= e(number_format($summary['remaining_minutes'] / 60, 2)) ?> hrs</span></div>
      <div class="detail-row"><span class="detail-label">OJT Start Date</span><span class="detail-value"><?= ($profile['ojt_start_date'] ?? null) ? e(date('M j, Y', strtotime($profile['ojt_start_date']))) : 'Not set' ?></span></div>
      <div class="detail-row"><span class="detail-label">Expected Completion</span><span class="detail-value"><?= ($profile['ojt_end_date'] ?? null) ? e(date('M j, Y', strtotime($profile['ojt_end_date']))) : 'Not set' ?></span></div>
    </div>
  </div>

  <!-- TODAY'S SCHEDULE — timeline -->
  <div class="card card-fluid">
    <h2 class="card-section-title">Today's OJT Schedule</h2>
    <?php if (!$todaySchedule['is_active']): ?>
      <p class="field-hint">No OJT schedule for today — this is your designated day off.</p>
    <?php else:
      $hasBreakSlot = $todaySchedule['break_start'] && $todaySchedule['break_end'];
      $hasCompletedBreak = (bool) $completedBreaks;
      $steps = [];
      $steps[] = ['time' => fmt_schedule_time($todaySchedule['start_time']), 'label' => 'Expected Time In',
        'done' => $attendanceState !== 'not_timed_in', 'active' => $attendanceState === 'not_timed_in'];
      if ($hasBreakSlot) {
        $steps[] = ['time' => fmt_schedule_time($todaySchedule['break_start']), 'label' => 'Break',
          'done' => $hasCompletedBreak || $attendanceState === 'completed', 'active' => $attendanceState === 'on_break'];
        $steps[] = ['time' => fmt_schedule_time($todaySchedule['break_end']), 'label' => 'Resume Work',
          'done' => $hasCompletedBreak || $attendanceState === 'completed',
          'active' => $attendanceState === 'timed_in' && $hasCompletedBreak];
      }
      $steps[] = ['time' => fmt_schedule_time($todaySchedule['end_time']), 'label' => 'Expected Time Out',
        'done' => $attendanceState === 'completed',
        'active' => $attendanceState === 'timed_in' && (!$hasBreakSlot || $hasCompletedBreak)];
    ?>
    <div class="timeline">
      <?php foreach ($steps as $i => $step): ?>
        <div class="timeline-step <?= $step['done'] ? 'done' : ($step['active'] ? 'active' : '') ?>">
          <div class="timeline-marker-col">
            <span class="timeline-dot"></span>
            <?php if ($i < count($steps) - 1): ?><span class="timeline-line"></span><?php endif; ?>
          </div>
          <div class="timeline-content">
            <span class="timeline-time"><?= e($step['time']) ?></span>
            <?php if ($step['active']): ?><span class="timeline-current-tag">Current</span><?php endif; ?>
            <div class="timeline-label"><?= e($step['label']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- RECENT ATTENDANCE -->
  <div class="card card-fluid">
    <h2 class="card-section-title">Recent Attendance</h2>
    <?php if (!$recent): ?>
      <p class="field-hint">No recent attendance records.</p>
    <?php else: ?>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr><th>Date</th><th>Time In</th><th>Break</th><th>Time Out</th><th>Worked</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $row):
            $breakMin = calculate_break_minutes_for_attendance((int) $row['id']);
            $rowIncomplete = !$row['time_out'];
            if ($rowIncomplete) {
                $rowStatusKey = 'timed_in';
                $rowStatusLabel = 'In Progress';
            } else {
                $rowSchedule = get_effective_schedule($user['id'], new DateTime($row['attendance_date']));
                $rowRequired = schedule_duration_minutes($rowSchedule);
                $rowWorked = calculate_worked_minutes($row, false);
                $rowCompletion = get_daily_completion_status($rowWorked, $rowRequired, $row['status']);
                $rowStatusKey = $rowCompletion['key'];
                $rowStatusLabel = $rowCompletion['label'];
            }
          ?>
          <tr>
            <td><?= e(date('M j', strtotime($row['attendance_date']))) ?></td>
            <td><?= fmt_time($row['time_in']) ?></td>
            <td><?= $breakMin > 0 ? e(format_minutes($breakMin)) : '--' ?></td>
            <td><?= fmt_time($row['time_out']) ?></td>
            <td><?= $rowIncomplete ? '--' : e(format_minutes(calculate_worked_minutes($row, false))) ?></td>
            <td><span class="status-pill status-<?= e($rowStatusKey) ?>"><?= e($rowStatusLabel) ?></span></td>
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

  <!-- OJT ASSIGNMENT -->
  <div class="card card-fluid" id="assignment">
    <h2 class="card-section-title">OJT Assignment</h2>
    <div class="detail-list">
      <div class="detail-row"><span class="detail-label">Company</span><span class="detail-value"><?= e($profile['company'] ?? 'Not assigned') ?></span></div>
      <div class="detail-row"><span class="detail-label">Supervisor</span><span class="detail-value">Not assigned</span></div>
      <div class="detail-row"><span class="detail-label">Position</span><span class="detail-value"><?= e($profile['position'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">Department</span><span class="detail-value"><?= e($profile['department'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">Start Date</span><span class="detail-value"><?= ($profile['ojt_start_date'] ?? null) ? e(date('M j, Y', strtotime($profile['ojt_start_date']))) : 'Not set' ?></span></div>
      <div class="detail-row"><span class="detail-label">End Date</span><span class="detail-value"><?= ($profile['ojt_end_date'] ?? null) ? e(date('M j, Y', strtotime($profile['ojt_end_date']))) : 'Not set' ?></span></div>
    </div>
  </div>

  <!-- NOTIFICATIONS -->
  <div class="card card-fluid">
    <h2 class="card-section-title">Notifications</h2>
    <?php if (!$headerNotifications): ?>
      <p class="field-hint">No notifications.</p>
    <?php else: ?>
      <div class="notif-list">
        <?php foreach ($headerNotifications as $n): ?>
          <div class="notif-item <?= $n['is_read'] ? 'read' : '' ?>">
            <span class="notif-item-dot"></span>
            <div>
              <div class="notif-item-title"><?= e($n['title']) ?></div>
              <div class="notif-item-message"><?= e($n['message']) ?></div>
              <div class="notif-item-time"><?= e(date('M j, g:i A', strtotime($n['created_at']))) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>
</div>

<!-- Time Out confirmation -->
<div class="modal-backdrop" id="timeOutModal" style="display:none;">
  <div class="modal-box">
    <h3>End Your OJT Session?</h3>
    <p class="field-hint">Please review today's hours before confirming.</p>
    <div class="detail-list" style="margin:16px 0;">
      <div class="detail-row"><span class="detail-label">Today's Worked Hours</span><span class="detail-value" id="confirmWorked"><?= e(format_minutes($todayWorkedMinutes)) ?></span></div>
      <div class="detail-row"><span class="detail-label">Break Duration</span><span class="detail-value"><?= e(format_minutes($todayBreakMinutes)) ?></span></div>
      <div class="detail-row"><span class="detail-label">Expected Hours</span><span class="detail-value"><?= e(format_minutes($requiredDailyMinutes)) ?></span></div>
    </div>
    <div class="filter-actions">
      <button type="button" class="btn-secondary" data-modal-cancel>Cancel</button>
      <button type="button" class="btn" id="timeOutBtn" data-action="time_out" data-csrf="<?= e(csrf_token()) ?>" data-action-url="<?= APP_URL ?>/student/attendance_action.php" style="width:auto;padding:11px 20px;">Confirm Time Out</button>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/partials/app_footer.php'; ?>
