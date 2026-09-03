# OJT-system

A web-based On-the-Job Training (OJT) management system for tracking student attendance,
progress, and OJT program administration. Built as a plain PHP + MySQL app (PDO, no framework)
intended to run on XAMPP.

**Current scope:** secure login/logout with role-based dashboards, "remember me", forgot/reset
password, **student self-registration**, and the **Student Dashboard** (Time In/Time Out, OJT
progress, attendance history — no break tracking in this version). Admin approval UI, attendance
corrections, notifications (inbox/UI), and announcements are not yet implemented.

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
"C:\xampp\mysql\bin\mysql.exe" -u root < database/migration_004_login.sql
```

- `schema.sql` creates the database and the `users`, `password_resets`, and `auth_attempts` tables.
- `migration_002_student_module.sql` adds `student_profiles`, `attendance`, `correction_requests`,
  `announcements`, `announcement_reads`, and `notifications` (additive, no changes to existing tables).
- `migration_003_registration.sql` widens `users.status` to add `pending`/`rejected` (for
  self-registration + future admin approval) and `auth_attempts.action` to add `register`
  (so registration reuses the existing rate limiter).
- `migration_004_login.sql` adds `remember_tokens` (secure "Remember Me" persistent login).

All three migrations are additive `ALTER`s/`CREATE TABLE IF NOT EXISTS` — no data loss, and every
existing row keeps working exactly as before.

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

## Login & Authentication module usage

Students and admins both log in at **`/auth/login.php`** with either their **email or Student
ID** plus password.

- **Role-based redirect**: `student` → `/student/dashboard.php`, `admin` → `/admin/dashboard.php`.
  This decision is made server-side (`auth/login.php`, then re-verified by `require_role()` on
  every protected page) — never trust a frontend redirect for authorization.
- **Account status gates login**, checked only *after* the password has already been verified
  (so a wrong password never reveals whether — or in what state — an account exists):
  | Status | Result |
  |---|---|
  | `active` | Logs in normally |
  | `pending` | "Your account is still pending admin approval..." |
  | `inactive` | "Your account is currently inactive..." |
  | `rejected` | "Your registration has been rejected..." |
- **Route protection**: `require_login()` and `require_role('admin' \| 'student')` in
  `includes/auth.php` are this project's `requireLogin()`/`requireRole()` — every dashboard
  already calls them, so a student can never reach `/admin/dashboard.php` by editing the URL
  (verified — returns `403`).
- **Session security**: `session_regenerate_id(true)` on every login (prevents fixation),
  `httponly`/`SameSite=Lax` cookies, `secure` cookies once `APP_ENV` is `production`. Sessions
  store `user_id`, `role`, and `authenticated` — but authorization always re-reads the *live*
  role/status from the database on each request (`current_user()`), never trusting the session
  values alone, so a role/status change (or admin deactivating someone) takes effect immediately
  even on an already-open session.
- **"Remember Me"**: uses a selector/validator token pair (`remember_tokens` table +
  `REMEMBER_COOKIE_NAME` cookie), *not* a raw session ID or user ID in a cookie. See
  [Security notes](#security-notes) for the full rationale. Configurable via `REMEMBER_ME_DAYS`.
- **Brute-force protection**: reuses the existing `auth_attempts` table/`too_many_attempts()`
  helper — configurable via `MAX_LOGIN_ATTEMPTS`, `MAX_LOGIN_ATTEMPTS_PER_IP`, and
  `LOGIN_LOCKOUT_MINUTES` in `config.php` (defaults: 5 / 20 / 15). Failed-attempt counters reset
  automatically once a login succeeds (attempts are only ever counted, not stored as a running
  "lock" — a normal account is never permanently locked).
- **Forgot/Reset Password** (`/auth/forgot_password.php`, `/auth/reset_password.php`) — already
  built prior to this module and reused as-is: cryptographically random single-use tokens
  (`password_resets` table, SHA-256 hash stored, 45-minute expiry), a generic "if an account
  exists..." response regardless of whether the email is registered, and a password reset now
  also **revokes every remember-me token** for that user. See **Password reset configuration**
  below for the SMTP setup this flow needs to actually deliver email.
- **Logout** (`/auth/logout.php`): destroys the session server-side, clears the session cookie,
  and revokes this device's remember-me token — an old session ID or cookie is provably unusable
  afterward (verified: replaying the previous session ID after logout redirects to login, not
  the dashboard).

### Password reset configuration

`includes/mailer.php` sends reset emails through PHPMailer/SMTP using the `SMTP_*` constants in
`config/config.php` — nothing is hardcoded. Until you fill in real SMTP credentials there
(`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `FROM_EMAIL`), the reset token is
still generated and stored correctly, but the email itself won't send (the failure is caught and
logged via `error_log`, never shown to the user — the UI always shows the same generic success
message either way). For local testing without real SMTP, either point `SMTP_HOST`/`SMTP_PORT`
at a mail-catching relay (e.g. Mailtrap, Mailhog), or skip email entirely and drive the flow
directly: generate a random token, `hash('sha256', $token)` it, `INSERT` that hash into
`password_resets` for the target `user_id`, then visit
`/auth/reset_password.php?token=<the-unhashed-token>`.

## Student Dashboard usage

After login, a student lands on **`/student/dashboard.php`**: their info (name, Student ID,
course, year level, company, OJT status), a Today's Attendance card with a Time In/Time Out
button, live OJT progress (required/completed/remaining hours + a percent bar), and a recent
attendance table linking to **`/student/attendance_history.php`** for the full record.

- **Time In/Time Out** post to `student/attendance_action.php`, which calls `do_time_in()` /
  `do_time_out()` in `includes/attendance.php` — these already existed (written before this
  task) and are reused as-is rather than reimplemented. Every timestamp is `new DateTime()` on
  the server; the client never sends one.
- **No break tracking**: `break_start`/`break_end` columns and `do_start_break()`/`do_end_break()`
  already existed in the schema/codebase for a future module, but nothing on the dashboard calls
  them — they simply stay `NULL`, which makes the existing hours math reduce to exactly
  `Time Out − Time In` with nothing to subtract.
- **Duplicate Time In/Out** is prevented at the database level: `attendance` has a
  `UNIQUE (user_id, attendance_date)` constraint, and the Time Out `UPDATE` is a single
  conditional statement (`WHERE time_out IS NULL ...`), so even concurrent double-clicks can only
  ever produce one row/one recorded time — verified under 5 simultaneous requests.
- **Ownership**: every query is scoped to the authenticated student's own `user_id` from the
  session (`require_role('student')`) — there is no `?id=`/`?user_id=` parameter anywhere in this
  module for one student to reach another's records.
- `includes/notifications.php` was added as a small, UI-less data-layer function
  (`notify_user()`) purely because `includes/attendance.php`'s existing, already-correct
  `do_time_in()`/`do_time_out()` call it and the file didn't exist yet — it is not a Notifications
  module/inbox, which remains out of scope.

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
- **"Remember Me" tokens** (`remember_tokens`) use the selector/validator pattern (Barry Jaspan's
  "Improved Persistent Login Cookie Best Practice"): the cookie is `selector:validator`, where
  `selector` is an indexed, non-secret DB lookup key and `validator` is the actual secret — only
  its SHA-256 hash is ever stored, compared with `hash_equals()`. Never a raw session ID or user
  ID in a cookie, and never the password. Tokens are single-use and rotated on every successful
  auto-login, revoked entirely on logout, and revoked for *every* device on password change/reset.
  A selector that resolves but whose validator doesn't match (a strong signal of a stolen/tampered
  cookie) revokes every remaining token for that user as a precaution, not just the one presented.
- **Session fixation**: `session_regenerate_id(true)` runs on every login (including a "remember
  me" auto-login) — verified: the session ID always changes across the login boundary, and an old
  (pre-login) session ID is rejected afterward.
- **Login brute-force protection**: reuses `auth_attempts`/`too_many_attempts()` (the same
  mechanism already protecting registration and password-reset requests), keyed on both the
  submitted identifier and the requesting IP, over a configurable rolling window
  (`MAX_LOGIN_ATTEMPTS`, `MAX_LOGIN_ATTEMPTS_PER_IP`, `LOGIN_LOCKOUT_MINUTES`). The lockout is
  temporary and time-based — no account is ever permanently disabled by normal failed attempts.

## Git/GitHub workflow

Standard flow — this repo already has `config/config.php` and `uploads/profile_pictures/*` gitignored:

```bash
git status
git add .
git commit -m "feat: implement student dashboard"
git push origin main
```

Avoid destructive commands (`git reset --hard`, `git clean -fd`, `git push --force`) unless you
specifically mean to discard work.
