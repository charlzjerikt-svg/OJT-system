<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/icons.php';

$user = require_role('student');
$navUser = $user;

$all = get_all_notifications($user['id']);

$typeIcons = [
    'account_approved' => 'check-circle',
    'time_in' => 'log-in',
    'time_out' => 'log-out',
    'late' => 'alert-triangle',
    'break_start' => 'coffee',
    'break_end' => 'play',
    'ojt_completed' => 'award',
];

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$groups = ['Today' => [], 'Yesterday' => [], 'Earlier' => []];
foreach ($all as $n) {
    $date = date('Y-m-d', strtotime($n['created_at']));
    if ($date === $today) {
        $groups['Today'][] = $n;
    } elseif ($date === $yesterday) {
        $groups['Yesterday'][] = $n;
    } else {
        $groups['Earlier'][] = $n;
    }
}

$pageTitle = 'Notifications';
$activeNav = 'notifications';
include __DIR__ . '/../includes/partials/app_header.php';
?>
<div class="card card-fluid">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <h1>Notifications</h1>
      <p class="subtitle">Your recent account and attendance activity.</p>
    </div>
    <?php if ($all): ?>
    <form method="post" action="<?= APP_URL ?>/student/notification_action.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_all_read">
      <button type="submit" class="btn-secondary">Mark All As Read</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if (!$all): ?>
    <p class="field-hint">No notifications yet.</p>
  <?php endif; ?>

  <?php foreach ($groups as $label => $items): ?>
    <?php if (!$items) continue; ?>
    <h2 class="card-section-title" style="margin-top:24px;"><?= e($label) ?></h2>
    <div class="notif-list">
      <?php foreach ($items as $n): ?>
        <div class="notif-item <?= $n['is_read'] ? 'read' : '' ?>" style="align-items:flex-start;gap:12px;">
          <span style="color:var(--color-primary);flex-shrink:0;margin-top:2px;"><?= icon($typeIcons[$n['type']] ?? 'info') ?></span>
          <div style="flex:1;">
            <div class="notif-item-title"><?= e($n['title']) ?></div>
            <div class="notif-item-message"><?= e($n['message']) ?></div>
            <div class="notif-item-time"><?= e(date('M j, g:i A', strtotime($n['created_at']))) ?></div>
          </div>
          <?php if (!$n['is_read']): ?>
          <form method="post" action="<?= APP_URL ?>/student/notification_action.php" style="flex-shrink:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_read">
            <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
            <button type="submit" class="btn-secondary btn-tiny">Mark Read</button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/partials/app_footer.php'; ?>
