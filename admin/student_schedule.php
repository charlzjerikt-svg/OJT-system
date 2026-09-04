<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';

$admin = require_role('admin');
$navUser = $admin;

$studentId = (int) ($_GET['id'] ?? 0);
$profile = get_student_admin_profile($studentId);

if (!$profile) {
    http_response_code(404);
    $pageTitle = 'Student Not Found';
    include __DIR__ . '/../includes/partials/header.php';
    echo '<div class="card"><h1>Student not found</h1>'
       . '<a class="btn" href="' . APP_URL . '/admin/students.php">Back to Student Management</a></div>';
    include __DIR__ . '/../includes/partials/footer.php';
    exit;
}

$week = get_student_schedule_week($studentId);

$fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['middle_name'] ? $profile['middle_name'] . ' ' : '') . ($profile['last_name'] ?? ''));
if ($fullName === '') {
    $fullName = $profile['full_name'];
}

function fmt_hhmm(?string $time): string {
    return $time ? substr($time, 0, 5) : '';
}

$pageTitle = 'Manage Schedule';
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card card-wide">
  <h1>OJT Schedule — <?= e($fullName) ?></h1>
  <p class="subtitle">Set this student's expected Time In, Break, and Time Out for each day of the week. A day left as "Day Off" is not a valid working day for Time In. Leave a day untouched to keep the app-wide default (<?= e(date('g:i A', strtotime(WORKDAY_START_TIME))) ?> – <?= e(date('g:i A', strtotime(WORKDAY_END_TIME))) ?>, break <?= e(date('g:i A', strtotime(DEFAULT_BREAK_START))) ?> – <?= e(date('g:i A', strtotime(DEFAULT_BREAK_END))) ?>).</p>

  <form method="post" action="<?= APP_URL ?>/admin/schedule_action.php">
    <?= csrf_field() ?>
    <input type="hidden" name="student_id" value="<?= $studentId ?>">

    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr><th>Day</th><th>Working Day</th><th>Time In</th><th>Time Out</th><th>Break Start</th><th>Break End</th></tr>
        </thead>
        <tbody>
          <?php foreach (SCHEDULE_DAY_LABELS as $dow => $label): $day = $week[$dow]; ?>
          <tr>
            <td><?= e($label) ?><?= $day['source'] === 'default' ? ' <span class="field-hint" style="margin:0;display:inline;">(default)</span>' : '' ?></td>
            <td><input type="checkbox" name="days[<?= $dow ?>][is_active]" value="1" <?= $day['is_active'] ? 'checked' : '' ?>></td>
            <td><input type="time" name="days[<?= $dow ?>][start_time]" value="<?= e(fmt_hhmm($day['start_time'])) ?>"></td>
            <td><input type="time" name="days[<?= $dow ?>][end_time]" value="<?= e(fmt_hhmm($day['end_time'])) ?>"></td>
            <td><input type="time" name="days[<?= $dow ?>][break_start]" value="<?= e(fmt_hhmm($day['break_start'])) ?>"></td>
            <td><input type="time" name="days[<?= $dow ?>][break_end]" value="<?= e(fmt_hhmm($day['break_end'])) ?>"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="admin-row-actions" style="margin-top:20px;">
      <button type="submit" class="btn-secondary">Save Schedule</button>
      <a class="btn-secondary" href="<?= APP_URL ?>/admin/student_profile.php?id=<?= $studentId ?>">Cancel</a>
    </div>
  </form>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
