<?php
require_once __DIR__ . '/../includes/auth.php';

if (current_user()) {
    redirect('/index.php');
}

$errors = [];
$submitted = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email = trim($_POST['email'] ?? '');
    $ip = client_ip();

    if ($email === '' || !is_valid_email($email)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (too_many_attempts($email, $ip, 'password_reset_request', 3, 10, 60)) {
        // Same generic outcome as success — never reveal rate limiting vs. account existence separately.
        $submitted = true;
    } else {
        record_attempt($email, $ip, 'password_reset_request', true);

        global $pdo;
        $stmt = $pdo->prepare('SELECT id, full_name, email, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'active') {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', time() + 45 * 60);

            $pdo->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
            )->execute([$user['id'], $tokenHash, $expiresAt]);

            $resetLink = APP_URL . '/auth/reset_password.php?token=' . $rawToken;

            require_once __DIR__ . '/../includes/mailer.php';
            send_reset_email($user['email'], $user['full_name'], $resetLink);
        }

        $submitted = true;
    }
}

$pageTitle = 'Forgot Password';
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card">
  <h1>Forgot your password?</h1>
  <p class="subtitle">Enter your registered email and we'll send you a reset link.</p>

  <?php if ($submitted): ?>
    <div class="alert alert-success">If an account with that email exists, a password reset link has been sent. Please check your inbox.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$submitted): ?>
  <form method="post" action="">
    <?= csrf_field() ?>
    <div class="form-group">
      <label for="email">Email Address</label>
      <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
    </div>
    <button type="submit" class="btn">Send Reset Link</button>
  </form>
  <?php endif; ?>

  <div class="form-footer">
    <a href="<?= APP_URL ?>/auth/login.php">Back to Login</a>
  </div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
