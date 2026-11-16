# Security Architecture & Hardening Guide — LightPath Training Portal

> **Scope:** Threat model, authentication & brute-force defense, account active lifecycle, RBAC & course scoping, strict zero-inline-style CSP (`style-src 'self'`), same-origin verification, CSRF/XSS/path/CSV protections, SHA-256 tamper-evident audit hash chains, PostgreSQL SQL sandbox isolation, and Docker container quotas.

---

## 1. Threat Model & Trust Boundaries

During live training workshops and standalone cloud hosting, the portal enforces four security boundaries:
1. **Unauthenticated Internet/LAN Surface:** Login, self-registration (`self_registration` toggle), and public Qualiopi certificate lookup (`page=verify`). Must resist user enumeration, credential stuffing, session fixation, and CSRF.
2. **Authenticated Student vs Course Content:** Students must access only courses in their `enrolled_courses` assignment, cannot inspect locked lab solutions (`lock_solutions`) or locked certification exams (`lock_exam`), and cannot forge quiz scores (`api_submit_quiz`).
3. **Trainer vs Administrator Privilege Separation:** Trainers (`trainer`) hold classroom operations (`cohort`, `qna`, `docker`, attendance countersignature, OPCO/SCORM exports) while Administrators (`admin`) exclusively manage users, enrollments, custom courses, audit exports, system health, and runtime feature flags.
4. **Untrusted Student SQL vs Host/Container OS:** Student queries submitted via `api_run_sql` run inside per-user Docker PostgreSQL 18 containers (`lp_pg18_user_<id>`) as non-superuser `lms_student`, blocked from filesystem/shell escape vectors and runaway resource exhaustion.

---

## 2. Authentication, Sessions & Brute-Force Protection ([src/Core/Auth.php](../src/Core/Auth.php))

- **Password Hashing & Minimum Policy:** Passwords hash via PHP `PASSWORD_BCRYPT`. Minimum password length is `6` characters (`registerUser()` and `changePassword()`), and newly registered accounts explicitly initialize `'active' => true`.
- **Account Active Lifecycle (`Auth::isUserActive()`):** Evaluates `false`, `0`, and `'0'` as suspended. Enforced inside both `Auth::verifyCredentials()` (constant-time dummy check on suspended accounts) and `Auth::currentUser()`, which actively calls `$this->logout()` if an authenticated user is suspended mid-session. Administrators manage active status and resets via `setUserActive()` (protecting super-admin `#1` from self/accidental suspension), `adminResetUserPassword()`, and bulk actions.
- **Anti-Enumeration Timing Defense:** `verifyCredentials()` evaluates `password_verify($password, self::DUMMY_BCRYPT_HASH)` whenever an email does not exist or is inactive, preventing timing-based account discovery.
- **Persistent Multi-Bucket Rate Limiting & Admin Unlock:**
  - `MAX_LOGIN_ATTEMPTS = 5` per normalized `email|ip` bucket within `LOCKOUT_SECONDS = 300` (5 minutes).
  - `MAX_IP_LOGIN_ATTEMPTS = 25` per `ip` bucket within `300` seconds to mitigate distributed password spraying.
  - Counters persist atomically through `StorageInterface` (`getLoginThrottle()`, `recordLoginFailure()`, `clearLoginThrottle()`, `listLoginThrottles()`) via `mutateFile()`, eliminating cookie/session reset bypasses. Administrators can inspect active lockouts and trigger 1-click unlock (`admin_unlock_throttle`) from **Administration -> Utilisateurs**.
- **Verified Password Changes & Session Regeneration:** `Auth::changePassword($userId, $currentPassword, $newPassword)` requires `password_verify($currentPassword, ...)` before rotating `password_hash`, and regenerates the session ID (`session_regenerate_id(true)`) whenever invoked in an active web session.
- **Session Hardening ([src/bootstrap.php](../src/bootstrap.php)):**
  - `session.use_strict_mode=1`, `session.use_only_cookies=1`, `httponly=true`, `samesite=Lax`, TLS-aware `secure`.
  - [src/bootstrap.php](../src/bootstrap.php) aligns `session.gc_maxlifetime` and session cookie `lifetime` dynamically with `'session_idle_ttl'` in [config/app.php](../config/app.php) (`7200` seconds default), while `Auth::login()` regenerates session IDs (`session_regenerate_id(true)`) and `Auth::currentUser()` terminates idle sessions automatically.

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
| `Content-Security-Policy` | `default-src 'self'; script-src 'self' 'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (emitted automatically over HTTPS / reverse-proxy TLS) |

> [!IMPORTANT]
> **Strict Zero-Inline-Style & Standalone Document CSP (`P1-6` Resolved):** All LMS portal routes emit `style-src 'self'` with **zero** `'unsafe-inline'` and **zero** inline `style=` attributes across `src/` and `templates/` (dynamic progress widths hydrate via `data-progress` + CSSOM in [public/assets/js/app.js](../public/assets/js/app.js), and verification QR SVGs use `.lms-qr-verify-svg` in [public/assets/css/app.css](../public/assets/css/app.css)). Only self-contained inline `.html` course previews streamed by `DownloadController::streamFile()` emit `Security::sendStandaloneDocumentCspHeader(array $extraScriptHashes = [])`, which scopes `style-src 'self' 'unsafe-inline'` and validated per-document inline `<script>` SHA-256 hashes strictly to that standalone HTML frame (never adding `script-src 'unsafe-inline'`).

### Input/Output Sanitization, CSRF & Same-Origin Helpers
- **CSRF Tokens (`getCsrfToken`, `verifyCsrfToken`):** Per-session 256-bit token via `random_bytes(32)` validated with constant-time `hash_equals()` across POST forms and mutating `api_*` calls.
- **Same-Origin Request Verification (`Security::verifySameOriginRequest()`):** Validates HTTP `Origin` (preferred) or `Referer` hostname and port (when present) against `HTTP_HOST`/`SERVER_NAME` on mutating web requests while gracefully allowing absent headers in CLI/curl harness runs and rejecting cross-origin origins, `Origin: null`, and port mismatches.
- **XSS Output Encoding (`Security::e`):** Wraps `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. Delegated listeners in `public/assets/js/app.js` replace legacy inline event handlers.
- **Path Traversal Prevention (`isSafeRelativePath`, `isPathInside`, `sanitizeFilename`):** Validates downloadable files, course assets, and video streams against canonical `realpath()` roots (`DownloadController.php`).
- **Spreadsheet Formula Neutralization (`Security::sanitizeCsvCell()`):** Prefixes leading `'` when non-numeric strings begin with `\t` or `\r`, or when `ltrim(..., " \t\r\n")` begins with `=`, `+`, `-`, or `@`. Applied across user imports, OPCO attendance exports, Cohort KPI exports, Qualiopi exports, and Audit CSV exports.
- **Inline Static HTML Preview Isolation:** `DownloadController::streamFile()` rewrites local `../assets/` links to `assets/`, injects `Permissions-Policy: unload=*`, computes unique `'sha256-...'` digests for trusted static inline `<script>` blocks before prepending the unload shim, emits `Security::sendStandaloneDocumentCspHeader($inlineHashes)` when `$disp === 'inline'` for `text/html`, and appends `Security::renderUnloadShimScript()` (`pagehide`). `Security::sendSecurityHeaders()` computes the exact SHA-256 hash of `Security::UNLOAD_SHIM_JS` (`'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='`) to eliminate `'unsafe-inline'` from both `script-src` and `style-src` on portal pages.

---

## 5. PostgreSQL SQL Sandbox & Container Isolation ([src/Docker/LabSqlRunner.php](../src/Docker/LabSqlRunner.php), [src/Docker/DockerManager.php](../src/Docker/DockerManager.php))

### Least-Privilege Database Execution (`lms_student`)
Provisioned idempotently by `SakilaSeeder::getSandboxRoleSql()`:
- Role `lms_student` has `LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS`.
- Explicitly revoked: `pg_execute_server_program`, `pg_read_server_files`, `pg_write_server_files`.
- Granted schema privileges: `pg_read_all_stats` and `pg_stat_scan_tables` (for `pg_stat_*` observability exercises) plus `USAGE, CREATE` on schema `public` and DML/sequence rights on `public` objects.
- Untrusted student SQL via `api_run_sql` runs with `psql -X -U lms_student`, whereas trusted system validation (`api_validate_lab`, `api_run_oracle`, CLI `oracle`) uses `$trustedOracle = true`.

### Timeout Enforcement & Command Filtering
- **Transport & Timeouts:** SQL payloads travel base64-encoded (`$b64 = base64_encode($sql);` -> `base64 -d`) into `psql -X` with `PGOPTIONS="-c statement_timeout=15000 -c lock_timeout=10000"`, executed via non-blocking `proc_open()` timeouts (`execCommandWithTimeout`).
- **Lexical Scanner & Blocked Escalation Vectors (`LabSqlRunner::stripCommentsAndLiterals()`):**
  - A single-pass lexical scanner strips `--` line comments, nested `/* /* */ */` block comments, `'...'` string literals, and dollar-quoted PostgreSQL blocks (`$$...$$`, `$tag$...$tag$`) into `comments_stripped` and `outside_quotes`.
  - All interactive `psql` backslash commands (`\!`, `\copy`, `\o`, `\w`, `\i`, `\connect`/`\c`, `\cd`, `\e*`, `\prompt`, `\watch`, `\setenv`, `\g`, `\gx`, `\gset`, `\gexec`, `\lo_import`, `\lo_export`, `\lo_unlink` — `'Commandes backslash interactives non autorisées'`) are rejected strictly on `$outsideQuotes`, avoiding false positives inside dollar-quoted `DO $$ ... $$` blocks.
  - Timeout overrides (`SET`/`RESET` on `statement_timeout`/`lock_timeout`/`ALL`, and `set_config(...)` targeting timeout keywords or dynamic `||` expressions) are checked on **both raw SQL and `$commentsStripped`** before literal stripping—blocking nested `DO $$ BEGIN EXECUTE 'SET statement_timeout = 0'; END $$;` bypasses while allowing benign queries such as `SELECT current_setting('statement_timeout')`.
  - Server-side OS/privilege escalation: `COPY ... FROM/TO PROGRAM`, `ALTER SYSTEM`, `ALTER USER`/`ALTER ROLE`, `SET`/`RESET ROLE` or `SESSION AUTHORIZATION` (checked across both raw and `$commentsStripped` SQL for untrusted learners), and filesystem functions (`pg_read_file`, `pg_read_binary_file`, `pg_ls_dir`, `pg_stat_file`, `lo_import`, `lo_export`).
- **Shared-Cluster Fallback Protection (`$allowSharedFallback = false`):** `DockerManager::seedUserContainer()`, `DockerManager::getContainerLogs()`, savepoint operations (`createSavepoint()`, `listSavepoints()`, `restoreSavepoint()`, `deleteSavepoint()`), `runPgbenchBenchmark()`, `inspectPgDiagnostics()`, and `checkLabReadiness()` deny falling back to shared `pg_formation_primary` unless `$allowSharedFallback === true` (passed solely for `$auth->isStaff($currentUser)` in HTTP endpoints and host `bin/lab-runner.php`).
- **Savepoint & Benchmark Parameter Boundaries:** Savepoint identifiers enforce `^[a-zA-Z0-9_-]{1,32}$` (max `5` per user, DB allowlist `['formation_pg', 'dvdrental', 'postgres']`); bounded `pgbench` clamps `$clients` (`1..8`) and `$durationSeconds` (`1..5`) under non-blocking `execCommandWithTimeout()`.

### Container Resource Quotas (`DockerManager::startUserContainer()`)
Every per-user container `lp_pg18_user_<id>` launches with hardened cgroup and privilege limits:
- `--shm-size=512m` (PostgreSQL shared memory / parallel query workers)
- `--memory=1g --cpus=1.0 --pids-limit=256` (fork-bomb and noisy-neighbor protection)
- `--security-opt=no-new-privileges --restart=no`
- `--label lms.managed=lightpath --label lms.user_id=<id>`
- Host capacity cap: `max_containers` (default `40`).

---

## 6. Cryptographic Certificates & Tamper-Evident Audit Hash Chain

- **Certificate Codes:** `JsonStorage::saveExamAttempt()` generates unpredictable identifiers matching `LP-PG18-CERT-<uid>-<8HEX>` via CSPRNG `strtoupper(bin2hex(random_bytes(4)))`.
- **Audit Retention & Monthly Rotation:** `JsonStorage::logAuditEvent()` retains up to `1,000` active events in `audit_logs` and archives overflow entries into monthly `audit_logs_YYYYMM` files atomically inside `mutateFile()` rather than truncating history.
- **SHA-256 Tamper-Evident Audit Hash Chain (`verifyAuditIntegrity()`):** Each newly written audit entry stores deterministic `prev_hash` and `entry_hash` SHA-256 chain links across `audit_logs` and monthly `audit_logs_YYYYMM` archives. `StorageInterface::verifyAuditIntegrity(bool $includeArchives = false): array` returns `['valid' => bool, 'total_checked' => int, 'chained_entries' => int, 'legacy_unchained' => int, 'broken_links' => array]`, cleanly distinguishing pre-chain historical rows (`legacy_unchained`) from tampered payloads or broken `prev_hash` links.
- **Production Secrets Hygiene:** Set `LMS_CERT_HMAC_SECRET` (or `APP_SECRET`) and override the development container password (`postgres`) via dedicated deployment environment configuration; `AdminService::getSystemHealthReport()` flags development defaults in **Administration -> Système**.
