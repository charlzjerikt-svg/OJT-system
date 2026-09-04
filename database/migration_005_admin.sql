-- Migration 005: Admin side — audit log + manual-attendance marker.
-- Additive only — new table, one new column with a safe default. No existing
-- data changes: every current attendance row becomes source='system' automatically.
USE ojt_system_db;

ALTER TABLE attendance
  ADD COLUMN source ENUM('system','manual') NOT NULL DEFAULT 'system' AFTER status;

CREATE TABLE IF NOT EXISTS admin_audit_log (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id   INT UNSIGNED NOT NULL,
  action          VARCHAR(60) NOT NULL,
  target_user_id  INT UNSIGNED NULL,
  old_value       TEXT NULL,
  new_value       TEXT NULL,
  reason          TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_audit_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_audit_admin (admin_user_id),
  KEY idx_audit_target (target_user_id),
  KEY idx_audit_action_time (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
