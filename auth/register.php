<?php
// Buffered from the very first line so that any stray PHP notice/warning
// (e.g. from upload handling) never corrupts a JSON response below.
ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/registration.php';
require_once __DIR__ . '/../includes/upload.php';

$existingUser = current_user();
if ($existingUser) {
    redirect($existingUser['role'] === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php');
}

function json_response(array $payload, int $status = 200): void {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The page's JS always submits this via fetch(); a JSON response is all
    // register.js knows how to handle.
    csrf_verify();

    $ip = client_ip();

    if (too_many_attempts($ip, $ip, 'register', 10, 20, 15)) {
        json_response([
            'success' => false,
            'message' => 'Too many registration attempts from this location. Please try again later.',
        ], 429);
    }

    global $pdo;

    $result = validate_registration($_POST);
    $errors = $result['errors'];
    $data = $result['data'];

    if (!$errors) {
        if (student_id_exists($pdo, $data['student_id'])) {
            $errors['student_id'] = 'Student ID already exists.';
        }
        if (email_exists($pdo, $data['email'])) {
            $errors['email'] = 'Email address is already registered.';
        }
    }

    if ($errors) {
        record_attempt($ip, $ip, 'register', false);
        json_response([
            'success' => false,
            'message' => 'Please correct the highlighted fields.',
            'errors' => $errors,
        ], 422);
    }

    $uploadDir = __DIR__ . '/../uploads/profile_pictures';
    $upload = handle_image_upload($_FILES['profile_picture'] ?? [], $uploadDir);

    if (!$upload['ok']) {
        record_attempt($ip, $ip, 'register', false);
        json_response([
            'success' => false,
            'message' => 'Please correct the highlighted fields.',
            'errors' => ['profile_picture' => $upload['error']],
        ], 422);
    }

    $profilePicturePath = $upload['path'] ? 'profile_pictures/' . $upload['path'] : null;

    try {
        create_student_registration($pdo, $data, $profilePicturePath);
    } catch (PDOException $e) {
        if ($upload['path']) {
            delete_uploaded_file($uploadDir, $upload['path']);
        }

        // Defense in depth: a race between the exists-checks above and this
        // insert (two submissions with the same student_id/email at once).
        if ((int) $e->getCode() === 23000) {
            record_attempt($ip, $ip, 'register', false);
            json_response([
                'success' => false,
                'message' => 'Please correct the highlighted fields.',
                'errors' => ['student_id' => 'This Student ID or email was just registered. Please double-check your details.'],
            ], 422);
        }

        error_log('Registration failed: ' . $e->getMessage());
        record_attempt($ip, $ip, 'register', false);
        json_response([
            'success' => false,
            'message' => 'Something went wrong while creating your account. Please try again.',
        ], 500);
    }

    record_attempt($ip, $ip, 'register', true);
    json_response([
        'success' => true,
        'message' => 'Registration successful. Your account is pending admin approval.',
    ]);
}

$pageTitle = 'Student Registration';
$extraScripts = ['/assets/js/register.js'];
include __DIR__ . '/../includes/partials/header.php';
?>
<div class="card card-wide">
  <h1>Create your student account</h1>
  <p class="subtitle">Register for OJT tracking. An administrator will review and approve your account before you can log in.</p>

  <noscript><div class="alert alert-error">JavaScript is required to submit this form.</div></noscript>

  <div id="formAlert" class="alert" style="display:none;" role="alert"></div>

  <form id="registerForm" method="post" action="<?= APP_URL ?>/auth/register.php" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <h2 class="form-section-title">Student Information</h2>
    <div class="form-group">
      <label for="student_id" class="required">Student ID</label>
      <input type="text" id="student_id" name="student_id" maxlength="50" required autocomplete="off">
      <span class="field-error" id="error-student_id"></span>
    </div>

    <div class="form-grid form-grid-3">
      <div class="form-group">
        <label for="first_name" class="required">First Name</label>
        <input type="text" id="first_name" name="first_name" maxlength="100" required autocomplete="given-name">
        <span class="field-error" id="error-first_name"></span>
      </div>
      <div class="form-group">
        <label for="middle_name">Middle Name</label>
        <input type="text" id="middle_name" name="middle_name" maxlength="100" autocomplete="additional-name">
        <span class="field-error" id="error-middle_name"></span>
      </div>
      <div class="form-group">
        <label for="last_name" class="required">Last Name</label>
        <input type="text" id="last_name" name="last_name" maxlength="100" required autocomplete="family-name">
        <span class="field-error" id="error-last_name"></span>
      </div>
    </div>

    <div class="form-grid form-grid-2">
      <div class="form-group">
        <label for="email" class="required">Email Address</label>
        <input type="email" id="email" name="email" maxlength="191" required autocomplete="email">
        <span class="field-error" id="error-email"></span>
      </div>
      <div class="form-group">
        <label for="mobile_number">Mobile Number</label>
        <input type="tel" id="mobile_number" name="mobile_number" maxlength="20" autocomplete="tel" placeholder="09XX XXX XXXX">
        <span class="field-error" id="error-mobile_number"></span>
      </div>
    </div>

    <div class="form-grid form-grid-2">
      <div class="form-group">
        <label for="course" class="required">Course / Program</label>
        <input type="text" id="course" name="course" maxlength="150" required placeholder="e.g. BS Information Technology">
        <span class="field-error" id="error-course"></span>
      </div>
      <div class="form-group">
        <label for="year_level" class="required">Year Level</label>
        <select id="year_level" name="year_level" required>
          <option value="">Select year level</option>
          <option value="1st Year">1st Year</option>
          <option value="2nd Year">2nd Year</option>
          <option value="3rd Year">3rd Year</option>
          <option value="4th Year">4th Year</option>
          <option value="5th Year">5th Year</option>
          <option value="Graduate Student">Graduate Student</option>
        </select>
        <span class="field-error" id="error-year_level"></span>
      </div>
    </div>

    <h2 class="form-section-title">OJT Information</h2>
    <div class="form-group">
      <label for="company" class="required">Company / Establishment</label>
      <input type="text" id="company" name="company" maxlength="150" required>
      <span class="field-error" id="error-company"></span>
    </div>

    <div class="form-grid form-grid-3">
      <div class="form-group">
        <label for="ojt_hours" class="required">OJT Required Hours</label>
        <input type="number" id="ojt_hours" name="ojt_hours" min="1" max="5000" step="0.5" required>
        <span class="field-error" id="error-ojt_hours"></span>
      </div>
      <div class="form-group">
        <label for="ojt_start_date" class="required">OJT Start Date</label>
        <input type="date" id="ojt_start_date" name="ojt_start_date" required>
        <span class="field-error" id="error-ojt_start_date"></span>
      </div>
      <div class="form-group">
        <label for="ojt_end_date">OJT End Date</label>
        <input type="date" id="ojt_end_date" name="ojt_end_date">
        <span class="field-error" id="error-ojt_end_date"></span>
      </div>
    </div>

    <h2 class="form-section-title">Account Information</h2>
    <div class="form-grid form-grid-2">
      <div class="form-group">
        <label for="password" class="required">Password</label>
        <div class="password-field">
          <input type="password" id="password" name="password" required autocomplete="new-password">
          <button type="button" class="password-toggle" data-toggle-password="password">Show</button>
        </div>
        <span class="field-hint">At least 8 characters, with an uppercase letter, a lowercase letter, and a number.</span>
        <span class="field-error" id="error-password"></span>
      </div>
      <div class="form-group">
        <label for="confirm_password" class="required">Confirm Password</label>
        <div class="password-field">
          <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
          <button type="button" class="password-toggle" data-toggle-password="confirm_password">Show</button>
        </div>
        <span class="field-error" id="error-confirm_password"></span>
      </div>
    </div>

    <h2 class="form-section-title">Profile Picture <span class="optional-tag">Optional</span></h2>
    <div class="form-group">
      <div class="file-upload-row">
        <img id="profilePreview" class="profile-preview" alt="Profile picture preview" style="display:none;">
        <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/webp">
      </div>
      <span class="field-hint">JPG, PNG, or WEBP. Max 2MB.</span>
      <span class="field-error" id="error-profile_picture"></span>
    </div>

    <button type="submit" class="btn" id="registerSubmit">Create Account</button>
  </form>

  <div class="form-footer">
    <span>Already have an account? <a href="<?= APP_URL ?>/auth/login.php">Log in</a></span>
  </div>
</div>
<?php include __DIR__ . '/../includes/partials/footer.php'; ?>
