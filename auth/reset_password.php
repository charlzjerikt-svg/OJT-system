<?php
require_once __DIR__ . '/../includes/auth.php';

if (current_user()) {
    redirect('/index.php');
}

global $pdo;

function find_valid_reset(string $rawToken): ?array {
    global $pdo;
    if ($rawToken === '') {
        return null;
    }
    $tokenHash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare(
        'SELECT pr.id AS reset_id, pr.user_id, u.full_name, u.email, u.status
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$token = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '');
$token = is_string($token) ? $token : '';
$reset = find_valid_reset($token);
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (!$reset || $reset['status'] !== 'active') {
        $errors[] = 'This password reset link is invalid or has expired. Please request a new one.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $errors = validate_password_strength($password);
        if ($password !== $confirm) {
            $errors[] = 'Password confirmation does not match.';
        }

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);

                $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                    ->execute([$reset['user_id']]);

                $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')
                    ->execute([$reset['user_id']]);

                $pdo->commit();
                $success = true;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('Password reset failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
    }
}

$pageTitle = 'Reset Password';
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card">
  <h1>Reset your password</h1>

  <?php if ($success): ?>
    <p class="subtitle">Your password has been reset successfully.</p>
    <div class="alert alert-success">You can now log in with your new password.</div>
    <a class="btn" href="<?= APP_URL ?>/auth/login.php">Go to Login</a>
  <?php elseif (!$reset): ?>
    <p class="subtitle">This link is no longer valid.</p>
    <div class="alert alert-error">This password reset link is invalid, expired, or has already been used.</div>
    <a class="btn" href="<?= APP_URL ?>/auth/forgot_password.php">Request a new link</a>
  <?php else: ?>
    <p class="subtitle">Choose a new password for <?= e($reset['email']) ?>.</p>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="form-group">
        <label for="password">New Password</label>
        <div class="password-field">
          <input type="password" id="password" name="password" autocomplete="new-password" required>
          <button type="button" class="password-toggle" data-toggle-password="password">Show</button>
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
      <button type="submit" class="btn">Reset Password</button>
    </form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
