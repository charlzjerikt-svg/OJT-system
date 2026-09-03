-- Migration 003: Self-service student registration.
-- Additive only — widens two existing ENUMs, no data loss, no table rewrites.
USE ojt_system_db;

-- New self-registered accounts start life as 'pending' until an admin
-- approves them; 'rejected' covers the admin declining a pending account.
-- Default stays 'active' since every existing INSERT path (seed.php, future
-- admin-created accounts) already sets status explicitly.
ALTER TABLE users
  MODIFY COLUMN status ENUM('pending','active','inactive','rejected') NOT NULL DEFAULT 'active';

-- Lets registration reuse the existing auth_attempts rate-limiting table
-- (see includes/rate_limit.php) instead of introducing a parallel mechanism.
ALTER TABLE auth_attempts
  MODIFY COLUMN action ENUM('login','password_reset_request','register') NOT NULL;
