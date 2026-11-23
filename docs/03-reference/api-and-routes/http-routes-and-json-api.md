# HTTP Routes and JSON API Specification

Complete HTTP dispatch contract for `public/index.php` and domain controllers (`src/Http/AuthController.php`, `src/Http/LearnerController.php`, `src/Http/LabApiController.php`, `src/Http/AdminController.php`).

## 1. Security Headers and Request Verification

Every HTTP response executes `src/bootstrap.php` and invokes `Security::sendSecurityHeaders()`.

| Header / Mechanism | Exact Value or Rule | Scope |
| :--- | :--- | :--- |
| `Content-Security-Policy` (Portal) | `default-src 'self'; script-src 'self' 'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'` | All standard portal HTML/API responses (`0` `'unsafe-inline'`). |
| `Content-Security-Policy` (Standalone HTML) | `style-src 'self' 'unsafe-inline'` with SHA-256 hashes computed per inline `<script>` block | Only inline `.html` course files served via `DownloadController::streamFile()` (`Security::sendStandaloneDocumentCspHeader()`). |
| `X-Content-Type-Options` | `nosniff` | All HTTP responses. |
| `X-Frame-Options` | `SAMEORIGIN` | All HTTP responses. |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | All HTTP responses. |
| `Permissions-Policy` | `unload=*, fullscreen=(self)` | All HTTP responses. |
| `Cross-Origin-Opener-Policy` | `same-origin` | All HTTP responses. |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Emitted when `Security::isHttpsRequest()` is `true`. |
| `csrf_token` / `X-CSRF-Token` | 48-character hex token (`bin2hex(random_bytes(24))`), 192-bit entropy | Validated via `hash_equals()` on all `api_*` routes and mutating `POST` forms (except `action=logout`). |
| `Security::verifySameOriginRequest()` | Compares `Origin` or `Referer` host (and port when explicit) against `HTTP_HOST` / `SERVER_NAME`; permits absent headers (CLI/`curl`) | Enforced on `POST` login/register, all `POST` `api_*`, and all authenticated `POST` forms. |
| `session_write_close()` | Releases PHP session file lock before executing controller logic | Executed on all `GET` requests and all `api_*` requests after `Security::getCsrfToken()`. |

## 2. Page View Routes (`GET ?page=<name>`)

All authenticated pages enforce `Auth::canAccessCourse()` (`trainer`/`admin` access all courses; students with unset `enrolled_courses` default to `postgresql-18`; explicit `[]` grants zero courses and returns `403`).

| `page` Parameter | Role Requirement | Feature Gate | Optional Query Parameters | Rendered Template |
| :--- | :--- | :--- | :--- | :--- |
| `login` | Public | `self_registration` (for `tab=register`) | `tab` (`login` \| `register`) | `templates/pages/login.php` |
| `verify` | Public | - | `code`, `course` | `templates/pages/verify.php` |
| `dashboard` | Auth | - | `course` | `templates/pages/dashboard.php` |
| `catalog` | Auth | - | `course`, `track`, `tab` (`labs`) | `templates/pages/catalog.php` |
| `module` | Auth | - | `course`, `id`, `mode` (`reader` \| `slides`) | `templates/pages/module_view.php` |
| `lab` | Auth | - | `course`, `id`, `mode` (`enonce` \| `corrige`) | `templates/pages/lab_view.php` |
| `documents`, `cheatsheets` | Auth | - | `course` | `templates/pages/documents.php` |
| `certificate` | Auth | - | `course` | `templates/pages/certificate.php` |
| `diagnostic` | Auth | - | `course` | `templates/pages/diagnostic.php` |
| `exam` | Auth | - | `course`, `retake` (`1`) | `templates/pages/exam.php` |
| `attendance` | Auth | - | `course`, `student_id`, `track` | `templates/pages/attendance.php` |
| `satisfaction` | Auth | `cold_satisfaction_j60` (for `tab=cold`) | `course`, `tab` (`hot` \| `cold`) | `templates/pages/satisfaction.php` |
| `flashcards` | Auth | `flashcards_srs` (`403` if off) | `course` | `templates/pages/flashcards.php` |
| `notes` | Auth | `personal_notes` (`403` if off) | `course` | `templates/pages/notes.php` |
| `search` | Auth | - | `course`, `q` | `templates/pages/search.php` |
| `profile` | Auth | - | `course` | `templates/pages/profile.php` |
| `admin` (`tab=cohort`, `qna`, `docker`) | Staff (`trainer`, `admin`) (`403` for `student`) | - | `course`, `tab` | `templates/pages/admin.php` -> `templates/partials/admin/tab_*.php` |
| `admin` (`tab=users`, `courses`, `audit`, `system`) | Admin only (`403` for `student`/`trainer`) | - | `course`, `tab`, `uq`, `ur`, `audit_cat`, `audit_actor` | `templates/pages/admin.php` -> `templates/partials/admin/tab_*.php` |

## 3. Public Authentication Actions (`?action=`)

| `action` | Method | Auth | Request Fields | Response / Behavior |
| :--- | :--- | :--- | :--- | :--- |
| `login` | `POST` | Public | `email`, `password`, `csrf_token` | Checks Same-Origin, CSRF, `Auth::isLoginRateLimited()` (`5` per `email\|ip`, `25` per `ip` in `300s`), constant-time `verifyCredentials()`, regenerates session ID, redirects to `?page=dashboard` or `?page=login`. |
| `register` | `POST` | Public (`self_registration`) | `name`, `email`, `company`, `password` (`>= 6` chars), `csrf_token` | Rejects with `403` if `self_registration` is false; creates `student` account enrolled in `['postgresql-18', 'git-basics']`, logs user in, redirects to `?page=dashboard`. |
| `logout` | `GET` / `POST` | Auth / Public | - | Clears `$_SESSION`, expires session cookie, destroys session, redirects to `index.php?page=login`. |

## 4. JSON API Endpoints (`?action=api_*`)

All `api_*` responses emit `Content-Type: application/json; charset=UTF-8` and require a valid CSRF token (`payload['csrf_token']` or `X-CSRF-Token`). Only the seven `readOnlyApis` (`api_docker_status`, `api_docker_logs`, `api_docker_stats`, `api_schema_inspect`, `api_lab_pg_diag`, `api_lab_readiness`, `api_search`) permit `GET`; all other `api_*` actions return HTTP `405` with `Allow: POST` on `GET`.

### 4.1 Learner and Pedagogy JSON APIs

| `action` | Allowed Methods | Role / Feature Gate | Payload Parameters | Response JSON Fields / Error Codes |
| :--- | :--- | :--- | :--- | :--- |
| `api_toggle_complete` | `POST` | Auth | `item_type` (`module` \| `lab`), `item_id`, `completed` (bool), `course` | Validates item via `CourseRepository` (`400` if invalid); `{ success, completed, completed_modules_count, completed_labs_count }` |
| `api_submit_quiz` | `POST` | Auth | `module_id`, `answers` (non-empty map `{ qIdx: choice }`), `course` | `400` if module missing, QCM missing, or `answers` empty; server-graded (`passed` at `>= 66%`); `{ success, quiz_result }` |
| `api_heartbeat` | `POST` | Auth | `seconds` (clamped `1..300`), `course` | `{ success, learning_seconds, formatted, classroom_state }` |
| `api_save_note` | `POST` | Auth + `personal_notes` | `item_id`, `note`, `course` | `403` if `personal_notes` disabled; `{ success, saved_at }` |
| `api_toggle_bookmark` | `POST` | Auth | `item_type` (`module` \| `lab`), `item_id`, `title?`, `course` | `{ success, bookmarked, bookmarks }` |
| `api_flashcard_rate` | `POST` | Auth + `flashcards_srs` | `card_id`, `level` (`1..3`), `course` | `403` if `flashcards_srs` disabled; `{ success, card_id, level }` |
| `api_reset_flashcards` | `POST` | Auth + `flashcards_srs` | `course` | `403` if `flashcards_srs` disabled; `{ success, message }` |
| `api_post_discussion` | `POST` | Auth + `qna_forum` | `item_id`, `message` (max `2000` chars), `parent_id?`, `course` | `403` if `qna_forum` disabled; `400` if empty; `{ success, entry }` |
| `api_search` | `GET` / `POST` | Auth | `q` (`>= 2` chars), `course` | Searches modules, labs, cheatsheets, referentiel, tools, examples, flashcards, glossary; `{ success, results }` |

### 4.2 Docker Labs, SQL Console, Diagnostics, and Chaos JSON APIs

Non-staff requests to any Docker/SQL endpoint return HTTP `403` when `docker_labs` is disabled for the target course.

| `action` | Allowed Methods | Role / Gate | Payload Parameters | Response JSON Fields / Guardrails |
| :--- | :--- | :--- | :--- | :--- |
| `api_docker_status` | `GET` / `POST` | Auth + `docker_labs` | - | `{ success, status: { container_name, port, status, running, user_container_running, primary_cluster_running, default_password, runtime_mode, runtime_version, connection_string, checked_at } }` |
| `api_docker_action` | `POST` | Auth + `docker_labs` (Staff for `target_user_id`) | `docker_cmd` (`start` \| `stop` \| `restart` \| `reset` \| `status`), `target_user_id?` | `{ success, simulated?, message, status }` |
| `api_seed_lab` | `POST` | Auth + `docker_labs` (Staff for `target_user_id` & shared cluster) | `dataset` (`all` \| `sakila` \| `pgbench`), `force_reset?` (bool, default `true`), `target_user_id?` | `{ success, simulated?, mode, container, dataset, force_reset, catalog, message, status }` |
| `api_docker_logs` | `GET` / `POST` | Auth + `docker_labs` (Staff for `target_user_id` & shared cluster) | `tail?` (controller clamps `10..500`, `DockerManager` clamps `10..200`), `target_user_id?` | `{ success, simulated, mode, container, tail, logs, message? }` |
| `api_docker_stats` | `GET` / `POST` | Auth + `docker_labs` (Staff for `target_user_id`) | `target_user_id?` | `{ success, simulated, mode, container, running, cpu_percent, mem_usage, mem_percent, pids }` |
| `api_run_sql` | `POST` | Auth + `docker_labs` | `sql`, `database` / `db` (`formation_pg` \| `dvdrental` \| `postgres`) | Executes via `lms_student` with `PGOPTIONS="-c statement_timeout=15000 -c lock_timeout=10000"`; `{ success, container, database, role?, output, duration_ms }` |
| `api_schema_inspect` | `GET` / `POST` | Auth + `docker_labs` | `database` / `db` (`dvdrental` \| `formation_pg` \| `postgres`) | `{ success, mode, source, database, container, output, tables: [{ schema, table, est_rows, columns }] }` |
| `api_run_oracle` / `api_validate_lab` | `POST` | Auth + `docker_labs` | `lab_id`, `database?`, `course?` | Marks lab completed and logs `LAB_ORACLE_PASS` when `passed === true`; `{ passed, lab_id, database, found_do_block, expected_markers, oracle_sql, output, duration_ms }` |
| `api_run_lab_solution` | `POST` | Auth + `docker_labs` (`403` for non-staff if `lock_solutions`) | `lab_id`, `database?`, `course?` | Executes Markdown SQL blocks sequentially (`$trustedOracle=true`); `{ passed, lab_id, database, steps_count, steps_executed, steps, oracle, duration_ms, output }` |
| `api_lab_savepoint` | `POST` | Auth + `docker_labs` | `op` (`create` \| `list` \| `restore` \| `delete`), `name?` (`^[a-zA-Z0-9_-]{1,32}$`), `db` / `database?` | Max `5` savepoints per user; `400` if `op=delete` lacks `name` or invalid `op`; `{ success, simulated, mode, container?, name?, savepoints?, count?, max_savepoints, duration_ms?, message? }` |
| `api_lab_pgbench` | `POST` | Auth + `docker_labs` | `clients?` (clamped `1..8`, default `4`), `duration` / `duration_seconds?` (clamped `1..5`, default `2`), `select_only?`, `db` / `database?` | `{ success, simulated?, mode, clients, duration_seconds, select_only, database, tps, latency_avg_ms, transactions, output, container, message }` |
| `api_lab_pg_diag` | `GET` / `POST` | Auth + `docker_labs` | `db` / `database?` (`formation_pg` \| `dvdrental` \| `postgres`) | `{ success, simulated, mode, container, database, server_version?, settings?, cache_hit_ratio?, activity?, databases?, extensions?, message }` |
| `api_lab_readiness` | `GET` / `POST` | Auth + `docker_labs` | `lab_id` (`01..29`, `sql_01..sql_05`) | `{ success, simulated, ready, mode, lab_id, lab_code, bloom, title, oracle_found, recommended_database, required_extensions, required_tables, container, container_running, checks, message }` |
| `api_chaos` | `POST` | **Staff** + `chaos_engineering` (`403` otherwise) | `scenario` (`bloat` \| `lock` \| `idle_in_tx` \| `wal_pressure`), `chaos_action` (`inject` \| `heal`) | Applies controlled fault SQL across running containers; `{ success, message, targets }` |

## 5. Downloads, Media Streaming, and Compliance Exports (`GET ?action=`)

| `action` | Role / Feature Gate | Parameters | Response Content-Type and Behavior |
| :--- | :--- | :--- | :--- |
| `download` | Auth (`403` on `type=lab` if `lock_solutions` for non-staff) | `course`, `type` (`module` \| `lab` \| `cheatsheet` \| `referentiel` \| `tool` \| `example`), `id`, `format` (`pdf` \| `slides_pdf` \| `pptx` \| `md` \| `html` \| `docx` \| `video_mp4` \| `video_slides_mp4` \| `video_vtt` \| `video_transcript`), `inline` (`1` optional) | Attachment download or inline stream; MP4 video supports HTTP `Range` (`200`, `206 Partial Content` in `64 KB` chunks, `416 Range Not Satisfiable`); inline HTML emits `Security::sendStandaloneDocumentCspHeader()`. |
| `course_asset` | Auth | `course`, `path` (validated by `Security::isSafeRelativePath()` + `Security::isPathInside()`) | Streams local diagram/image/HTML/asset (`404` if outside `source_root` or missing). |
| `export_notes_md` | Auth + `personal_notes` | `course` | `text/markdown; charset=UTF-8` attachment (`notes_<course>_<learner>.md`). |
| `export_scorm_manifest` | Staff + `scorm_export` | `course` | `application/xml; charset=UTF-8` attachment (`imsmanifest.xml`). |
| `export_scorm_zip` | Staff + `scorm_export` | `course` | `application/zip` attachment (`scorm12_<course>.zip`) via `ZipArchive` or pure-PHP PKZIP Store fallback. |
| `export_attendance_csv` | Staff (`403` for `student`) | `course` | UTF-8 BOM `;`-delimited CSV (`registre_emargement_opco_<course>_<ts>.csv`), cells sanitized via `Security::sanitizeCsvCell()`. |
| `export_qualiopi_csv` | Staff (`403` for `student`) | `course` | UTF-8 BOM `;`-delimited consolidated KPI, exam, attendance, and satisfaction CSV (`audit_qualiopi_<course>_<ts>.csv`). |
| `export_cohort_csv` | Staff (`403` for `student`) | `course` | UTF-8 BOM `;`-delimited progression and quiz CSV (`cohort_progression_<course>_<ts>.csv`). |
| `export_qualiopi_json` | Staff (`403` for `student`) | `course` | `application/json; charset=UTF-8` dossier (`schema_version: 2.0`, `audit_integrity`, `at_risk_learners`, `module_completion_matrix`, `learners`, `audit_logs`). |
| `export_audit_csv` | **Admin only** (`403` for `student`/`trainer`) | `course`, `category?`, `actor?` | Filters up to `1000` active + archived logs into UTF-8 BOM `;`-delimited CSV; emits `X-Audit-Chain-Status: VALID` or `BROKEN`. |
| `export_features_json` | **Admin only** (`403` for `student`/`trainer`) | - | `application/json; charset=UTF-8` portable snapshot (`schema: lightpath-lms-features-v1`). |

## 6. Authenticated Form Actions (`POST ?action=` + CSRF + Same-Origin)

### 6.1 Learner Actions

| `action` | Role / Feature Gate | Key POST Fields | Side Effects & Redirect |
| :--- | :--- | :--- | :--- |
| `update_profile` | Auth | `name`, `company`, `current_password?`, `new_password?` | Updates user profile; verifies `current_password` before rotating bcrypt hash and regenerating session ID; redirects to `?page=profile`. |
| `submit_diagnostic` | Auth | `answers[diag_XX]` | Scores `15` PG18 or `6` Git MCQs; maps score (`< 45%`, `45..74%`, `>= 75%`) to recommended track; redirects to `?page=diagnostic`. |
| `submit_exam` | Auth (Blocked if `lock_exam` for non-staff) | `answers[exam_XX]`, `duration_seconds` | Scores `40` PG18 or `10` Git MCQs (`>= 75%` pass); issues CSPRNG certificate `LP-<PREFIX>-CERT-<uid>-<8HEX>`; redirects to `?page=exam`. |
| `sign_attendance` | Auth | `half_day_key` (`J1_AM`..`J11_PM`), `signature_text` | Validates key via `Security::isValidHalfDayKey()`, preserves existing trainer countersignature, stores 16-hex SHA-256 receipt; redirects to `?page=attendance`. |
| `submit_satisfaction` | Auth | `ratings[content_quality, labs_docker, trainer_pedagogy, lms_ergonomics, objectives_met]` (`1..5`), `nps_score` (`0..10`) | Stores hot satisfaction survey; redirects to `?page=satisfaction`. |
| `submit_cold_satisfaction` | Auth + `cold_satisfaction_j60` | `ratings[prod_application, perf_gains, autonomy_level, tools_adoption, career_impact]` (`1..5`), `concrete_projects`, `further_needs` | Stores J+60 delayed satisfaction survey (`403` if feature off); redirects to `?page=satisfaction&tab=cold`. |
| `save_note_form` | Auth + `personal_notes` | `item_id`, `note` | Persists note (`403` if feature off); redirects to `?page=notes`. |
| `post_discussion` | Auth + `qna_forum` | `item_id`, `message`, `parent_id?`, `return_page` (`module` \| `lab`) | Appends discussion post (`403` if feature off); redirects to `#discussion-section`. |

### 6.2 Staff Actions (`trainer` and `admin`, HTTP `403` for `student`)

| `action` | Key POST Fields | Behavior & Redirect |
| :--- | :--- | :--- |
| `sign_trainer_attendance` | `target_user_id`, `half_day_key` (`ALL`, `J1..J11`, or `JX_AM/PM`), `return_student_id?`, `return_track?` | Applies trainer countersignature and `trainer_hash`; redirects to `?page=attendance`. |
| `save_classroom_state` | `broadcast_message`, `broadcast_level`, `lock_solutions` (`1`), `lock_exam` (`1`), `active_track` | Updates per-course live classroom broadcast and security locks; redirects to `?page=admin&tab=cohort`. |
| `admin_apply_classroom_preset` | `preset` (`lecture` \| `guided_lab` \| `exam_lockdown` \| `open_review`), `active_track?` | Applies atomic classroom broadcast and solution/exam locks; redirects to `?page=admin&tab=cohort`. |
| `moderate_discussion` | `item_id`, `message_id`, `mod_action` (`resolve` \| `delete`), `return_page` (`admin` \| `module` \| `lab`) | Resolves or deletes Q&A entry; redirects to `admin&tab=qna` or source item. |
| `admin_bulk_docker` | `bulk_cmd` (`stop_all` \| `prune_stopped` \| `refresh_runtime`) | Executes fleet container action or forces `detectRuntime(true)`; redirects to `?page=admin&tab=docker`. |
| `admin_reap_idle_docker` | `max_idle_seconds` (min `60`, default `3600`) | Calls `DockerManager::reapIdleContainers()`; redirects to `?page=admin&tab=docker`. |
| `admin_heal_chaos` | - | Calls `DockerManager::healChaosScenario($currentCourseSlug)`; redirects to `?page=admin&tab=docker`. |

### 6.3 Administrator-Only Control Plane (`admin` only, HTTP `403` for `student`/`trainer`)

| `action` | Key POST Fields | Behavior & Constraints |
| :--- | :--- | :--- |
| `admin_clear_cache` | - | Calls `CourseRepository::clearCatalogCache()` and `MarkdownParser::clearParsedCache()`. |
| `admin_save_features` | `features[<key>]` | Updates global flags via `FeatureManager::updateFlags()`. |
| `admin_apply_feature_profile` | `profile_key` (`full_enterprise` \| `classroom_workshop` \| `exam_lockdown` \| `static_hosting_nodocker`) | Applies deployment profile via `FeatureManager::applyProfile()`. |
| `admin_save_course_features` | `target_course_slug`, `course_features[<key>]`, `reset_course_features?` | Persists or clears per-course flag overrides. |
| `admin_import_features_json` | `features_json` | Validates and applies JSON configuration via `FeatureManager::importConfigJson()`. |
| `admin_create_user` | `name`, `email`, `password` (`>= 6`), `role` (`Auth::VALID_ROLES`), `company` | Creates user enrolled in `$currentCourseSlug`. |
| `admin_update_user` | `user_id`, `name`, `company`, `role?`, `active?`, `reset_password?` | Protects `#1` and `$selfId` from role/active mutation. |
| `admin_toggle_user_active` | `user_id`, `active?` | Suspends or activates user; blocks deactivation of `#1` and `$selfId`. |
| `admin_reset_password` | `user_id`, `new_password` (`>= 6` chars) | Rotates target user's bcrypt password hash. |
| `admin_bulk_users` | `bulk_op` (`enroll` \| `suspend` \| `activate`), `selected_user_ids[]`, `bulk_course_slug?` | Batch updates selected accounts while protecting `#1` and `$selfId` from suspension. |
| `admin_unlock_throttle` | `throttle_key?` or `email` + `ip` | Clears brute-force lockout buckets via `clearLoginThrottle()`. |
| `admin_update_enrollments` | `user_id`, `enrolled_courses[]` | Replaces `enrolled_courses` intersected against known active courses. |
| `admin_reset_user_progress` | `user_id`, `target_course?` | Clears module, lab, quiz, and note state for `user_id:target_course`. |
| `admin_delete_user` | `user_id` | Deletes user while protecting `#1` and `$selfId`. |
| `admin_import_users_csv` | `csv_content` / `csv_payload`, `default_password?` | Parses CSV/TSV (`;`, `,`, `\t`), strips UTF-8 BOM, sanitizes CSV cells, enforces `>= 6`-char passwords. |
| `admin_add_course` | `slug`, `title`, `source_root?`, `category?`, `duration?`, `description?` | Registers custom course in `custom_courses.json`; rejects built-in reserved slugs; verifies `source_root` inside workspace via `Security::isPathInside()`. |
| `admin_toggle_course` | `course_slug`, `active` (`1` \| `0`) | Toggles active status on custom course and flushes catalog cache. |
| `admin_delete_course` | `course_slug` | Removes custom course entry and flushes catalog cache. |

## Related References and Procedures

- Configuration keys and feature flags: [Environment Variables and Feature Flags](../configuration/environment-and-feature-flags.md)
- Error lookup and runbook links: [Error and Alert Matrix](../troubleshooting-codes/error-and-alert-matrix.md)
- CSP and audit chain hardening procedure: [Harden CSP, Credentials, and Audit Chain](../../02-how-to-guides/security/harden-csp-credentials-and-audit-chain.md)
- SQL sandbox and Qualiopi integrity analysis: [SQL Sandbox and Qualiopi Integrity](../../04-explanation/deep-dives/sql-sandbox-and-qualiopi-integrity.md)
