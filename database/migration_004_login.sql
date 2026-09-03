-- Migration 004: Secure "Remember Me" tokens for the login module.
-- Additive only — new table, no changes to existing ones.
USE ojt_system_db;

-- Selector/validator pattern (Barry Jaspan's "Improved Persistent Login Cookie
-- Best Practice"): the cookie holds "selector:validator". `selector` is an
-- indexed, non-secret lookup key; `validator` is the actual secret and only
-- its SHA-256 hash is ever stored, compared with hash_equals(). This avoids
-- both a timing side-channel on the DB lookup and ever storing a usable
-- token/password in the database.
CREATE TABLE IF NOT EXISTS remember_tokens (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  selector        CHAR(18) NOT NULL,
  validator_hash  CHAR(64) NOT NULL,
  expires_at      DATETIME NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_remember_tokens_selector (selector),
  KEY idx_remember_tokens_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
