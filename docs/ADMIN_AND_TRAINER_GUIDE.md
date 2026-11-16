# Administrator & Trainer Operations Guide — LightPath Training Portal

> **Scope:** Role-based access boundaries (`student`, `trainer`, `admin`), modular Admin partials (`templates/partials/admin/tab_*.php`), Trainer Classroom Cockpit (at-risk learners, completion matrix, 1-click classroom presets, Cohort CSV & Qualiopi JSON exports), Administrator Control Plane (suspend/password reset/bulk ops, throttle unlock, 10 feature flags, 4 deployment profiles, per-course overrides, SHA-256 audit chain status, Docker fleet reaper/heal, `.admin-split-grid` System Health), and Qualiopi / Datadock / SCORM 1.2 compliance workflows.

---

## 1. Role Matrix (`Auth::VALID_ROLES`)

Role values are validated strictly against `Auth::VALID_ROLES = ['student', 'trainer', 'admin']`:

| Capability | `student` | `trainer` (`isStaff`) | `admin` (`isStaff` + `isAdmin`) |
| :--- | :---: | :---: | :---: |
| Course catalog, modules, quizzes, labs & notes | Enrolled courses | All courses | All courses |
| Personal Docker SQL sandbox & Chaos lab | Own container | Own + inspect student | Own + inspect student |
| Qualiopi Diagnostic, Attendance, Exam, Hot/Cold Satisfaction | Self only | Countersign & inspect | Countersign & inspect |
| Trainer Cockpit (`cohort`, `qna`, `docker` tabs on `admin.php`) | — (HTTP `403`) | Full access | Full access |
| Live Classroom Broadcast & Solution/Exam Locks | — | Full access | Full access |
| Q&A Discussion Moderation (resolve / delete) | — | Full access | Full access |
| OPCO Attendance, Qualiopi Surveys & SCORM 1.2 Exports | — | Full access | Full access |
| Bulk Docker Operations (`stop_all`, `prune_stopped`, `refresh_runtime`, `reap_idle`, `heal_chaos`) | — | Full access | Full access |
| Cohort CSV, Qualiopi JSON & 1-Click Classroom Presets | — | Full access | Full access |
| Users Tab (`create`, `update`, `delete`, suspend, password reset, bulk ops, throttle unlock, CSV import) | — | Hidden / HTTP `403` | Full access |
| Courses Tab (`add`, `toggle`, `delete` custom courses) | — | Hidden / HTTP `403` | Full access |
| Audit Tab (filter, SHA-256 integrity badge & `export_audit_csv`) | — | Hidden / HTTP `403` | Full access |
| System Tab (10 feature flags, 4 profiles, per-course overrides, JSON export/import, cache flush, 7-probe System Health) | — | Hidden / HTTP `403` | Full access |

> **Security Note:** Attempting any trainer or admin action without the required role returns an explicit HTTP `403` (JSON error for `api_*` routes or restricted access state for web routes).

---

## 2. Trainer Cockpit (`page=admin`, Tabs: `cohort`, `qna`, `docker`)

Trainers and Administrators share the `admin` workspace orchestrated by `src/Http/AdminController.php` and [templates/pages/admin.php](../templates/pages/admin.php), scoped dynamically by `$auth->isAdmin($currentUser)` and lazy-loading datasets into seven clean partial views (`templates/partials/admin/tab_{cohort,users,qna,docker,courses,audit,system}.php`):

### 2.1 Executive Cohort KPI Strip, At-Risk Detection & Completion Matrix (`tab_cohort.php`)
Calculated in a single pass for the active `$currentCourseSlug` using preloaded progress payloads (eliminating N+1 storage reads), rendered across KPI cards with `tabular-nums`:
- Active student count (`active_students_count` / `students_count`) and staff count (`staff_count`).
- Cohort average completion (`avg_modules_pct` and `avg_labs_pct` across the active course's real module/lab counts).
- Certification exam pass count & rate (`exams_passed_count`, `exam_pass_rate`) plus completed diagnostics (`diagnostics_count`); `quizzes_total_count` is also aggregated in the KPI payload.
- Average signed attendance (`avg_attendance_half_days` half-days and `avg_attendance_hours` at `3.5h` per slot) and Qualiopi survey responses (`surveys_count`).
- **At-Risk Learner Detection (`AdminService::detectAtRiskLearners()`):** Identifies active enrolled students below the completion threshold (default `20%`) and renders `.at-risk-badge` indicators with actionable risk reasons (`0%` inactivity or `< threshold`).
- **Module & Lab Completion Matrix (`AdminService::computeModuleCompletionMatrix()`):** Displays per-module and per-lab cohort completion percentages, quiz attempts, and average quiz scores.
- **Cohort & Qualiopi Exports (`action=export_cohort_csv`, `action=export_qualiopi_json`):**
  - `AdminService::exportCohortCsv()`: Formula-safe `;`-delimited UTF-8 BOM CSV (`User_ID;Nom;Email;Entreprise;Role;Statut;Modules_Completes;...`).
  - `AdminService::exportQualiopiJson()`: Consolidated JSON dossier (`schema_version: 2.0`) bundling cryptographic `audit_integrity`, `at_risk_learners`, `module_completion_matrix`, learner attendance/completion metrics, and audit trail records.

### 2.2 Live Classroom Broadcast, Presets & Security Locks (`save_classroom_state`, `admin_apply_classroom_preset`)
Trainers control the live workshop state stored per course slug:
- **1-Click Classroom Presets (`.preset-toolbar` / `action=admin_apply_classroom_preset`):** Instant mode toggles for `lecture`, `guided_lab`, `exam_lockdown`, and `open_review` updating `lock_solutions`, `lock_exam`, and broadcast state atomically.
- **`broadcast_message`, `broadcast_level`, & `active_track`:** Displays a synchronized top alert banner (`info` / `warning` / etc.) across learner workspaces and sets the active classroom track filter.
- **`lock_solutions`:** Strips all lab `Corrigé` views and disables `api_run_lab_solution` for students until unlocked.
- **`lock_exam`:** Prevents non-staff learners (`!$auth->isStaff($currentUser)`) from starting or submitting the 40-question Certification Exam before scheduled exam time.
- **Per-Learner Cohort Navigation (`return_student_id`, `return_track`):** Opens a learner's individual Attendance sheet or Diagnostic without losing track context.

### 2.3 Q&A Moderation (`qna` Tab)
- View all unresolved and resolved course questions (`StorageInterface::getAllCourseDiscussions()`).
- Reply officially (`action=post_discussion`), mark resolved/reopened (`action=moderate_discussion`, mode resolve), or remove inappropriate posts.

### 2.4 Classroom Docker Fleet Operations (`docker` Tab)
- **Fleet Summary KPIs (`DockerManager::getFleetSummary()`):** Reports live vs `cloud_simulated` mode, running/stopped/max container counts, primary cluster state, and idle container telemetry.
- Inspect active containers per user (`lp_pg18_user_<id>`, port `5500 + userId`).
- Trigger **Start**, **Restart**, **Stop**, **Seed Sakila/pgbench** (`api_seed_lab`), view **PostgreSQL Logs** (`api_docker_logs`), or **Reset** (`data-cmd="reset"` with mandatory browser confirmation dialog).
- Execute room-wide cleanup via `action=admin_bulk_docker` (`stop_all`, `prune_stopped`, `refresh_runtime`), **Reap Idle Containers** (`action=admin_reap_idle_docker` -> `DockerManager::reapIdleContainers()`), and **Heal Chaos Locks** (`action=admin_heal_chaos` -> `DockerManager::healChaosScenario()`).

---

## 3. Administrator Control Plane (`admin`-Only Tabs)

Only `$auth->isAdmin($currentUser)` users see and can submit requests to the four administrative tabs:

### 3.1 Users, Bulk Lifecycle & Throttle Management (`tab_users.php`)
- **Live Filtering (`AdminService::filterUsers()`):** Search by name, email, or company (UTF-8 case-insensitive) and filter by role (`student`, `trainer`, `admin`).
- **Create, Update, Suspend & Password Reset:** Passwords always use `type="password"` (no hardcoded plaintext default pre-filled in HTML). Role updates strictly check `Auth::VALID_ROLES`. Administrators can toggle account active status (`action=admin_toggle_user_active` -> `setUserActive()`, immediately terminating active sessions for suspended accounts via `Auth::isUserActive()`), reset user passwords (`action=admin_reset_password` -> `adminResetUserPassword()`), or execute bulk operations (`action=admin_bulk_users` via `[data-bulk-select-all]` checkboxes: `enroll`, `suspend`, `activate`).
- **Login Throttle Inspector & 1-Click Unlock (`action=admin_unlock_throttle`):** Displays active `email|ip` and `ip|<addr>` rate-limit buckets via `StorageInterface::listLoginThrottles()` and clears individual lockouts in 1 click via `clearLoginThrottle()`.
- **Course Enrollments (`action=admin_update_enrollments`):** Assign specific course slugs to each learner; students can only access assigned slugs (`Auth::canAccessCourse()` defaults unset/`null` enrollments to `['postgresql-18']`, while an explicit empty array `[]` grants zero courses), whereas `trainer` and `admin` always retain global visibility.
- **Reset Progress (`action=admin_reset_user_progress`):** Clears a learner's module/lab completion and quiz history for a given course slug.
- **Bulk CSV Import (`action=admin_import_users_csv`):**
  - Accepts pasted CSV/TSV content (`csv_content` / `csv_payload`), auto-detects `;`, `,`, or `\t` delimiters, strips UTF-8 BOM, and skips optional headers (`name`/`nom`/`fullname`, `email`/`courriel`).
  - Expected column order: `Name;Email;Company;Role;Password` (minimum 6-character password or random CSPRNG fallback; invalid roles normalize to `student`; names/companies are sanitized via `Security::sanitizeCsvCell()`).

### 3.2 Course Catalog (`tab_courses.php`)
- **Register Custom Course (`action=admin_add_course`):** Adds external Markdown/course directories (verified inside the workspace via `Security::isPathInside()`) into `custom_courses.json`, while rejecting reserved built-in slugs such as `'postgresql-18'`.
- **Toggle & Delete (`action=admin_toggle_course`, `action=admin_delete_course`):** Activate, deactivate, or delete custom course entries cleanly without touching core configuration files.

### 3.3 Audit Trail, SHA-256 Chain Verification & CSV Export (`tab_audit.php`)
- **Retention & Rotation:** Active log holds up to `1,000` events; older records automatically archive into monthly `audit_logs_YYYYMM` buckets (`JsonStorage::logAuditEvent()`).
- **Tamper-Evident SHA-256 Hash Chain Badge (`.integrity-pill-ok` / `.integrity-pill-error`):** Evaluates `verifyAuditIntegrity(true)` via `AdminService::buildAuditExportMetadata()` across active and monthly archives, reporting total checked entries, chained entries, tolerated pre-chain historical records (`legacy_unchained`), and any broken/tampered chain links (`broken_links_count`).
- **Filter & Export (`AdminService::filterAuditLogs()`, `action=export_audit_csv`):** The Audit tab lazy-loads `$storage->getAuditLogs(200, true)` (including monthly archives), while `action=export_audit_csv` filters up to `1,000` archived/active entries (`$storage->getAuditLogs(1000, true)`) by `category` prefix (e.g., `AUTH_`, `ADMIN_`, `DOCKER_`) and `actor`/detail text into a sanitized UTF-8 BOM `;`-delimited CSV (`Security::sanitizeCsvCell()`).

### 3.4 Modular Feature Flags, Deployment Profiles, Per-Course Overrides & System Health (`tab_system.php`)
- **10 Categorized Runtime Feature Toggles (`action=admin_save_features` via `FeatureManager`):**

| Flag Key | Category | Dependencies | Effect When Disabled |
| :--- | :--- | :--- | :--- |
| `self_registration` | Authentification & Accès | — | Hides self-registration form on login and blocks `action=register` |
| `demo_quick_login` | Authentification & Accès | — | Hides 1-click Admin/Trainer/Student demo buttons on login |
| `docker_labs` | Laboratoires & Pratique | — | Hides live Docker container controls, Savepoints, `pgbench`, PG18 Diag, Readiness & SQL workbench |
| `chaos_engineering` | Laboratoires & Pratique | `docker_labs` | Disables Chaos fault injection (`api_chaos`) — automatically disabled when `docker_labs` is off |
| `gamification` | Pédagogie & Engagement | — | Hides XP bar, DBA rank, and competency badges |
| `qna_forum` | Pédagogie & Engagement | — | Hides module/lab Q&A thread forms |
| `personal_notes` | Pédagogie & Engagement | — | Hides learner personal notes sidebar link (`notes`) and blocks note editing/export |
| `flashcards_srs` | Pédagogie & Engagement | — | Hides spaced-repetition flashcards sidebar link (`flashcards`) and blocks SRS endpoints |
| `scorm_export` | Conformité & Exports | — | Hides and guards SCORM 1.2 manifest/ZIP export controls |
| `cold_satisfaction_j60` | Conformité & Exports | — | Hides the Qualiopi J+60 delayed satisfaction workflow |

- **Deployment Feature Profiles (`action=admin_apply_feature_profile`):** 1-click profiles via `FeatureManager::applyProfile()`: `full_enterprise`, `classroom_workshop`, `exam_lockdown`, and `static_hosting_nodocker`.
- **Per-Course Feature Overrides (`action=admin_save_course_features`):** Calls `FeatureManager::setCourseOverrides()` / `clearCourseOverrides()` to tailor any of the 10 flags for a specific course slug.
- **Configuration JSON Export / Import (`export_features_json`, `admin_import_features_json`):** `FeatureManager::exportConfigJson()` and `importConfigJson()` allow portable snapshot transfer across LMS instances.
- **Catalog Cache Flush (`action=admin_clear_cache`):** Calls `CourseRepository::clearCatalogCache(): int` and records the count of deleted cache files.
- **System Health Diagnostics (`.admin-split-grid` + `AdminService::getSystemHealthReport()`):** Rendered in a balanced 2-column `.admin-split-grid` with `.table-col-nowrap` status badges across **7 real-time probes**:
  1. PHP version & required extensions (`json`, `mbstring`, `zip`, `pdo`).
  2. Persistence status (PDO active flag & `$pdoCheck->getLastError()` diagnostics vs writable JSON directory).
  3. Cache directory writability and active footprint (`storage/data/parsed_cache/*.html` & `catalog_cache_*.json`).
  4. Docker orchestration runtime (`wsl`/`native`/`cloud_simulated`, active vs total lab containers).
  5. Loaded course catalog count (`$courseRepo->getAllCourses()`).
  6. Production secret hygiene (`LMS_CERT_HMAC_SECRET` / `APP_SECRET` presence and non-default Docker password check).
  7. Tamper-evident SHA-256 audit chain integrity (`Chaîne Audit SHA-256` via `buildAuditExportMetadata()`).

---

## 4. Qualiopi, Datadock & SCORM 1.2 Compliance Workflows

1. **Positioning Diagnostic (`page=diagnostic`):** `15` balanced QCM questions (`LmsCatalog::getDiagnosticQuestions()`) mapping learners automatically to recommended tracks:
   - Score `< 45%` -> `fondamentaux-sql`
   - Score `45% – 74%` -> `cursus-complet`
   - Score `>= 75%` -> `performance-tuning`
2. **Half-Day OPCO Attendance (`page=attendance`, `J1_AM`..`J11_PM`):** Learner digital signature + IP timestamp paired with Trainer countersignature (`sign_trainer_attendance`), exported cleanly via `action=export_attendance_csv` (`;`-delimited UTF-8 BOM CSV labeling roles as `PERSONNEL FORMATEUR`, `AUCUNE (ABSENT)`, and `STAFF (N/A)` as appropriate).
3. **Formative & Summative Evaluation:**
   - Per-module Quiz QCMs graded server-side (`api_submit_quiz`).
   - 7-pillar Practical Labs validated via SQL oracles (`api_validate_lab`).
   - **Final Certification Exam (`page=exam`):** `40` balanced questions (`8` across each of `5` DBA domains), `75%` pass threshold, issuing a CSPRNG code `LP-PG18-CERT-<uid>-<8HEX>` verifiable publicly at `index.php?page=verify&code=...` with an offline SVG QR badge (`Security::renderVerificationQrSvg()`).
4. **Hot & Cold J+60 Satisfaction (`page=satisfaction`):** Immediate end-of-session rating plus J+60 post-training impact follow-up, consolidated via `action=export_qualiopi_csv`.
5. **SCORM 1.2 LMS Interoperability (`export_scorm_manifest`, `export_scorm_zip`):** Staff-gated exports packaging `imsmanifest.xml` and course SCO references using `ZipArchive` or pure-PHP PKZIP store fallback (`DownloadController::buildScorm12ZipPackage()`).
