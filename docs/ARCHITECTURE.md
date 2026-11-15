# System Architecture — LightPath Training Portal

> **Scope:** Runtime topology, request dispatch, persistence drivers, course-pack engine, Docker lab bridge, and self-hosted UI architecture for `lightpath-training-portal`.

---

## 1. System Topology

The LMS runs as a monolithic-friendly, domain-modular PHP 8.1+ web application backed either by atomic local JSON stores or a PDO database, consuming an external multi-track Markdown/Slide course pack (`../training_postgresql`) and optionally orchestrating per-student PostgreSQL 18 Docker containers.

```mermaid
flowchart LR
    U["Browser / Trainee / Trainer / Admin"] -->|"HTTP / HTTPS (Sessions + CSRF)"| FC["public/index.php (Front Controller)"]
    FC -->|"Auth & RBAC"| CORE["src/Core (Auth, Security, FeatureManager, AdminService, Gamification)"]
    FC -->|"Content & Exports"| COURSE["src/Course (CourseRepository, MarkdownParser, LmsCatalog, DownloadController)"]
    FC -->|"Labs & Oracles"| DOCKER["src/Docker (DockerManager, LabSqlRunner, SakilaSeeder)"]
    CORE -->|"StorageInterface"| STORE["JsonStorage / PdoStorage (storage/data or lms_kv_store)"]
    COURSE -->|"Read & Fingerprint"| CP["Course Pack (../training_postgresql + assets/mermaid_cache)"]
    COURSE -->|"Self-Hosted Assets"| ASSETS["public/assets (CSS/JS, slides_cache, mermaid.min.js)"]
    DOCKER -->|"proc_open (Timeout)"| ENG["Docker Engine / WSL2 / cloud_simulated"]
    ENG -->|"Isolated DB (Port 5500 + userId)"| PG["lp_pg18_user_<id> (PostgreSQL 18 + Sakila + pgbench)"]
```

---

## 2. Bootstrap & Request Lifecycle

Every HTTP request and CLI utility loads [src/bootstrap.php](../src/bootstrap.php):
1. Configures timezone to `Europe/Paris`.
2. Registers an anonymous PSR-4 autoloader mapping namespace prefix `LightPath\LMS\` to `src/`.
3. For web SAPI requests (not CLI):
   - Hardens PHP session parameters (`session.use_strict_mode=1`, `session.use_only_cookies=1`, `cookie_lifetime=604800`, `httponly=true`, `samesite=Lax`, and TLS-conditional `secure`).
   - Starts the PHP session and emits hardened HTTP headers via `Security::sendSecurityHeaders()`.

### Front Controller Dispatch ([public/index.php](../public/index.php))

```mermaid
flowchart TD
    REQ["Incoming Request"] --> INIT["Instantiate Storage, Auth, FeatureManager, AdminService, CourseRepository, DockerManager"]
    INIT --> ROUTE{"Determine Action / Page"}
    ROUTE -->|"Public / Auth"| AUTH_ACT["login (incl. [data-demo-email]), register, logout, page=verify"]
    ROUTE -->|"Downloads & Exports (GET)"| DL["download, course_asset (HTTP 206 / 416), export_notes_md, export_scorm_manifest, export_scorm_zip, export_attendance_csv, export_qualiopi_csv, export_audit_csv"]
    ROUTE -->|"AJAX JSON APIs (api_*)"| API["CSRF Verify + RBAC Check -> JSON Payload (Lab SQL, Schema, Oracle, Docker, Heartbeat, Quiz, Q&A, Search, Chaos)"]
    ROUTE -->|"Authenticated POST Forms"| POST["Profile, Diagnostic, Exam, Attendance, Satisfaction, Classroom State, Admin Actions"]
    ROUTE -->|"HTML Page Render"| VIEW["Load Course Context -> Check enrolled_courses -> templates/layout.php + templates/pages/*.php"]
```

When serving `api_*` endpoints or `download`/`course_asset` streams, the front controller calls `session_write_close()` where appropriate or isolates heavy Docker/file I/O so concurrent AJAX heartbeats and PDF/MP4 streams do not lock the PHP session across tabs.

---

## 3. Domain Layer Organization

| Domain | Classes | Responsibilities |
| :--- | :--- | :--- |
| **Security & Governance** | `Security`, `Auth`, `FeatureManager` | CSRF tokens, strict CSP SHA-256 headers, path & CSV sanitization, bcrypt auth, role allowlists (`VALID_ROLES`), `enrolled_courses` enforcement (`[]` = 0 courses), storage-backed brute-force throttling, per-request memoized runtime feature flags (`$resolvedFlags`) |
| **Administration** | `AdminService` | Course-scoped Cohort KPI aggregation (zero N+1), user/audit filtering, CSV audit export, System Health probes (PHP, PDO + `getLastError()`, `parsed_cache` / `catalog_cache`, Docker, courses) |
| **Persistence** | `StorageInterface`, `JsonStorage`, `PdoStorage` | Atomic key-value store across 14+ domain collections (users, progress, courses, audit, diagnostic, exam, time, attendance, satisfaction, cold satisfaction, certificates, bookmarks, flashcards, discussions, classroom state, login throttles, feature flags) |
| **Pedagogy & Gamification** | `Gamification`, `LmsCatalog` | XP calculation, 5 DBA ranks, 8 achievement badges, 15-question diagnostic, 40-question final exam across 5 DBA domains, 53 SRS flashcards, 56 glossary entries, SCORM 1.2 XML |
| **Content & Streaming** | `CourseRepository`, `MarkdownParser`, `DownloadController` | Course discovery, fingerprint cache invalidation, Markdown-to-HTML rendering, 5 GitHub alerts, slide deck extraction, 7-pillar Enonce/Corrige split, `resolveHttpRange()` HTTP `206` / `416` streaming, ZIP/SCORM packaging |
| **Container Labs** | `DockerManager`, `LabSqlRunner`, `SakilaSeeder` | WSL2/native Docker orchestration with `proc_open` timeouts, container resource limits, `lms_student` non-superuser SQL sandbox, schema inspector, 34 deterministic lab oracles, full Sakila + 1,000-row `pgbench` seeding |

---

## 4. Dual Persistence & Concurrency Design

`StorageInterface` decouples domain services from storage implementation. Both drivers guarantee atomic read-modify-write semantics through `mutateFile(string $name, callable $mutator, mixed $default = [])`.

```mermaid
sequenceDiagram
    participant Caller as Auth / JsonStorage / Index
    participant Lock as Sidecar .lock / DB Row Lock
    participant Store as *.json File / lms_kv_store Table
    Caller->>Lock: Acquire EXCLUSIVE Lock (flock LOCK_EX / SELECT ... FOR UPDATE)
    Caller->>Store: Read Current Payload (or Default Seed)
    Caller->>Caller: Execute Pure Mutator Closure ($current -> $next)
    Caller->>Store: Atomic Write (.tmp + rename backoff / UPSERT)
    Caller->>Lock: Release Lock (LOCK_UN / COMMIT)
```

### Driver Comparison

| Aspect | `JsonStorage` (`LMS_STORAGE_DRIVER=json`) | `PdoStorage` (`LMS_STORAGE_DRIVER=pdo`) |
| :--- | :--- | :--- |
| **Locking Mechanism** | `fopen('<name>.lock', 'c+')` + `flock($fp, LOCK_EX)` | `PDO::beginTransaction()` + `SELECT payload FROM lms_kv_store WHERE store_key = :k FOR UPDATE` |
| **Write Atomicity** | Write to `.tmp.<pid>.<uniqid>`, `rename()` with short Windows backoff retry | PostgreSQL/SQLite `ON CONFLICT (store_key) DO UPDATE`; MySQL `ON DUPLICATE KEY UPDATE` |
| **Audit Log Lifecycle** | Active window capped at `1,000` entries; overflow rotated to `audit_logs_YYYYMM`; `getAuditLogs($limit, true)` merges archives newest-first | Identical logical keys and monthly archive merging in `lms_kv_store` |
| **Failure Handling** | Corruption backup before default reset; returns explicit status | Falls back gracefully if DB unreachable at boot; logs & retains `getLastError()` on runtime write failures |

---

## 5. Course-Pack Discovery & Multi-Layer Caching

To maintain sub-50ms page loads across 34 long-form modules, 34 labs, 69 cheatsheets, and 35 `pgscripts`, parsing is memoized across two cache layers keyed by file modification times:

1. **Course Catalog Cache (`storage/data/catalog_cache_<slug>.json`):**
   - Managed by `CourseRepository::loadCourse()` and invalidated automatically via `computeCatalogFingerprint()` (`v8_rca164` + directory and `.md` mtime checksums) or manually via `clearCatalogCache(): int` from the Admin System panel.
2. **Parsed Markdown Cache (`storage/data/parsed_cache/*.json`):**
   - `MarkdownParser::parseModuleFile()` caches rendered HTML, Table of Contents, extracted quiz QCMs, and slide metadata keyed by `md5('mod_v8_rca158:' . $courseSlug . ':' . $mdPath . ':' . $mtime . ':' . $manifestMtime)`.
   - `MarkdownParser::parseLabFile()` caches full, Enonce-only, Corrige-only HTML/TOC plus extracted SQL code snippets keyed by `md5('lab_v9_rca158:' . $courseSlug . ':' . $mdPath . ':' . $mtime)`.
3. **Static Visual Caches (`public/assets/` & `$sourceRoot/assets/`):**
   - `public/assets/slides_cache/<module_id>/slide_NNN.png` + `manifest.json` generated offline by [bin/export_slides_cache.py](../bin/export_slides_cache.py) for 1:1 Studio slide alignment.
   - `$sourceRoot/assets/mermaid_cache/mermaid_<sha256_first16>.png` Retina 2x pre-rendered course-pack diagrams resolved in `MarkdownParser::renderMermaidBlock()` (via `resolveAssetUrl()` or `?action=course_asset`), falling back to local `public/assets/mermaid.min.js` when uncached.

---

## 6. Frontend & Responsive UI Architecture

- **Design Tokens (`public/assets/css/app.css`):**
  - Brand Palette: Dark theme Obsidian `#0B1120`, Navy `#0F172A`, Surface `#1E293B`, LightPath Cyan `#00B0F0` / `#38BDF8`; Light theme Slate `#F8FAFC` / `#F1F5F9`.
  - **WCAG 2.1 AA Compliance:** `--text-muted` uses `#94A3B8` on dark surfaces (`>= 6.9:1` on `#0F172A`) and `#475569` on light surfaces, accompanied by `:focus-visible` outlines, `@media (prefers-reduced-motion: reduce)`, skip-to-content navigation, and explicit `<label for>`/`aria-label` bindings.
  - **Numeric Precision:** All KPI counters, latency timings, TPS rates, XP metrics, and cohort tables enforce `font-variant-numeric: tabular-nums`.
- **Workspace Shell (`templates/layout.php`):**
  - Dual collapsible sidebars (left curriculum navigation + module filter `#sidebar-module-filter`; right sticky contextual Table of Contents with ScrollSpy `updateScrollSpy` / `centerElementInScrollBox`).
  - `body { overflow-x: clip }` preserves `position: sticky` behavior on the right sidebar without horizontal overflow, while sidebars auto-collapse at `<= 1024px` (`@media (max-width: 1024px)`) and grids stack cleanly down to `375x812` mobile viewports.
- **Client Interactivity (`public/assets/js/app.js`):**
  - Vanilla ES6 module-free script loaded with `defer` (including on `login.php`, eliminating inline `<script>` tags under strict CSP) handling dark/light theme + logo sync (`syncThemeLogos`), `[data-demo-email]` quick-login, `buildApiUrl()`/`postApiJson()` helpers, `data-filter-table` client search, dual slide viewer (`switchSlideEngine`), SQL workbench Base64 UTF-8 transport via `TextDecoder` + `Uint8Array`, schema browser rendering, and delegated event handlers.
