<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';

$user = require_role('student');
$navUser = $user;

global $pdo;
$stmt = $pdo->prepare(
    'SELECT first_name, middle_name, last_name, contact_number, school, course, major,
            year_level, section, company, department, position, ojt_start_date, ojt_end_date, ojt_status
     FROM student_profiles WHERE user_id = ?'
);
$stmt->execute([$user['id']]);
$profile = $stmt->fetch() ?: [];

$summary = calculate_ojt_summary($user['id']);
$ojtStatus = ojt_status_from_summary($summary);

$pageTitle = 'Profile';
$activeNav = 'profile';
include __DIR__ . '/../includes/partials/app_header.php';
?>
<div class="card card-fluid">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <span class="avatar-lg" style="width:64px;height:64px;font-size:24px;"><?= e(nav_initials($user['full_name'])) ?></span>
    <div style="flex:1;min-width:200px;">
      <h1 style="margin:0;"><?= e($user['full_name']) ?></h1>
      <p class="subtitle" style="margin:2px 0 0;">Student ID: <?= e($user['student_id'] ?? '—') ?></p>
    </div>
    <span class="status-pill status-<?= e($user['status']) ?>"><?= e(strtoupper($user['status'])) ?></span>
  </div>
</div>

<div class="dashboard-layout">
<div class="dashboard-main">
  <div class="card card-fluid">
    <h2 class="card-section-title">Student Information</h2>
    <div class="detail-list">
      <div class="detail-row"><span class="detail-label">Full Name</span><span class="detail-value"><?= e($user['full_name']) ?></span></div>
      <div class="detail-row"><span class="detail-label">Email</span><span class="detail-value"><?= e($user['email']) ?></span></div>
      <div class="detail-row"><span class="detail-label">Contact Number</span><span class="detail-value"><?= e($profile['contact_number'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">School</span><span class="detail-value"><?= e($profile['school'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">Course</span><span class="detail-value"><?= e($profile['course'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">Major</span><span class="detail-value"><?= e($profile['major'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">Year Level</span><span class="detail-value"><?= e($profile['year_level'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">Section</span><span class="detail-value"><?= e($profile['section'] ?? 'Not set') ?></span></div>
    </div>
  </div>
</div>
<div class="dashboard-side">
  <div class="card card-fluid">
    <h2 class="card-section-title">OJT Information</h2>
    <div class="detail-list">
      <div class="detail-row"><span class="detail-label">Company</span><span class="detail-value"><?= e($profile['company'] ?? 'Not assigned') ?></span></div>
      <div class="detail-row"><span class="detail-label">Department</span><span class="detail-value"><?= e($profile['department'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">Position</span><span class="detail-value"><?= e($profile['position'] ?? 'Not set') ?></span></div>
      <div class="detail-row"><span class="detail-label">OJT Status</span><span class="detail-value"><span class="status-pill status-<?= e($ojtStatus['key']) ?>"><?= e(strtoupper($ojtStatus['label'])) ?></span></span></div>
      <div class="detail-row"><span class="detail-label">OJT Start Date</span><span class="detail-value"><?= ($profile['ojt_start_date'] ?? null) ? e(date('M j, Y', strtotime($profile['ojt_start_date']))) : 'Not set' ?></span></div>
      <div class="detail-row"><span class="detail-label">OJT End Date</span><span class="detail-value"><?= ($profile['ojt_end_date'] ?? null) ? e(date('M j, Y', strtotime($profile['ojt_end_date']))) : 'Not set' ?></span></div>
    </div>
  </div>

  <div class="card card-fluid">
    <h2 class="card-section-title">Account</h2>
    <a class="btn-secondary" href="<?= APP_URL ?>/auth/change_password.php" style="display:block;text-align:center;">Change Password</a>
  </div>
</div>
</div>
<?php include __DIR__ . '/../includes/partials/app_footer.php'; ?>
