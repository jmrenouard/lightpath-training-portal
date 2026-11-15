# PROJECT-CONTEXT — LightPath Training Portal

> **Onboarding Baseline for Engineers and AI Coding Agents**  
> **Organization:** LightPath SARL (`https://www.lightpath.fr` | `contact@lightpath.fr`)  
> **Lead Instructor & Author:** Jean-Marie Renouard (Database & DataOps Expert, Co-developer of MySQLTuner)  
> **LMS Edition:** LightPath Academy (`v2.0.0` / `LP-LMS-QUALIOPI-2026`)  

---

## 1. Purpose and Runtime Philosophy

`lightpath-training-portal` is a **zero-dependency PHP >= 8.1 Learning Management System (LMS)** built for hands-on technical certifications—starting with the 4-track **PostgreSQL 18** curriculum (`34` modules, `34` 7-pillar Qualiopi labs, `69` cheatsheets, `18` Qualiopi reference documents, `35` Python `pgscripts`, and multi-language companion examples).

### Core Architectural Decisions
1. **Zero Composer/Node Runtime Dependencies:** Custom PSR-4 autoloader in [src/bootstrap.php](src/bootstrap.php) (`LightPath\LMS\` -> `src/`), standard library PHP `>= 8.1`, and a built-in pure-PHP PKZIP store fallback when `ZipArchive` is unavailable in [DownloadController.php](src/Course/DownloadController.php).
2. **100% Offline & Zero External CDNs:** All JavaScript, CSS, diagramming engines (`public/assets/mermaid.min.js`), pre-rendered slide decks (`public/assets/slides_cache/`), Retina Mermaid PNGs (`public/assets/mermaid_cache/`), and brand images (`public/assets/`) are self-hosted. External `<script>` or CDN links are strictly prohibited.
3. **Dual-Driver Storage Abstraction:** Supports zero-setup local JSON flat files (`JsonStorage`) or transactional database persistence (`PdoStorage`) via [StorageInterface.php](src/Core/StorageInterface.php).
4. **Thin Domain Modules over Monolithic Routing:** Domain logic lives in `src/Core/`, `src/Course/`, and `src/Docker/`, coordinated by [public/index.php](public/index.php), [FeatureManager.php](src/Core/FeatureManager.php), and [AdminService.php](src/Core/AdminService.php) without framework bloat.
5. **Graceful Docker Degradation:** Live per-student containers (`lp_pg18_user_<id>`) run locally via native Docker or Windows WSL2 with non-blocking `proc_open()` timeouts and automatically degrade to `cloud_simulated` mode on static/shared PHP hosts.

---

## 2. Directory Map

| Path | Ownership & Responsibility |
| :--- | :--- |
| [config/app.php](config/app.php) | Application metadata, storage driver selection, PDO DSN settings, Docker port/image/quota defaults |
| [config/courses.php](config/courses.php) | Declarative course-pack registry (`postgresql-18`, 4 tracks, module filters) |
| [config/features.php](config/features.php) | Default definitions for 8 modular LMS feature toggles |
| [config/playwright-mcp.config.json](config/playwright-mcp.config.json) | Headless Chrome MCP configuration restricted to `localhost`/`127.0.0.1` |
| `src/Core/` | `Auth`, `Security`, `StorageInterface`, `JsonStorage`, `PdoStorage`, `FeatureManager`, `AdminService`, `Gamification` |
| `src/Course/` | `CourseRepository`, `MarkdownParser`, `LmsCatalog`, `DownloadController` |
| `src/Docker/` | `DockerManager`, `LabSqlRunner`, `SakilaSeeder` |
| `bin/` | CLI utilities: `lab-runner.php`, `sync-course.php`, `export_slides_cache.py` |
| `public/` | Web root (`index.php` front controller, `assets/css/app.css`, `assets/js/app.js`, self-hosted media) |
| `templates/` | `layout.php` global shell + 16 modular view templates in `templates/pages/` |
| `storage/data/` | Runtime JSON stores, `.lock` files, `parsed_cache/`, `catalog_cache_*.json`, monthly audit archives |
| `tests/` | PHP TDD suite (`run_tests.php`), adversarial suites (`test_adversarial_b1..b5.php`), Playwright E2E (`tests/e2e/`) |
| `docs/` | Modular engineering, security, operations, Docker, course-pack, API, and audit documentation |

---

## 3. Storage & Environment Contracts

### Storage Drivers & Filesystem Safety
- **`JsonStorage`** ([src/Core/JsonStorage.php](src/Core/JsonStorage.php)):
  - Uses exclusive sidecar `.lock` files with `flock($lockFp, LOCK_EX)` and `LOCK_UN` around read-modify-write cycles in `mutateFile()`.
  - Writes via temporary file + `rename()` with short retry backoff for Windows file-lock contention.
  - Seeds 3 default accounts when `users.json` does not exist: ID `1` (`jmrenouard@lightpath.fr`, `admin`), ID `2` (`formateur@lightpath.fr`, `trainer`), ID `3` (`stagiaire@lightpath.fr`, `student`).
  - Generates CSPRNG certificate identifiers matching `LP-PG18-CERT-<uid>-<8HEX>` via `random_bytes(4)`.
  - Retains 1,000 active audit records in `audit_logs` and rotates overflow into monthly `audit_logs_YYYYMM` payloads; `getAuditLogs(int $limit, bool $includeArchives = false)` merges archived months newest-first when `$includeArchives === true`.
- **`PdoStorage`** ([src/Core/PdoStorage.php](src/Core/PdoStorage.php)):
  - Extends `JsonStorage`, overriding `readFile()`, `writeFile()`, and `mutateFile()` against auto-created `lms_kv_store (store_key VARCHAR(128) PRIMARY KEY, payload TEXT, updated_at VARCHAR(32))`.
  - Uses `ON CONFLICT (store_key) DO UPDATE` (`pgsql`/`sqlite`), `ON DUPLICATE KEY UPDATE` (`mysql`), and `SELECT ... FOR UPDATE` row locking during `mutateFile()`.
  - Falls back safely to local files only when offline at startup; once connected, logs write errors and exposes `getLastError()` for System Health diagnostics.

### Environment Variables

| Variable | Default | Purpose |
| :--- | :--- | :--- |
| `LMS_STORAGE_DRIVER` | `json` | Persistence backend: `json` or `pdo` |
| `LMS_DATA_DIR` | `storage/data` | Override data/cache folder (used by Playwright E2E tests for isolation) |
| `LMS_PDO_DSN` | `pgsql:host=localhost;port=5432;dbname=lightpath_lms` | PDO connection string when `LMS_STORAGE_DRIVER=pdo` |
| `LMS_PDO_USER` | `postgres` | PDO database username |
| `LMS_PDO_PASS` | `""` | PDO database password |
| `LMS_WSL_DISTRO` | `Ubuntu-24.04` | Windows WSL2 Linux distribution hosting Docker daemon |
| `LMS_DOCKER_FORCE_SIMULATED` | unset (`0`) | Set to `1` to force non-blocking simulated Docker mode |

---

## 4. Critical Test Compatibility Constraints (Read Before Refactoring)

> **Warning for Maintainers & Agents:** `tests/run_tests.php` (`46` checks) and `tests/test_adversarial_b1..b5.php` (`10` checks) assert exact source-code substrings via `file_get_contents()` across 16 files alongside live behavioral tests. Do **not** remove or rename these contracts without updating the corresponding test assertions in the same PR.

- **Registration Password Floor:** `tests/test_adversarial_b5.php` registers a test user with `'pwd12345'` (8 lowercase/digit characters, no uppercase or symbol). Keep `Auth::registerUser()` minimum length at `6` without imposing forced uppercase/symbol complexity unless `test_adversarial_b5.php` is updated in lockstep.
- **Locked Source Markers by File:**
  - `public/index.php`: `session_write_close()`, `parseModuleFile($modObj['source_md_path']`, `lock_solutions`, `api_run_lab_solution`, `$type === 'lab'`, `$solutionsLocked`, `$_POST['csv_content'] ?? ($_POST['csv_payload'] ?? '')`, `$page !== 'verify'`, `action === 'export_scorm_manifest' && $auth->isStaff($currentUser)`, `action === 'export_scorm_zip' && $auth->isStaff($currentUser)`, `action === 'export_attendance_csv'`, `'classroom_state' => $storage->getClassroomState`, `action === 'admin_clear_cache'`, `'Role'`, `'PERSONNEL FORMATEUR'`, `'AUCUNE (ABSENT)'`, `'STAFF (N/A)'`, `'return_student_id'`, `'return_track'`, `$_GET['track']`, `admin_import_users_csv`, and absence of `$page === 'docker'`.
  - `Security.php`: `sendSecurityHeaders`, `renderUnloadShimScript`, `pagehide`.
  - `JsonStorage.php` / `PdoStorage.php`: `flock($lockFp, LOCK_EX)`, `LOCK_UN`, `ON CONFLICT (store_key) DO UPDATE`, `FOR UPDATE`.
  - `DockerManager.php`: `LOCK_EX`, `@rename($tmpPath, $this->cacheFile)`, `--shm-size=512m`.
  - `LabSqlRunner.php`: `'PGOPTIONS="-c statement_timeout=15000 -c lock_timeout=10000"'`, `'$b64 = base64_encode($sql);'`, `'base64 -d'`, `'Commandes backslash interactives non autorisées'`, and `inspectDatabaseSchema()` returning `>= 6` tables for `dvdrental` and `>= 4` for `formation_pg`.
  - `CourseRepository.php` / `DownloadController.php`: `video_transcript_path`, `transcript_md_path`, `v8_rca164`, `loadCourse()`, `clearCatalogCache()`, `assets/`, `Permissions-Policy: unload=`, `Security::renderUnloadShimScript()`, `Security::isPathInside(`, `$chunkSize = 64 * 1024`, `fread($fp, $readSize)`, `ob_flush()`, `flush()`.
  - `app.css` / `app.js` / templates: `overflow-wrap: break-word`, `min-width: 0`, `[hidden] {`, `@media (max-width: 640px)`, `minmax(min(100%, 330px), 1fr)`, `.reader-grid > *`, `.lms-callout-note`, `#00B0F0`, `.lms-code-badge`, `tabular-nums`, `updateScrollSpy`, `data-scrollspy-target`, `syncThemeLogos`, `sidebar-module-filter`, `switchSlideEngine`, `centerElementInScrollBox`, `index.php?page=search&q=`, `elseif ($adminTab === 'system'):`, `tab=system`, `!$auth->isStaff($currentUser)`, `$inspectedUserId === $userId`, `export_notes_md`, `note-inline-editor`.
- **Shared Cache Hygiene:** Running `tests/run_tests.php` or `tests/test_adversarial_b4.php` creates/clears entries under `storage/data/parsed_cache`. Always use a dedicated `LMS_DATA_DIR` temporary directory when running standalone Playwright/HTTP scripts.
- **Wave 4A Hardened Behavioral Contracts:**
  - `Auth::canAccessCourse($user, $courseSlug)`: unset/`null` `enrolled_courses` defaults to `['postgresql-18']`, whereas an explicit empty array `[]` grants **zero** courses to non-staff students.
  - Strict CSP (`Security::sendSecurityHeaders()`): `script-src 'self' 'sha256-...'` (zero `'unsafe-inline'` in `script-src`) hashes `Security::UNLOAD_SHIM_JS`; `login.php` uses `app.js` `[data-demo-email]` listeners instead of inline `<script>`.
  - `FeatureManager` memoizes `all()` per HTTP request in `$resolvedFlags` and resets it in `updateFlags()`.
  - `LabSqlRunner::stripCommentsAndLiterals()` provides single-pass comment/quote/dollar-quote lexing so backslash checks run strictly on `$outsideQuotes` while timeout/role bypass checks inspect both raw and `$commentsStripped` SQL.
  - `DockerManager::seedUserContainer()` and `getContainerLogs()` default `$allowSharedFallback = false`; web APIs pass `$auth->isStaff($currentUser)`.
  - `DownloadController::resolveHttpRange()` validates `Range: bytes=...` headers cleanly (`200`, `206 Partial Content` in `64 * 1024` byte chunks, or `416 Range Not Satisfiable`).
  - `MarkdownParser::renderMermaidBlock()` resolves course-pack Retina PNGs from `$sourceRoot/assets/mermaid_cache/mermaid_<hash>.png`.
  - `templates/pages/admin.php` lazy-loads tab datasets (`cohort`, `qna`, `docker`, `users`, `courses`, `audit`, `system`) per active tab, while `AdminService::getSystemHealthReport()` scans `storage/data/parsed_cache/*.html` and `catalog_cache_*.json`.

---

## 5. Engineering Governance, Git Hooks & Brand Standards

### Mandatory 9-Step GitHub Workflow
All repository work follows the 9-step lifecycle:
1. Open a GitHub Issue (`gh issue create`) in English (`0`).
2. Create a dedicated feature/fix branch (`feat/...`, `fix/...`, `docs/...`) — **never** commit to `main` (`1`).
3. Run 100% of unit, adversarial, and syntax checks before staging (`2`).
4. Stage only tracked source/doc files (`git add`), excluding logs/dumps/caches (`3`).
5. Commit with English Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:`, `test:`) (`4`).
6. Push branch (`git push -u origin <branch>`) (`5`).
7. Open a Pull Request (`gh pr create`) referencing `Closes #<id>` (`6`).
8. Merge PR (`gh pr merge`) once green (`7`).
9. Close the issue with an English summary comment (`gh issue close --comment "..."`) (`8`).

### Active Git Hooks
Enable local enforcement immediately after cloning:

```bash
git config core.hooksPath .githooks
```

- [.githooks/pre-commit](.githooks/pre-commit): Blocks direct commits on `main` and executes `php tests/run_tests.php`.
- [.githooks/pre-push](.githooks/pre-push): Blocks direct pushes to `main`.

### Language & LightPath Branding Policy
- **Code & GitHub Strict English:** All PHP, JS, CSS, Python, and SQL comments, docstrings, CLI technical docs, Git commits, PRs, and GitHub issues MUST be written in English.
- **French Learner Experience:** Course content (`training_postgresql/`), UI strings, labels, quizzes, and Qualiopi forms remain in French.
- **Strict Brand Identity:** Display only **LightPath SARL** branding (`Jean-Marie Renouard`, `contact@lightpath.fr`, `https://www.lightpath.fr`), official PostgreSQL Slonik and LightPath logos, palette Obsidian `#0B1120` / Navy `#0F172A` / Cyan `#00B0F0` & `#38BDF8` / Slate `#F8FAFC`, and `font-variant-numeric: tabular-nums` for telemetry. Zero third-party trainer mentions or legacy external logos, and zero purple-nebula gradients.
