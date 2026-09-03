<?php
/**
 * CLI-only. Inserts test accounts with properly hashed passwords.
 * Run with: C:\xampp\php\php.exe database\seed.php
 */
if (php_sapi_name() !== 'cli') {
    exit('This script may only be run from the command line.');
}

require_once __DIR__ . '/../config/database.php';

$accounts = [
    [
        'role' => 'admin',
        'status' => 'active',
        'full_name' => 'System Administrator',
        'email' => 'admin@ojt.test',
        'username' => 'admin',
        'password' => 'Admin@12345',
        'student_id' => null,
    ],
    [
        'role' => 'student',
        'status' => 'active',
        'full_name' => 'Juan Student',
        'email' => 'student.active@ojt.test',
        'username' => 'jstudent',
        'password' => 'Student@12345',
        'student_id' => '2024-0001',
    ],
    [
        'role' => 'student',
        'status' => 'inactive',
        'full_name' => 'Dora Student',
        'email' => 'student.inactive@ojt.test',
        'username' => 'dstudent',
        'password' => 'Student@12345',
        'student_id' => '2024-0002',
    ],
];

$stmt = $pdo->prepare(
    'INSERT INTO users (role, status, full_name, email, username, password_hash, student_id)
     VALUES (:role, :status, :full_name, :email, :username, :password_hash, :student_id)
     ON DUPLICATE KEY UPDATE
       password_hash = VALUES(password_hash),
       status = VALUES(status),
       role = VALUES(role),
       full_name = VALUES(full_name)'
);

foreach ($accounts as $account) {
    $stmt->execute([
        'role' => $account['role'],
        'status' => $account['status'],
        'full_name' => $account['full_name'],
        'email' => $account['email'],
        'username' => $account['username'],
        'password_hash' => password_hash($account['password'], PASSWORD_DEFAULT),
        'student_id' => $account['student_id'],
    ]);
    echo "Seeded {$account['role']} ({$account['status']}): {$account['email']} / {$account['username']} / {$account['password']}\n";
}

echo "Done.\n";
