<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';

$user = require_role('admin');
$navUser = $user;

$stats = get_admin_dashboard_stats();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
include __DIR__ . '/../includes/partials/app_header.php';
?>
<div class="card card-fluid">
  <h1>Welcome, <?= e($user['full_name']) ?></h1>
  <p class="subtitle">Admin Dashboard — <?= e($user['email']) ?></p>
</div>

<div class="card card-fluid">
  <h2 class="card-section-title">Overview</h2>
  <div class="stat-tile-row">
    <div class="stat-tile"><span class="stat-value"><?= $stats['total_students'] ?></span><span class="stat-label">Total Students</span></div>
    <div class="stat-tile"><span class="stat-value"><?= $stats['active_students'] ?></span><span class="stat-label">Active Students</span></div>
    <div class="stat-tile"><span class="stat-value"><?= $stats['currently_timed_in'] ?></span><span class="stat-label">Currently Timed In</span></div>
    <div class="stat-tile"><span class="stat-value"><?= $stats['currently_on_break'] ?></span><span class="stat-label">Currently On Break</span></div>
    <div class="stat-tile"><span class="stat-value"><?= $stats['today_present'] ?></span><span class="stat-label">Today's Present</span></div>
    <div class="stat-tile"><span class="stat-value"><?= $stats['today_late'] ?></span><span class="stat-label">Today's Late</span></div>
    <div class="stat-tile"><span class="stat-value"><?= $stats['completed_ojt'] ?></span><span class="stat-label">Completed OJT</span></div>
  </div>
</div>

<div class="dashboard-grid">
  <a class="dashboard-tile" href="<?= APP_URL ?>/admin/students.php" style="text-decoration:none;color:inherit;"><strong>Student Management</strong>Approve registrations, manage accounts.</a>
  <a class="dashboard-tile" href="<?= APP_URL ?>/admin/attendance.php" style="text-decoration:none;color:inherit;"><strong>Attendance Management</strong>Review, correct, and export attendance.</a>
  <div class="dashboard-tile"><strong>Announcements</strong>Post announcements.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Notifications</strong>View system notifications.<span class="tile-soon">Coming soon</span></div>
</div>
<?php include __DIR__ . '/../includes/partials/app_footer.php'; ?>
