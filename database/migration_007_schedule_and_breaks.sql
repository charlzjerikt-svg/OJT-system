-- Migration 007: OJT Schedule system + real (multi-)break support.
-- Additive only: two new tables, no existing table's columns are altered or
-- dropped. attendance.break_start/break_end (added in the original schema,
-- never populated by anything) are superseded by the new `breaks` table and
-- simply go unused from here on — left in place rather than dropped, per this
-- project's standing "avoid destructive schema changes" convention.
USE ojt_system_db;

-- Per-student, per-day-of-week expected schedule. Keyed by user_id (not a
-- separate "students"/"ojt_records" id) to match every other table in this
-- schema (attendance, student_profiles, remember_tokens, ...), all of which
-- hang directly off users.id. day_of_week matches PHP's date('w'): 0=Sunday
-- .. 6=Saturday. No row for a given (user_id, day_of_week) means "use the
-- app-wide default" — an admin only needs to add a row to override a specific
-- day for a specific student (e.g. a company with different hours), not
-- populate all 7 days for every student up front.
CREATE TABLE IF NOT EXISTS ojt_schedules (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  day_of_week   TINYINT UNSIGNED NOT NULL,
  start_time    TIME NOT NULL,
  end_time      TIME NOT NULL,
  break_start   TIME NULL,
  break_end     TIME NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ojt_schedules_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ojt_schedules_user_day (user_id, day_of_week),
  CONSTRAINT chk_ojt_schedules_dow CHECK (day_of_week BETWEEN 0 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Actual recorded breaks — separate from attendance so a day can have more
-- than one (short break + lunch, etc). active_attendance_id is a virtual
-- column that equals attendance_id while the break is still open (break_end
-- IS NULL) and NULL once it's closed; the UNIQUE constraint on it means the
-- database itself guarantees at most one open break per attendance row, even
-- under concurrent Start Break requests — the loser gets a clean constraint
-- violation (caught in do_start_break()) rather than a second open break.
CREATE TABLE IF NOT EXISTS breaks (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id         INT UNSIGNED NOT NULL,
  break_start           DATETIME NOT NULL,
  break_end             DATETIME NULL,
  duration_seconds      INT UNSIGNED NULL,
  active_attendance_id  INT UNSIGNED GENERATED ALWAYS AS (IF(break_end IS NULL, attendance_id, NULL)) VIRTUAL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_breaks_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE,
  UNIQUE KEY uq_breaks_one_active (active_attendance_id),
  KEY idx_breaks_attendance (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
