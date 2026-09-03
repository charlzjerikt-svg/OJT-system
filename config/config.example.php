<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored — never commit real credentials.
 */

// 'development' keeps session cookies non-HTTPS-only for local XAMPP use.
// Switch to 'production' once served over real HTTPS.
define('APP_ENV', 'development');

date_default_timezone_set('Asia/Manila');

define('APP_URL', 'http://localhost/OJT-system');
define('APP_NAME', 'OJT-system');

// Attendance business rules (no Admin Settings UI yet — these are the defaults)
define('WORKDAY_START_TIME', '08:00:00');
define('LATE_GRACE_MINUTES', 15);
define('STANDARD_SHIFT_MINUTES', 480); // 8 hours

// Login brute-force protection (see includes/rate_limit.php)
define('MAX_LOGIN_ATTEMPTS', 5);         // failed attempts for one identifier (email/username/student ID)...
define('MAX_LOGIN_ATTEMPTS_PER_IP', 20); // ...or from one IP address...
define('LOGIN_LOCKOUT_MINUTES', 15);     // ...within this rolling window, before login is blocked.

// "Remember me" persistent login
define('REMEMBER_COOKIE_NAME', 'ojt_remember');
define('REMEMBER_ME_DAYS', 30);

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'ojt_system_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// SMTP (used by includes/mailer.php for password-reset emails)
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'user@example.com');
define('SMTP_PASSWORD', 'app-password-here');
define('FROM_EMAIL', 'no-reply@example.com');
define('FROM_NAME', 'OJT-system');
