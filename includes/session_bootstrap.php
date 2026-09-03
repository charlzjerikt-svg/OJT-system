<?php
require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', APP_ENV === 'production' ? '1' : '0');
    ini_set('session.gc_maxlifetime', '28800'); // 8 hours

    session_name('OJTSESSID');
    session_start();
}
