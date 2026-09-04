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
    echo '<div class="card"><h1>Student not found</h1><p class="subtitle">This student does not exist or is not a student account.</p>'
       . '<a class="btn" href="' . APP_URL . '/admin/students.php">Back to Student Management</a></div>';
    include __DIR__ . '/../includes/partials/footer.php';
    exit;
}

$summary = calculate_ojt_summary($studentId);
$ojtStatus = ojt_status_from_summary($summary);
$attSummary = get_student_attendance_summary($studentId);

global $pdo;
$stmt = $pdo->prepare(
    'SELECT id, attendance_date, time_in, time_out, status, source
     FROM attendance WHERE user_id = ? ORDER BY attendance_date DESC LIMIT 10'
);
$stmt->execute([$studentId]);
$recent = $stmt->fetchAll();

$today = get_today_attendance($studentId);
$todayStatusLabel = (!$today || !$today['time_in']) ? 'Not Timed In'
    : (!$today['time_out'] ? 'Currently Working' : 'Completed');

function fmt_time_admin(?string $datetime): string {
    return $datetime ? date('g:i A', strtotime($datetime)) : '--';
}

$fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['middle_name'] ? $profile['middle_name'] . ' ' : '') . ($profile['last_name'] ?? ''));
if ($fullName === '') {
    $fullName = $profile['full_name'];
}

$pageTitle = $fullName;
$extraScripts = [APP_URL . '/assets/js/admin.js'];
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card card-wide">
  <h1><?= e($fullName) ?></h1>
  <p class="subtitle">
    Student ID: <?= e($profile['student_id'] ?? '—') ?>
    &middot; <span class="status-pill status-<?= e($profile['account_status']) ?>"><?= e(strtoupper($profile['account_status'])) ?></span>
  </p>

  <h2 class="card-section-title" style="margin-top:20px;">Student Information</h2>
  <dl class="info-grid">
    <div><dt>Email</dt><dd><?= e($profile['email']) ?></dd></div>
    <div><dt>Contact Number</dt><dd><?= e($profile['contact_number'] ?? 'Not set') ?></dd></div>
    <div><dt>Course / Program</dt><dd><?= e($profile['course'] ?? 'Not set') ?></dd></div>
    <div><dt>Year Level</dt><dd><?= e($profile['year_level'] ?? 'Not set') ?></dd></div>
    <div><dt>School</dt><dd><?= e($profile['school'] ?? 'Not set') ?></dd></div>
    <div><dt>OJT Company</dt><dd><?= e($profile['company'] ?? 'Not set') ?></dd></div>
    <div><dt>OJT Start Date</dt><dd><?= $profile['ojt_start_date'] ? e(date('M j, Y', strtotime($profile['ojt_start_date']))) : 'Not set' ?></dd></div>
    <div><dt>Expected End Date</dt><dd><?= $profile['ojt_end_date'] ? e(date('M j, Y', strtotime($profile['ojt_end_date']))) : 'Not set' ?></dd></div>
    <div><dt>Registered</dt><dd><?= e(date('M j, Y', strtotime($profile['created_at']))) ?></dd></div>
  </dl>

  <div class="admin-row-actions" style="margin-top:20px;">
    <?php if ($profile['account_status'] === 'pending'): ?>
      <form method="post" action="<?= APP_URL ?>/admin/student_action.php" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="student_id" value="<?= $studentId ?>">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="return_to" value="profile">
        <button type="submit" class="btn-secondary">Approve</button>
      </form>
      <button type="button" class="btn-secondary" data-reject-student="<?= $studentId ?>" data-reject-name="<?= e($fullName) ?>" data-return-profile="1">Reject</button>
    <?php elseif ($profile['account_status'] === 'active'): ?>
      <button type="button" class="btn-secondary" data-deactivate-student="<?= $studentId ?>" data-deactivate-name="<?= e($fullName) ?>" data-return-profile="1">Deactivate</button>
    <?php else: ?>
      <form method="post" action="<?= APP_URL ?>/admin/student_action.php" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="student_id" value="<?= $studentId ?>">
        <input type="hidden" name="action" value="activate">
        <input type="hidden" name="return_to" value="profile">
        <button type="submit" class="btn-secondary">Activate</button>
      </form>
    <?php endif; ?>
    <form method="post" action="<?= APP_URL ?>/admin/student_action.php" style="display:inline;">
      <?= csrf_field() ?>
      <input type="hidden" name="student_id" value="<?= $studentId ?>">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="return_to" value="profile">
      <button type="submit" class="btn-secondary">Reset Password</button>
    </form>
  </div>
</div>

<div class="card card-wide" style="margin-top:20px;">
  <h2 class="card-section-title">OJT Progress</h2>
  <div class="stat-tile-row">
    <div class="stat-tile"><span class="stat-value"><?= number_format($summary['required_minutes'] / 60, 2) ?> hrs</span><span class="stat-label">Required Hours</span></div>
    <div class="stat-tile"><span class="stat-value"><?= number_format($summary['completed_minutes'] / 60, 2) ?> hrs</span><span class="stat-label">Completed</span></div>
    <div class="stat-tile"><span class="stat-value"><?= number_format($summary['remaining_minutes'] / 60, 2) ?> hrs</span><span class="stat-label">Remaining</span></div>
  </div>
  <div class="progress-row">
    <div class="progress-bar-track"><div class="progress-bar-fill" style="width: <?= (string) round($summary['percent'], 2) ?>%;"></div></div>
    <span class="progress-percent"><?= number_format($summary['percent'], 2) ?>%</span>
  </div>
  <p class="attendance-status" style="margin:16px 0 0;">Status: <span class="status-pill status-<?= e($ojtStatus['key']) ?>"><?= e(strtoupper($ojtStatus['label'])) ?></span></p>
</div>

<div class="card card-wide" style="margin-top:20px;">
  <h2 class="card-section-title">Attendance Summary</h2>
  <div class="stat-tile-row">
    <div class="stat-tile"><span class="stat-value"><?= $attSummary['total_days'] ?></span><span class="stat-label">Total Attendance Days</span></div>
    <div class="stat-tile"><span class="stat-value"><?= number_format($summary['completed_minutes'] / 60, 2) ?> hrs</span><span class="stat-label">Total Hours</span></div>
    <div class="stat-tile"><span class="stat-value"><?= $attSummary['late_count'] ?></span><span class="stat-label">Late Count</span></div>
    <div class="stat-tile"><span class="stat-value"><?= e($todayStatusLabel) ?></span><span class="stat-label">Today's Status</span></div>
  </div>
</div>

<div class="card card-wide" style="margin-top:20px;">
  <h2 class="card-section-title">Recent Attendance</h2>
  <?php if (!$recent): ?>
    <p class="field-hint">No attendance records yet.</p>
  <?php else: ?>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Worked</th><th>Status</th><th>Source</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $row): $incomplete = !$row['time_out']; ?>
        <tr>
          <td><?= e(date('M j, Y', strtotime($row['attendance_date']))) ?></td>
          <td><?= fmt_time_admin($row['time_in']) ?></td>
          <td><?= fmt_time_admin($row['time_out']) ?></td>
          <td><?= $incomplete ? 'In Progress' : e(format_minutes(calculate_worked_minutes($row, false))) ?></td>
          <td><span class="status-pill status-<?= $incomplete ? 'incomplete' : e($row['status']) ?>"><?= $incomplete ? 'Incomplete' : e(ucfirst($row['status'])) ?></span></td>
          <td><?= $row['source'] === 'manual' ? '<span class="status-pill status-late">Manual</span>' : 'System' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <a class="btn btn-secondary" href="<?= APP_URL ?>/admin/attendance.php?search=<?= urlencode($profile['student_id'] ?? '') ?>" style="margin-top:16px;">View Full Attendance</a>
</div>

<div class="form-footer">
  <a href="<?= APP_URL ?>/admin/students.php">&larr; Back to Student Management</a>
</div>

<!-- Reject / Deactivate dialogs (shared markup/JS with students.php) -->
<div class="modal-backdrop" id="rejectModal" style="display:none;">
  <div class="modal-box">
    <h3>Reject Registration?</h3>
    <p id="rejectModalText" class="field-hint"></p>
    <form method="post" action="<?= APP_URL ?>/admin/student_action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="student_id" id="rejectStudentId" value="">
      <input type="hidden" name="return_to" id="rejectReturnTo" value="">
      <div class="form-group"><label for="reject_reason">Reason</label><textarea id="reject_reason" name="reason" rows="3" required></textarea></div>
      <div class="filter-actions">
        <button type="button" class="btn-secondary" data-modal-cancel>Cancel</button>
        <button type="submit" class="btn" style="background:var(--color-danger);width:auto;padding:11px 20px;">Reject</button>
      </div>
    </form>
  </div>
</div>
<div class="modal-backdrop" id="deactivateModal" style="display:none;">
  <div class="modal-box">
    <h3>Deactivate Student?</h3>
    <p id="deactivateModalText" class="field-hint"></p>
    <form method="post" action="<?= APP_URL ?>/admin/student_action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="deactivate">
      <input type="hidden" name="student_id" id="deactivateStudentId" value="">
      <input type="hidden" name="return_to" id="deactivateReturnTo" value="">
      <div class="form-group"><label for="deactivate_reason">Reason (optional)</label><textarea id="deactivate_reason" name="reason" rows="3"></textarea></div>
      <div class="filter-actions">
        <button type="button" class="btn-secondary" data-modal-cancel>Cancel</button>
        <button type="submit" class="btn" style="background:var(--color-danger);width:auto;padding:11px 20px;">Deactivate</button>
      </div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
