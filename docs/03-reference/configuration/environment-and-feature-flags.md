# Environment Variables, Application Config, and Feature Flags

Exact configuration parameters, runtime environment variables, course registry schema, modular feature toggles, and PHP runtime specifications for LightPath Training Portal (`v2.0.0`, Ref. `LP-LMS-QUALIOPI-2026`).

## 1. Runtime and Storage Environment Variables

| Variable | Type | Default | Allowed / Expected Values | Source Consumer | Purpose |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `LMS_STORAGE_DRIVER` | string | `json` | `json`, `pdo` | `config/app.php`, `public/index.php` | Selects `JsonStorage` or `PdoStorage` persistence backend. |
| `LMS_DATA_DIR` | path | `<repo>/storage/data` | Writable directory path | `config/app.php`, `DockerManager.php` | Sets directory for JSON collections, `.lock` files, `parsed_cache/`, `catalog_cache_*.json`, and `docker_status_cache.json`. |
| `LMS_PDO_DSN` | string | `pgsql:host=localhost;port=5432;dbname=lightpath_lms` | Valid PHP PDO DSN (`pgsql:`, `mysql:`, `sqlite:`) | `config/app.php`, `PdoStorage.php` | Database connection DSN used when `LMS_STORAGE_DRIVER=pdo`. |
| `LMS_PDO_USER` | string | `postgres` | Database username | `config/app.php`, `PdoStorage.php` | Database user for `lms_kv_store` reads and writes. |
| `LMS_PDO_PASS` | string | `""` (empty string) | Database password secret | `config/app.php`, `PdoStorage.php` | Password for PDO authentication. |
| `LMS_PORT` | integer | `8080` | `1..65535` | `docker-compose.yml` | Host port mapped to container port `8080`. |
| `LMS_CERT_HMAC_SECRET` | string | unset | Non-empty secret string (64-char hex recommended) | `AdminService.php` | Production certificate signing secret checked by `AdminService::getSystemHealthReport()`. |
| `APP_SECRET` | string | unset | Non-empty secret string | `AdminService.php` | Secondary fallback secret checked when `LMS_CERT_HMAC_SECRET` is unset. |
| `LMS_INIT_ADMIN_PASSWORD` | string | unset (CSPRNG 24-hex generated) | Password string (`>= 6` chars) | `JsonStorage.php`, `docker-compose.yml` | Seeds initial `jmrenouard@lightpath.fr` account (`id: 1`) on empty `users` store. |
| `LMS_INIT_TRAINER_PASSWORD` | string | unset (CSPRNG 24-hex generated) | Password string (`>= 6` chars) | `JsonStorage.php`, `docker-compose.yml` | Seeds initial `formateur@lightpath.fr` account (`id: 2`) on empty `users` store. |
| `LMS_INIT_STUDENT_PASSWORD` | string | unset (CSPRNG 24-hex generated) | Password string (`>= 6` chars) | `JsonStorage.php`, `docker-compose.yml` | Seeds initial `stagiaire@lightpath.fr` account (`id: 3`) on empty `users` store. |

## 2. Course-Pack and Docker Lab Environment Variables

| Variable | Type | Default | Allowed / Expected Values | Source Consumer | Purpose |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `LMS_COURSE_ROOT` | path | `../../training_postgresql` if present, else `examples/demo-course` | Existing directory path | `config/courses.php`, `MarkdownParser.php`, `bin/sync-course.php`, `bin/export_slides_cache.py` | Overrides source root for the primary `postgresql-18` course pack. |
| `LMS_PGSCRIPTS_ROOT` | path | `../../pgscripts` if present, else `examples/demo-course/pgscripts` | Existing directory path | `config/courses.php` | Overrides directory containing pure-Python DBA utility scripts. |
| `LMS_DOCKER_FORCE_SIMULATED` | flag | unset (`0`) | `1` (active) or unset | `DockerManager.php`, `docker-compose.yml` | Bypasses WSL2/native Docker probes and forces deterministic `cloud_simulated` mode. |
| `LMS_WSL_DISTRO` | string | `Ubuntu-24.04` | Installed WSL2 distribution name | `config/app.php` | Target Windows WSL2 distribution name configured in the `docker` block. |
| `LMS_DOCKER_COMPOSE_DIR` | path | `../../training_postgresql/docker` if present, else repo root | Existing directory path | `config/app.php` | Base path for Docker Compose lab topology definitions. |
| `LMS_CLASSROOMCTL_PATH` | path | `../../training_postgresql/scripts/classroomctl.py` if present, else `""` | File path or empty string | `config/app.php` | Optional external classroom control script path. |

## 3. Documentation Publishing Environment Variables

| Variable | Type | Default | Allowed / Expected Values | Source Consumer | Purpose |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `LMS_DOCS_REF` | string | `main` | Git branch or tag name (`WORKTREE` and `HEAD` normalize to `main`) | `scripts/mkdocs_hooks.py`, `scripts/build_versioned_docs.py` | Controls the Git ref used when rewriting relative repository links to GitHub `blob/` or `tree/` URLs. |
| `LMS_DOCS_SITE_URL` | URL | `https://lms.lightpath.fr/` | Absolute HTTP/HTTPS base URL | `scripts/mkdocs_hooks.py`, `scripts/build_versioned_docs.py` | Overrides `site_url` per built version directory (for example `/main/` or `/v2.0.0/`). |
| `PYTHONUTF8` | flag | unset | `1` | `scripts/build_versioned_docs.py` | Forces UTF-8 encoding in child `mkdocs build` Python processes across platforms. |

## 4. Application Configuration Defaults (`config/app.php`)

| Key | Type | Default Value | Unit | Constraints / Behavior |
| :--- | :--- | :--- | :--- | :--- |
| `app_name` | string | `LightPath Academy` | - | Displayed in HTML `<title>` and login branding. |
| `company` | string | `LightPath SARL` | - | Organization name in sidebar footer, Qualiopi exports, and SCORM packages. |
| `trainer_name` | string | `Jean-Marie Renouard` | - | Default instructor name for attendance countersignatures and certificates. |
| `version` | string | `2.0.0` | semver | Application release identifier. |
| `qualiopi_ref` | string | `LP-LMS-QUALIOPI-2026` | - | Official Qualiopi quality reference code. |
| `session_idle_ttl` | integer | `7200` | seconds | Aligns `session.gc_maxlifetime`, session cookie `lifetime` (floor `300` in `src/bootstrap.php`), and `Auth::currentUser()` idle timeout (floor `60` in `Auth`). |
| `docker.enabled` | boolean | `true` | - | Global toggle indicating Docker lab capability in configuration. |
| `docker.base_port` | integer | `5500` | TCP port | Clamped `[1024..60000]`; user container publishes `base_port + userId` (wrapped within `<= 65535`). |
| `docker.image` | string | `docker-pg-primary:18` | Docker image | Preferred container image when present locally. |
| `docker.fallback_image` | string | `postgres:18` | Docker image | Fallback image when `docker.image` is absent locally. |
| `docker.default_db` | string | `formation_pg` | DB name | Default database initialized inside `lp_pg18_user_<id>`. |
| `docker.default_user` | string | `postgres` | role name | PostgreSQL superuser account used for bootstrap and trusted oracles. |
| `docker.default_password` | string | `postgres` | string | Non-production container password; `AdminService::getSystemHealthReport()` emits a warning when left as `postgres`. |
| `docker.max_containers` | integer | `40` | count | Maximum concurrent `lp_pg18_user_*` containers permitted by `DockerManager::startUserContainer()`. |

## 5. Built-In Course Registry (`config/courses.php`)

| Course Slug | Category | Duration | Docker Enabled | Track Keys (`module_filter`) | Source Root Resolution |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `postgresql-18` | Database & DataOps | `77h` (4 tracks) | `true` | `cursus-complet` (`01..20`), `performance-tuning` (`21..25`), `ingenierie-specialisee` (`26..29`), `fondamentaux-sql` (`sql_01..sql_05`) | `LMS_COURSE_ROOT` -> `../../training_postgresql` -> `examples/demo-course` |
| `git-basics` | Software Engineering & DevOps | `7h` (1 day) | `false` | `fondamentaux-git` (`01..03`) | `examples/git-basics-course` |

## 6. Modular Feature Flags (`config/features.php`)

Runtime values saved in storage (`features` key) supersede defaults. `FeatureManager::isEnabled()` evaluates recursive `requires` dependencies and optional per-course overrides (`course_feature_overrides`).

| Flag Key | Category | Default | `requires` | Effect When Disabled |
| :--- | :--- | :--- | :--- | :--- |
| `self_registration` | Authentication & Access | `true` | - | Hides registration tab on `?page=login` and rejects `POST ?action=register` with HTTP `403`. |
| `demo_quick_login` | Authentication & Access | `false` | - | Hides 1-click Admin, Trainer, and Student quick-login buttons on `?page=login`. |
| `docker_labs` | Labs & Practice | `true` | - | Hides Docker controls in UI and rejects non-staff `api_docker_*`, `api_seed_lab`, `api_run_sql`, `api_schema_inspect`, `api_run_oracle`, `api_validate_lab`, `api_run_lab_solution`, `api_lab_*` calls with HTTP `403`. |
| `chaos_engineering` | Labs & Practice | `true` | `docker_labs` | Disables `api_chaos` (`403`); automatically resolves to `false` whenever `docker_labs` is disabled. |
| `gamification` | Pedagogy & Engagement | `true` | - | Hides XP bar, DBA rank badge, and achievement progress indicators in topbar and dashboard. |
| `qna_forum` | Pedagogy & Engagement | `true` | - | Hides module/lab Q&A threads and blocks `api_post_discussion` and `post_discussion` with HTTP `403`. |
| `personal_notes` | Pedagogy & Engagement | `true` | - | Hides `notes` sidebar link and blocks `?page=notes`, `export_notes_md`, `api_save_note`, and `save_note_form` with HTTP `403`. |
| `flashcards_srs` | Pedagogy & Engagement | `true` | - | Hides `flashcards` sidebar link and blocks `?page=flashcards`, `api_flashcard_rate`, and `api_reset_flashcards` with HTTP `403`. |
| `scorm_export` | Compliance & Exports | `true` | - | Hides and rejects `export_scorm_manifest` and `export_scorm_zip` with HTTP `403`. |
| `cold_satisfaction_j60` | Compliance & Exports | `true` | - | Hides and rejects `submit_cold_satisfaction` (`J+60` impact questionnaire) with HTTP `403`. |

## 7. Preset Deployment Profiles (`FeatureManager::getProfiles()`)

| Flag Key | `full_enterprise` | `classroom_workshop` | `exam_lockdown` | `static_hosting_nodocker` |
| :--- | :---: | :---: | :---: | :---: |
| `self_registration` | `true` | `false` | `false` | `true` |
| `demo_quick_login` | `true` | `false` | `false` | `false` |
| `docker_labs` | `true` | `true` | `false` | `false` |
| `chaos_engineering` | `true` | `true` | `false` | `false` |
| `gamification` | `true` | `true` | `false` | `true` |
| `qna_forum` | `true` | `true` | `false` | `true` |
| `personal_notes` | `true` | `true` | `false` | `true` |
| `flashcards_srs` | `true` | `true` | `false` | `true` |
| `scorm_export` | `true` | `true` | `true` | `true` |
| `cold_satisfaction_j60` | `true` | `true` | `true` | `true` |

## 8. PHP Runtime and Extension Matrix (`composer.json`, `Dockerfile`)

| Requirement | Status | Purpose |
| :--- | :--- | :--- |
| `php >= 8.3` | Mandatory | Strict types (`declare(strict_types=1)`), `match`, `never` return types, zero Composer vendor libraries. |
| `ext-json` | Mandatory | KV persistence, catalog/markdown caches, API JSON responses, Qualiopi JSON exports. |
| `ext-pdo` | Mandatory | Required by `PdoStorage` interface bindings in `composer.json`. |
| `ext-mbstring` | Suggested / Built-in in Alpine | UTF-8 case-insensitive user/audit search (`AdminService`) and snippet extraction (`CourseRepository`). |
| `ext-zip` | Suggested | Native `ZipArchive` generation for SCORM 1.2 ZIP bundles (pure-PHP PKZIP fallback runs if absent). |
| `ext-pdo_pgsql` / `ext-pdo_sqlite` | Suggested | Relational backend drivers for `PdoStorage` (`lms_kv_store` table). |

## Related Procedures and Architecture

- Configure feature flags, profiles, and custom courses: [Configure Feature Flags and Courses](../../02-how-to-guides/operations/configure-feature-flags-and-courses.md)
- Deploy portal with Docker and PDO storage: [Deploy Portal with Docker and PDO](../../02-how-to-guides/deployment/deploy-portal-docker-and-pdo.md)
- Architectural rationale and dual-driver persistence: [Zero-Dependency LMS Architecture](../../04-explanation/architecture-overview/zero-dependency-lms-architecture.md)
