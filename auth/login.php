<?php
require_once __DIR__ . '/../includes/auth.php';

$existingUser = current_user();
if ($existingUser) {
    redirect($existingUser['role'] === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php');
}

$errors = [];
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $identifier = trim($_POST['identifier'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);
    $ip = client_ip();

    if ($identifier === '' || $password === '') {
        $errors[] = 'Please enter both your email/student ID and password.';
    } elseif (too_many_attempts($identifier, $ip, 'login', MAX_LOGIN_ATTEMPTS, MAX_LOGIN_ATTEMPTS_PER_IP, LOGIN_LOCKOUT_MINUTES)) {
        $errors[] = 'Too many failed attempts. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.';
    } else {
        $result = attempt_login($identifier, $password);

        if ($result['ok']) {
            record_attempt($identifier, $ip, 'login', true);
            login_user($result['user']);

            if ($remember) {
                issue_remember_token($result['user']['id']);
            }

            redirect($result['user']['role'] === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php');
        } else {
            record_attempt($identifier, $ip, 'login', false);
            $errors[] = $result['error'];
        }
    }
}

$pageTitle = 'Login';
$extraScripts = ['/assets/js/login.js'];
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="auth-split">
  <div class="auth-split-panel">
    <img src="<?= e(asset_url('/assets/images/mbc-logo-white.png')) ?>" alt="MBC Media Group">
    <h2>OJT ATTENDANCE SYSTEM</h2>
    <p class="auth-split-tagline">&ldquo;Building Connections. Creating Impact.&rdquo;</p>
  </div>
  <div class="auth-split-form">
    <h1>Welcome Back!</h1>
    <p class="subtitle">Sign in to your OJT-system account.</p>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="" id="loginForm">
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="identifier">Email / Student ID</label>
        <input type="text" id="identifier" name="identifier" value="<?= e($identifier) ?>" autocomplete="username" required autofocus>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <div class="password-field">
          <input type="password" id="password" name="password" autocomplete="current-password" required>
          <button type="button" class="password-toggle" data-toggle-password="password">Show</button>
        </div>
      </div>
      <div class="form-group checkbox-row">
        <input type="checkbox" id="remember" name="remember" value="1">
        <label for="remember" style="margin:0;font-weight:400;">Remember Me</label>
      </div>
      <button type="submit" class="btn" id="loginSubmit">Log In</button>
    </form>

    <div class="form-footer">
      <a href="<?= APP_URL ?>/auth/forgot_password.php">Forgot Password?</a>
      <a href="<?= APP_URL ?>/auth/register.php">Don't have an account? Register here</a>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
