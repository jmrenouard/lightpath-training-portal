# Security Architecture & Hardening Guide — LightPath Training Portal

> **Scope:** Threat model, authentication & brute-force defense, RBAC & course scoping, CSRF/XSS/header hardening, CSV & path traversal sanitization, PostgreSQL SQL sandbox isolation, Docker container quotas, and audit logging.

---

## 1. Threat Model & Trust Boundaries

During live training workshops and standalone cloud hosting, the portal enforces four security boundaries:
1. **Unauthenticated Internet/LAN Surface:** Login, self-registration (`self_registration` toggle), and public Qualiopi certificate lookup (`page=verify`). Must resist user enumeration, credential stuffing, session fixation, and CSRF.
2. **Authenticated Student vs Course Content:** Students must access only courses in their `enrolled_courses` assignment, cannot inspect locked lab solutions (`lock_solutions`) or locked certification exams (`lock_exam`), and cannot forge quiz scores (`api_submit_quiz`).
3. **Trainer vs Administrator Privilege Separation:** Trainers (`trainer`) hold classroom operations (`cohort`, `qna`, `docker`, attendance countersignature, OPCO/SCORM exports) while Administrators (`admin`) exclusively manage users, enrollments, custom courses, audit exports, system health, and runtime feature flags.
4. **Untrusted Student SQL vs Host/Container OS:** Student queries submitted via `api_run_sql` run inside per-user Docker PostgreSQL 18 containers (`lp_pg18_user_<id>`) as non-superuser `lms_student`, blocked from filesystem/shell escape vectors and runaway resource exhaustion.

---

## 2. Authentication, Sessions & Brute-Force Protection ([src/Core/Auth.php](../src/Core/Auth.php))

- **Password Hashing & Minimum Policy:** Passwords hash via PHP `PASSWORD_BCRYPT`. Minimum password length is `6` characters (`registerUser()` and `changePassword()`).
- **Anti-Enumeration Timing Defense:** `verifyCredentials()` evaluates `password_verify($password, self::DUMMY_BCRYPT_HASH)` whenever an email does not exist or is inactive, preventing timing-based account discovery.
- **Persistent Multi-Bucket Rate Limiting:**
  - `MAX_LOGIN_ATTEMPTS = 5` per normalized `email|ip` bucket within `LOCKOUT_SECONDS = 300` (5 minutes).
  - `MAX_IP_LOGIN_ATTEMPTS = 25` per `ip` bucket within `300` seconds to mitigate distributed password spraying.
  - Counters persist atomically through `StorageInterface` (`getLoginThrottle()`, `recordLoginFailure()`, `clearLoginThrottle()`) via `mutateFile()`, eliminating cookie/session reset bypasses.
- **Verified Password Changes:** `Auth::changePassword($userId, $currentPassword, $newPassword)` requires `password_verify($currentPassword, ...)` before rotating a user's `password_hash` during `action=update_profile`.
- **Session Hardening ([src/bootstrap.php](../src/bootstrap.php)):**
  - `session.use_strict_mode=1`, `session.use_only_cookies=1`, `httponly=true`, `samesite=Lax`, `lifetime=604800` (7 days), TLS-aware `secure`.
  - Session ID regenerates on login (`session_regenerate_id(true)`), and inactive sessions expire automatically after `14,400` seconds (4 hours).

---

## 3. Role-Based Access Control (RBAC) & Server-Side Integrity

- **Strict Role Allowlist:** `Auth::VALID_ROLES = ['student', 'trainer', 'admin']` enforced via `Auth::isValidRole()` on user creation, `admin_update_user`, and CSV import (`JsonStorage::importUsersFromCsv()`).
- **Course Enrollment Scoping:** `Auth::canAccessCourse($user, $courseSlug)` grants global access to `trainer`/`admin` staff; for students, unset/`null` `enrolled_courses` defaults to `['postgresql-18']`, while an explicit empty array `[]` denies **all** courses (preventing unauthorized URL course switching).
- **Explicit HTTP `403` Enforcement:** Privileged endpoints and admin-only operations (`users`, `courses`, `audit`, `system`, `admin_update_enrollments`, `admin_reset_user_progress`, `admin_toggle_course`, `admin_delete_course`, `admin_save_features`, `export_audit_csv`) return HTTP `403` on insufficient role rather than silent no-op HTTP `200` responses.
- **Server-Side Quiz Grading:** When a module Markdown file contains quiz questions parsed by `MarkdownParser::extractQuizQuestions()`, `api_submit_quiz` rejects client-forged `score`/`total` submissions lacking a valid `answers` map (`400 Bad Request`).

---

## 4. HTTP Headers, CSRF, XSS, Path & CSV Protections ([src/Core/Security.php](../src/Core/Security.php))

### Security Response Headers (`Security::sendSecurityHeaders()`)
Emitted on every web response via `bootstrap.php`:

| Header | Value / Purpose |
| :--- | :--- |
| `X-Content-Type-Options` | `nosniff` — disables MIME-type sniffing |
| `X-Frame-Options` | `SAMEORIGIN` — prevents cross-origin clickjacking |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `unload=*, fullscreen=(self)` |
| `Cross-Origin-Opener-Policy` | `same-origin` — isolates browsing context group |
| `Content-Security-Policy` | `default-src 'self'; script-src 'self' 'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (emitted automatically over HTTPS / reverse-proxy TLS) |

### Input/Output Sanitization Helpers
- **CSRF Tokens (`getCsrfToken`, `verifyCsrfToken`):** Per-session 256-bit token via `random_bytes(32)` validated with constant-time `hash_equals()` across POST forms and mutating `api_*` calls.
- **XSS Output Encoding (`Security::e`):** Wraps `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. Delegated listeners in `public/assets/js/app.js` replace legacy inline event handlers.
- **Path Traversal Prevention (`isSafeRelativePath`, `isPathInside`, `sanitizeFilename`):** Validates downloadable files, course assets, and video streams against canonical `realpath()` roots (`DownloadController.php`).
- **Spreadsheet Formula Neutralization (`Security::sanitizeCsvCell()`):** Prefixes leading `'` when non-numeric strings begin with `\t` or `\r`, or when `ltrim(..., " \t\r\n")` begins with `=`, `+`, `-`, or `@`. Applied across user imports, OPCO attendance exports, Qualiopi exports, and Audit CSV exports.
- **Inline Static HTML Preview Isolation & Strict Script CSP:** `DownloadController::streamFile()` rewrites local `../assets/` links to `assets/`, injects `Permissions-Policy: unload=*`, and appends `Security::renderUnloadShimScript()` (`pagehide`). `Security::sendSecurityHeaders()` computes the exact SHA-256 hash of `Security::UNLOAD_SHIM_JS` (`'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='`) to eliminate `'unsafe-inline'` from `script-src`, while `login.php` delegates quick-login buttons (`[data-demo-email]`) to `public/assets/js/app.js` instead of emitting an inline `<script>`. (`style-src` retains `'unsafe-inline'` for legacy inline template attributes.)

---

## 5. PostgreSQL SQL Sandbox & Container Isolation ([src/Docker/LabSqlRunner.php](../src/Docker/LabSqlRunner.php), [src/Docker/DockerManager.php](../src/Docker/DockerManager.php))

### Least-Privilege Database Execution (`lms_student`)
Provisioned idempotently by `SakilaSeeder::getSandboxRoleSql()`:
- Role `lms_student` has `LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS`.
- Explicitly revoked: `pg_execute_server_program`, `pg_read_server_files`, `pg_write_server_files`.
- Granted schema privileges: `pg_read_all_stats` (for `pg_stat_*` observability exercises) plus `USAGE, CREATE` on schema `public` and DML/sequence rights on `public` objects.
- Untrusted student SQL via `api_run_sql` runs with `psql -X -U lms_student`, whereas trusted system validation (`api_validate_lab`, `api_run_oracle`, CLI `oracle`) uses `$trustedOracle = true`.

### Timeout Enforcement & Command Filtering
- **Transport & Timeouts:** SQL payloads travel base64-encoded (`$b64 = base64_encode($sql);` -> `base64 -d`) into `psql -X` with `PGOPTIONS="-c statement_timeout=15000 -c lock_timeout=10000"`, executed via non-blocking `proc_open()` timeouts (`execCommandWithTimeout`).
- **Lexical Scanner & Blocked Escalation Vectors (`LabSqlRunner::stripCommentsAndLiterals()`):**
  - A single-pass lexical scanner strips `--` line comments, nested `/* /* */ */` block comments, `'...'` string literals, and dollar-quoted PostgreSQL blocks (`$$...$$`, `$tag$...$tag$`) into `comments_stripped` and `outside_quotes`.
  - All interactive `psql` backslash commands (`\!`, `\copy`, `\o`, `\w`, `\i`, `\connect`/`\c`, `\cd`, `\e*`, `\prompt`, `\watch`, `\setenv`, `\g`, `\gx`, `\gset`, `\gexec`, `\lo_import`, `\lo_export`, `\lo_unlink` — `'Commandes backslash interactives non autorisées'`) are rejected strictly on `$outsideQuotes`, avoiding false positives inside dollar-quoted `DO $$ ... $$` blocks.
  - Timeout overrides (`SET`/`RESET` on `statement_timeout`/`lock_timeout`/`ALL`, and `set_config(...)` targeting timeout keywords or dynamic `||` expressions) are checked on **both raw SQL and `$commentsStripped`** before literal stripping—blocking nested `DO $$ BEGIN EXECUTE 'SET statement_timeout = 0'; END $$;` bypasses while allowing benign queries such as `SELECT current_setting('statement_timeout')`.
  - Server-side OS/privilege escalation: `COPY ... FROM/TO PROGRAM`, `ALTER SYSTEM`, `ALTER USER`/`ALTER ROLE`, `SET`/`RESET ROLE` or `SESSION AUTHORIZATION` (checked across both raw and `$commentsStripped` SQL for untrusted learners), and filesystem functions (`pg_read_file`, `pg_read_binary_file`, `pg_ls_dir`, `pg_stat_file`, `lo_import`, `lo_export`).
- **Shared-Cluster Fallback Protection (`$allowSharedFallback = false`):** `DockerManager::seedUserContainer()` and `DockerManager::getContainerLogs()` forbid falling back to the shared `pg_formation_primary` instance unless `$allowSharedFallback === true` (passed solely for `$auth->isStaff($currentUser)` in HTTP endpoints and host `bin/lab-runner.php`), requiring students to start their personal container first.

### Container Resource Quotas (`DockerManager::startUserContainer()`)
Every per-user container `lp_pg18_user_<id>` launches with hardened cgroup and privilege limits:
- `--shm-size=512m` (PostgreSQL shared memory / parallel query workers)
- `--memory=1g --cpus=1.0 --pids-limit=256` (fork-bomb and noisy-neighbor protection)
- `--security-opt=no-new-privileges --restart=no`
- `--label lms.managed=lightpath --label lms.user_id=<id>`
- Host capacity cap: `max_containers` (default `40`).

---

## 6. Cryptographic Certificates & Audit Retention

- **Certificate Codes:** `JsonStorage::saveExamAttempt()` generates unpredictable identifiers matching `LP-PG18-CERT-<uid>-<8HEX>` via CSPRNG `strtoupper(bin2hex(random_bytes(4)))`.
- **Audit Retention & Rotation:** `JsonStorage::logAuditEvent()` retains up to `1,000` active events in `audit_logs` and archives overflow entries into monthly `audit_logs_YYYYMM` files rather than silently truncating history.
- **Production Secrets Hygiene:** Set `LMS_CERT_HMAC_SECRET` (or `APP_SECRET`) and override the development container password (`postgres`) via dedicated deployment environment configuration; `AdminService::getSystemHealthReport()` flags development defaults in **Administration -> Système**.
