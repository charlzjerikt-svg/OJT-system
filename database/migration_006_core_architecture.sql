-- Migration 006: Core architecture prep (companies, ojt_records) + a genuinely
-- missing index. Additive and non-destructive throughout:
--   - No existing table's columns are altered or dropped.
--   - No existing PHP query is changed by this migration — student_profiles
--     stays exactly as every shipped module (Registration, Dashboard, History,
--     Admin) already reads it. companies/ojt_records are prepared and backfilled
--     so they're valid and ready, but not yet adopted by any query — cutting
--     over is a future task, once there's an actual need (e.g. a student with
--     more than one OJT placement).
--   - No break/breaks table. This project has no break functionality and none
--     is planned — every prior task explicitly established "no break
--     functionality in this version," and that stands. attendance's existing
--     break_start/break_end columns remain unused for the same reason.
USE ojt_system_db;

-- Admin Attendance Management filters/sorts by date across ALL students (not
-- scoped to one user_id), so the existing UNIQUE(user_id, attendance_date)
-- composite can't serve that efficiently — it's only useful when user_id is
-- also in the WHERE clause. A standalone index fixes that specific query shape.
ALTER TABLE attendance
  ADD INDEX idx_attendance_date (attendance_date);

CREATE TABLE IF NOT EXISTS companies (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(150) NOT NULL,
  address         VARCHAR(255) NULL,
  contact_person  VARCHAR(150) NULL,
  contact_number  VARCHAR(30)  NULL,
  email           VARCHAR(191) NULL,
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_companies_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A student's OJT placement. Kept separate from student_profiles so a student
-- can eventually have more than one over time; is_current marks which one is
-- the active placement (exactly one per student is expected, enforced at the
-- application layer when this table is ever adopted — not yet).
CREATE TABLE IF NOT EXISTS ojt_records (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  company_id        INT UNSIGNED NULL,
  required_minutes  INT UNSIGNED NOT NULL DEFAULT 0,
  start_date        DATE NULL,
  end_date          DATE NULL,
  status            ENUM('not_started','ongoing','completed') NOT NULL DEFAULT 'not_started',
  is_current        TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ojt_records_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ojt_records_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  KEY idx_ojt_records_user (user_id),
  KEY idx_ojt_records_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: one company row per distinct non-empty company string already on
-- student_profiles (0 rows today in this environment — every test account was
-- cleaned up after each task — but the logic is validated against real data
-- shape either way).
INSERT INTO companies (name)
SELECT DISTINCT company FROM student_profiles
WHERE company IS NOT NULL AND company != ''
ON DUPLICATE KEY UPDATE name = name;

-- Backfill: one ojt_records row per student that has OJT data on their profile,
-- linked to the matching company row where one exists.
INSERT INTO ojt_records (user_id, company_id, required_minutes, start_date, end_date, status, is_current)
SELECT sp.user_id, c.id, sp.required_minutes, sp.ojt_start_date, sp.ojt_end_date, sp.ojt_status, 1
FROM student_profiles sp
LEFT JOIN companies c ON c.name = sp.company
WHERE sp.required_minutes > 0 OR sp.ojt_start_date IS NOT NULL OR sp.company IS NOT NULL;
