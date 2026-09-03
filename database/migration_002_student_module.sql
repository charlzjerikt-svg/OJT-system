-- Student module: attendance, profile, corrections, announcements, notifications.
-- Additive only — no ALTER on existing tables.
USE ojt_system_db;

CREATE TABLE IF NOT EXISTS student_profiles (
  user_id             INT UNSIGNED PRIMARY KEY,
  first_name          VARCHAR(100) NOT NULL,
  middle_name         VARCHAR(100) NULL,
  last_name           VARCHAR(100) NOT NULL,
  contact_number      VARCHAR(30)  NULL,
  profile_picture     VARCHAR(255) NULL,
  school              VARCHAR(150) NULL,
  course              VARCHAR(150) NULL,
  major               VARCHAR(150) NULL,
  year_level          VARCHAR(30)  NULL,
  section             VARCHAR(50)  NULL,
  company             VARCHAR(150) NULL,
  department          VARCHAR(150) NULL,
  position            VARCHAR(150) NULL,
  ojt_start_date      DATE NULL,
  ojt_end_date        DATE NULL,
  required_minutes    INT UNSIGNED NOT NULL DEFAULT 0,
  ojt_status          ENUM('not_started','ongoing','completed') NOT NULL DEFAULT 'not_started',
  rest_day            TINYINT UNSIGNED NULL, -- 0=Sun..6=Sat; NULL = no fixed rest day
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_student_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  attendance_date   DATE NOT NULL,
  time_in           DATETIME NULL,
  break_start       DATETIME NULL,
  break_end         DATETIME NULL,
  time_out          DATETIME NULL,
  status            ENUM('present','late') NULL,
  remarks           TEXT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_attendance_user_date (user_id, attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS correction_requests (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id                 INT UNSIGNED NOT NULL,
  attendance_date         DATE NOT NULL,
  requested_time_in       TIME NULL,
  requested_time_out      TIME NULL,
  requested_break_start   TIME NULL,
  requested_break_end     TIME NULL,
  reason                  TEXT NOT NULL,
  status                  ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  admin_remarks           TEXT NULL,
  reviewed_by             INT UNSIGNED NULL,
  reviewed_at             DATETIME NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_correction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_correction_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_correction_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(200) NOT NULL,
  body          TEXT NOT NULL,
  created_by    INT UNSIGNED NOT NULL,
  published_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_announcements_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_reads (
  announcement_id  INT UNSIGNED NOT NULL,
  user_id          INT UNSIGNED NOT NULL,
  read_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (announcement_id, user_id),
  CONSTRAINT fk_areads_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
  CONSTRAINT fk_areads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  type        VARCHAR(40) NOT NULL,
  title       VARCHAR(150) NOT NULL,
  message     VARCHAR(500) NOT NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_notifications_user_unread (user_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
