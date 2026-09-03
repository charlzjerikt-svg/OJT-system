<?php
require_once __DIR__ . '/includes/auth.php';

$user = current_user();

if ($user) {
    redirect($user['role'] === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php');
}

redirect('/auth/login.php');
