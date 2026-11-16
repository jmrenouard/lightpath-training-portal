# LightPath Training Portal — Technical Gateway

[![CI](https://github.com/jmrenouard/lightpath-training-portal/actions/workflows/ci.yml/badge.svg)](https://github.com/jmrenouard/lightpath-training-portal/actions/workflows/ci.yml)

> **Organization:** LightPath SARL (`https://www.lightpath.fr` | `contact@lightpath.fr`)  
> **Lead Instructor & Author:** Jean-Marie Renouard (Database & DataOps Expert, Co-developer of MySQLTuner)  
> **License:** MIT License ([LICENSE](LICENSE)) — LMS Engine & Bundled Demo Course-Pack  
> **Stack:** Zero-dependency PHP `>= 8.3` • Atomic JSON / Transactional PDO • Docker / WSL2 PostgreSQL 18 Sandbox • Self-hosted Studio Assets (`Zero External CDNs`) • Qualiopi / Datadock / SCORM 1.2

---

## 1. Platform Overview

**LightPath Training Portal** (`LightPath Academy v2.0.0`, Ref. `LP-LMS-QUALIOPI-2026`) is an industrial-grade Learning Management System purpose-built for hands-on technical certifications. It boots out-of-the-box with the MIT-licensed [examples/demo-course/](examples/demo-course/) pack (`2` modules, `1` 7-pillar Qualiopi lab, cheatsheets, Python examples, and MP4 streaming stub) and scales seamlessly to full multi-track commercial curricula—such as the 4-track **PostgreSQL 18** curriculum (`34` modules, `34` 7-pillar Qualiopi practical labs, `69` cheatsheets, `18` Qualiopi reference documents, `35` pure-Python `pgscripts`, multi-language client examples, and synchronized 16:9 Studio slide decks)—mounted via `LMS_COURSE_ROOT`.

### System Architecture

```mermaid
flowchart LR
    Learner["Learner / Trainer / Admin"] -->|"HTTP/HTTPS + CSRF + Same-Origin"| Index["public/index.php (Front Controller)"]
    Index -->|"Domain Dispatch"| Http["src/Http (AuthController, LearnerController, LabApiController, AdminController)"]
    Http -->|"Auth & RBAC"| Core["src/Core (Auth, Security, FeatureManager, AdminService, Gamification)"]
    Http -->|"Markdown & Exports"| Course["src/Course (CourseRepository, MarkdownParser, LmsCatalog, DownloadController)"]
    Http -->|"Labs & Oracles"| Docker["src/Docker (DockerManager, LabSqlRunner, SakilaSeeder)"]
    Core -->|"StorageInterface"| Persistence["JsonStorage / PdoStorage (storage/data or lms_kv_store)"]
    Course -->|"Reads"| Pack["Course Pack (LMS_COURSE_ROOT / ../training_postgresql / examples/demo-course)"]
    Docker -->|"proc_open + Quotas"| Pg["lp_pg18_user_{id} (PostgreSQL 18 + Sakila + pgbench)"]
```

---

## 2. Two-Minute Quickstart

### A. 1-Command Docker Compose (Standalone Out-of-the-Box)
Build and launch the non-root PHP `8.3` CLI container on `http://localhost:8080` with persistent storage and the bundled [examples/demo-course/](examples/demo-course/) pack:

```bash
cp .env.example .env   # optional: customize environment variables
docker compose up -d
```

Docker Compose defaults `LMS_DOCKER_FORCE_SIMULATED=1` and mounts the named volume `lms_data` at `/app/storage/data`.

### B. Zero-Config Local PHP Server (`examples/demo-course/`)
Start the built-in PHP development server directly with zero Composer or Node runtime dependencies—JSON stores and the bundled demo course-pack load automatically on first request:

```bash
php -S localhost:8080 -t public
```

Or via Composer script:

```bash
composer serve
```

### C. Mounting an External Course-Pack (`LMS_COURSE_ROOT`)
By default, [config/courses.php](config/courses.php) resolves `LMS_COURSE_ROOT` if set, then an adjacent `../training_postgresql` checkout if present, and otherwise falls back to `examples/demo-course/`:

```bash
export LMS_COURSE_ROOT=/path/to/training_postgresql
export LMS_PGSCRIPTS_ROOT=/path/to/pgscripts
php -S localhost:8080 -t public
```

With Docker Compose, bind-mount the external pack read-only into `/course-pack` and set `LMS_COURSE_ROOT=/course-pack`.

### D. Production Relational PDO Mode (`pgsql` / `mysql` / `sqlite`)
Point `LMS_STORAGE_DRIVER=pdo` to PostgreSQL, MySQL, or SQLite (`lms_kv_store` table is auto-created on boot):

```bash
export LMS_STORAGE_DRIVER=pdo
export LMS_PDO_DSN="pgsql:host=127.0.0.1;port=5432;dbname=lightpath_lms"
export LMS_PDO_USER=postgres
export LMS_PDO_PASS="<strong-password>"
php -S 0.0.0.0:8080 -t public
```

### E. Docker Classroom Labs & Simulated Fallback
Each authenticated user can launch an isolated PostgreSQL 18 lab container (`lp_pg18_user_<id>`, port `5500 + userId`) seeded automatically via [SakilaSeeder.php](src/Docker/SakilaSeeder.php) with **12 Sakila tables**, **4 `pgbench` tables (`1,000` accounts)**, **4 diagnostic extensions**, and restricted non-superuser role `lms_student`. Non-staff learners cannot fall back to the shared `pg_formation_primary` cluster during web seeding or log inspection (`$allowSharedFallback = false`).

- **Windows WSL2 hosts:** Set `LMS_WSL_DISTRO=Ubuntu-24.04` (default in [config/app.php](config/app.php)).
- **Shared hosting / CI / Docker Compose without DinD:** Automatically uses `cloud_simulated` mode with static Sakila/PG18 schema catalog; force simulated mode explicitly via `LMS_DOCKER_FORCE_SIMULATED=1`.

### First-Boot Credentials & Authentication Defaults (`JsonStorage::ensureDefaultUsers()`)
No static default passwords are hardcoded in the repository. On first boot when `users.json` does not yet exist:

1. **Environment override (recommended for automation/Docker):** Set `LMS_INIT_ADMIN_PASSWORD` (and optionally `LMS_INIT_TRAINER_PASSWORD`, `LMS_INIT_STUDENT_PASSWORD`) before starting the server.
2. **Auto-generated one-time bootstrap file:** If an environment password is unset, `JsonStorage::ensureDefaultUsers()` generates a cryptographically random 24-hex-character password (`bin2hex(random_bytes(12))`) per unconfigured account and writes the credentials once to `storage/data/initial_admin_credentials.txt` (or `$LMS_DATA_DIR/initial_admin_credentials.txt`, mode `0600`). Rotate passwords and delete the bootstrap file after initial sign-in.
3. **Demo quick-login disabled by default:** `demo_quick_login` defaults to `false` in [config/features.php](config/features.php); 1-click demo role buttons are hidden on the login screen unless explicitly enabled by an administrator.

| Role | Default Email | Scope |
| :--- | :--- | :--- |
| `admin` | `jmrenouard@lightpath.fr` | Full platform control, users, enrollments, courses, audit CSV, feature flags, system health |
| `trainer` | `formateur@lightpath.fr` | Cohort KPIs, Q&A moderation, classroom locks/broadcast, attendance countersign, OPCO/SCORM exports, Docker lab ops |
| `student` | `stagiaire@lightpath.fr` | Enrolled courses, modules, quizzes, isolated Docker SQL labs, diagnostic, attendance, exam, satisfaction |

---

## 3. Role & Feature Matrix

| Module / Capability | `student` | `trainer` | `admin` | Notes |
| :--- | :---: | :---: | :---: | :--- |
| Course catalog, modules, PDF/HTML/MP4 playback | Enrolled | All | All | HTTP `206` / `416` byte-range streaming + `Auth::canAccessCourse()` (`[]` = 0 courses) + standalone HTML frame CSP |
| Sandboxed SQL workbench, savepoints, `pgbench`, PG18 Diag, lab readiness | Own container | Own + Cohort | Own + Cohort | `stripCommentsAndLiterals()` guard + `lms_student` (`statement_timeout=15000`, `pg_read_all_stats`), `Ctrl+Enter` execution |
| Classroom broadcast, 1-click presets, `lock_solutions`, `lock_exam` | Read banner | Full control | Full control | Persisted per course slug (`lecture`, `guided_lab`, `exam_lockdown`, `open_review`) |
| Half-day Attendance (`J1_AM`..`J11_PM`) & OPCO/Cohort/Qualiopi Exports | Sign own slots | Countersign + Exports | Countersign + Exports | Formula-safe `;`-delimited UTF-8 BOM CSV + structured Qualiopi JSON dossier |
| Trainer Cockpit (`cohort`, `qna`, `docker` partials) | `403` | Full access | Full access | Lazy-loaded per tab (`tab_*.php`), KPI strip (`tabular-nums`), at-risk learners, fleet reaper & chaos heal |
| Admin Control Plane (`users`, `courses`, `audit`, `system` partials) | `403` | `403` (Hidden) | Full access | Suspend/reset/bulk ops, throttle unlock, SHA-256 audit hash-chain check, 10 flags/4 profiles/per-course overrides, `.admin-split-grid` System Health |

---

## 4. Modular Feature Flags ([config/features.php](config/features.php))

Managed at runtime through [FeatureManager.php](src/Core/FeatureManager.php) and **Administration -> Système** (`action=admin_save_features`), supporting `requires` dependency enforcement, **4 deployment profiles** (`full_enterprise`, `classroom_workshop`, `exam_lockdown`, `static_hosting_nodocker`), **per-course overrides**, and **JSON config export/import**:

| Flag Key | Default | Category | Requires | Controlled Capability |
| :--- | :---: | :--- | :---: | :--- |
| `self_registration` | `true` | Authentification & Accès | — | Self-service account creation on login page |
| `demo_quick_login` | `false` | Authentification & Accès | — | 1-click Demo role sign-in buttons on login page |
| `docker_labs` | `true` | Laboratoires & Pratique | — | Per-user Docker PostgreSQL 18 containers, savepoints, bounded `pgbench`, PG18 diagnostics, readiness & SQL console |
| `chaos_engineering` | `true` | Laboratoires & Pratique | `docker_labs` | Controlled failure injection scenarios (`bloat`, `lock`, `idle_in_tx`, `wal_pressure`) |
| `gamification` | `true` | Pédagogie & Engagement | — | XP scoring, 5 DBA grades, and 8 achievement badges |
| `qna_forum` | `true` | Pédagogie & Engagement | — | Module/lab Q&A threads and trainer resolution workflow |
| `personal_notes` | `true` | Pédagogie & Engagement | — | Learner personal notes editor (`notes`) and Markdown export (`export_notes_md`) |
| `flashcards_srs` | `true` | Pédagogie & Engagement | — | Active recall SRS flashcards (`flashcards`) and technical glossary |
| `scorm_export` | `true` | Conformité & Exports | — | SCORM 1.2 `imsmanifest.xml` & standalone `.zip` exports |
| `cold_satisfaction_j60` | `true` | Conformité & Exports | — | Qualiopi J+60 post-training impact evaluation workflow |

---

## 5. CLI Utilities

| Command | Purpose |
| :--- | :--- |
| `php bin/lab-runner.php <cmd> [args] [--json]` | Manage lab containers: `list`, `fleet`, `status <uid>`, `start <uid>`, `stop <uid>`, `restart <uid>`, `reset <uid>`, `seed <uid> [dataset]`, `logs <uid> [tail]`, `stats <uid>`, `oracle <uid> <lab_id>`, `savepoint <uid> <name\|list\|create\|restore\|delete>`, `restore <uid> <name> [db]`, `bench <uid> [clients] [duration] [db] [--select-only]`, `diag <uid> [db]`, `readiness <uid> <lab_id>`, `reap-idle [secs]`, `heal [course]`, `stop-all`, `prune` |
| `php bin/sync-course.php <slug> <source-dir>` | Validate source directory and write `storage/courses/<slug>/manifest.json` |
| `python bin/export_slides_cache.py` | Export 135 DPI Studio 16:9 slide PNGs + speaker notes `manifest.json` for course-pack modules (`LMS_COURSE_ROOT` or `../training_postgresql`) |

---

## 6. Testing, Adversarial QA & Git Hooks

### PHP Syntax, Unit & Adversarial Suites
```bash
# Run core TDD, functional HTTP, and reflection/CSP coverage audit together
composer test:all

# Or execute each tier individually
php tests/run_tests.php
php tests/test_functional_http.php
php tests/test_coverage_audit.php

# Adversarial regression & security suites (B1 through B5)
for f in tests/test_adversarial_b*.php; do php "$f"; done
```

### Browser E2E Verification (`@playwright/mcp` JSON-RPC)
Uses isolated `LMS_DATA_DIR` temporary storage and [config/playwright-mcp.config.json](config/playwright-mcp.config.json) across `1440x900`, `768x1024`, and `375x812` viewports:

```bash
npm run test:e2e
npm run test:e2e:adversarial
```

### Mandatory Git Hooks Setup
Activate [.githooks/pre-commit](.githooks/pre-commit) and [.githooks/pre-push](.githooks/pre-push) to block direct commits/pushes to `main` and enforce passing tests prior to commit:

```bash
git config core.hooksPath .githooks
```

---

## 7. Qualiopi, Datadock & SCORM 1.2 Compliance

- **Complete Qualiopi Evidence Trail:** `15`-question positioning diagnostic -> half-day attendance (`J1_AM`..`J11_PM`) with dual trainee/trainer signatures -> formative module quizzes + oracle-verified 7-pillar labs -> `40`-question certification exam (`75%` pass threshold) -> CSPRNG certificate `LP-PG18-CERT-<uid>-<8HEX>` with offline SVG QR verification (`page=verify`) -> Hot & Cold (`J+60`) satisfaction surveys -> Formula-safe OPCO/Qualiopi CSV & SCORM 1.2 exports.
- **Accessibility & Brand Discipline:** WCAG 2.1 AA `--text-muted` contrast (`#94A3B8` dark / `#475569` light), keyboard `:focus-visible`, reduced-motion support, `tabular-nums`, self-hosted assets, zero external CDNs, and exclusive LightPath SARL branding.

---

## 8. Engineering Documentation Index

| Document | Contents |
| :--- | :--- |
| [PROJECT-CONTEXT.md](PROJECT-CONTEXT.md) | Developer/agent onboarding baseline, storage & environment contracts, baseline test guardrails, 9-step GitHub workflow |
| [SECURITY.md](SECURITY.md) | Vulnerability disclosure policy (`contact@lightpath.fr`), 48h SLA, supported versions |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Contribution guidelines, PHP `>= 8.3` zero-dependency rules, strict CSP, English comment policy, PR checklist |
| [CHANGELOG.md](CHANGELOG.md) | Release notes following Keep a Changelog (`2.0.0`) |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Mermaid topology, `src/Http/*` controllers & admin partials, dual `JsonStorage`/`PdoStorage` concurrency, caching, strict zero-inline-style UI |
| [docs/SECURITY.md](docs/SECURITY.md) | Threat model, `style-src 'self'` CSP + standalone doc CSP, `verifySameOriginRequest()`, `isUserActive()`, SHA-256 tamper-evident audit hash chain, SQL sandbox |
| [docs/ADMIN_AND_TRAINER_GUIDE.md](docs/ADMIN_AND_TRAINER_GUIDE.md) | Role matrix, Trainer cockpit (at-risk learners, matrix, presets, Cohort/Qualiopi exports), Admin control plane (10 flags, 4 profiles, per-course overrides, `.admin-split-grid` health) |
| [docs/DOCKER_LABS_GUIDE.md](docs/DOCKER_LABS_GUIDE.md) | Per-user container topology, savepoints, bounded `pgbench`, PG18 diagnostics, lab readiness, idle reaper, chaos heal, `bin/lab-runner.php` |
| [docs/COURSE_PACK_GUIDE.md](docs/COURSE_PACK_GUIDE.md) | `config/courses.php` registry, per-course overrides, course-pack layout, Markdown/alerts/Mermaid rules, 7-pillar labs, `export_slides_cache.py`, `sync-course.php` |
| [docs/API_REFERENCE.md](docs/API_REFERENCE.md) | Every `api_*`, download/export, authenticated form, and admin action with auth, CSRF, same-origin, parameter, and JSON contract details |
| [docs/DEPLOYMENT_AND_OPERATIONS.md](docs/DEPLOYMENT_AND_OPERATIONS.md) | Production/workshop runbook: PHP 8.3+ extensions, Nginx/Apache strict CSP compatibility, `JsonStorage` vs `PdoStorage`, cron reaper/heal, audit verification, backup/restore |
| [docs/DEVELOPER_AND_TESTING_GUIDE.md](docs/DEVELOPER_AND_TESTING_GUIDE.md) | Developer onboarding (`src/Http/*`, `templates/partials/admin/*`), zero-inline-style/strict-English standards, 6-tier PHP & Playwright test pyramid |
| [docs/QUALITY_AUDIT_PONYTAIL.md](docs/QUALITY_AUDIT_PONYTAIL.md) | Deep 7-axis `/ponytail-audit` & `/dev-team` findings, remediation roadmap, Issue `#39` sign-off ledger, and Issue `#41` Iteration A baseline |
| [docs/internal/REGISTRE_RCA.md](docs/internal/REGISTRE_RCA.md) | Internal engineering Root Cause Analysis ledger (`RCA-LMS-01`..`RCA-LMS-41`) |
| [docs/internal/JOURNAL_DE_BORD.md](docs/internal/JOURNAL_DE_BORD.md) | Internal engineering changelog and test verification ledger |

---

## 9. License & Courseware Scope

- **Open-Source LMS Engine & Demo Pack (`MIT`):** The LightPath Training Portal engine (`src/`, `public/`, `templates/`, `bin/`, `config/`, `tests/`) and the bundled [examples/demo-course/](examples/demo-course/) curriculum are licensed under the [MIT License](LICENSE) (`Copyright (c) 2026 LightPath SARL (Jean-Marie Renouard)`).
- **Commercial Courseware Repositories:** Separate full-scale training repositories (such as LightPath SARL's proprietary `training_postgresql` curriculum) are **not** included in this repository and remain governed by their own commercial licensing terms when mounted via `LMS_COURSE_ROOT`.
