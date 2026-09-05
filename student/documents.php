<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_role('student');
$navUser = $user;

$pageTitle = 'Documents';
$activeNav = 'documents';
include __DIR__ . '/../includes/partials/app_header.php';
?>
<div class="card card-fluid" style="text-align:center;padding:64px 32px;">
  <h1>Documents</h1>
  <p class="subtitle">Document uploads and requirements tracking are coming soon.</p>
  <span class="tile-soon">Coming soon</span>
</div>
<?php include __DIR__ . '/../includes/partials/app_footer.php'; ?>
