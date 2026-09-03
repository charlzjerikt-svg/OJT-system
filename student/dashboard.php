<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_role('student');
$navUser = $user;
$pageTitle = 'Student Dashboard';
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card card-wide">
  <h1>Welcome, <?= e($user['full_name']) ?></h1>
  <p class="subtitle">Student Dashboard — <?= e($user['email']) ?><?= $user['student_id'] ? ' · ID: ' . e($user['student_id']) : '' ?></p>
</div>

<div class="dashboard-grid" style="margin-top:20px;max-width:720px;">
  <div class="dashboard-tile"><strong>Time In</strong>Record your arrival time.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Time Out</strong>Record your departure time.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Attendance History</strong>View your past attendance records.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>OJT Progress</strong>Track your accumulated hours.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Correction Requests</strong>Request attendance corrections.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Announcements</strong>View program announcements.<span class="tile-soon">Coming soon</span></div>
  <div class="dashboard-tile"><strong>Notifications</strong>View your notifications.<span class="tile-soon">Coming soon</span></div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
