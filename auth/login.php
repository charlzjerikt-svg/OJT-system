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
        $errors[] = 'Please enter both your email/username and password.';
    } elseif (too_many_attempts($identifier, $ip, 'login', 5, 20, 15)) {
        $errors[] = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $result = attempt_login($identifier, $password);

        if ($result['ok']) {
            record_attempt($identifier, $ip, 'login', true);
            login_user($result['user']);

            if ($remember) {
                // Extend the session cookie lifetime beyond the browser session.
                $params = session_get_cookie_params();
                setcookie(session_name(), session_id(), time() + (30 * 24 * 60 * 60), $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }

            redirect($result['user']['role'] === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php');
        } else {
            record_attempt($identifier, $ip, 'login', false);
            $errors[] = $result['error'];
        }
    }
}

$pageTitle = 'Login';
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card">
  <h1>Welcome back</h1>
  <p class="subtitle">Sign in to your OJT-system account.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="">
    <?= csrf_field() ?>
    <div class="form-group">
      <label for="identifier">Email or Username</label>
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
      <label for="remember" style="margin:0;font-weight:400;">Remember me</label>
    </div>
    <button type="submit" class="btn">Log In</button>
  </form>

  <div class="form-footer">
    <a href="<?= APP_URL ?>/auth/forgot_password.php">Forgot password?</a>
    <a href="<?= APP_URL ?>/auth/register.php">Register as student</a>
  </div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
