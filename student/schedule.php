<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';

$user = require_role('student');
$navUser = $user;

$dayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$displayOrder = [1, 2, 3, 4, 5, 6, 0]; // Monday .. Sunday
$todayDow = (int) (new DateTime())->format('w');

$now = new DateTime();
$sundayThisWeek = (clone $now)->modify('-' . $todayDow . ' days');

$week = [];
foreach ($displayOrder as $dow) {
    $dateForDow = (clone $sundayThisWeek)->modify('+' . $dow . ' days');
    $schedule = get_effective_schedule($user['id'], $dateForDow);
    $week[$dow] = $schedule;
}

function fmt_schedule_time_page(?string $time): string {
    return $time ? date('g:i A', strtotime($time)) : '--';
}

$pageTitle = 'Schedule';
$activeNav = 'schedule';
include __DIR__ . '/../includes/partials/app_header.php';
?>
<div class="card card-fluid">
  <h1>My OJT Schedule</h1>
  <p class="subtitle">Your expected Time In, Break, and Time Out for each day of the week.</p>

  <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>Day</th><th>Time In</th><th>Break</th><th>Time Out</th><th>Required Hours</th></tr>
      </thead>
      <tbody>
        <?php foreach ($displayOrder as $dow): $day = $week[$dow]; $isToday = $dow === $todayDow; ?>
        <tr style="<?= $isToday ? 'background:var(--color-info-bg);' : '' ?>">
          <td>
            <?= e($dayLabels[$dow]) ?>
            <?php if ($isToday): ?><span class="status-pill status-timed_in" style="margin-left:6px;">TODAY</span><?php endif; ?>
          </td>
          <?php if (!$day['is_active']): ?>
            <td colspan="4"><span class="status-pill status-inactive">DAY OFF</span></td>
          <?php else: ?>
            <td><?= e(fmt_schedule_time_page($day['start_time'])) ?></td>
            <td><?= $day['break_start'] ? e(fmt_schedule_time_page($day['break_start']) . ' – ' . fmt_schedule_time_page($day['break_end'])) : 'None' ?></td>
            <td><?= e(fmt_schedule_time_page($day['end_time'])) ?></td>
            <td><?= e(format_minutes(schedule_duration_minutes($day))) ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="field-hint" style="margin-top:16px;">Days without a custom schedule follow the default company hours. Contact your OJT coordinator if you believe your schedule is incorrect.</p>
</div>
<?php include __DIR__ . '/../includes/partials/app_footer.php'; ?>
