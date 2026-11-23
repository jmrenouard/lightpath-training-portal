# Error, Alert, and Troubleshooting Matrix

Lookup matrix mapping HTTP errors, authentication lockouts, SQL sandbox rejections, Docker runtime failures, storage/audit integrity alerts, and documentation build failures to their root causes and remediation runbooks.

## 1. HTTP Status and Authorization Error Matrix

| Code / Error | Severity | Component | Probable Cause | Remediation Runbook |
| :--- | :--- | :--- | :--- | :--- |
| `HTTP 400` (`api_submit_quiz`) | Warning | `public/index.php`, `LearnerController` | Missing or unknown `module_id`, module lacks QCM questions, or empty `answers` payload. | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `HTTP 400` (`api_toggle_complete`, `api_post_discussion`, `api_lab_savepoint`) | Warning | `LearnerController`, `LabApiController` | Invalid `item_id`, empty discussion `message`, missing savepoint `name` on `op=delete`, or unknown `op`. | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `HTTP 403` (Cross-Origin Rejected) | High | `Security::verifySameOriginRequest()` | `Origin` or `Referer` host/port mismatches `HTTP_HOST` / `SERVER_NAME` (or `Origin: null`). | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `HTTP 403` (Invalid CSRF Token) | High | `Security::verifyCsrfToken()` | Missing or stale `csrf_token` / `X-CSRF-Token`, or expired PHP session. | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `HTTP 403` (Course Access Denied) | Warning | `AuthController::enforceCourseAccess()` | Learner `enrolled_courses` excludes requested `course` slug (or is explicitly `[]`). | [Manage Cohorts and Qualiopi Exports](../../02-how-to-guides/operations/manage-cohorts-and-qualiopi-exports.md) |
| `HTTP 403` (RBAC Insufficient Role) | Warning | `public/index.php`, `AdminController`, `LabApiController` | `student` accessed trainer/admin routes, or `trainer` accessed admin-only endpoints (`users`, `courses`, `audit`, `system`, `export_audit_csv`). | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `HTTP 403` (Solutions / Exam Locked) | Info | `public/index.php`, `LearnerController`, `LabApiController` | Classroom state `lock_solutions` or `lock_exam` is active for non-staff learners. | [Manage Cohorts and Qualiopi Exports](../../02-how-to-guides/operations/manage-cohorts-and-qualiopi-exports.md) |
| `HTTP 403` (Feature Disabled) | Info | `FeatureManager::isEnabledForCourse()` | Global flag, `requires` parent (`docker_labs` for `chaos_engineering`), or per-course override is `false`. | [Configure Feature Flags and Courses](../../02-how-to-guides/operations/configure-feature-flags-and-courses.md) |
| `HTTP 404` (`download`, `course_asset`) | Warning | `DownloadController` | Missing course artifact or relative path fails `Security::isSafeRelativePath()` / `isPathInside()`. | [Configure Feature Flags and Courses](../../02-how-to-guides/operations/configure-feature-flags-and-courses.md) |
| `HTTP 405` (`Allow: POST`) | Warning | `public/index.php` | `GET` or unsupported HTTP method sent to a mutating `api_*` endpoint outside the 7 `readOnlyApis`. | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `HTTP 416` (`Range Not Satisfiable`) | Info | `DownloadController::resolveHttpRange()` | Malformed `Range` header, negative/zero byte length, or offset `>= filesize`. | [Deploy Portal with Docker and PDO](../../02-how-to-guides/deployment/deploy-portal-docker-and-pdo.md) |
| `HTTP 500` (`export_scorm_zip`) | Critical | `LearnerController`, `DownloadController` | Temporary SCORM archive creation failed due to non-writable `sys_get_temp_dir()`. | [Deploy Portal with Docker and PDO](../../02-how-to-guides/deployment/deploy-portal-docker-and-pdo.md) |

## 2. Authentication and Session Incident Matrix

| Alert / Symptom | Severity | Component | Probable Cause | Remediation Runbook |
| :--- | :--- | :--- | :--- | :--- |
| `AUTH-LOCK-01` (Account Lockout) | Warning | `Auth::isLoginRateLimited()` | `>= 5` failed logins on `email\|ip` bucket within `300` seconds. | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `AUTH-LOCK-02` (IP Spray Lockout) | High | `Auth::isLoginRateLimited()` | `>= 25` failed logins from one IP (`ip\|<addr>`) within `300` seconds. | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `AUTH-SUSP-01` (Forced Session Logout) | Info | `Auth::currentUser()` | Account `active` property set to `false`, `0`, or `'0'` while session active, or idle time exceeded `session_idle_ttl` (`7200s` default). | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `AUTH-BOOT-01` (`initial_admin_credentials.txt` Present) | Critical | `JsonStorage::ensureDefaultUsers()` | Initial bootstrap ran without `LMS_INIT_*_PASSWORD` env vars and wrote plaintext credentials file in `LMS_DATA_DIR`. | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |

## 3. SQL Sandbox Guard and Oracle Rejection Matrix

| Rejection / Condition | Severity | Component | Trigger Pattern / Condition | Remediation Runbook |
| :--- | :--- | :--- | :--- | :--- |
| `SQL-GUARD-01` (Empty Query) | Info | `LabSqlRunner::executeSql()` | Empty or whitespace-only SQL string submitted. | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `SQL-GUARD-02` (Interactive Backslash Blocked) | High | `LabSqlRunner::stripCommentsAndLiterals()` | `\!`, `\copy`, `\o`, `\w`, `\i`, `\c`, `\connect`, `\cd`, `\e*`, `\prompt`, `\watch`, `\setenv`, `\g*`, or `\lo_*` outside quotes/dollar-quotes. | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `SQL-GUARD-03` (Timeout Modification Blocked) | High | `LabSqlRunner::executeSql()` | `SET`/`RESET statement_timeout`/`lock_timeout`/`ALL` or `set_config(...)` targeting timeouts in raw or comment-stripped SQL. | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `SQL-GUARD-04` (OS / Filesystem Access Blocked) | Critical | `LabSqlRunner::executeSql()` | `COPY ... FROM/TO PROGRAM`, `pg_read_file`, `pg_read_binary_file`, `pg_ls_dir`, `pg_stat_file`, `lo_import`, or `lo_export`. | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `SQL-GUARD-05` (Privilege Escalation Blocked) | Critical | `LabSqlRunner::executeSql()` | Untrusted student query contains `ALTER SYSTEM`/`USER`/`ROLE`, `SET`/`RESET ROLE`, `SESSION AUTHORIZATION`, or role-mutating `set_config()`. | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `SQL-EXEC-01` (Statement / Lock Timeout) | Warning | `psql` (`PGOPTIONS`), `DockerManager` | Query exceeded `15000ms` `statement_timeout`, `10000ms` `lock_timeout`, or `18.0s` PHP `proc_open` limit (`exit code 124`). | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `SQL-ORACLE-01` (Oracle Failed) | Info | `LabSqlRunner::validateLabOracle()` | SQL returned `ERROR:`, timed out, or missed `ORACLE_PASS` / `VALIDATION_OK` marker. | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |

## 4. Docker Runtime and Container Fleet Matrix

| Alert / Status | Severity | Component | Probable Cause | Remediation Runbook |
| :--- | :--- | :--- | :--- | :--- |
| `DOCK-SIM-01` (`mode: cloud_simulated`) | Info | `DockerManager::detectRuntime()` | `LMS_DOCKER_FORCE_SIMULATED=1` is set, or both `wsl docker --version` and `docker info` failed/timed out (`3.0s`). | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `DOCK-CONT-01` (`status: not_created` / `cluster_ready`) | Warning | `DockerManager::getUserContainerStatus()` | Learner container `lp_pg18_user_<id>` not started; shared `pg_formation_primary` fallback blocked for students. | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `DOCK-CAP-01` (`max_containers` Quota Reached) | High | `DockerManager::startUserContainer()` | Active `lp_pg18_user_*` containers reached `max_containers` (`40` default). | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `DOCK-TIMEOUT-01` (`timed_out: true`, `code: 124`) | High | `DockerManager::execCommandWithTimeout()` | Hung WSL2 bridge or Docker CLI exceeded command timeout (`taskkill /F /T` / `SIGKILL` triggered). | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `DOCK-SP-01` (Savepoint Cap / Missing / Invalid) | Warning | `DockerManager` (`createSavepoint`, `restoreSavepoint`, `deleteSavepoint`) | Name fails `^[a-zA-Z0-9_-]{1,32}$`, user reached `MAX_SAVEPOINTS_PER_USER = 5`, DB not in `ALLOWED_DATABASES`, or `.dump` missing (`code: 44`). | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |
| `DOCK-CHAOS-01` (Residual Chaos Locks / Tables) | High | `DockerManager::triggerChaosScenario()` | Unhealed `chaos_orders`, `chaos_ledger`, `chaos_sessions`, or `chaos_wal_burst` tables or idle-in-transaction locks. | [Remediate Docker Lab and Auth Incidents](../../02-how-to-guides/incident-response/remediate-docker-lab-and-auth-incidents.md) |

## 5. Storage, PDO, Audit Hash Chain, and System Health Matrix

| Alert / Code | Severity | Component | Probable Cause | Remediation Runbook |
| :--- | :--- | :--- | :--- | :--- |
| `STOR-PDO-01` (`isPdoActive() === false` at Boot) | High | `PdoStorage::__construct()` | PostgreSQL/MySQL/SQLite DSN unreachable at startup; adapter falls back to `JsonStorage` and stores message in `getLastError()`. | [Deploy Portal with Docker and PDO](../../02-how-to-guides/deployment/deploy-portal-docker-and-pdo.md) |
| `STOR-PDO-02` (`PDO write error`) | Critical | `PdoStorage::writeFile()` | Runtime SQL `UPSERT` failed after initial connection; throws `RuntimeException` to prevent split-brain writes to local JSON. | [Deploy Portal with Docker and PDO](../../02-how-to-guides/deployment/deploy-portal-docker-and-pdo.md) |
| `STOR-JSON-01` (Corrupt JSON Guard) | Critical | `JsonStorage::mutateFile()` | Non-empty JSON file decoded to default empty structure; mutation aborted before overwriting valid records. | [Deploy Portal with Docker and PDO](../../02-how-to-guides/deployment/deploy-portal-docker-and-pdo.md) |
| `AUDIT-CHAIN-01` (`entry_hash_mismatch`) | Critical | `JsonStorage::verifyAuditIntegrity()` | Audit payload fields altered after write (`computeAuditEntryHash()` differs from `entry_hash`). | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `AUDIT-CHAIN-02` (`prev_hash_mismatch` / `invalid_genesis_prev_hash` / `malformed_prev_hash`) | Critical | `JsonStorage::verifyAuditIntegrity()` | Entry deleted, reordered, or spliced across active `audit_logs` or archived `audit_logs_YYYYMM`. | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `AUDIT-CHAIN-03` (`missing_hash_fields` / `unchained_entry_after_chain_start`) | Critical | `JsonStorage::verifyAuditIntegrity()` | Unchained record injected after SHA-256 chain initialization (`$seenChained === true`). | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `SYS-SEC-01` (Health Probe Secrets Warning) | Warning | `AdminService::getSystemHealthReport()` | `LMS_CERT_HMAC_SECRET` / `APP_SECRET` unset or `docker.default_password === 'postgres'`. | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `CSP-AUDIT-01` (`test_coverage_audit.php` Failure) | Critical | `tests/test_coverage_audit.php`, `Security.php` | External CDN URL, inline `style=`, inline `on*=`, French code comment, or missing SHA-256 `script-src` / `style-src 'self'`. | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |

## 6. Documentation Build and Publishing Matrix

| Alert / Failure | Severity | Component | Probable Cause | Remediation Runbook |
| :--- | :--- | :--- | :--- | :--- |
| `DOCS-PRIV-01` (Private File / Path Error) | Critical | `scripts/build_versioned_docs.py` | Output dir targets protected repo path, or `.env*`, `*.kdbx`, `*.keyx`, `*.pid`, `*.log`, `*.sqlite`, `*.db`, `initial_admin_credentials.txt`, or `internal` segment leaked into `site_dist`. | [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md) |
| `DOCS-STE-01` (`ste_check.py` Exit `1`) | Warning | `diataxis_check.py`, `ste_check.py` | How-to guide exceeds `20` words/sentence or uses passive voice, banned modals, contractions, or semicolons. | [Configure Feature Flags and Courses](../../02-how-to-guides/operations/configure-feature-flags-and-courses.md) |
| `DOCS-DNS-01` (Live HTTPS Smoke Skipped) | Info | `.github/workflows/docs-publish.yml` | SFTP deploy to IONOS succeeded, but `getent hosts lsm.lightpath.fr` returned non-zero pending DNS/vhost binding. | [Deploy Portal with Docker and PDO](../../02-how-to-guides/deployment/deploy-portal-docker-and-pdo.md) |
