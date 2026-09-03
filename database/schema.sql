-- OJT-system authentication schema
CREATE DATABASE IF NOT EXISTS ojt_system_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ojt_system_db;

CREATE TABLE IF NOT EXISTS users (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role                  ENUM('admin','student') NOT NULL,
  status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
  full_name             VARCHAR(150) NOT NULL,
  email                 VARCHAR(191) NOT NULL,
  username              VARCHAR(100) NOT NULL,
  password_hash         VARCHAR(255) NOT NULL,
  student_id            VARCHAR(50)  NULL,
  password_changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_student_id (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  expires_at    DATETIME NOT NULL,
  used_at       DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_password_resets_token_hash (token_hash),
  KEY idx_password_resets_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_attempts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier    VARCHAR(191) NOT NULL,
  action        ENUM('login','password_reset_request') NOT NULL,
  ip_address    VARCHAR(45) NOT NULL,
  succeeded     TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_auth_attempts_identifier_action_time (identifier, action, created_at),
  KEY idx_auth_attempts_ip_action_time (ip_address, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
