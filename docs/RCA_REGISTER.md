# RCA Register — Errors, Technical Dead-Ends, Approximations & Unimplemented Ideas

> **Governance Rule ([`PROJECT-CONTEXT.md`](../PROJECT-CONTEXT.md) & [`CONTRIBUTING.md`](../CONTRIBUTING.md))**:
> This register guarantees full engineering traceability for the **LightPath Training Portal** project (strictly in English). Historical `v1.x`–`v2.0.0` engineering notes reside in `docs/internal/`. This register **MUST be systematically updated on every request, every change, and every result** across its four sections (paired with [`ACTION_LOGBOOK.md`](ACTION_LOGBOOK.md)):
> 1. **Errors & Bugs Encountered (Root Cause Analysis — `RCA-XXX`)**
> 2. **Technical Dead-Ends & Abandoned Leads (`IMP-XXX`)**
> 3. **Current Approximations & Simplifying Assumptions (`APP-XXX`)**
> 4. **Unimplemented Ideas & Future Roadmap (`IDEA-XXX`)**

---

## 1. Errors & Bugs Encountered (Root Cause Analysis — `RCA`)

### RCA-001 — Non-Index Directory Links in `docs/MOODLE_COMPARISON.md:53` Caught by `diataxis_check.py` (`Issue #49`)

- **Date / Linked Action**: `2026-11-23` (`ACT-005` / Issue `#49`)
- **Observed Symptom**:
  - Validating `docs/` with `diataxis_check.py --strict` reported two broken relative links on line 53 of [`docs/MOODLE_COMPARISON.md`](MOODLE_COMPARISON.md): `[examples/demo-course/](../examples/demo-course/)` and `[examples/git-basics-course/](../examples/git-basics-course/)`.
- **Root Cause Analysis (5 Whys)**:
  1. *Why did `diataxis_check.py` flag links targeting real directories on disk?* Because `check_relative_links()` only treats a directory link as valid when `<dir>/index.md` exists (`resolved.is_dir() and (resolved / "index.md").is_file()`), matching MkDocs directory URL resolution.
  2. *Why did `examples/demo-course/` and `examples/git-basics-course/` lack `index.md`?* Because both directories are standalone MIT course-pack fixtures at repository root rather than MkDocs documentation sections.
  3. *Why did earlier PHP checks miss this?* Because `tests/run_tests.php` Section 27 verified text tokens (`FEAT-01`..`FEAT-20`) and CDN/vendor absence without walking relative Markdown links across `docs/**/*.md`.
- **Applied Change / Fix**:
  - [`docs/MOODLE_COMPARISON.md`](MOODLE_COMPARISON.md): Replaced the two non-index directory links on line 53 with inline code paths `` `examples/demo-course/` `` and `` `examples/git-basics-course/` `` while preserving all `FEAT-01`..`FEAT-20` items and remaining lines intact.
  - [`scripts/mkdocs_hooks.py`](../scripts/mkdocs_hooks.py) & [`tests/test_diataxis_documentation.py`](../tests/test_diataxis_documentation.py): Added GitHub `blob/`/`tree/` rewriting for non-docs repository links and automated relative-link checking across `docs/**/*.md` (`test_zero_broken_relative_links_across_all_docs`).
- **Result & Non-Regression Test**:
  - `diataxis_check.py docs --strict --check-ste --lang en` and `tests/test_diataxis_documentation.py` enforce zero broken relative links across all Markdown files under `docs/`.

---

## 2. Technical Dead-Ends & Abandoned Leads (`IMP`)

### IMP-001 — Pushing `.github/workflows/*.yml` Over HTTPS `gho_` OAuth Credentials

- **Context**: Configuring the Git `origin` remote during Issue `#49` (`ACT-005`) before authoring `.github/workflows/docs-ci.yml` and `.github/workflows/docs-publish.yml`.
- **Why It Is a Dead-End**:
  - GitHub CLI (`gh auth login`) HTTPS credential helpers rely on OAuth tokens (`gho_*`) scoped to `repo`, `gist`, `read:org`, and `user`, which omit the `workflow` scope. Pushing any commit touching `.github/workflows/*.yml` over HTTPS is rejected by GitHub (`refusing to allow an OAuth App to create or update workflow ... without workflow scope`).
- **Adopted Decision**:
  - Switched `origin` strictly to SSH (`git remote set-url origin git@github.com:jmrenouard/lightpath-training-portal.git`) and verified key authentication via `ssh -T git@github.com`, enabling deterministic pushes for both application code and GitHub Actions workflow definitions.

---

## 3. Current Approximations & Simplifying Assumptions (`APP`)

### APP-001 — Immutable `v2.0.0` Git Tag Overlay & Historical Quadrant Fallback Routing (`scripts/build_versioned_docs.py` & `scripts/mkdocs_hooks.py`)

- **Target Scope**: [`scripts/build_versioned_docs.py`](../scripts/build_versioned_docs.py), [`scripts/mkdocs_hooks.py`](../scripts/mkdocs_hooks.py), [`mkdocs.yml`](../mkdocs.yml).
- **Nature of the Approximation**:
  - The immutable `v2.0.0` release tag predates `mkdocs.yml`, [`docs/index.md`](index.md), the four Diátaxis quadrant directories (`01-tutorials/`..`04-explanation/`), [`docs/MOODLE_COMPARISON.md`](MOODLE_COMPARISON.md), [`docs/ACTION_LOGBOOK.md`](ACTION_LOGBOOK.md), and [`docs/RCA_REGISTER.md`](RCA_REGISTER.md). Instead of mutating the `v2.0.0` Git tag, [`scripts/build_versioned_docs.py`](../scripts/build_versioned_docs.py) unpacks `v2.0.0` via `git archive` and overlays current [`mkdocs.yml`](../mkdocs.yml), [`scripts/mkdocs_hooks.py`](../scripts/mkdocs_hooks.py), `docs/stylesheets/extra.css`, `docs/javascripts/katex.js`, and fallback portal assets (`docs/index.md`, `docs/assets/*.svg`). [`scripts/mkdocs_hooks.py`](../scripts/mkdocs_hooks.py) prunes missing `nav:` entries and routes missing quadrant links via `QUADRANT_HISTORICAL_FALLBACKS` (`01-tutorials` -> `COURSE_PACK_GUIDE.md`, `02-how-to-guides` -> `DEPLOYMENT_AND_OPERATIONS.md`, `03-reference` -> `API_REFERENCE.md`, `04-explanation` -> `ARCHITECTURE.md`) and missing root portal guides to `overview.md` (`CHANGELOG.md` for `RELEASE_NOTES_*`).
- **Justification & Impact**:
  - Preserves cryptographic immutability of `v2.0.0` while publishing a unified `mike` version switcher (`versions.json` covering `latest` -> `v2.0.0`, `dev` -> `main`, and future `v*` tags) under `mkdocs build --strict`.
- **Removal Condition**:
  - Releases tagged after Issue `#49` ship native `mkdocs.yml` and complete 4-quadrant Diátaxis trees, restricting fallback routing solely to legacy `v2.0.0`.

---

### APP-002 — Smart DNS-Guarded HTTPS Smoke Verification in `.github/workflows/docs-publish.yml`

- **Target Scope**: [`.github/workflows/docs-publish.yml`](../.github/workflows/docs-publish.yml) (`deploy-ionos` job).
- **Nature of the Approximation**:
  - After `wlixcc/SFTP-Deploy-Action` uploads the multi-version `site_dist/` bundle and `.htaccess` to IONOS (`IONOS_REMOTE_PATH = lms.lightpath.fr`), the post-deploy verification step tests `getent hosts lms.lightpath.fr` before running `curl` checks against `https://lms.lightpath.fr`. If DNS does not yet resolve, the step logs a `::warning::` annotation instructing operators to configure the `lms.lightpath.fr` DNS record and IONOS vhost instead of failing the SFTP upload job.
- **Justification & Impact**:
  - Enables reliable automated SFTP deployment to IONOS as soon as Issue `#49` merges to `main` or cuts a release tag, even if `lms.lightpath.fr` DNS/vhost binding in the IONOS control panel completes separately.
- **Removal Condition**:
  - Once `lms.lightpath.fr` DNS resolution and IONOS virtual-host TLS binding are verified active in production, remove the DNS skip branch so HTTPS `200`/`302` checks are mandatory on every publish run.

---

## 4. Unimplemented Ideas & Future Roadmap (`IDEA`)

All 20 strategic LMS capabilities benchmarked against Moodle `4.5 LTS` / `5.0` (`FEAT-01` through `FEAT-20`) are tracked with full acceptance criteria in [`ACTION_LOGBOOK.md`](ACTION_LOGBOOK.md) and analyzed in [`MOODLE_COMPARISON.md`](MOODLE_COMPARISON.md):

| Priority / Target | Scope | Canonical IDs | Authoritative Specification |
| :--- | :---: | :--- | :--- |
| **`P0` (`v2.1`)** | Global OSS Adoption, `FR`/`EN` i18n, Visual Identity (`1280x640` Hero) & Self-Service Auth | `FEAT-01`..`FEAT-03` | [`ACTION_LOGBOOK.md`](ACTION_LOGBOOK.md) · [`MOODLE_COMPARISON.md`](MOODLE_COMPARISON.md) |
| **`P1` (`v2.2`)** | Rich Question Bank, Assignments, Weighted Gradebook, Prerequisites & Multi-Session Cohorts | `FEAT-04`..`FEAT-08` | [`ACTION_LOGBOOK.md`](ACTION_LOGBOOK.md) · [`MOODLE_COMPARISON.md`](MOODLE_COMPARISON.md) |
| **`P2` (`v2.3`)** | Enterprise SSO (`OIDC`/`SAML2`/`LDAP`/TOTP), `.lpcourse.zip`, SCORM/H5P/`xAPI`, LTI 1.3, Open Badges v3.0, Notifications & REST API | `FEAT-09`..`FEAT-15` | [`ACTION_LOGBOOK.md`](ACTION_LOGBOOK.md) · [`MOODLE_COMPARISON.md`](MOODLE_COMPARISON.md) |
| **`P3` (`v2.4+`)** | Peer Workshops & Polls, Cross-Course Competencies (`CBE`), Psychometric Analytics, Opt-In Local AI Coach & Event Hooks | `FEAT-16`..`FEAT-20` | [`ACTION_LOGBOOK.md`](ACTION_LOGBOOK.md) · [`MOODLE_COMPARISON.md`](MOODLE_COMPARISON.md) |

Additional documentation-portal follow-ups linked to Issue `#49`:

| ID | Target Release | Priority | Title | Target Area | Description & Expected Value |
| :--- | :---: | :---: | :--- | :--- | :--- |
| **IDEA-001** | `v2.1` | High | **Enforce Unconditional Live HTTPS Smoke Checks on `lms.lightpath.fr`** | [`.github/workflows/docs-publish.yml`](../.github/workflows/docs-publish.yml) | Retire `APP-002` DNS-skip fallback once IONOS `lms.lightpath.fr` DNS and TLS vhost binding are verified active in production. |
| **IDEA-002** | `v2.1` | Medium | **Bilingual French Diátaxis Documentation Mirror (`01-tutoriels/`..`04-explications/`)** | `docs/` & [`mkdocs.yml`](../mkdocs.yml) | Pair with `FEAT-01` (`FR`/`EN` i18n) to expose an ASD-STF100-verified French documentation mirror alongside the English portal. |
