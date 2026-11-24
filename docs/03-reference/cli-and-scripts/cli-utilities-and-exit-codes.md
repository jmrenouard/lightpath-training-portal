# CLI Utilities, Build Scripts, and Exit Codes

Reference specification for command-line tools under `bin/`, documentation build scripts under `scripts/`, Composer and npm scripts, and automated verification runners under `tests/`.

## 1. Docker Lab Fleet CLI (`bin/lab-runner.php`)

Executes `DockerManager` and `LabSqlRunner` operations directly on the host with `$allowSharedFallback = true`. Append `--json` to any subcommand for machine-readable JSON output.

### Subcommands

| Command | Positional Arguments | Optional Flags | Behavior |
| :--- | :--- | :--- | :--- |
| `list` | - | `--json` | Lists all managed `lp_pg18_user_*` and `pg_formation_*` containers. |
| `fleet` | - | `--json` | Emits `DockerManager::getFleetSummary()` (mode, container counts, idle metrics, primary cluster status). |
| `status` | `<uid>` (default `1`) | `--json` | Inspects container state and connection details for `<uid>`. |
| `start` | `<uid>` | `--json` | Starts or provisions `lp_pg18_user_<uid>` on port `5500 + uid` and bootstraps schemas. |
| `stop` | `<uid>` | `--json` | Stops `lp_pg18_user_<uid>`. |
| `restart` | `<uid>` | `--json` | Restarts `lp_pg18_user_<uid>` and re-verifies schema readiness. |
| `reset` | `<uid>` | `--json` | Force-removes `lp_pg18_user_<uid>` and provisions a fresh container. |
| `seed` | `<uid>` `[all\|sakila\|pgbench]` | `--no-reset`, `--json` | Seeds Sakila (12 tables) and/or pgbench (4 tables) into `formation_pg` and `dvdrental`. |
| `logs` | `<uid>` `[tail]` (default `60`, clamped `10..200`) | `--json` | Displays trailing container stdout/stderr logs. |
| `stats` | `<uid>` | `--json` | Captures non-streaming CPU, memory, and PID metrics (`docker stats --no-stream`). |
| `oracle` | `<uid>` `<lab_id>` | `--json` | Executes built-in Qualiopi SQL oracle for `<lab_id>` (`01..29`, `sql_01..sql_05`). |
| `savepoint` | `<uid>` `<name\|list\|create\|restore\|delete>` `[name_or_db]` `[db]` | `--db=<db>`, `--json` | Lists, creates, restores, or deletes compressed `.dump` checkpoints (max `5` per user). |
| `restore` | `<uid>` `<name>` `[db]` | `--db=<db>`, `--json` | Alias for `savepoint <uid> restore <name> [db]`. |
| `bench` | `<uid>` `[clients]` `[duration]` `[db]` | `--clients=<n>`, `--duration=<s>`, `--db=<db>`, `--select-only`, `--json` | Runs bounded `pgbench` (`1..8` clients, `1..5` seconds). |
| `diag` | `<uid>` `[db]` | `--db=<db>`, `--json` | Collects `pg_stat_activity`, `pg_settings`, buffer cache hit ratio, and extension status. |
| `readiness` | `<uid>` `<lab_id>` | `--json` | Evaluates container, target DB, `lms_student`, extensions, tables, and oracle prerequisites. |
| `reap-idle` | `[max_idle_seconds]` (default `3600`) | `--max-idle=<s>`, `--json` | Stops running `lp_pg18_user_*` containers idle longer than the threshold. |
| `heal` | `[course_slug]` (default `postgresql-18`) | `--course=<slug>`, `--json` | Terminates blocking/chaos sessions and drops `chaos_*` tables across running containers. |
| `stop-all` | - | `--json` | Stops all running `lp_pg18_user_*` containers. |
| `prune` | - | `--json` | Removes all exited `lp_pg18_user_*` containers. |
| `help` / `-h` / `--help` | - | - | Prints usage summary and exits `0`. |

### `bin/lab-runner.php` Exit Codes

| Exit Code | Meaning |
| :--- | :--- |
| `0` | Command succeeded (`list`, `fleet`, `status`, `stats`, `help`, or operation payload returned `success: true` / `passed: true`). |
| `1` | Missing required argument, unknown command, failed Docker/SQL operation, or oracle assertion failed. |

## 2. Course Packaging and Slide Cache Utilities

### `bin/sync-course.php`

Synchronizes a course source directory into `storage/courses/<slug>/manifest.json`.

| Parameter / Option | Default | Behavior |
| :--- | :--- | :--- |
| `argv[1]` (`<course-slug>`) | `postgresql-18` | Sanitized with `[^a-zA-Z0-9_\-]` removal; falls back to `postgresql-18` if empty. |
| `argv[2]` (`<source-directory>`) | `LMS_COURSE_ROOT` -> `../training_postgresql` -> `examples/demo-course` | Verified via `is_dir()`; writes `{ slug, synced_at, source_dir }` atomically with `LOCK_EX`. |
| `-h` / `--help` / `help` | - | Prints command usage and exits `0` without writing a manifest directory. |
| Exit `0` | - | Usage printed or manifest written successfully. |
| Exit `1` | - | Source directory missing, target directory creation failed, or JSON write failed. |

### `bin/export_slides_cache.py`

Renders PDF slide decks (`*_slides.pdf` in `<COURSE_ROOT>/03_supports_de_cours`) to `public/assets/slides_cache/<module_id>/slide_NNN.png` at `135` DPI and matches PPTX speaker notes into `manifest.json`.

| Input / Dependency | Source Path | Output Path | Exit Behavior |
| :--- | :--- | :--- | :--- |
| Python packages (`pymupdf`, `python-pptx`) | `LMS_COURSE_ROOT/03_supports_de_cours/*_slides.pdf` and `*.pptx` | `public/assets/slides_cache/<module_id>/slide_001.png..` + `manifest.json` | Exits `0` after printing deck and slide counts; non-zero on missing dependencies or unreadable PDF/PPTX files. |

## 3. Versioned Documentation Builder (`scripts/build_versioned_docs.py`)

Builds multi-version MkDocs Material sites (`main` plus all semver Git tags `vX.Y.Z`) into an Apache-ready IONOS bundle.

### CLI Flags

| Flag | Argument Type | Default | Behavior |
| :--- | :--- | :--- | :--- |
| `--output` | path | `site_dist` | Target bundle directory; refuses repository root, ancestor directories, and protected roots (`.git`, `.github`, `.agents`, `bin`, `config`, `docs`, `examples`, `public`, `scripts`, `src`, `storage`, `templates`, `tests`). |
| `--base-url` | URL | `https://lsm.lightpath.fr` | Base URL injected via `LMS_DOCS_SITE_URL` as `<base_url>/<version>/`. |
| `--main-ref` | Git ref | `WORKTREE` | Source ref for `/main/` (`WORKTREE` builds working tree; `origin/main` or tag builds via `git archive`). |
| `--tags` | list | all semver tags | Explicit release tags (space- or comma-separated) sorted newest-first. |
| `--skip-tags` | flag | `false` | Skips historical release tags and builds `/main/` only. |
| `--no-strict` | flag | `false` | Omits `--strict` when invoking `python -m mkdocs build`. |
| `--repo-root` | path | repository root | Overrides repository root path. |

### Overlay Files, Bundle Artifacts, and Privacy Guard

| Component | Exact Files / Rules |
| :--- | :--- |
| `ALWAYS_OVERLAY_FILES` | `mkdocs.yml`, `scripts/mkdocs_hooks.py`, `docs/stylesheets/extra.css`, `docs/javascripts/katex.js` (overlaid onto historical tag snapshots). |
| `FALLBACK_OVERLAY_FILES` | `docs/index.md`, `docs/assets/logo.svg`, `docs/assets/header-banner.svg` (copied only when missing from historical tags). |
| Generated root artifacts | `versions.json` (mike provider manifest), `.htaccess` (HTTPS 301, security headers, `ErrorDocument 404`, `302` `/`, `/latest/...`, `/dev/...`), `index.html`, `latest/index.html`, `dev/index.html`. |
| Post-build privacy check | `assert_no_private_files()` fails build if any `.env*`, `*.kdbx`, `*.keyx`, `*.pid`, `*.log`, `*.sqlite`, `*.db`, `initial_admin_credentials.txt`, or `internal` path segment exists inside output directory. |

## 4. Verification, Test, and Documentation Linter Runners

### Composer and npm Scripts

| Script Command | Underlying Command | Scope |
| :--- | :--- | :--- |
| `composer test` | `php tests/run_tests.php` | Core unit and integration test suite. |
| `composer test:http` | `php tests/test_functional_http.php` | Ephemeral `php -S` HTTP, CSRF, RBAC, Range `206`/`416`, and API suite. |
| `composer test:audit` | `php tests/test_coverage_audit.php` | 6-gate static coverage, CDN, brand, inline CSS/JS, and English comment audit. |
| `composer test:all` | `@test`, `@test:http`, `@test:audit` | Runs tiers 2, 3, and 4 sequentially. |
| `composer serve` | `php -S localhost:8080 -t public` | Starts local PHP development server. |
| `npm run test:e2e` | `node tests/e2e/run_playwright_campaign.mjs` | Headless Chrome `@playwright/mcp` campaign across `1440x900`, `768x1024`, `375x812`. |
| `npm run test:e2e:adversarial` | `node tests/e2e/test_adversarial_challenger.mjs` | Responsive overflow and diagnostic journey adversarial campaign. |

### Standalone Verification Scripts and Exit Codes

| Tool / Script | Exit `0` | Exit `1` | Exit `2` |
| :--- | :--- | :--- | :--- |
| `php tests/run_tests.php` | All assertions passed (`0` failures). | One or more assertions failed. | - |
| `php tests/test_functional_http.php` | All HTTP/API assertions passed (`0` failures). | Ephemeral port/server failure or assertion failure. | - |
| `php tests/test_coverage_audit.php` | `100.0%` reflection API coverage and `0` security/governance violations. | Coverage `< 100.0%` or any vendor, CDN, inline `style=`/`on*=`, pattern, French comment, or CSP violation. | - |
| `php tests/test_adversarial_b1.php` .. `b5.php` | All adversarial regression assertions passed. | One or more adversarial checks failed. | - |
| `node tests/e2e/run_playwright_campaign.mjs` | Zero console/CSP/layout failures. | Server startup error or browser check failure. | - |
| `node tests/e2e/test_adversarial_challenger.mjs` | Zero responsive overflow or journey failures. | Server startup error or layout/journey failure. | - |
| `pytest tests/test_diataxis_documentation.py tests/test_mkdocs_publishing.py -v` | All Diataxis, STE, hook, and publishing tests passed. | One or more assertions failed. | Test usage or collection error. |
| `python ~/.gemini/config/skills/diataxis-docs/scripts/diataxis_check.py docs --strict --check-ste --lang en` | Structure, relative links, quadrant rules, and STE passed with `0` errors. | Missing structure, broken link, governance warning (under `--strict`), or STE error. | Invalid CLI usage. |
| `python ~/.gemini/config/skills/simplified-technical-english/scripts/ste_check.py --mode procedural --lang en <file>` | `0` STE errors in procedural guide. | One or more STE violations (`> 20` words, passive voice, banned modals, contractions, semicolons). | Invalid CLI usage or unreadable input path. |

## Related References and Procedures

- Environment and feature flag matrix: [Environment Variables and Feature Flags](../configuration/environment-and-feature-flags.md)
- Portal and Docker deployment guide: [Deploy Portal with Docker and PDO](../../02-how-to-guides/deployment/deploy-portal-docker-and-pdo.md)
- Cohort and Qualiopi operational tasks: [Manage Cohorts and Qualiopi Exports](../../02-how-to-guides/operations/manage-cohorts-and-qualiopi-exports.md)
- Versioned MkDocs publishing ADR: [ADR-002: Versioned Diataxis MkDocs Publishing](../../04-explanation/design-decisions/adr-002-versioned-diataxis-mkdocs-publishing.md)
