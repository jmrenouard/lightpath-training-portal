# LightPath Training Portal — Action Logbook & Strategic Roadmap

> **Organization:** LightPath SARL (`https://www.lightpath.fr` | `contact@lightpath.fr`)  
> **Lead Instructor & Maintainer:** Jean-Marie Renouard (Database & DataOps Expert, Co-developer of MySQLTuner)  
> **Release Baseline:** `v2.0.0` (`main` @ `98f6c51` with multi-course `git-basics` PR `#46`)  
> **Governance Standard:** In accordance with [PROJECT-CONTEXT.md](../PROJECT-CONTEXT.md) and [CONTRIBUTING.md](../CONTRIBUTING.md), every engineering initiative follows the mandatory **9-Step English-Only GitHub Workflow**:
> `Issue (gh issue create)` -> `Dedicated Branch` -> `Pre-Commit Test Suite (100% Pass)` -> `Rigorous Staging (git add)` -> `Conventional Commit (git commit)` -> `Branch Push` -> `Pull Request (gh pr create)` -> `CI Validation` -> `PR Merge (gh pr merge)` -> `Close Issue with Comment (gh issue close --comment)`.  
> **Living Register Policy:** Active tasks open under **Section 1 (`IN PROGRESS`)** as soon as a GitHub Issue is created and transition to **Section 2 (`COMPLETED`)** once all tests pass, CI turns green, the Pull Request merges into `main`, and the Issue closes cleanly. Strategic capabilities benchmarked against **Moodle LMS (4.5 LTS / 5.0)** are tracked in [docs/MOODLE_COMPARISON.md](MOODLE_COMPARISON.md) and materialized in the prioritized backlog below (`FEAT-01`..`FEAT-20`).  
> **Documentation Portal & RCA Register:** The versioned Diátaxis portal lives at [https://lms.lightpath.fr/](https://lms.lightpath.fr/) ([docs/index.md](index.md)); root cause analyses (`RCA`), technical dead-ends (`IMP`), approximations (`APP`), and roadmap items (`IDEA`) are tracked in [docs/RCA_REGISTER.md](RCA_REGISTER.md).

---

## Executive Phase Progress Dashboard

| Phase | Target / Priority | Scope & Architectural Deliverable | Status | Progress | Verification / Reference |
| :--- | :---: | :--- | :---: | :---: | :--- |
| **Phase 1** | `v1.0.0` | Core PHP 8.3 LMS Engine, Dual Storage (`JsonStorage` / `PdoStorage`), Per-Learner Docker PG18 Sandbox & Qualiopi 7-Pillar Platform | `Completed` | `Done` | `402/402` TDD, `B1`–`B5` `344/344` (`docs/internal/JOURNAL_DE_BORD.md`) |
| **Phase 2** | `v1.5.0` | 7-Axis Ponytail Enterprise Hardening, Modular `src/Http/*` Controllers, Admin Tab Partials & Strict `style-src 'self'` CSP (Issues `#39`, `#41`) | `Completed` | `Done` | PRs `#40` & `#42`, `641/641` TDD, `155/155` HTTP, `211/211` Audit |
| **Phase 3** | `v2.0.0` | MIT Open-Source Release, Standalone Demo Pack (`examples/demo-course/`), CSPRNG First-Boot Hardening, CI & Docker Compose (Issue `#43` / PR `#44`) | `Completed` | `Done` | Tag `v2.0.0`, CI Run `43982313884`, `644/644` Mode A, `658/658` Mode B |
| **Phase 4** | `v2.0.1` / `main` | Multi-Course `git-basics` Curriculum (`examples/git-basics-course/`), Course-Aware Engine, `LMS_PORT`, Docker Validation & Live Browser QA (Issue `#45` / PR `#46`) | `Completed` | `Done` | CI Run `44367586062`, `727/727` Mode A, `741/741` Mode B, `405/405` HTTP |
| **Phase 5** | `v2.1` (`P0`) | Global OSS Adoption, Multi-Language i18n, Visual Identity & Self-Service Auth (`FEAT-01`..`FEAT-03`) | `Planned` | `0/3` | Backlog items `FEAT-01`–`FEAT-03` |
| **Phase 6** | `v2.2` (`P1`) | Rich Assessments, Assignments, Unified Gradebook, Conditional Access & Cohorts (`FEAT-04`..`FEAT-08`) | `Planned` | `0/5` | Backlog items `FEAT-04`–`FEAT-08` |
| **Phase 7** | `v2.3` (`P2`) | Enterprise SSO, Portable Archives, SCORM/H5P/xAPI, LTI 1.3, Open Badges, Notifications & REST API (`FEAT-09`..`FEAT-15`) | `Planned` | `0/7` | Backlog items `FEAT-09`–`FEAT-15` |
| **Phase 8** | `v2.4+` (`P3`) | Collaborative Pedagogy, Competencies, Psychometrics, Opt-In Local AI & Event Hooks (`FEAT-16`..`FEAT-20`) | `Planned` | `0/5` | Backlog items `FEAT-16`–`FEAT-20` |

> **Backlog Completion Snapshot:** `4 / 4` delivery phases completed (`v1.0.0` through `v2.0.1` / `main`); `0 / 20` planned Moodle-benchmark roadmap capabilities (`FEAT-01`..`FEAT-20`) started (`v2.1`–`v2.4+`).

---

## Prioritized Missing-Feature Backlog (Moodle Benchmark — `FEAT-01`..`FEAT-20`)

See [docs/MOODLE_COMPARISON.md](MOODLE_COMPARISON.md) for the complete 12-domain comparison against **Moodle LMS (4.5 LTS / 5.0)** and the explicit YAGNI anti-goals preserving LightPath's zero-dependency, zero-external-CDN, strict-CSP architecture.

### Phase 5 (`v2.1`, Priority `P0`) — Global OSS Adoption, Multi-Language i18n, Visual Identity & Self-Service Auth

- [ ] **FEAT-01: Multi-Language i18n Engine (`FR`/`EN` UI dictionaries, user/session locale switcher, English demo pack)**
  - **Moodle Equivalent:** Core `lang/` string API (`get_string()`), installed language packs, user profile locale preference, and multi-language content filters.
  - **LightPath Target Files / Modules:** `src/Core/I18n.php` *(new)*, `config/lang/en.php` *(new)*, `config/lang/fr.php` *(new)*, `config/app.php`, `src/Core/Auth.php`, `templates/layout.php`, `templates/pages/*.php`, `templates/partials/admin/*.php`, `examples/demo-course-en/` *(new or localized variant)*.
  - **Acceptance Criteria:**
    - Introduce zero-dependency PHP array dictionaries (`I18n::t(string $key, array $params = [])`) supporting `fr` and `en` with fallback resolution and zero external CDN font/locale requests.
    - Persist per-user locale (`$_SESSION['locale']` + user record `locale`) with a topbar/profile switcher and default `LMS_DEFAULT_LOCALE` env override.
    - Translate 100% of learner/trainer/admin UI chrome and deliver an English demo course-pack variant while preserving French Qualiopi legal wording when `fr` is selected; verify via unit and HTTP locale tests in `tests/run_tests.php` and `tests/test_functional_http.php`.
  - **Effort:** `L`

- [ ] **FEAT-02: Visual Identity, Hero Banner (`1280x640`), OpenGraph Social Preview & White-Label Theme Tokens**
  - **Moodle Equivalent:** Core theme presets (`theme_boost`), site logo/favicon branding settings, custom SCSS brand palette variables, and public frontpage hero metadata.
  - **LightPath Target Files / Modules:** `public/assets/img/og_banner_1280x640.png` *(new)*, `config/branding.php` *(new)*, `config/app.php`, `templates/layout.php`, `templates/pages/login.php`, `templates/partials/admin/tab_system.php`, `public/assets/css/app.css`.
  - **Acceptance Criteria:**
    - Provide self-hosted `1280x640` LightPath Studio hero/OpenGraph social preview banner (`public/assets/img/og_banner_1280x640.png`) and `<meta property="og:*">` tags on public entry pages with zero external URLs.
    - Expose configurable white-label theme tokens in `config/branding.php` / storage (accent hex validated strictly against `^#[0-9A-Fa-f]{6}$`, organization title, subtitle, and self-hosted logo path) compiled into static cached CSS token classes without violating `style-src 'self'` or adding inline `style=` attributes.
    - Pass `tests/test_coverage_audit.php` with `0` inline `style=` attributes and `0` external CDN hits.
  - **Effort:** `S`

- [ ] **FEAT-03: Self-Service Password Reset & Email Verification Workflow**
  - **Moodle Equivalent:** `login/forgot_password.php`, core email-based self-registration confirmation (`auth_email`), and password reset token expiration.
  - **LightPath Target Files / Modules:** `src/Core/Auth.php`, `src/Core/StorageInterface.php`, `src/Core/JsonStorage.php`, `src/Core/PdoStorage.php`, `src/Http/AuthController.php`, `templates/pages/login.php`, `templates/pages/reset_password.php` *(new)*, `public/index.php`.
  - **Acceptance Criteria:**
    - Add `request_password_reset` and `confirm_password_reset` flows using single-use `random_bytes(32)` tokens stored as SHA-256 digests with strict 15-minute TTL, constant-time `hash_equals()`, anti-enumeration uniform responses, and `(email|ip)` rate limiting.
    - Support optional `require_email_verification` flag in `config/features.php` for `self_registration` accounts, storing `email_verified_at` and emitting audit events (`AUTH_RESET_REQUEST`, `AUTH_RESET_SUCCESS`, `AUTH_EMAIL_VERIFIED`).
    - Validate expiry, replay rejection, and token single-use consumption in `tests/run_tests.php` and `tests/test_functional_http.php`.
  - **Effort:** `M`

---

### Phase 6 (`v2.2`, Priority `P1`) — Rich Assessments, Assignments, Unified Gradebook, Conditional Access & Cohorts

- [ ] **FEAT-04: Shared Question Bank & Rich Question Types (multi-select, fill-in/Cloze, code/SQL output match, randomized pools)**
  - **Moodle Equivalent:** Core `question` bank (`qbank`), category/tag pools, random question draws, `qtype_multichoice` (multi-answer), `qtype_multianswer` (Cloze), and `qtype_coderunner`.
  - **LightPath Target Files / Modules:** `src/Course/QuestionBank.php` *(new)*, `src/Course/MarkdownParser.php`, `src/Course/LmsCatalog.php`, `src/Http/LearnerController.php`, `templates/pages/module_view.php`, `templates/pages/exam.php`, `public/assets/js/app.js`.
  - **Acceptance Criteria:**
    - Extend question schema beyond single-choice `A`/`B`/`C`/`D` to support multi-select checkboxes, Cloze fill-in-the-blank tokens, deterministic SQL/CLI output match checks, and tagged randomized exam pool draws per domain.
    - Enforce 100% server-side grading inside `LearnerController` (`400` on malformed answer payloads) while maintaining full backward compatibility with Markdown-embedded module quizzes and Qualiopi 40-question final exams.
    - Cover all question types, partial/exact scoring rules, and deterministic seed reproducibility in `tests/run_tests.php`.
  - **Effort:** `L`

- [ ] **FEAT-05: Learner Assignment Submissions & Trainer Rubric Grading (`mod_assign` equivalent)**
  - **Moodle Equivalent:** `mod_assign` file/text submission, submission states (`draft`, `submitted`, `graded`), grading workflow, and structured rubric criteria.
  - **LightPath Target Files / Modules:** `src/Course/AssignmentService.php` *(new)*, `src/Core/StorageInterface.php`, `src/Core/JsonStorage.php`, `src/Core/PdoStorage.php`, `src/Http/LearnerController.php`, `src/Http/AdminController.php`, `templates/pages/lab_view.php`, `templates/partials/admin/tab_cohort.php`.
  - **Acceptance Criteria:**
    - Allow learners to submit text answers, SQL scripts, or size-capped archive uploads (`.sql`, `.md`, `.zip`, `.txt`, validated via `Security::sanitizeFilename()` + canonical path checks inside `$LMS_DATA_DIR`) per lab/module.
    - Enable trainers (`$auth->isStaff()`) to score submissions against structured rubric criteria, leave inline feedback, update submission status, and log audit events (`ASSIGN_SUBMIT`, `ASSIGN_GRADE`).
    - Enforce strict learner isolation (`student` reads/writes own submissions only; HTTP `403` on cross-learner access) in `tests/test_functional_http.php`.
  - **Effort:** `L`

- [ ] **FEAT-06: Unified Weighted Gradebook, Scales & Learner Transcript Export**
  - **Moodle Equivalent:** `grade/` core gradebook (`grade_items`, weighted categories, custom scales `Pass`/`Merit`/`Distinction`, user grade report & transcript export).
  - **LightPath Target Files / Modules:** `src/Core/GradebookService.php` *(new)*, `src/Core/AdminService.php`, `src/Http/LearnerController.php`, `src/Http/AdminController.php`, `templates/pages/dashboard.php`, `templates/pages/certificate.php`, `templates/partials/admin/tab_cohort.php`.
  - **Acceptance Criteria:**
    - Aggregate module quizzes, assignment rubric grades (`FEAT-05`), verified lab oracles, and the certification exam using configurable per-course category weights and custom qualitative/numeric scales.
    - Expose a unified learner Gradebook view and downloadable formula-safe CSV/printable HTML transcript (`Security::sanitizeCsvCell()` + offline QR verification linkage).
    - Verify weighted mean calculations, missing-item handling, and CSV formula sanitization in `tests/run_tests.php`.
  - **Effort:** `M`

- [ ] **FEAT-07: Conditional Access & Prerequisite Chains (unlock by module completion, minimum score, or date window)**
  - **Moodle Equivalent:** `availability` API (`availability_completion`, `availability_grade`, `availability_date`) restricting module/activity access until prerequisites are met.
  - **LightPath Target Files / Modules:** `src/Course/CourseRepository.php`, `src/Course/AvailabilityEvaluator.php` *(new)*, `config/courses.php`, `src/Http/LearnerController.php`, `templates/layout.php`, `templates/pages/catalog.php`, `templates/pages/module_view.php`, `templates/pages/lab_view.php`.
  - **Acceptance Criteria:**
    - Support declarative prerequisite rules per module/lab/exam (`requires_modules`, `requires_labs`, `min_quiz_percent`, `unlock_after`, `lock_after`) in course manifests while defaulting unrestricted when omitted.
    - Enforce server-side gating (`HTTP 403` / lock screen with unmet criteria summary) across web views, downloads, and `api_*` endpoints for students while keeping staff (`trainer`/`admin`) bypass intact.
    - Verify locked/unlocked transitions and download bypass prevention in `tests/run_tests.php` and `tests/test_functional_http.php`.
  - **Effort:** `M`

- [ ] **FEAT-08: Multi-Session Cohorts, Course Groups & `.ics` Training Calendar Export**
  - **Moodle Equivalent:** Core `cohort` (site-wide batches), `group` / `grouping` per-course sections, and `calendar` `.ics` subscription/export.
  - **LightPath Target Files / Modules:** `src/Core/StorageInterface.php`, `src/Core/JsonStorage.php`, `src/Core/PdoStorage.php`, `src/Core/AdminService.php`, `src/Http/AdminController.php`, `src/Http/LearnerController.php`, `templates/partials/admin/tab_cohort.php`, `templates/partials/admin/tab_users.php`.
  - **Acceptance Criteria:**
    - Model first-class `cohorts` (`id`, `course_slug`, `session_label`, `start_date`, `end_date`, `trainer_ids`, `member_ids`) and optional subgroups so multiple training deliveries of the same course keep isolated KPIs, attendance sheets, and OPCO exports.
    - Generate RFC 5545-compliant `.ics` calendar downloads for scheduled training half-days and live workshop sessions without external dependencies.
    - Validate cohort isolation in `AdminService::computeCohortKpis()` and valid `VEVENT` output in `tests/run_tests.php`.
  - **Effort:** `M`

---

### Phase 7 (`v2.3`, Priority `P2`) — Enterprise SSO, Portable Archives, SCORM/H5P/xAPI, LTI 1.3, Open Badges, Notifications & REST API

- [ ] **FEAT-09: Enterprise SSO (`OIDC / OAuth 2.0 / SAML 2.0 / LDAP`) & Optional TOTP 2FA**
  - **Moodle Equivalent:** `auth_oauth2` (OIDC), `auth_ldap`, community `auth_saml2`, and core `tool_mfa` (`factor_totp`).
  - **LightPath Target Files / Modules:** `src/Core/SsoProvider.php` *(new)*, `src/Core/TotpVerifier.php` *(new)*, `src/Core/Auth.php`, `src/Http/AuthController.php`, `config/app.php`, `config/features.php`, `templates/pages/login.php`, `templates/pages/profile.php`.
  - **Acceptance Criteria:**
    - Implement zero-dependency pure-PHP OIDC Authorization Code + PKCE client (`openssl` / `curl`), optional `ext-ldap` bind adapter, signed SAML 2.0 assertion interface, and RFC 6238 TOTP 2FA (with offline SVG QR enrollment reusing `Security::renderVerificationQrSvg()` style patterns and hashed recovery codes).
    - Enforce `state` / `nonce` CSRF validation, role claim mapping bounded strictly to `Auth::VALID_ROLES`, and feature-flag gating (`sso_enabled`, `mfa_totp`).
    - Cover PKCE/state mismatch rejection and RFC 6238 TOTP window + replay prevention in `tests/run_tests.php`.
  - **Effort:** `L`

- [ ] **FEAT-10: Portable Course & Cohort Backup/Restore Archives (`.lpcourse.zip` import/export)**
  - **Moodle Equivalent:** Moodle `.mbz` (`backup/` and `restore/`) course packaging for cross-instance migration and archival.
  - **LightPath Target Files / Modules:** `src/Course/ArchiveManager.php` *(new)*, `src/Course/DownloadController.php`, `src/Http/AdminController.php`, `bin/sync-course.php`, `templates/partials/admin/tab_courses.php`.
  - **Acceptance Criteria:**
    - Export a self-contained `.lpcourse.zip` archive containing course Markdown, labs, cheatsheets, local Mermaid SVGs, `manifest.json` with SHA-256 checksums, and optional anonymized cohort snapshot.
    - Validate ZIP entries against Zip-Slip path traversal (`Security::isSafeRelativePath()`, disallowed symlinks, bounded extracted byte limit) before registering or restoring via `AdminController`.
    - Test round-trip export/import and Zip-Slip attack rejection in `tests/run_tests.php`.
  - **Effort:** `M`

- [ ] **FEAT-11: Inbound SCORM 1.2 / H5P Runtime Player & `xAPI` / `cmi5` LRS Telemetry**
  - **Moodle Equivalent:** `mod_scorm` inbound player (`API.LMSInitialize` / `LMSSetValue`), `mod_h5pactivity` runtime, and `logstore_xapi` statement emitter.
  - **LightPath Target Files / Modules:** `src/Course/ScormPlayerService.php` *(new)*, `src/Core/XapiEmitter.php` *(new)*, `src/Http/LearnerController.php`, `public/assets/js/scorm_api.js` *(new)*, `templates/pages/scorm_player.php` *(new)*.
  - **Acceptance Criteria:**
    - Complement outbound SCORM 1.2 export with an inbound SCORM 1.2 runtime API bridge (`LMSInitialize`, `LMSGetValue`, `LMSSetValue`, `LMSCommit`, `LMSFinish`) persisting `cmi.core.*` progress atomically via `StorageInterface` and served inside strict sandboxed CSP headers.
    - Emit valid `xAPI` 1.0.3 JSON statements (`initialized`, `completed`, `passed`, `experienced`) to local storage or a configured external LRS endpoint when enabled.
    - Verify CMI data persistence, XSS containment on package HTML, and `xAPI` statement schema in `tests/run_tests.php` and `tests/test_functional_http.php`.
  - **Effort:** `L`

- [ ] **FEAT-12: LTI 1.3 Advantage Provider & Consumer (launch LightPath Docker SQL Labs from Moodle/Canvas with Grade Passback)**
  - **Moodle Equivalent:** `mod_lti` (LTI 1.3 Consumer) and `enrol_lti` (LTI 1.3 Advantage Tool Provider with Deep Linking and Assignment & Grade Services).
  - **LightPath Target Files / Modules:** `src/Core/Lti13Service.php` *(new)*, `src/Http/LtiController.php` *(new)*, `public/index.php`, `config/app.php`, `config/features.php`, `templates/partials/admin/tab_system.php`.
  - **Acceptance Criteria:**
    - Implement pure-PHP RS256 JWT + JWKS signature verification (`openssl`), OIDC third-party initiated login, Deep Linking resource selection (targeting specific PostgreSQL/Git modules or Docker labs), and LTI AGS Grade Passback upon oracle/exam validation.
    - Isolate LTI-launched learners strictly to the authorized target course/lab with no escalation to unrelated courses or admin endpoints.
    - Verify JWT signature/audience/nonce validation and grade passback payload formatting in `tests/run_tests.php`.
  - **Effort:** `L`

- [ ] **FEAT-13: Open Badges v3.0 Verifiable Credentials Export**
  - **Moodle Equivalent:** Core `badges/` subsystem issuing downloadable Open Badges JSON-LD credentials with verification metadata.
  - **LightPath Target Files / Modules:** `src/Core/OpenBadgeExporter.php` *(new)*, `src/Core/Gamification.php`, `src/Core/JsonStorage.php`, `templates/pages/certificate.php`, `templates/pages/verify.php`.
  - **Acceptance Criteria:**
    - Export existing CSPRNG certifications (`LP-PG18-CERT-*`, `LP-GITBAS-CERT-*`) and earned competency badges as Open Badges v3.0 / W3C Verifiable Credential JSON-LD documents signed/hashed deterministically and linked to `?page=verify&code=...`.
    - Preserve strict offline usability (zero external `@context` network fetches required at issuance time) and expose downloadable `.json` credentials from `certificate.php`.
    - Validate JSON-LD schema keys, issuer metadata (`LightPath SARL`), and verification linkage in `tests/run_tests.php`.
  - **Effort:** `S`

- [ ] **FEAT-14: Outbound Notification Channels (SMTP Email, Webhooks, Slack/Teams Reminders & J+60 Survey Triggers)**
  - **Moodle Equivalent:** Core `message/` output plugins (`message_email`, Slack/webhook notification plugins, scheduled task reminders).
  - **LightPath Target Files / Modules:** `src/Core/Notifier.php` *(new)*, `bin/notify-due.php` *(new)*, `config/app.php`, `config/features.php`, `src/Http/AdminController.php`, `templates/partials/admin/tab_system.php`.
  - **Acceptance Criteria:**
    - Deliver a pure-PHP socket SMTP (`STARTTLS`/`SMTPS`) + signed JSON/Slack/Teams webhook dispatcher (`X-LightPath-Signature` HMAC-SHA256) with retry backoff and dead-letter audit logging.
    - Provide CLI cron worker `bin/notify-due.php` to trigger automated Qualiopi `J+60` cold satisfaction reminders, inactive learner nudges, and missing-attendance alerts with SSRF private-IP allowlist/denylist validation on webhook URLs.
    - Test SMTP envelope formatting, HMAC signatures, SSRF private-IP rejection, and `J+60` due-date filtering in `tests/run_tests.php`.
  - **Effort:** `M`

- [ ] **FEAT-15: Token-Authenticated REST API & Outbound Webhooks for HRIS/CRM/OPCO Automation**
  - **Moodle Equivalent:** Moodle Web Services (`webservice/rest`, personal tokens, external function registry) and event webhooks.
  - **LightPath Target Files / Modules:** `src/Http/RestApiController.php` *(new)*, `src/Core/ApiTokenService.php` *(new)*, `public/index.php`, `src/Core/StorageInterface.php`, `templates/partials/admin/tab_system.php`, `docs/API_REFERENCE.md`.
  - **Acceptance Criteria:**
    - Introduce scoped Bearer API tokens (`SHA-256` hashed at rest, explicit scope permissions such as `users:write`, `enrollments:write`, `reports:read`, expiry timestamps, and admin revocation).
    - Expose versioned JSON endpoints (`/api/v1/users`, `/api/v1/cohorts`, `/api/v1/enrollments`, `/api/v1/reports/qualiopi`) plus signed outbound webhook subscriptions (`user.enrolled`, `lab.validated`, `exam.passed`, `certificate.issued`).
    - Enforce scope checks, `401`/`403` error contracts, and rate limits in `tests/test_functional_http.php` and maintain `100%` reflection coverage in `tests/test_coverage_audit.php`.
  - **Effort:** `L`

---

### Phase 8 (`v2.4+`, Priority `P3`) — Collaborative Pedagogy, Competencies, Psychometrics, Opt-In Local AI & Event Hooks

- [ ] **FEAT-16: Peer-Review Workshops (`mod_workshop`), Live Classroom Polls (`mod_choice`) & Collaborative Wiki/Glossary**
  - **Moodle Equivalent:** `mod_workshop` (multi-phase peer assessment), `mod_choice` (instant live polls), `mod_wiki`, and editable `mod_glossary`.
  - **LightPath Target Files / Modules:** `src/Course/CollaborationService.php` *(new)*, `src/Http/LearnerController.php`, `src/Http/AdminController.php`, `templates/pages/module_view.php`, `templates/pages/documents.php`, `public/assets/js/app.js`.
  - **Acceptance Criteria:**
    - Add trainer-launched live classroom polls rendered inside the existing top banner / heartbeat loop (`api_heartbeat`) with instant aggregate charts using pure CSS/SVG (`0` inline `style=`).
    - Support structured peer-review checklists on lab/assignment artifacts and learner-contributed glossary proposals gated by trainer moderation.
    - Verify poll voting idempotency, peer-allocation integrity, and XSS sanitization across all user-authored entries.
  - **Effort:** `L`

- [ ] **FEAT-17: Cross-Course Competency Frameworks (`CBE`) & Individual Learning Plans**
  - **Moodle Equivalent:** `core_competency` (`tool_lp` Competency-Based Education frameworks, Bloom skill mappings across courses, Individual Learning Plans).
  - **LightPath Target Files / Modules:** `src/Course/CompetencyService.php` *(new)*, `config/courses.php`, `src/Core/AdminService.php`, `templates/pages/dashboard.php`, `templates/pages/certificate.php`, `templates/partials/admin/tab_cohort.php`.
  - **Acceptance Criteria:**
    - Map course modules, oracle-verified labs, and exam domains to reusable Bloom competency IDs across multiple courses (e.g., bridging `git-basics` and `postgresql-18`).
    - Generate learner skill-matrix progress reports and Individual Learning Plans (`ILP`) exportable in Qualiopi JSON dossiers.
    - Validate cross-course proficiency aggregation and framework schema parsing in `tests/run_tests.php`.
  - **Effort:** `M`

- [ ] **FEAT-18: Psychometric Question Analytics & Predictive At-Risk Early Warning Rules**
  - **Moodle Equivalent:** `quiz_statistics` (Facility Index, Discrimination Index, Discriminative Efficiency) and `core_analytics` predictive dropout/at-risk models.
  - **LightPath Target Files / Modules:** `src/Core/AnalyticsService.php` *(new)*, `src/Core/AdminService.php`, `templates/partials/admin/tab_cohort.php`, `templates/pages/admin.php`.
  - **Acceptance Criteria:**
    - Compute per-question facility index (`%` correct), discrimination index (top vs. bottom quartile correlation), and distractor selection distributions across stored quiz and certification exam attempts.
    - Upgrade `AdminService::detectAtRiskLearners()` with multi-signal weighted heuristics (attendance gaps, failed lab oracles, quiz drop-off, inactivity window) and configurable alert thresholds without external ML services.
    - Verify statistical accuracy on synthetic cohorts and zero division-by-zero edge cases in `tests/run_tests.php`.
  - **Effort:** `M`

- [ ] **FEAT-19: Opt-In Local AI Study Coach (`Ollama` / OpenAI strictly off-by-default to preserve air-gapped CSP)**
  - **Moodle Equivalent:** Moodle 4.5+ `core_ai` subsystem (provider abstraction, AI placement controls for explain/summarize, opt-in per course).
  - **LightPath Target Files / Modules:** `src/Core/AiCoachService.php` *(new)*, `config/features.php`, `src/Core/FeatureManager.php`, `src/Http/LabApiController.php`, `templates/pages/module_view.php`, `templates/pages/lab_view.php`.
  - **Acceptance Criteria:**
    - Disabled by default (`ai_study_coach => false` in `config/features.php`) so air-gapped/strict-CSP deployments make zero outbound requests and incur zero runtime cost.
    - When explicitly enabled by an administrator and pointed to a configured local `Ollama` endpoint (or explicit external provider opt-in), proxy prompts strictly server-side from PHP (preserving `connect-src 'self'` CSP), redact secrets/PII, and log AI hints in audit logs without leaking lab solutions when `lock_solutions` is active.
    - Assert disabled-by-default HTTP `403`, solution-lock enforcement, and SSRF protection in `tests/test_functional_http.php`.
  - **Effort:** `M`

- [ ] **FEAT-20: Lightweight Event Hook Contract & Self-Enrollment Cohort Keys / Time-Bounded Access**
  - **Moodle Equivalent:** Moodle Core `hook` / Events 2 API (`observer` callbacks) and `enrol_self` (enrollment keys, max capacity, start/expiry dates).
  - **LightPath Target Files / Modules:** `src/Core/EventDispatcher.php` *(new)*, `src/Core/Auth.php`, `src/Http/AuthController.php`, `src/Http/AdminController.php`, `templates/pages/login.php`, `templates/partials/admin/tab_courses.php`.
  - **Acceptance Criteria:**
    - Add an internal typed event hook contract (`EventDispatcher::dispatch(string $event, array $payload)`) firing on login, enrollment, lab oracle pass, exam pass, and certificate issuance so new integrations (`FEAT-14`, `FEAT-15`, `FEAT-11`) decouple cleanly without monolithic controller edits.
    - Add per-course / per-cohort enrollment keys (hashed secret, max seats, validity window `access_starts_at` / `access_expires_at`) enforced in `Auth::canAccessCourse()` with HTTP `403` expiry handling.
    - Validate expired-access denial, seat-cap enforcement, and hook subscriber isolation in `tests/run_tests.php` and `tests/test_functional_http.php`.
  - **Effort:** `M`

---

## 1. Ongoing Actions (`IN PROGRESS`)

| Action ID | Date / Time (UTC) | Issue / Branch | Status | Request, Ongoing Changes & Expected Results |
| :--- | :--- | :--- | :---: | :--- |
| _None._ | — | — | — | All tracked actions (`ACT-001`..`ACT-005`) have transitioned to `COMPLETED`. |

---

## 2. Completed Actions (`COMPLETED`)

### `ACT-005` — Versioned Diátaxis MkDocs Material Portal, ASD-STE100 Guides, IONOS SFTP Publishing & Pre-Public Release Prep (Issue `#49`)
- **Timestamp (UTC)**: `2026-11-23T20:09:17Z`
- **Status**: `COMPLETED`
- **GitHub Issue / Branch**: Issue [`#49`](https://github.com/jmrenouard/lightpath-training-portal/issues/49) | `feat/issue-49-mkdocs-diataxis-publish` -> `main`
- **Request**:
  - Publish a versioned 4-quadrant **Diátaxis MkDocs Material** documentation portal (`https://lms.lightpath.fr/`) with **ASD-STE100 Issue 9** procedural guides under `docs/02-how-to-guides/`, initialize an English living RCA register ([docs/RCA_REGISTER.md](RCA_REGISTER.md)), automate Docs-as-Code validation and IONOS SFTP publishing for `main` (`/main/`, `/dev/`) and immutable Git tags (`v2.0.0` / `latest` and future `v*`), enforce Python/PHP/JS verification suites, and prepare the repository for public open-source release.
- **Changes**:
  - **Phase 1 Bootstrap & IONOS Secrets**: Switched `origin` to SSH (`IMP-001`), created Issue `#49`, provisioned `IONOS_FTP_SERVER`, `IONOS_FTP_USERNAME`, `IONOS_FTP_PASSWORD`, `IONOS_FTP_PORT`, and `IONOS_REMOTE_PATH` in repository secrets via in-memory KeePass vault injection (zero plaintext output), and completed a pre-public working-tree and full Git history secret/privacy scan.
  - **MkDocs Material Toolchain, Theme, Hooks & Versioned Publishing (`mkdocs.yml`, `requirements-docs.txt`, `.vale.ini`, `.markdownlint.json`, `.gitignore`, `docs/stylesheets/extra.css`, `docs/javascripts/katex.js`, `docs/assets/{logo,header-banner}.svg`, `scripts/mkdocs_hooks.py`, `scripts/build_versioned_docs.py`, `.github/workflows/{docs-ci,docs-publish}.yml`)**: Configured 6-tab Material navigation, Obsidian `#0B1120` / Cyan `#00B0F0` Studio theme (`tabular-nums`, `.lms-hero`, `.lms-metrics`, `.lms-actions`), `provider: mike` version switcher, dependency-free `mkdocs_hooks.py` (`VIRTUAL_DOCS`, `v2.0.0` historical quadrant/root fallbacks `APP-001`, `docs/internal/` GitHub `blob`/`tree` rewriting, sensitive target neutralization, GitHub alerts, `gfm_slugify`, nav pruning, `RELEASE_NOTES_*.md` discovery), multi-version `git archive` + `.htaccess` bundle builder (`versions.json`, HTTPS `301`, `/latest/` & `/dev/` `302`, protected-dir & post-build leak checks), and SHA-pinned least-privilege CI/IONOS SFTP workflows (`APP-002`).
  - **Diátaxis 4-Quadrant Documentation, Portal & Living Governance (`docs/index.md`, `docs/01-tutorials/` [`4` files], `docs/02-how-to-guides/` [`6` files], `docs/03-reference/` [`5` files], `docs/04-explanation/` [`5` files], `docs/RCA_REGISTER.md`, `docs/MOODLE_COMPARISON.md`, `docs/ACTION_LOGBOOK.md`, `README.md`)**: Authored portal home, 3 tutorials, 5 ASD-STE100 Issue 9 guides (`deployment/`, `operations/`, `incident-response/`, `security/`), 4 reference matrices, 4 explanation/ADR/deep-dive docs, English living RCA register (`RCA-001`, `IMP-001`, `APP-001`, `APP-002`, `IDEA-001`..`IDEA-002`), repaired `docs/MOODLE_COMPARISON.md:53` non-index directory links, and exposed `https://lms.lightpath.fr/`, `docs/index.md`, all 4 quadrant indexes, and `docs/RCA_REGISTER.md` in `README.md`.
  - **Automated Docs-as-Code & Publishing Tests (`tests/test_diataxis_documentation.py`, `tests/test_mkdocs_publishing.py`)**: Added `23` Python tests enforcing Diátaxis quadrant structure, relative link resolution, genre guardrails, cross-linking mesh, ASD-STE100 Issue 9 structural rules, toolchain config validity, zero-CDN compliance, `mkdocs_hooks.py` rewriting/fallbacks, multi-version `build_versioned_docs.py` (`WORKTREE` + `v2.0.0` E2E), `.htaccess`, and workflow security.
- **Result**:
  - **Diátaxis & ASD-STE100**: `diataxis_check.py docs --strict --check-ste --lang en` **`0 error(s), 0 warning(s)`** across `35` Markdown files; `ste_check.py --mode procedural --lang en` **`0 errors, 0 warnings`** across all `5` non-index `docs/02-how-to-guides/` files.
  - **Python & Multi-Version MkDocs Publishing**: Bare Python `pytest tests/test_diataxis_documentation.py tests/test_mkdocs_publishing.py -v` **`23/23 passed`**; MkDocs venv `pytest` **`23/23 passed` (`0` skipped)**; `mkdocs build --strict` **exit `0` (`0` warnings)**; `scripts/build_versioned_docs.py --main-ref WORKTREE` **exit `0`** (`v2.0.0` [`latest`] + `main` [`dev`], `.htaccess`, `0` `internal/` or sensitive leaks).
  - **PHP & JS Suites (`LMS_DOCKER_FORCE_SIMULATED=1`)**: `php -l` **`0` errors**; `node --check` **`3/3` (`exit 0`)**; `tests/run_tests.php` **`739/739 passed`** (incl. Sections `22` & `27`); `tests/test_adversarial_b1..b5.php` **`344/344` (`B1 69/69`, `B2 50/50`, `B3 69/69`, `B4 78/78`, `B5 78/78` — `APPROVE`)**; `tests/test_functional_http.php` **`405/405 passed`**; `tests/test_coverage_audit.php` **`211/211` (`100.0%`, `AUDIT PASSED`)**.
  - **PR-Scoped `/ponytail-review`**: All `6` review findings resolved and signed off with **`Verdict: Ship.`** (`docs/ACTION_LOGBOOK.md` finalized under `ACT-005`).

### `ACT-004` — Moodle LMS (`4.5 LTS` / `5.0`) vs. LightPath Portal (`v2.0.0`) Benchmark, Action Logbook Backlog (`FEAT-01`..`FEAT-20`) & `README.md` Roadmap Integration (Issue `#47`)
- **Timestamp (UTC)**: `2026-11-18T19:07:38Z`
- **Status**: `COMPLETED`
- **GitHub Issue / Branch**: Issue [`#47`](https://github.com/jmrenouard/lightpath-training-portal/issues/47) | `docs/moodle-comparison-and-action-backlog` -> `main`
- **Request**:
  - Benchmark **Moodle LMS (`4.5 LTS` / `5.0`)** against **LightPath Training Portal (`v2.0.0`)**, formalize a living engineering Action Logbook (`ACT-001`..`ACT-004`) paired with a prioritized 20-item missing-feature roadmap (`FEAT-01`..`FEAT-20`), surface an executive comparison matrix and both bundled MIT course packs in `README.md`, and enforce automated documentation and backlog integrity checks in `tests/run_tests.php`.
- **Changes**:
  - **`docs/MOODLE_COMPARISON.md` *(created)***: Authored a factual 12-domain architectural and functional comparison of Moodle `4.5 LTS` / `5.0` vs. LightPath `v2.0.0` (verified against `config/courses.php`, `config/features.php`, `public/index.php`, `src/Core/*`, `src/Course/*`, and `src/Docker/*`), a Mermaid selection & coexistence diagram (`SCORM 1.2` today + planned `FEAT-12` LTI 1.3), 5 concrete LightPath advantages, canonical `P0`–`P3` gap analysis (`FEAT-01`..`FEAT-20`), and 5 explicit YAGNI anti-goals.
  - **`docs/ACTION_LOGBOOK.md` *(created)***: Initialized the living engineering governance register featuring the 9-step English-only GitHub workflow header, Phase 1–8 Executive Progress Dashboard (`4/4` delivery phases completed; `0/20` planned Moodle-benchmark items started), canonical `FEAT-01`..`FEAT-20` backlog specifications (`S`/`M`/`L` effort, Moodle equivalents, LightPath targets, acceptance criteria), and action records `ACT-001`..`ACT-004`.
  - **`README.md` *(updated)***: Updated Section 1 (`Platform Overview`) and the architecture Mermaid diagram to cite both bundled MIT course packs (`examples/demo-course/` and `examples/git-basics-course/`); added Section 2 (`Moodle vs. LightPath Training Portal`) with positioning guidance, a 12-dimension comparison matrix, and a `P0`–`P3` roadmap summary (`FEAT-01`..`FEAT-20`); renumbered Sections 3–10; and indexed `docs/MOODLE_COMPARISON.md` and `docs/ACTION_LOGBOOK.md` in Section 9 (`Engineering Documentation Index`).
  - **`tests/run_tests.php` *(updated)***: Added Section 27 (`Moodle Comparison, Action Logbook & README Integrity`) enforcing file presence, canonical `FEAT-01`..`FEAT-20` parity across `docs/MOODLE_COMPARISON.md`, `docs/ACTION_LOGBOOK.md` (`[ ]` items), and `README.md`, `ACT-001`..`ACT-004` and Section 1/2 logbook headings, `examples/git-basics-course/` citation, and zero prohibited vendor brand or external CDN references.
- **Result**:
  - **Syntax & Static Audit**: `php -l tests/run_tests.php` (`0` syntax errors); `tests/test_coverage_audit.php` **`211/211` (`100.0%`, `AUDIT PASSED`)** with `0` prohibited vendor leaks, `0` external CDN references, `0` non-English source comments, and strict `style-src 'self'` CSP `PASS`.
  - **Mode A (Standalone `examples/demo-course/` + `examples/git-basics-course/`, `LMS_DOCKER_FORCE_SIMULATED=1`)**: `tests/run_tests.php` **`739/739` passed** (`0` failed); `tests/test_functional_http.php` **`405/405` passed** (`0` failed); `tests/test_adversarial_b5.php` **`344/344` (`B1 69/69`, `B2 50/50`, `B3 69/69`, `B4 78/78`, `B5 78/78` — `APPROVE`)**.
  - **Mode B (`training_postgresql` 34-module pack + `pgscripts`, `LMS_DOCKER_FORCE_SIMULATED=1`)**: `tests/run_tests.php` **`753/753` passed** (`0` failed).

### `ACT-003` — Multi-Course `git-basics` Curriculum (3 Modules), Course-Aware Engine, Docker Validation & Live Browser QA (Issue `#45` / PR `#46`)
- **Timestamp (UTC)**: `2026-11-18T18:16:59Z`
- **Status**: `COMPLETED`
- **GitHub Issue / PR**: Issue [`#45`](https://github.com/jmrenouard/lightpath-training-portal/issues/45) | PR [`#46`](https://github.com/jmrenouard/lightpath-training-portal/pull/46)
- **Branch / Commits**: `feat/git-basics-course-and-e2e` -> `main` (Feature: `da7e4d2`, Merge: `98f6c51`)
- **Request**:
  - Deliver a second standalone MIT-licensed curriculum (`git-basics`, 3 modules + 3 labs without Docker requirement) proving full multi-course generalization across catalog, Markdown parser, attendance, gamification, surveys, flashcards, certificate verification, Docker Compose `LMS_PORT` parameterization, and end-to-end QA.
- **Changes**:
  - **Bundled `git-basics` Course-Pack (`examples/git-basics-course/`)**: Authored `GUIDE_PARCOURS.md`, `01_programme_et_referentiel/programme_formation_git_bases.md`, 3 dual Markdown/HTML modules (`module_01`..`module_03`), 3 7-pillar Qualiopi labs (`lab_01`..`lab_03`) with `VALIDATION_OK` oracles, 6 cheatsheets, `pgscripts/pg_git_repo_audit.py`, `04_ateliers_et_tp/pgexamples/python/check_commit_msg.py`, `04_ateliers_et_tp/pgexamples/README_git_examples.md`, and `public/assets/img/git_branch_icon.svg`.
  - **Vector Mermaid SVGs & Parser Routing (`src/Course/MarkdownParser.php`, `examples/*/assets/mermaid_cache/*.svg`, `public/assets/{js/app.js,css/app.css}`)**: Generated 4 valid XML Studio vector SVGs (`3` for `git-basics`, `1` for `demo-course`) with zero `<style>` tags or `style=` attributes; prioritized `cheatsheet_` routing before `module_`/`lab_`; fixed `GUIDE_PARCOURS.md` relative asset URLs; hardened `initMermaid()` on visible slides.
  - **Multi-Course Engine Generalization (`config/courses.php`, `src/Course/{LmsCatalog,CourseRepository}.php`, `src/Core/{Auth,JsonStorage,Gamification}.php`, `src/Http/LearnerController.php`, `public/index.php`, `templates/pages/*.php`)**: Bound active course `source_root` before parsing, auto-detected `git-basics` from `LP-GITBAS-` codes on `?page=verify`, added dynamic non-PG18 attendance scheduling (`1` day / `2` half-days / `7h`), built 8 Git-specific Gamification badges/ranks, gated Docker/PG18 copy on `docker_enabled` / course slug, normalized flashcard line breaks with live `#fc-mastered-count` updates, and parameterized `docker-compose.yml` port via `LMS_PORT`.
  - **QA & Governance (`tests/run_tests.php`, `tests/test_functional_http.php`, `docs/internal/{REGISTRE_RCA,JOURNAL_DE_BORD}.md`)**: Added Section 26 multi-course unit/isolation/SVG XML checks, expanded `[7/7]` Git Basics HTTP E2E flows, and recorded `RCA-172` (`RCA-LMS-42`).
- **Result**:
  - **Mode A (Standalone `examples/demo-course/` + `examples/git-basics-course/`, `LMS_DOCKER_FORCE_SIMULATED=1`)**: `php -l` `59/59` (`0` errors), `node --check` `3/3` (`exit 0`), `tests/run_tests.php` **`727/727` passed**, `tests/test_functional_http.php` **`405/405` passed**, `tests/test_adversarial_b1..b5.php` **`344/344` (`B1 69/69`, `B2 50/50`, `B3 69/69`, `B4 78/78`, `B5 78/78` — `APPROVE`)**, and `tests/test_coverage_audit.php` **`211/211` (`100.0%`, `AUDIT PASSED`)**.
  - **Mode B (`training_postgresql` + `pgscripts`, `LMS_DOCKER_FORCE_SIMULATED=1`)**: `tests/run_tests.php` **`741/741` passed**.
  - **GitHub Actions CI & Live Validation**: CI Run [`44367586062`](https://github.com/jmrenouard/lightpath-training-portal/actions/runs/44367586062) passed (`success` on `ubuntu-latest`); rebuilt Docker Compose container (`lightpath-training-portal-lms-1`) passed `healthy` and HTTP `200` checks across both courses; live Browser QA signed off across all routes with `0` CSP/JS/404 errors; PR `#46` merged and Issue `#45` closed.

---

### `ACT-002` — MIT Open-Source Release `v2.0.0`, Standalone Demo Pack, First-Boot CSPRNG Hardening, Docker & CI (Issue `#43` / PR `#44`)
- **Timestamp (UTC)**: `2026-11-16T23:49:38Z`
- **Status**: `COMPLETED`
- **GitHub Issue / PR**: Issue [`#43`](https://github.com/jmrenouard/lightpath-training-portal/issues/43) | PR [`#44`](https://github.com/jmrenouard/lightpath-training-portal/pull/44)
- **Branch / Commits**: `feat/mit-open-source-v2` -> `main` (Commits: `f9226fc`, `97ae417`, Merge: `2bac6e9`, Tag: `v2.0.0`)
- **Request**:
  - Prepare the repository for MIT open-source publication (`v2.0.0`) by decoupling commercial courseware via `LMS_COURSE_ROOT`, bundling an out-of-the-box MIT demo course-pack, removing static default passwords, and shipping 1-command Docker Compose and GitHub Actions CI.
- **Changes**:
  - **MIT Licensing & Governance (`LICENSE`, `SECURITY.md`, `CONTRIBUTING.md`, `CHANGELOG.md`, `README.md`, `composer.json`, `package.json`, `.gitignore`)**: Published the engine under MIT (`php >= 8.3`), added security disclosure policy and contributor checklist, aligned `truncateUtf8` pure-PHP fallback parity, and archived internal historical ledgers under `docs/internal/` (`RCA-171` / `RCA-LMS-41`).
  - **Standalone Demo Course-Pack (`examples/demo-course/**`, `config/courses.php`, `bin/sync-course.php`, `bin/export_slides_cache.py`, `src/Course/MarkdownParser.php`)**: Delivered 2 PostgreSQL 18 modules, 1 7-pillar Qualiopi lab, cheatsheets, programme, Python companion scripts, and MP4 Range stub with `LMS_COURSE_ROOT` / `LMS_PGSCRIPTS_ROOT` resolution.
  - **First-Boot Credential Hardening (`src/Core/{JsonStorage,StorageInterface}.php`, `config/features.php`, `templates/pages/login.php`)**: Removed hardcoded passwords from `JsonStorage::ensureDefaultUsers()` in favor of `LMS_INIT_*_PASSWORD` environment variables or 24-hex-char CSPRNG generation (`random_bytes(12)`) written once to `$LMS_DATA_DIR/initial_admin_credentials.txt` (`0600`); defaulted `demo_quick_login` to `false`.
  - **Docker, CI & Dual-Mode Tests (`Dockerfile`, `docker-compose.yml`, `.dockerignore`, `.env.example`, `.github/workflows/ci.yml`, `tests/*`)**: Built non-root `lms` container (`php:8.3-cli-alpine3.21`), added CI pipeline on `ubuntu-latest`, and added Section 25 open-source/CSPRNG tests supporting both standalone Mode A and mounted `training_postgresql` Mode B.
- **Result**:
  - **Mode A (Standalone `examples/demo-course/`, `LMS_DOCKER_FORCE_SIMULATED=1`)**: `php -l` `59/59`, `node --check` `3/3`, `tests/run_tests.php` **`644/644`**, `tests/test_functional_http.php` **`155/155`**, `tests/test_adversarial_b1..b5.php` **`344/344` (`APPROVE`)**, and `tests/test_coverage_audit.php` **`211/211` (`100.0%`, `AUDIT PASSED`)**.
  - **Mode B (`training_postgresql` + `pgscripts`)**: `tests/run_tests.php` **`658/658`** (`34` modules/labs, `58` Studio slides M01); companion `training_postgresql` `pytest tests/test_rca_contracts.py -q` **`256/256`**.
  - **GitHub Actions CI & Release**: CI Run [`43982313884`](https://github.com/jmrenouard/lightpath-training-portal/actions/runs/43982313884) green on `ubuntu-latest`; PR `#44` merged, annotated tag `v2.0.0` created on `2bac6e9`, and Issue `#43` closed.

---

### `ACT-001` — Two-Iteration Ponytail 7-Axis Enterprise Hardening, Modular `src/Http/*` Split & Strict `style-src 'self'` CSP (Issues `#39` & `#41` / PRs `#40` & `#42`)
- **Timestamp (UTC)**: `2026-11-15T23:28:21Z` (Issue `#39` / PR `#40`) & `2026-11-16T00:50:23Z` (Issue `#41` / PR `#42`)
- **Status**: `COMPLETED`
- **GitHub Issues / PRs**: Issue [`#39`](https://github.com/jmrenouard/lightpath-training-portal/issues/39) / PR [`#40`](https://github.com/jmrenouard/lightpath-training-portal/pull/40) (`30eb833`, `b378910`) & Issue [`#41`](https://github.com/jmrenouard/lightpath-training-portal/issues/41) / PR [`#42`](https://github.com/jmrenouard/lightpath-training-portal/pull/42) (`9d907e2`, `c74134f`)
- **Branches**: `feat/issue-39-ponytail-audit-7-axes`, `feat/issue-41-deep-7-axis-iterations` -> `main`
- **Request**:
  - Execute a rigorous multi-iteration `/ponytail-audit` and `/dev-team` 7-axis enterprise consolidation eliminating security bypasses, monolithic routing/templates, inline CSS/JS CSP exceptions, and documentation drift with zero deferred items.
- **Changes**:
  - **Issue `#39` / PR `#40` (7-Axis Ponytail Audit & Remediation, `RCA-169`)**: Enforced `lms_student` non-superuser role + single-pass `LabSqlRunner::stripCommentsAndLiterals()` SQL scanner, persistent `(email|ip)` + `ip` login throttling, strict `Auth::canAccessCourse([])` empty-array guard, `FeatureManager` (`8` toggles), `AdminService` single-pass KPIs, `SakilaSeeder` (`12` Sakila + `4` `pgbench` tables), non-blocking `proc_open` Docker timeouts + cgroup limits, `DownloadController::resolveHttpRange()` (`200`/`206`/`416`), WCAG AA `--text-muted` contrast, translation of `514` French source comments to English, and the 9-guide engineering suite (`PROJECT-CONTEXT.md`, `README.md`, `docs/*.md`).
  - **Issue `#41` / PR `#42` (Deep 7-Axis Execution & Zero-Deferral Closure, `RCA-170`)**:
    - Resolved `P1-6` strict CSP: enforced `style-src 'self'` across all portal pages + `Security::sendStandaloneDocumentCspHeader()` for inline HTML previews, eliminated all `420` inline `style=` attributes (`0` across `src/` & `templates/`), added CSSOM `data-progress` hydration, `Security::verifySameOriginRequest()`, HTTP `405` on mutating `GET api_*`, `Auth::isUserActive()` mid-session revocation, and SHA-256 tamper-evident `prev_hash`/`entry_hash` audit chaining (`verifyAuditIntegrity()`).
    - Resolved `P1-7` modularization: split `public/index.php` (`347` lines) into `src/Http/{AuthController,LearnerController,LabApiController,AdminController}.php` and `templates/pages/admin.php` into 7 lazy-loaded `templates/partials/admin/tab_*.php` views; expanded `config/features.php` & `FeatureManager` to `10` flags, `4` deployment profiles, per-course overrides, and JSON config export/import.
    - Added Docker savepoints, bounded `pgbench`, live PG18 diagnostics, 34-lab readiness checks, idle fleet reaper, chaos heal, `AdminService::detectAtRiskLearners()` + completion matrix + Cohort CSV / Qualiopi JSON exports, plus `docs/DEPLOYMENT_AND_OPERATIONS.md` & `docs/DEVELOPER_AND_TESTING_GUIDE.md`.
- **Result**:
  - **Issue `#39` / PR `#40`**: `48/48` `php -l`, `541/541` `tests/run_tests.php`, `88/88` `tests/test_functional_http.php`, `162/162` (`100.0%`) `tests/test_coverage_audit.php`, `B1`–`B5` `344/344` (`APPROVE`), and Iteration 2 live `@playwright/mcp` Browser QA (`0` console/CSP errors); PR `#40` merged and Issue `#39` closed.
  - **Issue `#41` / PR `#42`**: `59/59` `php -l`, `3/3` `node --check`, `641/641` `tests/run_tests.php`, `155/155` `tests/test_functional_http.php`, `211/211` (`100.0%` incl. `src/Http` & strict `style-src 'self'` check) `tests/test_coverage_audit.php`, `B1`–`B5` `344/344` (`APPROVE`), `13/13` Markdown link/Mermaid/LaTeX validation clean, and Iteration B live Browser QA (`0` CSP/JS/overflow errors across `1440x900`, `768x1024`, `375x812`); PR `#42` merged and Issue `#41` closed.
