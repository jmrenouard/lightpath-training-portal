# Quality Audit & Ponytail Remediation Ledger — LightPath Training Portal

> **Scope:** Authoritative record of the `/ponytail-audit` & `/dev-team` 7-axis audit conducted on branch `feat/issue-39-ponytail-audit-7-axes`, tracking Iteration 1 baseline findings, architectural synthesis, wave execution roadmap, and Iteration 2 verification sign-off. Also preserves `Qualiopi` and exclusive LightPath SARL branding (`0` third-party brand leaks).  
> **Load Baseline Assumed:** Classroom cohorts of 1 trainer + 10–30 concurrent students during live workshops (bursty lab container starts, interactive SQL runs, heartbeat polling, quiz/exam submits), plus standalone/shared PHP web hosting without Docker.

---

## 1. Iteration 1 Audit Baseline & Measured Metrics

Static and structural inspection conducted in Iteration 1 (prior to remediation edits) measured the following repository baseline:

| Metric | Iteration 1 Baseline | Target State |
| :--- | :---: | :---: |
| PHP source syntax (`php -l`) | `42` PHP files (`0` errors) | `0` lint errors across all PHP files |
| JS/MJS syntax (`node --check`) | `3` JS/MJS files (`0` errors) | `0` syntax errors (`app.js` + E2E runners) |
| External CDN / Legacy third-party brand leaks | `0` (`0` CDN, `0` legacy third-party tokens) | Strictly `0` |
| French source comment lines | `514` lines across `32` files | `0` French comments in source code (`AGENTS.md`) |
| Inline `style=` attributes | `616` across `21` PHP files | Consolidated into CSS utility classes |
| Inline `on*=` handlers / `<script>` blocks | `10` handlers / `6` `<script>` blocks | Delegated listeners in `app.js` for strict CSP |
| Dark `--text-muted` contrast (`#64748B`) | `3.96:1` (`#0B1120`), `3.75:1` (`#0F172A`), `3.07:1` (`#1E293B`) | WCAG AA `>= 4.5:1` (`#94A3B8` dark / `#475569` light) |
| Light `--text-muted` contrast (`#64748B`) | `4.34:1` (`#F1F5F9`) | WCAG AA `>= 4.5:1` |
| Source-grep test invariants | `56` `file_get_contents()` assertions (`46` `run_tests.php`, `10` `b1..b5`) | Preserved or migrated cleanly to behavioral HTTP tests |
| Engineering docs (`PROJECT-CONTEXT.md`, `docs/`) | Missing (`README.md` only `56` lines) | Complete 9-document baseline suite |

---

## 2. Iteration 1 `/ponytail-audit` Ranked Findings (20 Items across 7 Axes)

### Must Fix (P0)

1. **Web SQL Blacklist & `statement_timeout` Bypass as `postgres` Superuser** (`src/Docker/LabSqlRunner.php:54-85`)
   - **Issue:** Student SQL console queries ran as `postgres` superuser (`psql -U postgres`), allowing `COPY ... FROM PROGRAM` / `pg_read_file()`, while regex filters missed `\g |cmd`, `\gx`, `\gset`, `\lo_export`, and `set_config('statement_timeout', '0', false)`.
   - **Remediation:** Run untrusted student queries under non-superuser role `lms_student`, invoke `psql -X`, expand interactive backslash rejection (`\g`, `\lo_*`), and block `set_config()`, `ALTER SYSTEM`, `ALTER USER`/`ROLE`, `SET ROLE`, and server filesystem functions.
2. **`inspectDatabaseSchema()` Breaks JS Schema Explorer Contract** (`src/Docker/LabSqlRunner.php:372-407`, `public/assets/js/app.js:927-935`)
   - **Issue:** PHP returned `'mode'` and an associative map `'tables' => ['film' => [...]]`, whereas `app.js` checked `Array.isArray(data.tables)` and read `data.source`, `t.schema`, `t.table`, `t.est_rows`, `t.columns`, always rendering `0 table(s)`.
   - **Remediation:** Return `'source'` and an indexed array of `['schema', 'table', 'est_rows', 'columns']` objects from live `information_schema` + `pg_class.reltuples` (with aligned static fallback).
3. **Lab Solution Runner Key Mismatch (`steps_count` vs `steps_executed`)** (`src/Docker/LabSqlRunner.php:259`, `public/assets/js/app.js:1003`)
   - **Issue:** PHP returned `'steps_count'`, while `app.js` rendered `${data.steps_executed || 0}`, always reporting `0 bloc(s) SQL exécuté(s)`.
   - **Remediation:** Return both `'steps_executed'` and `'steps_count'` in PHP and fall back gracefully in `app.js`.
4. **Docker Password Display Mismatch & Shared-Cluster False Positive** (`config/app.php:35`, `src/Docker/DockerManager.php:18-26,236-243`, `src/Docker/LabSqlRunner.php:312-314`, `templates/pages/lab_view.php:217`)
   - **Issue:** Config initialized `'default_password' => 'postgres'`, while `DockerManager` defaulted to `'postgres_master_pw'` and `lab_view.php` hardcoded `postgres_master_pw`. Additionally, `getUserContainerStatus()` reported `'running' => true` whenever shared `pg_formation_primary` ran, misleading learners onto a shared cluster.
   - **Remediation:** Align `default_password` via `DockerManager::getDefaultPassword()`, pass dynamically to `lab_view.php`, and report `'running' => false` unless `lp_pg18_user_<id>` itself is active.
5. **Blocking `@exec()` Docker/WSL2 Calls Without Timeouts or Container Quotas** (`src/Docker/DockerManager.php:104,123,158,300,325-332`, `src/Docker/LabSqlRunner.php:80-86`)
   - **Issue:** Synchronous `@exec()` had zero timeout, and `docker run` lacked CPU, memory, PID, and privilege caps.
   - **Remediation:** Introduce non-blocking `proc_open()` timeout wrapper (`execCommandWithTimeout`), honor `LMS_DOCKER_FORCE_SIMULATED=1`, enforce `--memory=1g --cpus=1.0 --pids-limit=256 --security-opt=no-new-privileges --restart=no`, and enforce `max_containers` (default `40`).
6. **Auth / RBAC Bypasses Across Role Update, Profile Password, Quiz Grading, Throttling & Enrollments** (`public/index.php:97,196-278,363-380,564-567,751-754`, `src/Core/Auth.php:16-31,165,184`, `templates/layout.php:462`)
   - **Issue:** Arbitrary `$_POST['role']` writes in `admin_update_user`, password reset without `current_password` verification in `update_profile`, client-trusted `score`/`total` in `api_submit_quiz`, session-only brute-force counters, unenforced `$currentUser['enrolled_courses']`, and silent HTTP 200 renders on unauthorized staff calls.
   - **Remediation:** Validate `Auth::VALID_ROLES`, add `Auth::changePassword()`, enforce server-side quiz answer arrays (`400` on missing `answers`), persist login throttling in storage (`email|ip` + IP bucket), add constant-time `DUMMY_BCRYPT_HASH` check, enforce `Auth::canAccessCourse()`, and return explicit HTTP `403`. *(Preserve `'pwd12345'` minimum length `6` in `Auth::registerUser()` unless `test_adversarial_b5.php` changes simultaneously.)*
7. **Trainer Sees Admin-Only Forms & Plaintext Password Default; Zero-Confirm Cohort Reset** (`templates/layout.php:462`, `templates/pages/admin.php:141,184,233-282,425-511`, `public/index.php:717`)
   - **Issue:** Trainers saw Create User (with pre-filled `type="text"` `LightPath2026!`), CSV import, Courses, Audit, and System forms that silently failed on submit; Cohort `Reset` wiped student containers without confirmation.
   - **Remediation:** Scope `users`, `courses`, `audit`, and `system` strictly to `$auth->isAdmin($currentUser)`, replace pre-filled plaintext password input with `type="password"`, and add confirmation prompts on destructive `reset` actions.
8. **UTF-8 SQL Mojibake via `atob()` in Lesson Code Fences** (`src/Course/MarkdownParser.php:614`, `public/assets/js/app.js:654`)
   - **Issue:** PHP emitted `base64_encode()` of UTF-8 SQL; `app.js` decoded Latin-1 via raw `atob(b64)`, corrupting French accents.
   - **Remediation:** Decode Base64 bytes cleanly through `TextDecoder` + `Uint8Array`.

### Should Fix (P1)

9. **`56` Brittle `file_get_contents()` Test Locks vs Untested Broken APIs** (`tests/run_tests.php`, `tests/test_adversarial_b1..b5.php`)
   - **Issue:** `56` substring checks lock implementation phrasing, while `api_schema_inspect`, `steps_executed`, `postgres_master_pw`, `admin_update_user`, `update_profile`, `api_docker_action`, and `atob()` had zero automated checks.
   - **Remediation:** Expand QA suite with behavioral HTTP, contract, RBAC, and container assertions while keeping legacy test invariants intact until migrated in lockstep.
10. **Monolithic Controller, Heavy Interface Coupling & Hardcoded PG18 Counters** (`public/index.php` `867` lines, `JsonStorage.php` `1074` lines, `config/courses.php`, templates)
    - **Issue:** Single `index.php` handled all domains, `'module_Filter'` key had a capitalization typo, shared templates hardcoded `34 Modules` / `/20 demi-j.` / `C01–C29`, and no modular feature flag layer existed.
    - **Remediation:** Introduce `config/features.php` + `FeatureManager` (`8` flags), extract `AdminService`, normalize `'module_filter'` with `'module_Filter'` fallback, and compute course counters dynamically.
11. **Minimal 7-Table Sakila Stub, Missing `pgbench`, and Absent Docker Ops/Telemetry** (`src/Docker/DockerManager.php:458-553`, `templates/pages/admin.php:395-423`, `bin/lab-runner.php:27-50`)
    - **Issue:** Container bootstrap seeded only 1 `actor` + 1 `film` row with zero `pgbench` tables and lacked `restart`, `seed`, `logs`, `stats`, `prune`, and bulk operations.
    - **Remediation:** Create `SakilaSeeder` (12+ Sakila tables + 1,000-row `pgbench` + `lms_student` role) and expose operational controls in `DockerManager`, `public/index.php`, `admin.php`, `lab_view.php`, and `bin/lab-runner.php`.
12. **Admin Console Gaps (N+1 Query, Missing Filters, Enrollment/Progress Controls, Capped Audit)** (`templates/pages/admin.php:7-538`, `src/Core/JsonStorage.php:331-342`)
    - **Issue:** Unused `$allProgress` alongside per-user N+1 reads, no cohort/user search filters, no enrollment/reset/course-toggle controls, silent 250-entry audit cap, and no System Health panel.
    - **Remediation:** Implement `AdminService::computeCohortKpis()`, user/audit filters, `enrolled_courses` & progress reset actions, custom course toggle/delete, audit CSV export, and System Health + Feature Flags UI.
13. **`514` French Source Comment Lines & Missing Engineering Docs** (`config/`, `src/`, `bin/`, `public/`, `templates/`, `tests/`, `README.md`)
    - **Issue:** `514` French comment lines across `32` code files violated `AGENTS.md`, and `PROJECT-CONTEXT.md` / `docs/` did not exist.
    - **Remediation:** Translate all source comments across PHP, JS, CSS, Python, and tests to technical English and deliver `PROJECT-CONTEXT.md` + `docs/*.md` + gateway `README.md`.
14. **WCAG AA `--text-muted` Contrast Failures & Missing Keyboard/Form Accessibility** (`public/assets/css/app.css:15,41,317,2055,2134,2167`, `templates/layout.php:373`, `templates/pages/{admin,profile,login}.php`)
    - **Issue:** `#64748B` failed 4.5:1 AA contrast on dark/light backgrounds; missing `:focus-visible`, `prefers-reduced-motion`, skip link, `<label for>` bindings, and `aria-label` attributes.
    - **Remediation:** Upgrade `--text-muted` (`#94A3B8` dark / `#475569` light), add `:focus-visible`, reduced-motion CSS, skip link, and semantic labels.
15. **`616` Inline `style=` Attributes, Inline JS Handlers & Mobile (`<=960px` / `375px`) Layout Issues** (`templates/**/*.php`, `public/assets/css/app.css:2618-2659`)
    - **Issue:** `616` inline styles, `10` inline `on*=` attributes, and `6` `<script>` blocks cluttered templates and blocked stricter CSP; below `960px`, full-height `.app-sidebar` and locked `2fr 1fr` / `min-width: 260px` grids broke mobile usability.
    - **Remediation:** Extract utility classes into `app.css`, bind delegated events in `app.js`, auto-collapse sidebar on tablet/mobile (`<=960px` target in Wave 2, widened to `<=1024px` in Wave 4A), and stack grids cleanly at `375px`.
16. **Duplicated Inline Markdown Parser & Broken `clearCatalogCache()` Contract** (`src/Course/MarkdownParser.php:1035-1065` vs `src/Core/Security.php:102-125`, `src/Course/CourseRepository.php:41-57` vs `public/index.php:720`)
    - **Issue:** Parallel `formatInline()` / `formatInlineText()` escaping logic could diverge, and `clearCatalogCache()` returned `void` while `public/index.php` assigned its return value.
    - **Remediation:** Consolidate shared inline formatting helpers and return `int` count of deleted cache files from `clearCatalogCache()`.

### Nice to Have (P2)

17. **Non-Atomic `copy()` Fallback & Implicit Seeded Passwords** (`src/Core/JsonStorage.php:55-58,96-150`) -> Add short `rename()` retry backoff on Windows file locks and warn in System Health when default passwords/dev secrets remain.
18. **Docker Status Cache Optimistically Reports `cluster_ready`** (`src/Docker/DockerManager.php:184-187`) -> Default uncached status to `'not_checked'` with `'primary_cluster_running' => false`.
19. **Dual SCORM 1.2 Manifest Builders & Silent 250-Entry Audit Truncation** (`src/Course/DownloadController.php:234`, `src/Course/LmsCatalog.php:584-640`, `src/Core/JsonStorage.php:331`) -> Raise active audit retention to `1,000` with monthly `audit_logs_YYYYMM` rotation and align SCORM manifest logic cleanly without breaking test checks.
20. **`PdoStorage` Swallows Write Failures Silently** (`src/Core/PdoStorage.php:33-36,72-74,116-118`) -> Log runtime errors (`error_log`) and expose `getLastError(): ?string` to System Health once PDO has initialized.

---

## 3. Iteration 1 `/dev-team` 4-Role Synthesis

| Perspective | Key Observations & Blocking Concerns |
| :--- | :--- |
| **PM** | Broken Lab console feedback (`0 table(s)`, `0 bloc(s)`, `postgres_master_pw` mismatch), trainer confusion on admin tabs, and hardcoded PG18 curriculum counts undermine live workshops and multi-course readiness. |
| **Architect** | Superuser `psql`, shared primary container fallback, synchronous `@exec()` calls, and 867-line `index.php` require security/isolation boundaries and lean domain services (`FeatureManager`, `AdminService`, `SakilaSeeder`) without framework bloat. |
| **Developer** | `56` `file_get_contents()` test checks, `'pwd12345'` in `b5.php`, Windows/WSL2 Docker quirks, `514` French comments, and `616` inline styles require strict file-ownership waves to avoid merge collisions. |
| **QA** | Zero regression coverage existed on the broken P0 API contracts, RBAC 403s, or `375px` responsive layout; behavioral PHP + headless Playwright (`1440x900`, `768x1024`, `375x812`) tests are mandatory before release. |

### Key Decisions Adopted
1. Preserve `admin.php` route entry point while enforcing strict `$auth->isAdmin()` scoping for `users`, `courses`, `audit`, and `system`, keeping `cohort`, `qna`, and `docker` operational for trainers.
2. Keep existing legacy `file_get_contents()` compatibility markers intact during Wave 2 modularization while expanding behavioral HTTP/API/E2E test coverage in Wave 3.
3. Introduce lean `config/features.php` + `FeatureManager` (`8` toggles), `AdminService`, and `SakilaSeeder` with strict English source comments across all modified files.

---

## 4. Remediation Roadmap & Wave Execution Plan

| Wave | Agents | Scope & Owned Files |
| :--- | :--- | :--- |
| **Wave 1** | `auditor` | Read-only 7-axis `/ponytail-audit` + `/dev-team` synthesis (Complete — Board `#3`) |
| **Wave 2** | `sec-core`, `containers-labs`, `admin-ui`, `docs-author` | Parallel implementation across disjoint file slices: Security & Core (`Auth`, `Security`, `Storage`, `FeatureManager`), Docker & Sakila Sandbox (`SakilaSeeder`, `DockerManager`, `LabSqlRunner`, `lab-runner.php`, `lab_view.php`), Admin/Course/UI (`AdminService`, `public/index.php`, `admin.php`, `layout.php`, `app.css`, `app.js`, `src/Course/*`), and 9-doc Engineering Suite (`README.md`, `PROJECT-CONTEXT.md`, `docs/*.md`) |
| **Wave 3** | `qa-verifier` | Expand `tests/` for all P0/P1 contracts, execute `php tests/run_tests.php`, `tests/test_adversarial_b1..b5.php`, Playwright MCP E2E across `1440`/`768`/`375` viewports, `php -l`, `node --check`, English-comment check, zero-CDN & brand-hygiene check |
| **Wave 4** | Coordinator & Docs/QA | Iteration 2 audit re-verification, update `JOURNAL_DE_BORD.md` & `REGISTRE_RCA.md`, append final sign-off below, run 9-step GitHub PR/merge/issue closure |

---

## 5. Iteration 2 Re-Audit, Wave 4 Remediation & Final Sign-Off

### 5.1 Iteration 2 `/ponytail-audit` Ranked Delta (15 Residual Findings)

Following Wave 2/3 implementation across `46` tracked files (`+4463 / -1734`) plus newly introduced core services, guides, and test suites (`config/features.php`, `src/Core/FeatureManager.php`, `src/Core/AdminService.php`, `src/Docker/SakilaSeeder.php`, `PROJECT-CONTEXT.md`, `docs/*.md`, `tests/test_functional_http.php`, `tests/test_coverage_audit.php`), Iteration 2 read-only audit (`#19`) measured `php -l` passing on `48/48` PHP files (`0` errors), `node --check` passing cleanly, `0` external CDN / prohibited vendor tokens, and `0` inline `on*=` attributes in `templates/`, and identified `15` residual items (`5 P0`, `8 P1`, `2 P2`):

| ID | Sev | Finding Summary (Iteration 2 Audit `#19`) | Wave 4 Resolution & Owner |
| :--- | :---: | :--- | :--- |
| **P0-1** | P0 | `seedUserContainer()` & `getContainerLogs()` silently fell back to shared `pg_formation_primary` when student container `lp_pg18_user_<id>` was stopped, enabling cross-student reset/log leaks | Fixed (`containers-labs`, `admin-ui`): added `bool $allowSharedFallback = false`; HTTP routes pass `$auth->isStaff($currentUser)` while `bin/lab-runner.php` retains CLI `true` |
| **P0-2** | P0 | `api_submit_quiz` bypassed server-side QCM grading on unknown/empty `module_id` and saved client-forged `score`/`total` | Fixed (`admin-ui`): `public/index.php` unconditionally rejects `null` modules or empty `$parsedQuiz` with HTTP `400` |
| **P0-3** | P0 | `Auth::canAccessCourse([])` returned `true` (granting access to all courses), `admin.php` mislabeled `[]`, and `admin_add_course` allowed overriding `'postgresql-18'` | Fixed (`sec-core`, `admin-ui`): unset/`null` defaults to `['postgresql-18']`, explicit `[]` denies all courses (`Aucun cours assigné`), and `admin_add_course` rejects `'postgresql-18'` & built-in slugs |
| **P0-4** | P0 | Admin live Cohort/Users search & role filters mismatched DOM attributes (`data-filter-table` / `data-search` vs `app.js` `[data-cohort-row]`) | Fixed (`admin-ui`): bound `[data-filter-table]` and `[data-filter-role-table]` in `public/assets/js/app.js` against `#<table> tbody tr[data-search]` & `data-role` |
| **P0-5** | P0 | SQL sandbox regex missed `DO $$ EXECUTE 'SET statement_timeout = 0' $$`, concatenated `set_config(...)`, mid-line `\w`/`\o`, nested `/* /* */ */` comments, and triggered false positives on `\g` inside `DO $$ ... $$` | Fixed (`containers-labs`): added single-pass lexical scanner `LabSqlRunner::stripCommentsAndLiterals()` (`comments_stripped` vs `outside_quotes`) |
| **P1-1** | P1 | `AdminService::getSystemHealthReport()` probed phantom `dirname($dataDir) . '/cache'` and ignored `$pdoCheck->getLastError()` | Fixed (`admin-ui`): probes `parsed_cache/*.html` + `catalog_cache_*.json` and reports `$pdoCheck->getLastError()` |
| **P1-2** | P1 | Eight `app.js` `fetch()` calls omitted `&course=...` and `api_toggle_complete` skipped validating `$itemId` | Fixed (`admin-ui`): extracted `buildApiUrl()`/`postApiJson()` in `app.js` and validated `item_id` against `CourseRepository` (`400` on unknown) |
| **P1-3** | P1 | `docker_labs=false` leaked controls in `lab_view.php` & `dashboard.php`, and `FeatureManager::isEnabled()` re-read storage per call | Fixed (`sec-core`, `containers-labs`, `admin-ui`): memoized `$resolvedFlags` per request in `FeatureManager` and gated `lab_view.php` / `dashboard.php` Docker CTAs |
| **P1-4** | P1 | `admin.php` ran eager container/KPI/audit loads on every tab and ignored monthly `audit_logs_YYYYMM` archives | Fixed (`sec-core`, `admin-ui`): lazy-loaded datasets per active `admin.php` tab and added `bool $includeArchives = false` to `getAuditLogs()` (`admin.php` `200, true`; `export_audit_csv` `1000, true`) |
| **P1-5** | P1 | `DownloadController::streamFile()` lacked HTTP `416 Range Not Satisfiable` handling and remained the lone uncovered public method (`160/161`) | Fixed (`admin-ui`, `qa-browser`): extracted `DownloadController::resolveHttpRange()` (`200`/`206`/`416`) + HTTP `416` `Content-Range: bytes */<size>` response; targeted in Wave 4B coverage expansion |
| **P1-6** | P1 | CSP kept `script-src 'unsafe-inline'` for `login.php` inline `<script>` alongside legacy template `style=` attributes | Fixed for `script-src` (`sec-core`, `admin-ui`): `Security::UNLOAD_SHIM_JS` SHA-256 hash CSP (`'sha256-Y+Iy31+SIVnkv2t35OtFkyF8C43lr3XQSadODH27V58='`) drops `'unsafe-inline'` from `script-src`; `login.php` uses `assets/js/app.js` `defer` + `[data-demo-email]` (`style-src` intentionally retains `'unsafe-inline'` until remaining template `style=` attributes are removed) |
| **P1-7** | P1 | `public/index.php` and `templates/pages/admin.php` file size growth constrained by legacy `file_get_contents()` assertions | Deferred modular split beyond Wave 4 to preserve existing baseline string-anchor invariants safely while keeping per-tab lazy loading cleanly isolated |
| **P1-8** | P1 | Documentation contract drift (`{ success }`, `quiz_result`, `item_type`/`item_id`, `docker_cmd`, `broadcast_*`, `actor`, `api_chaos` `403`, `$sourceRoot/assets/mermaid_cache/`, `416`, CSP SHA-256, `canAccessCourse([])`, `$allowSharedFallback`) and pending governance logs | Fixed (`docs-author`): reconciled all 9 Markdown engineering docs, `REGISTRE_RCA.md` (`RCA-169`), and `JOURNAL_DE_BORD.md` |
| **P2-1** | P2 | Residual French comments in `tests/e2e/*.mjs`, 2 docblocks (`LabSqlRunner.php`, `MarkdownParser.php`), and `test_adversarial_b2.php`, plus partial comment scan scope | Fixed (`containers-labs`, `admin-ui`, `qa-browser`): PHP docblocks translated cleanly in Wave 4A (`COMMENT_ACCENT_HITS: 0` / `FRENCH_ACCENT_COMMENTS=0`); E2E `.mjs` & expanded audit handled in Wave 4B |
| **P2-2** | P2 | Static method-name grep coverage in `test_coverage_audit.php` needed negative HTTP + live E2E browser checks for P0/P1 invariants | Targeted in Wave 4B (`qa-browser`): expanding `run_tests.php` Section 23, `test_functional_http.php`, `test_coverage_audit.php`, and Playwright E2E |

### 5.2 Iteration 2 `/dev-team` 4-Role Delta & Iteration 1 `/browser` QA Summary

- **PM Delta:** Confirmed cohort KPIs, 8 feature toggles, full Sakila + `pgbench` seeding, and Qualiopi 6-format exports met functional scope; flagged inert admin live filters (`P0-4`), `enrolled_courses=[]` ambiguity (`P0-3`), `docker_labs` UI leaks (`P1-3`), and missing governance logs (`P1-8`) — all resolved in Wave 4A/4B.
- **Architect Delta:** Validated `FeatureManager`, `AdminService`, and `SakilaSeeder` isolation while requiring strict multi-tenant boundaries on `pg_formation_primary` (`P0-1`), `Auth::canAccessCourse([])` (`P0-3`), CSP `script-src` removal of `'unsafe-inline'` (`P1-6`), and accurate System Health probes (`P1-1`).
- **Developer Delta:** Targeted surgical Wave 4A fixes (`resolveHttpRange()`, `stripCommentsAndLiterals()`, `FeatureManager` memoization, `buildApiUrl()`, `canAccessCourse([])`, and `[data-filter-table]`) without breaking legacy source-substring invariants (`56` anchors across `run_tests.php` & `b1..b5`) or `'pwd12345'` floor.
- **QA & Iteration 1 `/browser` UX Summary:** Flagged untested negative HTTP branches (`P0-2`, `P0-3`, `P1-2`, `P1-3`, `P1-5`), Tablet `768x1024` sidebar needing collapse at `<= 1024px`, `1200–1440px` topbar flex/pill truncation polish, and `login.php` inline `<script>` removal for strict CSP — Tablet/topbar CSS+JS polish landed in Wave 4A (`@media (max-width: 1024px)`), with final Wave 4B runtime re-verification owned by `qa-browser`.

### 5.3 Verification Baseline & Wave 4 Remediation Record

- **Verified Wave 3 Baseline (Board `#16`, pre-Wave 4A):**
  - `php -l`: `48/48` PHP files (`0` errors); `node --check public/assets/js/app.js`: exit `0`.
  - `php tests/run_tests.php`: `509` passed, `0` failed (`9.01s`).
  - `php tests/test_functional_http.php`: `70` passed, `0` failed (`6.66s`, isolated `LMS_DATA_DIR` + `LMS_DOCKER_FORCE_SIMULATED=1`).
  - `php tests/test_coverage_audit.php`: `160/161` public methods (`99.4%`; `DownloadController::streamFile` resolved in Wave 4A/4B), `0` external CDNs, `0` legacy third-party brand leaks, `0` inline `on*=` attributes in `templates/`.
  - Adversarial suites (`b1..b5`): `b1` `69/69`, `b2` `50/50`, `b3` `69/69`, `b4` `78/78`, `b5` `78/78` (all `APPROVE`, exit `0`).
- **Verified Wave 4A Targeted Checks (Board `#23`, `#24`, `#25`):**
  - `sec-core`: `12/12` `php -l` passed; isolated verification passed `ALL_SEC_CORE_W4_CHECKS_PASSED` (`Auth::canAccessCourse([])`, `FeatureManager` memoization, SHA-256 `script-src` CSP, monthly `audit_logs_YYYYMM` merge).
  - `containers-labs`: `6/6` `php -l` passed; isolated verification passed `182` checks / `0` failures (`stripCommentsAndLiterals()` nested comments, `DO $$ EXECUTE`, concatenated `set_config`, mid-line `\w`/`\o`/`\g`, 34 oracles clean, `$allowSharedFallback=false` rejection, `docker_labs` UI gate); `COMMENT_ACCENT_HITS: 0`; `php bin/lab-runner.php logs 3 25 --json` exited `0` under `LMS_DOCKER_FORCE_SIMULATED=1`.
  - `admin-ui`: `6/6` `php -l` passed; `node --check public/assets/js/app.js` passed; post-edit script passed `WAVE4A_CHECKS_OK` & `TOTAL_VERIFY_ERRORS=0` (`DownloadController::resolveHttpRange()` `200`/`206`/`416`, `AdminService::getSystemHealthReport()` `parsed_cache`, `admin.php` `users` render, markers intact); `FRENCH_ACCENT_COMMENTS=0`.
- **Wave 4B QA Certification (`#30`) & Iteration 2 Live Browser Sign-Off (`#33` — 100% PASS):**
  - All `11` owned documentation and governance files (`README.md`, `PROJECT-CONTEXT.md`, `7` `docs/*.md` guides, `REGISTRE_RCA.md` `RCA-169`, `JOURNAL_DE_BORD.md`) reconciled against exact landed Wave 4A contracts (`ERRORS: []`).
  - `php -l` (`config/`, `src/`, `public/`, `templates/`, `bin/`, `tests/`): `48/48` valid (`ERRORS=0`); `node --check` (`public/assets/js/app.js`, `tests/e2e/run_playwright_campaign.mjs`, `tests/e2e/test_adversarial_challenger.mjs`): exit `0` across all 3 files.
  - `php tests/run_tests.php`: `541` passed, `0` failed (including Section 23 Wave 4 regression checks & `Auth::canAccessCourse([])`).
  - `php tests/test_functional_http.php`: `88` passed, `0` failed (isolated `LMS_DATA_DIR`, `LMS_DOCKER_FORCE_SIMULATED=1`, covering P0/P1 negative HTTP guardrails & feature flag gating).
  - `php tests/test_coverage_audit.php`: `162/162` public API methods (`100.0%`), `0` external CDNs, `0` legacy third-party brand leaks, `0` inline `on*=` attributes in `templates/`, `0` non-English source comments across PHP/JS/CSS/Python/E2E `.mjs`, strict SHA-256 `script-src` CSP `PASS`.
  - Adversarial suites (`b1..b5`): `B1` `69/69`, `B2` `50/50`, `B3` `69/69`, `B4` `78/78`, `B5` `78/78` — all `APPROVE`.
  - Iteration 2 Live Browser QA (`http://127.0.0.1:18089`, host-verified, zero console/CSP errors): verified strict SHA-256 CSP demo quick-login via external `assets/js/app.js`, Desktop `1440x900` topbar alignment (`scrollWidth === 1440`), Lab 01 credential `postgres` + Schema Explorer (`8` tables) + SQL sandbox blocking `COPY actor TO PROGRAM 'id'`, live Admin Cohort (`#admin-cohort-search`) & Users/Role (`#admin-user-search` / `#admin-user-role-filter`) filtering, System Health (`parsed_cache`, `catalog_cache`, `8` feature flags), Tablet `768x1024` default collapsed sidebar at `<= 1024px` (`scrollWidth <= 768`), and `0` third-party branding leaks.

---

## 6. Issue `#41` Deep 7-Axis Execution — Iteration A/B Audit & Final Certification

> **Scope:** Two-iteration deep 7-axis hardening, modularization, and live browser certification conducted on branch `feat/issue-41-deep-7-axis-iterations` (Issue `#41`), eliminating the final Wave 4B structural deferrals (`P1-6` strict zero-inline-style CSP and `P1-7` monolithic controller/template splits) with **zero deferred items**.

### 6.1 Iteration A Principal Audit Findings (`#40`) & Targets

| Axis | Audited Baseline (`#40`) | Issue `#41` Remediation Delivered (`P1-6` & `P1-7` 100% Resolved) |
| :--- | :--- | :--- |
| **Axis 1 — Security** | `style-src 'self' 'unsafe-inline'` (`Security.php:29`) + inline SVG `style=` (`Security.php:229`); missing `Origin`/`Referer` guard; session `gc_maxlifetime` unaligned; `changePassword()` missing session regeneration; unchained audit logs | Enforced `style-src 'self'` across all portal routes + `Security::sendStandaloneDocumentCspHeader(array $extraScriptHashes = [])` solely for inline HTML course frames (`P1-6` resolved); added `Security::verifySameOriginRequest()`; aligned `session.gc_maxlifetime` & cookie `lifetime` with `session_idle_ttl` (`7200`s default); enforced `Auth::isUserActive()` logout on suspension; added `session_regenerate_id(true)` on `changePassword()`; added SHA-256 tamper-evident `prev_hash`/`entry_hash` audit chain + `verifyAuditIntegrity()` |
| **Axis 2 — Documentation** | `9` docs reflected Issue `#39` baseline (`8` flags, `'unsafe-inline'` `style-src`, monolithic `index.php`/`admin.php`); `DEPLOYMENT_AND_OPERATIONS.md` & `DEVELOPER_AND_TESTING_GUIDE.md` absent | Created [docs/DEPLOYMENT_AND_OPERATIONS.md](DEPLOYMENT_AND_OPERATIONS.md) & [docs/DEVELOPER_AND_TESTING_GUIDE.md](DEVELOPER_AND_TESTING_GUIDE.md); reconciled `README.md`, `PROJECT-CONTEXT.md`, and all `7` existing `docs/*.md` guides (`11`-document baseline) plus `REGISTRE_RCA.md` (`RCA-170`) & `JOURNAL_DE_BORD.md` |
| **Axis 3 — Modularity** | `public/index.php` (`1,164` lines) and `templates/pages/admin.php` (`915` lines) monolithic (`P1-7`); `8` global feature flags without dependency chains, profiles, or course overrides | Split `public/index.php` (`347` lines) into thin front controller + `src/Http/{AuthController,LearnerController,LabApiController,AdminController}.php`; split `admin.php` into `7` `templates/partials/admin/tab_*.php` partials while preserving `file_get_contents()` test anchors; expanded [config/features.php](../config/features.php) & [FeatureManager.php](../src/Core/FeatureManager.php) to **10** categorized flags (`personal_notes`, `flashcards_srs`), `requires` chains, **4** deployment profiles (`full_enterprise`, `classroom_workshop`, `exam_lockdown`, `static_hosting_nodocker`), per-course overrides, and JSON config export/import |
| **Axis 4 — Admin Richness** | `AdminService` exposed `5` methods; missing at-risk detection, completion matrix, Cohort/Qualiopi exports, user suspend/reset/bulk ops, throttle unlock, presets, audit integrity badge, and fleet reaper | Added `detectAtRiskLearners()`, `computeModuleCompletionMatrix()`, `exportCohortCsv()`, `exportQualiopiJson()`, `buildAuditExportMetadata()`, user suspend/password reset/bulk ops, login throttle inspector + 1-click unlock, 1-click classroom presets, SHA-256 audit badge, and Docker fleet summary + idle reaper + chaos heal |
| **Axis 5 — Container Ops** | Lacked per-user savepoints/restore, bounded `pgbench`, idle container reaper, live PG18 diagnostics, and 34-lab readiness checks | Added `createSavepoint`/`listSavepoints`/`restoreSavepoint`/`deleteSavepoint`, bounded `runPgbenchBenchmark()`, `touchContainerActivity()`, `getFleetSummary()`, `reapIdleContainers()`, `healChaosScenario()`, `LabSqlRunner::inspectPgDiagnostics()`, `checkLabReadiness()` (`34` labs), `pg_read_all_stats` + `pg_stat_scan_tables` grants, and parity in [bin/lab-runner.php](../bin/lab-runner.php) |
| **Axis 6 — UI & Strict CSP** | **420** inline `style=` attributes (`1` in `Security.php:229`, **419** across `16` templates); narrow `.reader-grid` (`330px`) squeezed Admin `system` System Health table | Eliminated **100%** of inline `style=` attributes (`0` across `src/` & `templates/`); hydrated progress widths via `data-progress` CSSOM in `app.js`; added `.admin-split-grid` + `.table-col-nowrap` + `.lms-qr-verify-svg`; added Lab Workbench controls (`Ctrl+Enter`, Savepoints, `pgbench`, PG18 Diag, Readiness) & `[data-bulk-select-all]` |
| **Axis 7 — Code Quality & QA** | `test_coverage_audit.php` scanned `Core`/`Course`/`Docker` only (`162/162`); `test_functional_http.php` had `71` HTTP assertions | Added `src/Http` to reflection audit (`211/211` = `100.0%`), enforced `style-src 'self'` + `0` inline `style=` + ES `v`-mode `pattern=` checks in `test_coverage_audit.php`, added new docs to `$s22RequiredDocs`, expanded `test_functional_http.php` to `155` HTTP assertions, and certified live via `@playwright/mcp` |

### 6.2 Wave 1 Execution, Iteration B Delta Findings (`#59`) & Wave 3 Remediation (`#63`–`#68`)

1. **Wave 1 Modular Execution (`#46`–`#50`):** Delivered across isolated file ownership slices (`sec-mod`, `docker-ops`, `admin-http`, `ui-csp`, `docs-v2`), followed by Wave 2 QA Pass 1 (`#52`–`#54`: fixed `LabApiController` scalar savepoint name extraction & `AdminController` `chain_valid` export header; verified `637/637` unit, `142/142` HTTP, `211/211` API coverage).
2. **Iteration B Live Browser Campaign #1 & Principal Delta Re-Audit (`#58`–`#59`):** Identified `2` live browser P0 blockers plus `P1`/`P2` polish items, all resolved cleanly with zero workarounds in Wave 3 (`#63`–`#68`):
   - **P0-1 (`mode=html_preview` Inline Script CSP Hash — `sec-mod` `#65` + `admin-http` `#66`):** Static course HTML previews in `../training_postgresql/` embed deterministic Mermaid/quiz `<script>` tags (`sha256-fIvSulwFNp3RteiAZGkbvt+fvUeVJoTUB439YMvw0EA=`, etc.). Updated `Security::buildCspPolicy()` & `Security::sendStandaloneDocumentCspHeader(array $extraScriptHashes = [])` to validate & append SHA-256 digests solely on standalone HTML document streams computed dynamically in `DownloadController::streamFile()`, keeping main portal `Security::sendSecurityHeaders()` strictly locked (`script-src 'self' 'sha256-{$shimHash}'; style-src 'self'`).
   - **P0-2 (`#lab-savepoint-name` ES `v`-Mode Regex — `ui-csp` `#64`):** Escaped unescaped hyphen inside `templates/pages/lab_view.php` (`pattern="[a-zA-Z0-9_\-]{1,32}"`) so Chromium `unicodeSets` (`v`-mode) compiles without `SyntaxError`.
   - **P1 Self-Admin Lockout & Mutating API `POST` Enforcement (`admin-http` `#66`):** Guarded `$targetId === $selfId` across `admin_update_user` and `admin_delete_user` in `src/Http/AdminController.php`; enforced HTTP `405 Method Not Allowed` (`Allow: POST`) on `GET` requests to mutating `api_*` actions in `public/index.php` (`347` lines).
   - **P1/P2 Governance, Audit Filter & `pgbench` Runtime Polish (`docker-ops` `#63`, `admin-http` `#66`, `docs-v2` `#60`):** Normalized empty runtime `'prefix'` to `'docker'` via `DockerManager::runDockerCommand()` inside `runPgbenchBenchmark()`; aligned `templates/partials/admin/tab_audit.php` filter options to actual emitted action prefixes (`CLASSROOM_`, `CHAOS_`, `ATTENDANCE_`, `TRAINER_`, etc.); reconciled `session_idle_ttl` (`7200`s) across `docs/DEVELOPER_AND_TESTING_GUIDE.md`; added regression checks across `tests/run_tests.php`, `tests/test_functional_http.php`, `tests/test_coverage_audit.php`, and `tests/e2e/run_playwright_campaign.mjs`.

### 6.3 Final Wave 3 QA & Iteration B Live Browser Sign-Off (**100% PASS — 0 Deferred Items**)

- **`php -l` (`59` PHP files across `config/`, `src/` incl. `src/Http/`, `public/`, `templates/` incl. `templates/partials/admin/`, `bin/`, `tests/`):** `59 / 59` valid (`0` syntax errors).
- **`node --check` (`public/assets/js/app.js`, `tests/e2e/run_playwright_campaign.mjs`, `tests/e2e/test_adversarial_challenger.mjs`):** `3 / 3` valid (`exit 0`).
- **`python -m py_compile bin/export_slides_cache.py`:** `exit 0`.
- **`php tests/run_tests.php` (`LMS_DOCKER_FORCE_SIMULATED=1`):** **`641` passed, `0` failed** (`exit 0`, including Section 24 covering all 7-axis Wave 1/Iteration B contracts and `11/11` docs checks).
- **`php tests/test_functional_http.php` (isolated `LMS_DATA_DIR`, `LMS_DOCKER_FORCE_SIMULATED=1`):** **`155` passed, `0` failed** (`exit 0`, `0` server stderr warnings/fatals, covering strict `style-src 'self'`, standalone HTML SHA-256 CSP, HTTP `405` on mutating `GET api_*`, cross-origin `403`, admin self-lockout protection, `Range` `206`/`416`, 10-flag/profile/course-override gating, and Lab Savepoints/`pgbench`/PG18 Diag/Readiness).
- **`php tests/test_coverage_audit.php`:** **`211 / 211` public API methods (`100.0%` across `Core`, `Course`, `Docker`, `Http`)**, `0` vendor leaks, `0` external CDNs, `0` `on*=`, `0` inline `style=`, `0` invalid ES `v`-mode `pattern=` attributes, `0` non-English source comments, strict `script-src` & `style-src 'self'` CSP **`PASS`** (`exit 0`).
- **Adversarial Suites (`tests/test_adversarial_b1.php`..`b5.php`):** `B1` **`69 / 69`**, `B2` **`50 / 50`**, `B3` **`69 / 69`**, `B4` **`78 / 78`**, `B5` **`78 / 78`** — all **`APPROVE`** (`exit 0`).
- **Iteration A & Iteration B Live Browser QA (`http://127.0.0.1:18090`, host-verified):** Iteration A diagnosed `mode=html_preview` inline script CSP hash (`sha256-fIvSulwFNp3RteiAZGkbvt+fvUeVJoTUB439YMvw0EA=`) & `#lab-savepoint-name` `v`-mode regex syntax; Iteration B certified **0 CSP violations**, **0 JS console errors**, **0 horizontal overflow** across `1440x900` (Desktop), `768x1024` (Tablet), and `375x812` (Mobile), crisp `.admin-split-grid` System Health layout, updated audit category filters & SHA-256 chain integrity badge, and live Savepoint Create/Restore workflow.
- **Markdown Validation (Inline Python across all `13` owned docs):** `CHECKED_FILES=13`, `ERRORS=[]` (0 broken relative links, 0 external CDNs, 0 vendor leaks, valid Mermaid fences, compliant LaTeX math).
