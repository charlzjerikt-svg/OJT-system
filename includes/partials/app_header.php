<?php
/**
 * Sidebar-shell chrome for authenticated app pages (student + admin +
 * change_password). Auth pages (login/register/forgot/reset password) keep
 * using the plain includes/partials/header.php instead — they have no
 * $navUser and stay a centered card, not this shell.
 *
 * Expects optional $pageTitle and $activeNav (nav item key) to already be set
 * by the including page. Reuses the exact same notifBell/notifDropdown/
 * profileTrigger/profileDropdown element IDs as header.php so the dropdown
 * JS in assets/js/main.js works unchanged for both shells.
 */
require_once __DIR__ . '/../notifications.php';
require_once __DIR__ . '/../icons.php';

$pageTitle = $pageTitle ?? APP_NAME;
$activeNav = $activeNav ?? '';
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

function nav_initials(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName));
    $initials = strtoupper(($parts[0][0] ?? '') . ($parts[count($parts) - 1][0] ?? ''));
    return $initials !== '' ? $initials : '?';
}

$studentNav = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => APP_URL . '/student/dashboard.php', 'icon' => 'home'],
    ['key' => 'attendance', 'label' => 'Attendance', 'href' => APP_URL . '/student/attendance_history.php', 'icon' => 'clock'],
    ['key' => 'progress', 'label' => 'OJT Progress', 'href' => APP_URL . '/student/progress.php', 'icon' => 'trending-up'],
    ['key' => 'schedule', 'label' => 'Schedule', 'href' => APP_URL . '/student/schedule.php', 'icon' => 'calendar'],
    ['key' => 'reports', 'label' => 'Reports', 'href' => APP_URL . '/student/reports.php', 'icon' => 'bar-chart-2'],
    ['key' => 'documents', 'label' => 'Documents', 'href' => APP_URL . '/student/documents.php', 'icon' => 'file-text'],
    ['key' => 'notifications', 'label' => 'Notifications', 'href' => APP_URL . '/student/notifications.php', 'icon' => 'bell'],
    ['key' => 'profile', 'label' => 'Profile', 'href' => APP_URL . '/student/profile.php', 'icon' => 'user'],
];
$adminNav = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => APP_URL . '/admin/dashboard.php', 'icon' => 'home'],
    ['key' => 'students', 'label' => 'Students', 'href' => APP_URL . '/admin/students.php', 'icon' => 'user'],
    ['key' => 'attendance', 'label' => 'Attendance', 'href' => APP_URL . '/admin/attendance.php', 'icon' => 'clock'],
    ['key' => 'schedules', 'label' => 'Schedules', 'href' => APP_URL . '/admin/student_schedule.php', 'icon' => 'calendar'],
];
$isAdmin = !empty($navUser) && $navUser['role'] === 'admin';
$navItems = $isAdmin ? $adminNav : $studentNav;

// Mobile bottom-nav: a curated 5-item subset for students (the remaining
// items — Progress/Reports/Documents — stay reachable via the hamburger
// drawer's full sidebar); admin's own nav only has 4 items, so it's used
// as-is rather than filtered further.
$bottomNavKeys = ['dashboard', 'attendance', 'schedule', 'notifications', 'profile'];
$bottomNavItems = $isAdmin
    ? $adminNav
    : array_values(array_filter($studentNav, fn($item) => in_array($item['key'], $bottomNavKeys, true)));
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
<div class="app-shell">
  <aside class="sidebar" id="appSidebar">
    <div class="sidebar-logo">
      <img src="<?= e(asset_url('/assets/images/mbc-logo-white.png')) ?>" alt="MBC Media Group">
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($navItems as $item): ?>
        <a class="sidebar-nav-item<?= $activeNav === $item['key'] ? ' active' : '' ?>" href="<?= e($item['href']) ?>">
          <?= icon($item['icon']) ?>
          <span><?= e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php if (!empty($navUser)): ?>
    <div class="sidebar-footer">
      <span class="profile-avatar"><?= e(nav_initials($navUser['full_name'])) ?></span>
      <div class="sidebar-footer-info">
        <div class="sidebar-footer-name"><?= e($navUser['full_name']) ?></div>
        <div class="sidebar-footer-role"><?= e(ucfirst($navUser['role'])) ?></div>
      </div>
      <a class="sidebar-logout" href="<?= APP_URL ?>/auth/logout.php" title="Logout" aria-label="Logout"><?= icon('log-out') ?></a>
    </div>
    <?php endif; ?>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <div class="app-main">
    <header class="app-topbar">
      <div class="app-topbar-left">
        <button type="button" class="hamburger-btn" id="hamburgerBtn" aria-label="Open menu"><?= icon('menu') ?></button>
        <h1 class="app-topbar-title"><?= e($pageTitle) ?></h1>
      </div>
      <?php if (!empty($navUser)): ?>
      <div class="app-topbar-right">
        <?php if ($navUser['role'] === 'student'): ?>
          <div class="profile-menu">
            <button type="button" class="notif-bell" id="notifBell" aria-label="Notifications">
              <?= icon('bell') ?>
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
                <a href="<?= APP_URL ?>/student/notifications.php" style="display:block;text-align:center;padding:10px;font-size:12px;font-weight:600;color:var(--color-primary);text-decoration:none;border-top:1px solid var(--color-border);">VIEW ALL NOTIFICATIONS &rarr;</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="profile-menu">
          <button type="button" class="profile-trigger" id="profileTrigger">
            <span class="profile-avatar"><?= e(nav_initials($navUser['full_name'])) ?></span>
            <span class="topnav-user"><?= e($navUser['full_name']) ?></span>
            <?= icon('chevron-down', 'icon') ?>
          </button>
          <div class="profile-dropdown" id="profileDropdown" role="menu">
            <?php if ($navUser['role'] === 'student'): ?>
              <a href="<?= APP_URL ?>/student/profile.php">My Profile</a>
              <a href="<?= APP_URL ?>/student/attendance_history.php">Attendance History</a>
              <a href="<?= APP_URL ?>/student/dashboard.php#assignment">OJT Information</a>
              <hr>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/auth/change_password.php">Change Password</a>
            <a href="<?= APP_URL ?>/auth/logout.php">Logout</a>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </header>

    <main class="app-content">
      <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endif; ?>
