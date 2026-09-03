<?php

/**
 * Validates raw registration input from $_POST. Always re-validates server-side
 * regardless of what the client already checked. Returns:
 *   ['errors' => ['field' => 'message', ...], 'data' => [...sanitized...]]
 * 'data' values are only meaningful for fields that passed validation.
 */
function validate_registration(array $post): array {
    $errors = [];

    $studentId = trim((string) ($post['student_id'] ?? ''));
    $firstName = trim((string) ($post['first_name'] ?? ''));
    $middleName = trim((string) ($post['middle_name'] ?? ''));
    $lastName = trim((string) ($post['last_name'] ?? ''));
    $email = mb_strtolower(trim((string) ($post['email'] ?? '')));
    $mobile = trim((string) ($post['mobile_number'] ?? ''));
    $course = trim((string) ($post['course'] ?? ''));
    $yearLevel = trim((string) ($post['year_level'] ?? ''));
    $company = trim((string) ($post['company'] ?? ''));
    $hoursRaw = trim((string) ($post['ojt_hours'] ?? ''));
    $startDate = trim((string) ($post['ojt_start_date'] ?? ''));
    $endDate = trim((string) ($post['ojt_end_date'] ?? ''));
    $password = (string) ($post['password'] ?? '');
    $confirmPassword = (string) ($post['confirm_password'] ?? '');

    if ($studentId === '') {
        $errors['student_id'] = 'Student ID is required.';
    } elseif (!preg_match('/^[A-Za-z0-9\-\/_.]{2,50}$/', $studentId)) {
        $errors['student_id'] = 'Student ID may only contain letters, numbers, and - _ . / characters.';
    }

    if ($firstName === '') {
        $errors['first_name'] = 'First name is required.';
    } elseif (mb_strlen($firstName) > 100) {
        $errors['first_name'] = 'First name is too long.';
    }

    if ($middleName !== '' && mb_strlen($middleName) > 100) {
        $errors['middle_name'] = 'Middle name is too long.';
    }

    if ($lastName === '') {
        $errors['last_name'] = 'Last name is required.';
    } elseif (mb_strlen($lastName) > 100) {
        $errors['last_name'] = 'Last name is too long.';
    }

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!is_valid_email($email)) {
        $errors['email'] = 'Please enter a valid email address.';
    } elseif (mb_strlen($email) > 191) {
        $errors['email'] = 'Email address is too long.';
    }

    if ($mobile !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $mobile)) {
        $errors['mobile_number'] = 'Please enter a valid mobile number.';
    }

    if ($course === '') {
        $errors['course'] = 'Course / Program is required.';
    } elseif (mb_strlen($course) > 150) {
        $errors['course'] = 'Course / Program is too long.';
    }

    $allowedYearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate Student'];
    if ($yearLevel === '') {
        $errors['year_level'] = 'Year level is required.';
    } elseif (!in_array($yearLevel, $allowedYearLevels, true)) {
        $errors['year_level'] = 'Please select a valid year level.';
    }

    if ($company === '') {
        $errors['company'] = 'Company / Establishment is required.';
    } elseif (mb_strlen($company) > 150) {
        $errors['company'] = 'Company / Establishment name is too long.';
    }

    $requiredMinutes = null;
    if ($hoursRaw === '') {
        $errors['ojt_hours'] = 'OJT required hours is required.';
    } elseif (!is_numeric($hoursRaw) || (float) $hoursRaw <= 0) {
        $errors['ojt_hours'] = 'OJT required hours must be a positive number.';
    } elseif ((float) $hoursRaw > 5000) {
        $errors['ojt_hours'] = 'That number of hours looks too high. Please check the value.';
    } else {
        $requiredMinutes = (int) round((float) $hoursRaw * 60);
    }

    $startDateObj = null;
    if ($startDate === '') {
        $errors['ojt_start_date'] = 'OJT start date is required.';
    } else {
        $startDateObj = DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$startDateObj || $startDateObj->format('Y-m-d') !== $startDate) {
            $errors['ojt_start_date'] = 'Please enter a valid start date.';
            $startDateObj = null;
        }
    }

    if ($endDate !== '') {
        $endDateObj = DateTime::createFromFormat('Y-m-d', $endDate);
        if (!$endDateObj || $endDateObj->format('Y-m-d') !== $endDate) {
            $errors['ojt_end_date'] = 'Please enter a valid end date.';
        } elseif ($startDateObj && $endDateObj < $startDateObj) {
            $errors['ojt_end_date'] = 'OJT end date cannot be earlier than the start date.';
        }
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } else {
        $pwErrors = validate_password_strength($password);
        if ($pwErrors) {
            $errors['password'] = $pwErrors[0];
        }
    }

    if ($confirmPassword === '') {
        $errors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== '' && $password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    return [
        'errors' => $errors,
        'data' => [
            'student_id' => $studentId,
            'first_name' => $firstName,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name' => $lastName,
            'email' => $email,
            'mobile_number' => $mobile !== '' ? $mobile : null,
            'course' => $course,
            'year_level' => $yearLevel,
            'company' => $company,
            'required_minutes' => $requiredMinutes,
            'ojt_start_date' => $startDate !== '' ? $startDate : null,
            'ojt_end_date' => $endDate !== '' ? $endDate : null,
            'password' => $password,
        ],
    ];
}

function student_id_exists(PDO $pdo, string $studentId): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE student_id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    return (bool) $stmt->fetchColumn();
}

function email_exists(PDO $pdo, string $email): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Creates the users + student_profiles rows for a new pending registration in one
 * transaction. $data must already have passed validate_registration(). The new
 * account's username is the student ID — the schema has no separate username field
 * on this form, and the student ID is already guaranteed unique.
 */
function create_student_registration(PDO $pdo, array $data, ?string $profilePicturePath): int {
    $fullName = trim($data['first_name'] . ' ' . ($data['middle_name'] ? $data['middle_name'] . ' ' : '') . $data['last_name']);
    $fullName = preg_replace('/\s+/', ' ', $fullName);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO users (role, status, full_name, email, username, password_hash, student_id)
             VALUES ('student', 'pending', :full_name, :email, :username, :password_hash, :student_id)"
        );
        $stmt->execute([
            'full_name' => $fullName,
            'email' => $data['email'],
            'username' => $data['student_id'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'student_id' => $data['student_id'],
        ]);
        $userId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO student_profiles
                (user_id, first_name, middle_name, last_name, contact_number, profile_picture,
                 course, year_level, company, ojt_start_date, ojt_end_date, required_minutes)
             VALUES
                (:user_id, :first_name, :middle_name, :last_name, :contact_number, :profile_picture,
                 :course, :year_level, :company, :ojt_start_date, :ojt_end_date, :required_minutes)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'last_name' => $data['last_name'],
            'contact_number' => $data['mobile_number'],
            'profile_picture' => $profilePicturePath,
            'course' => $data['course'],
            'year_level' => $data['year_level'],
            'company' => $data['company'],
            'ojt_start_date' => $data['ojt_start_date'],
            'ojt_end_date' => $data['ojt_end_date'],
            'required_minutes' => $data['required_minutes'],
        ]);

        $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
