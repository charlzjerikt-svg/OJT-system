# OJT-system

A web-based On-the-Job Training (OJT) management system for tracking student attendance,
progress, and OJT program administration. Built as a plain PHP + MySQL app (PDO, no framework)
intended to run on XAMPP.

**Current scope:** authentication (login/logout, password reset, change password) and
**student self-registration**. Attendance, time in/out, admin approval UI, and notifications
are scaffolded (dashboard "coming soon" tiles) but not yet implemented.

## Requirements

- PHP 8.1+ with the `pdo_mysql`, `mbstring`, and `fileinfo` extensions (all enabled by default
  in XAMPP). The `gd` extension is optional but recommended — see [Security notes](#security-notes).
- MySQL / MariaDB 5.7+
- XAMPP (Apache + PHP + MySQL) for local development

## Installation

1. Clone or copy this repository into your XAMPP `htdocs` directory, e.g. `C:\xampp\htdocs\OJT-system`.
2. Copy the config template and fill in local values:

   ```bash
   cp config/config.example.php config/config.php
   ```

   `config/config.php` is gitignored — it holds real credentials and must never be committed.

3. Create the `uploads/tmp` staging directory PHP expects for file uploads if it doesn't already
   exist on your machine (XAMPP's default `php.ini` points `upload_tmp_dir` at `C:\tmp`):

   ```bash
   mkdir C:\tmp
   ```

## Database setup

Run the SQL files in order against MySQL (via phpMyAdmin, or the CLI):

```bash
"C:\xampp\mysql\bin\mysql.exe" -u root < database/schema.sql
"C:\xampp\mysql\bin\mysql.exe" -u root < database/migration_002_student_module.sql
"C:\xampp\mysql\bin\mysql.exe" -u root < database/migration_003_registration.sql
```

- `schema.sql` creates the database and the `users`, `password_resets`, and `auth_attempts` tables.
- `migration_002_student_module.sql` adds `student_profiles`, `attendance`, `correction_requests`,
  `announcements`, `announcement_reads`, and `notifications` (additive, no changes to existing tables).
- `migration_003_registration.sql` widens `users.status` to add `pending`/`rejected` (for
  self-registration + future admin approval) and `auth_attempts.action` to add `register`
  (so registration reuses the existing rate limiter). Both are additive `ALTER`s — no data loss,
  and every existing row keeps working exactly as before.

Optionally seed two test accounts (admin + student, both `active`) for exercising the existing
login flow:

```bash
"C:\xampp\php\php.exe" database\seed.php
```

## Configuration

All configuration lives in `config/config.php` (gitignored) — see `config/config.example.php`
for the full list of constants (`APP_URL`, `DB_*`, `SMTP_*`, session/attendance business rules).
Nothing in this module is hardcoded outside that file.

## How to run locally

Start Apache and MySQL from the XAMPP control panel, then visit:

```
http://localhost/OJT-system/
```

You'll be redirected to `/auth/login.php` if not logged in.

## Registration module usage

Students register at **`/auth/register.php`** (linked from the login page as "Register as student").

- Fields: Student ID, First/Middle/Last Name, Email, Mobile Number, Course/Program, Year Level,
  Company/Establishment, OJT Required Hours, OJT Start/End Date, Password, and an optional
  Profile Picture.
- The form validates client-side for instant feedback, but **every rule is re-checked
  server-side** in `includes/registration.php` — client-side validation is never trusted alone.
- On success, the account is created with `status = 'pending'` and `role = 'student'`. The
  student **cannot log in yet** — the existing login flow (`includes/auth.php::attempt_login`)
  already rejects any non-`active` status, so pending/rejected accounts are blocked automatically.
  No changes were needed there.
- The account's `username` is set to the Student ID (there's no separate username field on the
  registration form, and the Student ID column is already unique-constrained), so students can
  log in later with either their email or Student ID once an admin approves them.
- An **admin approval UI to flip `pending` → `active`/`rejected` is not yet built** — that's the
  next module. Until then, approve a pending student manually:

  ```sql
  UPDATE users SET status = 'active' WHERE student_id = 'THE-ID';
  ```

### Endpoint

`POST /auth/register.php` (multipart/form-data, submitted via `assets/js/register.js`), returns JSON:

```json
{ "success": true, "message": "Registration successful. Your account is pending admin approval." }
```

```json
{
  "success": false,
  "message": "Please correct the highlighted fields.",
  "errors": { "student_id": "Student ID already exists." }
}
```

## Security notes

- **Passwords** are hashed with `password_hash()` (bcrypt via `PASSWORD_DEFAULT`) and verified
  with `password_verify()`. Plain-text passwords are never logged, stored, or echoed back.
- **SQL injection**: every query goes through PDO prepared statements with bound parameters —
  no string-concatenated SQL anywhere in the registration path.
- **XSS**: all dynamic output is escaped through the existing `e()` helper
  (`htmlspecialchars(..., ENT_QUOTES)`); the client-side JS only ever writes error/success text
  via `textContent`, never `innerHTML`.
- **CSRF**: every state-changing request requires the session-bound `csrf_token` (`includes/csrf.php`),
  verified with `hash_equals()`.
- **Rate limiting**: registration attempts are throttled per IP through the existing
  `auth_attempts` table/`too_many_attempts()` helper (10 failed attempts / 15 minutes).
- **Duplicate registration**: Student ID and email are checked before insert *and* enforced by
  `UNIQUE` constraints at the database level, so a race between two simultaneous submissions
  can't create two accounts — the second one fails cleanly with a duplicate-field error.
- **File uploads** (`includes/upload.php`): the profile picture's real content type is sniffed
  with `finfo` (never trusts the client-supplied MIME type or filename/extension), capped at 2MB,
  and stored under a random filename — the original filename never touches the filesystem. When
  the `gd` extension is enabled, the image is also re-encoded (stripping anything beyond raw pixel
  data, e.g. an embedded payload in a polyglot file); without `gd` it falls back to a validated
  move. **Enable `gd` in `php.ini` for the stronger guarantee** — uncomment `extension=gd` and
  restart Apache. The `uploads/` directory also has an `.htaccess` that refuses to execute PHP
  and denies directory listing, as defense in depth regardless of `gd`.
- **Error handling**: internal exceptions (DB failures, etc.) are logged server-side
  (`error_log`) and never surface their raw message to the client — the client only ever sees a
  generic "something went wrong" message.
- No secrets are committed: `config/config.php` is gitignored, and `config.example.php` ships
  with placeholder values only.

## Git/GitHub workflow

Standard flow — this repo already has `config/config.php` and `uploads/profile_pictures/*` gitignored:

```bash
git status
git add .
git commit -m "feat: implement student registration"
git push origin main
```

Avoid destructive commands (`git reset --hard`, `git clean -fd`, `git push --force`) unless you
specifically mean to discard work.
