<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_role('admin');
$navUser = $user;
$pageTitle = 'Admin Dashboard';
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card card-wide">
  <h1>Welcome, <?= e($user['full_name']) ?></h1>
  <p class="subtitle">Admin Dashboard — <?= e($user['email']) ?></p>
</div>

<div class="dashboard-grid" style="margin-top:20px;max-width:720px;">
  <div class="dashboard-tile"><strong>Student Management</strong>Manage student accounts and records.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Attendance Management</strong>Review Time In/Out logs.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Attendance Corrections</strong>Approve correction requests.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Reports</strong>Generate OJT progress reports.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>OJT Settings</strong>Configure program settings.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Schedule Management</strong>Manage student duty schedules.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Announcements</strong>Post announcements.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Notifications</strong>View system notifications.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Audit Logs</strong>Review account activity.<span class="tile-soon">Coming soon</span></div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
