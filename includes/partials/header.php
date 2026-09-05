<?php
/** Expects optional $pageTitle to already be set by the including page. */
require_once __DIR__ . '/../notifications.php';

$pageTitle = $pageTitle ?? APP_NAME;
$flash = get_flash();

$unreadCount = 0;
$headerNotifications = [];
if (!empty($navUser) && $navUser['role'] === 'student') {
    $headerNotifications = get_recent_notifications((int) $navUser['id'], 5);
    foreach ($headerNotifications as $n) {
        if (!$n['is_read']) {
            $unreadCount++;
        }
    }
    // Notifications are considered "seen" once they've appeared in the header
    // dropdown on any page load — the unread dot reflects activity since the
    // student's last visit, not whether they specifically clicked the bell open.
    if ($unreadCount > 0) {
        mark_notifications_read((int) $navUser['id']);
    }
}

function nav_initials(string $fullName): string {
    $parts = preg_split('/\s+/', trim($fullName));
    $initials = strtoupper(($parts[0][0] ?? '') . ($parts[count($parts) - 1][0] ?? ''));
    return $initials !== '' ? $initials : '?';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset_url('/assets/images/mbc-logo-color.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset_url('/assets/css/style.css')) ?>">
</head>
<body>
<div class="page">
  <header class="topbar">
    <a class="brand" href="<?= APP_URL ?>/index.php">
      <img src="<?= e(asset_url('/assets/images/mbc-logo-color.png')) ?>" alt="MBC Media Group" style="height:32px;width:auto;">
    </a>
    <?php if (!empty($navUser)): ?>
      <nav class="topnav">
        <?php if ($navUser['role'] === 'student'): ?>
          <div class="profile-menu">
            <button type="button" class="notif-bell" id="notifBell" aria-label="Notifications">
              &#128276;
              <?php if ($unreadCount > 0): ?><span class="notif-dot"></span><?php endif; ?>
            </button>
            <div class="profile-dropdown" id="notifDropdown" role="menu">
              <?php if (!$headerNotifications): ?>
                <div style="padding:12px;font-size:13px;color:var(--color-text-muted);">No notifications yet.</div>
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
        <?php endif; ?>

        <div class="profile-menu">
          <button type="button" class="profile-trigger" id="profileTrigger">
            <span class="profile-avatar"><?= e(nav_initials($navUser['full_name'])) ?></span>
            <span class="topnav-user"><?= e($navUser['full_name']) ?> <span class="badge badge-<?= e($navUser['role']) ?>"><?= e(ucfirst($navUser['role'])) ?></span></span>
          </button>
          <div class="profile-dropdown" id="profileDropdown" role="menu">
            <?php if ($navUser['role'] === 'student'): ?>
              <a href="<?= APP_URL ?>/student/dashboard.php#overview">My Profile</a>
              <a href="<?= APP_URL ?>/student/attendance_history.php">Attendance History</a>
              <a href="<?= APP_URL ?>/student/dashboard.php#assignment">OJT Information</a>
              <hr>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/auth/change_password.php">Change Password</a>
            <a href="<?= APP_URL ?>/auth/logout.php">Logout</a>
          </div>
        </div>
      </nav>
    <?php endif; ?>
  </header>

  <main class="content">
    <?php if ($flash): ?>
      <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
