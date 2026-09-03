<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    global $pdo;
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        $errors[] = 'Your current password is incorrect.';
    }

    $errors = array_merge($errors, validate_password_strength($newPassword));

    if ($newPassword !== $confirmPassword) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if (!$errors && password_verify($newPassword, $row['password_hash'])) {
        $errors[] = 'New password must be different from your current password.';
    }

    if (!$errors) {
        $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);

        // Re-read the canonical DB timestamp (not a PHP-side guess) and re-snapshot
        // this session so it stays valid; every OTHER session for this user now has
        // a stale snapshot and will be logged out on its next request.
        $freshStmt = $pdo->prepare('SELECT password_changed_at FROM users WHERE id = ?');
        $freshStmt->execute([$user['id']]);
        $_SESSION['pwd_changed_snapshot'] = strtotime($freshStmt->fetchColumn());
        revoke_all_remember_tokens($user['id']);
        $success = true;
    }
}

$navUser = $user;
$pageTitle = 'Change Password';
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card">
  <h1>Change Password</h1>
  <p class="subtitle">Update the password for your account.</p>

  <?php if ($success): ?>
    <div class="alert alert-success">Your password has been changed. Any other active sessions for your account have been signed out.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="">
    <?= csrf_field() ?>
    <div class="form-group">
      <label for="current_password">Current Password</label>
      <div class="password-field">
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        <button type="button" class="password-toggle" data-toggle-password="current_password">Show</button>
      </div>
    </div>
    <div class="form-group">
      <label for="new_password">New Password</label>
      <div class="password-field">
        <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
        <button type="button" class="password-toggle" data-toggle-password="new_password">Show</button>
      </div>
      <p class="field-hint">At least 8 characters, with an uppercase letter, a lowercase letter, and a number.</p>
    </div>
    <div class="form-group">
      <label for="confirm_password">Confirm New Password</label>
      <div class="password-field">
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
        <button type="button" class="password-toggle" data-toggle-password="confirm_password">Show</button>
      </div>
    </div>
    <button type="submit" class="btn">Change Password</button>
  </form>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
