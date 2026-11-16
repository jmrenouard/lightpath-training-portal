# API & Route Reference — LightPath Training Portal

> **Entrypoint:** [public/index.php](../public/index.php) dispatching via `src/Http/{AuthController,LearnerController,LabApiController,AdminController}.php` (`GET` / `POST` via `?action=...` and `?page=...`)  
> **Session & Security Model:** Cookie-backed PHP session (`session_idle_ttl` configurable in [config/app.php](../config/app.php), `7200s` default) + per-session CSRF token (`Security::verifyCsrfToken()`) + same-origin check (`Security::verifySameOriginRequest()`) + strict `style-src 'self'` CSP.

---

## 1. Request & Response Conventions

- **JSON Endpoints (`action=api_*`):** Return `Content-Type: application/json; charset=utf-8` with UTF-8 unescaped JSON (`JSON_UNESCAPED_UNICODE`).
- **Authentication & RBAC Codes:**
  - Unauthenticated requests to protected `api_*` endpoints return `401`/`403` JSON errors; web page requests redirect cleanly to `?page=login`.
  - Suspended accounts (`!Auth::isUserActive($user)`) are rejected on login and logged out immediately on active session access.
  - Insufficient privileges (e.g., `student` calling staff/admin routes, or `trainer` calling `admin`-only mutations) return explicit HTTP `403`.
  - Feature-disabled modules (e.g., `self_registration`, `chaos_engineering`, `scorm_export`, `docker_labs`, `personal_notes`, `flashcards_srs` — including `requires` dependency cascades and per-course overrides via `FeatureManager::isEnabledForCourse()`) reject forbidden actions cleanly with HTTP `403`.
  - Malformed requests (e.g., `api_submit_quiz` missing required `answers` when module QCM keys exist) return HTTP `400`.
- **HTTP Method, CSRF & Same-Origin Verification:** Read-only `GET` queries on `api_*` are strictly restricted to `api_docker_status`, `api_docker_logs`, `api_docker_stats`, `api_schema_inspect`, `api_lab_pg_diag`, `api_lab_readiness`, and `api_search`; all mutating `api_*` actions reject `GET` or unsupported methods with HTTP `405 Method Not Allowed` (`Allow: POST`). CSRF verification (`csrf_token` query/POST/JSON property or `X-CSRF-Token` HTTP header) and `Security::verifySameOriginRequest()` protect mutating `POST` forms and `api_*` calls.
- **Docker Flag Gate:** When `docker_labs` is disabled in `FeatureManager`, non-staff calls to all Docker/SQL/Lab endpoints (`api_docker_*`, `api_seed_lab`, `api_run_sql`, `api_schema_inspect`, `api_run_oracle`, `api_validate_lab`, `api_run_lab_solution`, `api_lab_savepoint`, `api_lab_pgbench`, `api_lab_pg_diag`, `api_lab_readiness`) return HTTP `403`.

---

## 2. Public & Authentication Actions

| Action / Page | Method | Auth | Key Parameters | Behavior |
| :--- | :--- | :--- | :--- | :--- |
| `action=login` | `POST` | Public | `email`, `password` (1-click `[data-demo-email]` buttons fill `#login-form` via `app.js` when `demo_quick_login` enabled; zero inline `<script>`) | Enforces `Auth::isLoginRateLimited()` (`5` tries / `email\|ip`, `25` / `ip` per `300s`), constant-time `verifyCredentials()`, session regeneration |
| `action=register` | `POST` | Public | `name`, `email`, `password` (`>= 6` chars), `company`, `csrf_token` | Allowed only when `self_registration` feature flag is active; enrolls into `postgresql-18` as `student` |
| `action=logout` | `GET`/`POST` | Auth | — | Logs `AUTH_LOGOUT` audit event and clears PHP session |
| `page=verify` | `GET` | Public | `code` (`LP-PG18-CERT-<uid>-<8HEX>`) | Public Qualiopi certificate verification lookup (`JsonStorage::findCertificateByCode()`) |

---

## 3. JSON AJAX APIs (`action=api_*`)

### 3.1 Pedagogy, Notes, Bookmarks, Flashcards & Search

| Action | Method | Role | Request Payload | Key JSON Response Fields |
| :--- | :--- | :--- | :--- | :--- |
| `api_toggle_complete` | `POST` | Auth | `item_type` (`module`\|`lab`), `item_id`, `completed`, `course` | Validates item existence via `CourseRepository` (`400` if unknown); `{ success, completed, completed_modules_count, completed_labs_count }` |
| `api_submit_quiz` | `POST` | Auth | `module_id`, `answers` (`{ idx: choice }`), `course` | Rejects unknown `module_id`, missing QCMs, or missing `answers` with `400`; server-graded against `MarkdownParser::extractQuizQuestions()`; returns `{ success, quiz_result }` |
| `api_save_note` | `POST` | Auth (`personal_notes`) | `item_id`, `note`, `course` | Requires `personal_notes` enabled for `$course` (`403` otherwise); `{ success, saved_at }` |
| `api_heartbeat` | `POST` | Auth | `seconds` (`1..300`), `course` | Accumulates learner study time (`recordLearningTime`); `{ success, learning_seconds, formatted, classroom_state }` |
| `api_toggle_bookmark` | `POST` | Auth | `item_type`, `item_id`, `title`, `course` | `{ success, bookmarked, bookmarks }` |
| `api_flashcard_rate` | `POST` | Auth (`flashcards_srs`) | `card_id`, `level`, `course` | Requires `flashcards_srs` enabled for `$course` (`403`); `{ success, card_id, level }` |
| `api_reset_flashcards` | `POST` | Auth (`flashcards_srs`) | `course` | Requires `flashcards_srs` enabled for `$course` (`403`); resets SRS history -> `{ success, message }` |
| `api_post_discussion` | `POST` | Auth | `item_id`, `message`, `parent_id?`, `course` | Requires `qna_forum` flag (`403`); rejects empty `item_id`/`message` (`400`); returns `{ success, entry }` |
| `api_search` | `GET` | Auth | `q`, `course` | Searches modules, labs, cheatsheets, referentiel, scripts, examples, flashcards, glossary (capped at `50` UTF-8 safe snippets); `{ success, results }` |

### 3.2 Docker Labs, SQL Console, Schema Explorer & Chaos APIs

| Action | Method | Role | Request Payload | Key JSON Response Fields |
| :--- | :--- | :--- | :--- | :--- |
| `api_docker_status` | `GET`/`POST` | Auth | — | `container_name`, `port`, `status` (`not_checked`, `cluster_ready`, `not_created`, `running`, `simulated`), `running`, `user_container_running`, `primary_cluster_running`, `default_password`, `runtime_mode`, `connection_string` |
| `api_docker_action` | `POST` | Auth (Staff for `target_user_id`) | `docker_cmd` (`start`\|`stop`\|`restart`\|`reset`\|`status`), `target_user_id?` | `{ success, simulated?, mode, message, status }` |
| `api_seed_lab` | `POST` | Auth (Staff for `target_user_id` & shared fallback) | `dataset` (`all`\|`sakila`\|`pgbench`), `force_reset?`, `target_user_id?` | Triggers `DockerManager::seedUserContainer(..., $allowSharedFallback = $auth->isStaff())`; students must run a personal container; `{ success, mode, container, dataset, force_reset, catalog, message, status }` |
| `api_docker_logs` | `GET`/`POST` | Auth (Staff for `target_user_id` & shared fallback) | `tail?` (HTTP `10..500`, clamped `10..200` in `DockerManager`), `target_user_id?` | Guards `pg_formation_primary` fallback with `$allowSharedFallback = $auth->isStaff()`; `{ success, simulated, mode, container, tail, logs }` |
| `api_docker_stats` | `GET`/`POST` | Auth (Staff for `target_user_id`) | `target_user_id?` | `{ success, simulated, mode, container, running, cpu_percent, mem_usage, mem_percent, pids }` |
| `api_run_sql` | `POST` | Auth | `sql`, `database` (`formation_pg`\|`dvdrental`\|`postgres`) | Guarded by `LabSqlRunner::stripCommentsAndLiterals()`, runs as `lms_student` + `statement_timeout=15000`; `{ success, container, database, role, output, duration_ms }` |
| `api_schema_inspect` | `GET`/`POST` | Auth | `database` (`dvdrental`\|`formation_pg`\|`postgres`) | `{ success, mode, source, database, container, output, tables: [{ schema, table, est_rows, columns }] }` |
| `api_run_oracle` | `POST` | Auth | `lab_id`, `database?` | Runs server-side oracle (`$trustedOracle=true`); `{ passed, checks, output, duration_ms }` |
| `api_validate_lab` | `POST` | Auth | `lab_id`, `database?`, `course?` | Evaluates lab oracle + expected output markers; marks lab complete on pass |
| `api_run_lab_solution` | `POST` | Auth (Blocked if `lock_solutions`) | `lab_id`, `database?`, `course?` | Executes official solution SQL blocks (`403` when solutions locked for non-staff); returns both `steps_executed` and `steps_count` |
| `api_lab_savepoint` | `POST` | Auth (`docker_labs`) | `op` (`create`\|`list`\|`restore`\|`delete`), `name?` (`^[a-zA-Z0-9_-]{1,32}$`), `db?` | Manages up to `5` per-user sandbox checkpoints (`$allowSharedFallback = $auth->isStaff()`); `{ success, simulated, mode, container, savepoints, count, max_savepoints, duration_ms?, message }` |
| `api_lab_pgbench` | `POST` | Auth (`docker_labs`) | `clients?` (`1..8`), `duration?` (`1..5`), `select_only?`, `db?` | Executes bounded non-blocking `pgbench` run; `{ success, simulated, mode, clients, duration_seconds, select_only, database, tps, latency_avg_ms, transactions, output, container }` |
| `api_lab_pg_diag` | `GET`/`POST` | Auth (`docker_labs`) | `db?` (`formation_pg`\|`dvdrental`\|`postgres`) | Inspects live PG18 diagnostics (`pg_stat_activity`, `pg_settings`, cache hit ratio, DB sizes, extensions); `{ success, simulated, mode, container, database, server_version, settings, cache_hit_ratio, activity, databases, extensions }` |
| `api_lab_readiness` | `GET`/`POST` | Auth (`docker_labs`) | `lab_id` (`01`..`29`, `sql_01`..`sql_05`) | Evaluates target DB, extensions, tables, container state, and Qualiopi oracle readiness; `{ success, simulated, ready, mode, lab_id, lab_code, bloom, title, oracle_found, recommended_database, required_extensions, required_tables, container_running, checks }` |
| `api_chaos` | `POST` | **Staff / Admin** + `chaos_engineering` flag (`403` otherwise) | `scenario` (`bloat`\|`lock`\|`idle_in_tx`\|`wal_pressure`), `chaos_action` (`inject`\|`heal`) | Applies controlled fault SQL via `DockerManager::triggerChaosScenario()`; `{ success, message, targets }` |

---

## 4. Downloads, Media Streaming & Compliance Exports (`GET`)

| Action | Role | Parameters | Output & Headers |
| :--- | :--- | :--- | :--- |
| `download` | Auth (`canAccessCourse`) | `course`, `path` | Validated via `Security::isPathInside()` & `isSafeRelativePath()`; attachment stream |
| `course_asset` | Auth (`canAccessCourse`) | `course`, `path` | Serves local course HTML (rewritten `assets/` URLs + `Security::sendStandaloneDocumentCspHeader()` + `Permissions-Policy: unload=*` + `renderUnloadShimScript()`), PNG diagrams, or MP4 videos via `DownloadController::resolveHttpRange()` (`200`, `206 Partial Content` in `64 KB` chunks, or `416 Range Not Satisfiable`) |
| `export_notes_md` | Auth (`personal_notes`) | `course` | Downloads learner personal Markdown study notebook (`403` when `personal_notes` disabled) |
| `export_scorm_manifest` | Staff (`scorm_export`) | `course` | Generates SCORM 1.2 `imsmanifest.xml` |
| `export_scorm_zip` | Staff (`scorm_export`) | `course` | Generates SCORM 1.2 `.zip` package (`ZipArchive` or pure-PHP PKZIP fallback) |
| `export_attendance_csv` | Staff | `course`, `track?` | OPCO/Qualiopi UTF-8 BOM `;`-delimited attendance sheet (`Security::sanitizeCsvCell()`) |
| `export_qualiopi_csv` | Staff | `course` | Consolidated diagnostics, exams, and hot/cold satisfaction UTF-8 BOM CSV |
| `export_cohort_csv` | Staff | `course` | Cohort progression & quiz metrics UTF-8 BOM `;`-delimited CSV (`AdminService::exportCohortCsv()`) |
| `export_qualiopi_json` | Staff | `course` | Structured Qualiopi audit dossier JSON (`schema_version: 2.0`, `audit_integrity`, `at_risk_learners`, `module_completion_matrix`, `learners`, `audit_logs`) via `AdminService::exportQualiopiJson()` |
| `export_audit_csv` | **Admin Only** | `category?`, `actor?` | Filters `$storage->getAuditLogs(1000, true)` (incl. `audit_logs_YYYYMM` archives) via `AdminService::filterAuditLogs()` into UTF-8 BOM CSV (`AdminService::exportAuditLogsCsv()`) with `X-Audit-Chain-Status` header |
| `export_features_json` | **Admin Only** | — | Downloads portable JSON feature configuration snapshot (`FeatureManager::exportConfigJson()`) |

---

## 5. Authenticated Form Actions (`POST` + CSRF)

### Learner & Classroom Forms
- `update_profile`: Updates `name` and `company`; rotates password only after verifying `current_password` via `Auth::changePassword()`.
- `submit_diagnostic`: Scores the `15` positioning QCM items and records recommended track (`fondamentaux-sql`, `cursus-complet`, `performance-tuning`).
- `submit_exam`: Scores the `40` final exam QCM questions (`75%` pass threshold); blocked for non-staff when `lock_exam` is active; issues CSPRNG certificate code `LP-PG18-CERT-<uid>-<8HEX>`.
- `sign_attendance`: Records student digital signature + IP timestamp for half-day slot (`J1_AM`..`J11_PM`, checked via `Security::isValidHalfDayKey()`).
- `sign_trainer_attendance` (**Staff Only**): Countersigns learner attendance slots (`return_student_id`, `return_track` preserved).
- `submit_satisfaction` / `submit_cold_satisfaction`: Stores Qualiopi hot and J+60 delayed satisfaction ratings.
- `save_note_form`: Server-side form fallback for note persistence.
- `save_classroom_state` (**Staff Only**): Updates `broadcast_message`, `broadcast_level`, `lock_solutions`, `lock_exam`, and `active_track`.
- `admin_apply_classroom_preset` (**Staff Only**): Applies 1-click workshop `preset` (`lecture`, `guided_lab`, `exam_lockdown`, `open_review`).
- `post_discussion` / `moderate_discussion` (**Staff Only** for moderation): Creates, resolves, or deletes Q&A posts.

### Administrator-Only Control Plane (`$auth->isAdmin() === true`, HTTP `403` otherwise)
- `admin_clear_cache`: Calls `CourseRepository::clearCatalogCache(): int` and logs cleared count.
- `admin_create_user`: Creates account with `Auth::isValidRole()` validation.
- `admin_update_user`: Updates user profile, active status, optional password reset, and strictly validated `role` (`Auth::VALID_ROLES`), protecting super-admin `#1` and active self-admin (`$targetId === $selfId`) from self-demotion/deactivation.
- `admin_toggle_user_active`: Toggles account active/suspended state (`StorageInterface::setUserActive()`); suspended users are blocked on login and logged out mid-session (`Auth::isUserActive()`).
- `admin_reset_password`: Resets a target user's bcrypt password hash directly (`StorageInterface::adminResetUserPassword()`).
- `admin_bulk_users`: Batch-executes `bulk_op` (`enroll`, `suspend`, or `activate`) across `selected_user_ids` (`[data-bulk-select-all]`).
- `admin_unlock_throttle`: Clears an individual `email|ip` or `ip|<addr>` brute-force throttle entry (`StorageInterface::clearLoginThrottle()`).
- `admin_delete_user`: Removes user accounts safely while blocking deletion of super-admin `#1` and the current active admin session (`$targetId <= 1 || $targetId === $selfId`).
- `admin_update_enrollments`: Updates `enrolled_courses` array for a target learner.
- `admin_reset_user_progress`: Invokes `StorageInterface::resetUserProgress($userId, $courseSlug)`.
- `admin_import_users_csv`: Parses `csv_content` / `csv_payload` (auto-detects `;`/`,`/`\t`, strips UTF-8 BOM, sanitizes cells via `Security::sanitizeCsvCell()`).
- `admin_add_course` / `admin_toggle_course` / `admin_delete_course`: Manages custom courses in `custom_courses.json`.
- `admin_save_features`: Persists runtime overrides for all 10 flags in [config/features.php](../config/features.php) via `FeatureManager::updateFlags()`.
- `admin_apply_feature_profile`: Applies `profile_key` (`full_enterprise`, `classroom_workshop`, `exam_lockdown`, `static_hosting_nodocker`) via `FeatureManager::applyProfile()`.
- `admin_save_course_features`: Saves or clears per-course feature overrides (`target_course_slug`, `course_features`, `reset_course_features`) via `FeatureManager::setCourseOverrides()` / `clearCourseOverrides()`.
- `admin_import_features_json`: Imports and validates `features_json` configuration payload via `FeatureManager::importConfigJson()` (paired with `GET ?action=export_features_json`).
- `admin_bulk_docker` (**Staff / Admin**): Executes room-wide operations via `$_POST['bulk_cmd']` (`stop_all`, `prune_stopped`, or `refresh_runtime`).
- `admin_reap_idle_docker` (**Staff / Admin**): Invokes `DockerManager::reapIdleContainers()` (`max_idle_seconds`) to stop idle student containers.
- `admin_heal_chaos` (**Staff / Admin**): Invokes `DockerManager::healChaosScenario()` to clean residual chaos locks and tables.
