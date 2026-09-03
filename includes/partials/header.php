<?php
/** Expects optional $pageTitle to already be set by the including page. */
$pageTitle = $pageTitle ?? APP_NAME;
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
<div class="page">
  <header class="topbar">
    <a class="brand" href="<?= APP_URL ?>/index.php">
      <span class="brand-mark">OJT</span>
      <span class="brand-name"><?= e(APP_NAME) ?></span>
    </a>
    <?php if (!empty($navUser)): ?>
      <nav class="topnav">
        <span class="topnav-user"><?= e($navUser['full_name']) ?> <span class="badge badge-<?= e($navUser['role']) ?>"><?= e(ucfirst($navUser['role'])) ?></span></span>
        <a href="<?= APP_URL ?>/auth/change_password.php">Change Password</a>
        <a href="<?= APP_URL ?>/auth/logout.php">Logout</a>
      </nav>
    <?php endif; ?>
  </header>

  <main class="content">
    <?php if ($flash): ?>
      <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
