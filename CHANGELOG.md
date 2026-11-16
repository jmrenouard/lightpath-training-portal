# Changelog

All notable changes to **LightPath Training Portal** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-11-16

### Added
- **MIT Open-Source Release:** Published the LMS engine under the MIT License ([LICENSE](LICENSE)) alongside [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and aligned `composer.json` / `package.json` metadata.
- **Bundled Demo Course-Pack (`examples/demo-course/`):** Included a self-contained MIT-licensed PostgreSQL 18 demo curriculum (2 modules, 7-pillar Qualiopi lab, cheatsheets, programme, Python companion examples, and MP4 Range stub) plus `LMS_COURSE_ROOT` and `LMS_PGSCRIPTS_ROOT` overrides for external course-packs.
- **1-Command Docker Deployment:** Added [Dockerfile](Dockerfile) (non-root `lms` user on `php:8.3-cli-alpine3.21`), [docker-compose.yml](docker-compose.yml), [.dockerignore](.dockerignore), and [.env.example](.env.example).
- **GitHub Actions CI:** Added [.github/workflows/ci.yml](.github/workflows/ci.yml) running PHP lint, JS/MJS syntax checks, `tests/run_tests.php`, adversarial suites `B1`–`B5`, `tests/test_functional_http.php`, and `tests/test_coverage_audit.php`.
- **Qualiopi 7-Pillar LMS Capabilities:** 15-question positioning diagnostic, dual-signed half-day attendance (`J1_AM`..`J11_PM`), 40-question timed certification exam (`75%` pass threshold), CSPRNG certificates (`LP-PG18-CERT-...`) with offline SVG QR verification, hot and cold (`J+60`) satisfaction surveys, formula-safe OPCO/Cohort CSV & Qualiopi JSON exports, SCORM 1.2 `.zip` packaging, SRS flashcards, glossary, personal notes, Q&A, DBA gamification, and 10 categorized feature flags across 4 deployment profiles.
- **Per-User PostgreSQL 18 Docker Workbench:** Isolated `lp_pg18_user_<id>` containers with Sakila/`pgbench` seeding, `lms_student` least-privilege SQL sandbox, savepoints, bounded `pgbench`, PG18 diagnostics, lab readiness checks, idle fleet reaper, and chaos heal scenarios (`cloud_simulated` fallback when Docker is absent).

### Security
- **First-Boot Credential Hardening:** Removed hardcoded login defaults; `JsonStorage::ensureDefaultUsers()` now reads `LMS_INIT_ADMIN_PASSWORD` / `LMS_INIT_TRAINER_PASSWORD` / `LMS_INIT_STUDENT_PASSWORD` or generates cryptographically random credentials written once to `storage/data/initial_admin_credentials.txt` (`0600`), and CSV user imports generate random passwords when omitted.
- **Safe Authentication Defaults:** Set `demo_quick_login` to `false` by default in `config/features.php` and removed prefilled credentials from `templates/pages/login.php`.
- **Strict Zero-Inline-Style CSP:** Enforced `style-src 'self'` and `script-src 'self' 'sha256-...'` (with per-document SHA-256 script digests for standalone HTML previews), `verifySameOriginRequest()`, HTTP `405` rejection on mutating `GET` requests, persistent `(email, ip)` & `ip` login throttling, `Auth::isUserActive()` session revocation, and SHA-256 tamper-evident audit hash chaining (`prev_hash` / `entry_hash`).

### Changed
- Raised minimum PHP requirement to `>= 8.3` with modular `src/Http/*` controllers and lazy-loaded `templates/partials/admin/tab_*.php` views.
- Archived internal historical engineering logs (`REGISTRE_RCA.md`, `JOURNAL_DE_BORD.md`) under [docs/internal/](docs/internal/).
