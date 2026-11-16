# Course-Pack Authoring & Sync Guide — LightPath Training Portal

> **Scope:** Declarative course registration ([config/courses.php](../config/courses.php)), source directory conventions, Markdown & Quiz parsing rules, 7-pillar Qualiopi labs, Retina Mermaid & Studio Slide caching, and packaging CLI tools.

---

## 1. Declarative Course Registry

Courses are registered declaratively in [config/courses.php](../config/courses.php) and merged at runtime with optional custom courses persisted in `custom_courses.json` (`toggleCustomCourse`, `deleteCustomCourse`).

### Per-Course Feature Overrides (`FeatureManager`)
While [config/features.php](../config/features.php) defines 10 global modular flags and 4 deployment profiles (`full_enterprise`, `classroom_workshop`, `exam_lockdown`, `static_hosting_nodocker`), administrators can override individual flags **per course slug** (`FeatureManager::setCourseOverrides()`, `FeatureManager::clearCourseOverrides()`, `FeatureManager::isEnabledForCourse()`):
- Useful when hosting a theoretical/non-Docker course alongside PostgreSQL 18 (`docker_labs => false`, automatically disabling dependent `chaos_engineering` via `'requires' => ['docker_labs']`), or selectively tailoring `personal_notes` and `flashcards_srs` per curriculum.
- Configured in **Administration -> Système** (`action=admin_save_course_features`) and exportable/importable as JSON (`exportConfigJson()` / `importConfigJson()`).

### Track Schema (`config/courses.php`)
Each course entry defines:
- Top-level metadata (`slug`, `title`, `subtitle`, `duration`, `level`, `icon`, `docker_enabled`, `source_root`, `pgscripts_root`, `description`).
- `tracks`: Ordered associative map of certification tracks. For the flagship `'postgresql-18'` course pack:
  - `'cursus-complet'`: Modules `01`–`20` (`JOUR 1`..`JOUR 5`, 35h–42h)
  - `'performance-tuning'`: Modules `21`–`25` (`ÉTAPE 1`..`ÉTAPE 3`)
  - `'ingenierie-specialisee'`: Modules `26`–`29` (`VOLET 1`..`VOLET 2`)
  - `'fondamentaux-sql'`: Modules `sql_01`–`sql_05` (`SOCLE 1`..`SOCLE 2`)
- Track keys use normalized `'module_filter'` arrays (with fallback support in `CourseRepository` and templates for legacy `'module_Filter'`).

---

## 2. Canonical Course-Pack Directory Layout

When pointing `source_root` to a LightPath course repository (e.g., `../training_postgresql`), [CourseRepository.php](../src/Course/CourseRepository.php) discovers artifacts across deterministic subdirectories:

| Directory | Contents & Discovery Pattern |
| :--- | :--- |
| `01_programme_et_referentiel/` | Qualiopi syllabus, evaluation grids, trainer guides (`referentiel` index) |
| `03_supports_de_cours/` | Module source Markdown files (`module_XX_*.md`, `module_sql_XX_*.md`), `.pptx` & `_slides.pdf` Studio decks |
| `04_ateliers_et_tp/` | Practical lab Markdown guides (`lab_XX_*.md`, `lab_sql_XX_*.md`) |
| `05_cheatsheets/` | Printable reference cheatsheets (`69` items in PG18 pack) |
| `pgexamples/` | Multi-language PostgreSQL client examples (`Python`, `Go`, `PHP`) |
| `pgscripts/` (configurable via `pgscripts_root`) | Pure-Python DBA diagnostic & benchmark toolkit (`35` scripts) with dual documentation (`README.md`, `README_fr.md`) |
| `video_engine/output/` | Module MP4 video lessons streamed via HTTP `206 Partial Content` and companion `.md` transcripts |
| `assets/` | Self-hosted diagrams, logos, and `assets/mermaid_cache/mermaid_<hash>.png` |

---

## 3. Markdown Course Authoring Rules

[MarkdownParser.php](../src/Course/MarkdownParser.php) compiles Markdown on demand and caches parsed structures in `storage/data/parsed_cache/`.

### Supported Extensions & Directives
- **GitHub Callout Alerts:** Rendered into styled `.lms-callout-*` cards with distinct accents:
  - `[!IMPORTANT]` -> `Important — Exigence Critique`
  - `[!WARNING]` -> `Attention — Point de Vigilance`
  - `[!CAUTION]` -> `Précaution Majeure en Production`
  - `[!TIP]` -> `Astuce & Bonne Pratique DBA`
  - `[!NOTE]` -> `Information Technique`
- **Inline SQL Workbench Injection:** Code fences tagged `sql` expose an *"Exécuter dans le Lab"* button carrying UTF-8 Base64 payloads decoded safely in `public/assets/js/app.js` using `TextDecoder`.
- **Quiz QCM Extraction:** `MarkdownParser::extractQuizQuestions()` parses quiz sections inside each module Markdown file so `api_submit_quiz` grades learner answers server-side against canonical keys.
- **Inline HTML Preview CSP Isolation:** Standalone compiled `.html` course files rendered inside LMS iframes (`?action=course_asset` / `DownloadController::streamFile()`) emit `Security::sendStandaloneDocumentCspHeader()` (`style-src 'self' 'unsafe-inline'` scoped strictly to standalone documents), whereas main LMS portal routes enforce zero-inline-style `style-src 'self'` (`P1-6` resolved).
- **Mermaid Diagrams (`renderMermaidBlock`):**
  1. Whitespace-normalizes the Mermaid source and computes `substr(hash('sha256', $normalized), 0, 16)`.
  2. Checks for a pre-rendered Retina 2x PNG at `$sourceRoot/assets/mermaid_cache/mermaid_<hash>.png` inside the active course pack and serves it (zoomable in the lightbox modal) via `resolveAssetUrl()` or the `?action=course_asset&course=...&path=...` controller fallback.
  3. Falls back cleanly to local `public/assets/mermaid.min.js` SVG rendering when no PNG cache matches (zero external CDNs).
  4. Always quote Mermaid node labels containing parentheses or brackets (e.g., `id["Node (Detail)"]`) to guarantee valid parsing.

### LaTeX Math Integrity Policy (When Authoring Technical Formulas)
Follow these five rules whenever formulas appear in course or portal Markdown:
1. Place display math `$$` at **column 0** (never indented inside lists) with blank lines before and after.
2. Restrict LaTeX operators strictly to standard built-ins (`\arg\max`, `\arg\min`, and `\text{...}`); never emit non-standard custom operator macros blocked by MathJax/KaTeX.
3. Preserve all subscript underscores (`_`) and superscript asterisks (`*`) with explicit indices (`\sum_{i=1}^n`).
4. Keep inline math tight (`$x$`, never `$ x $`) and escape prose currency/shell `$` (`\$100` or backticks).
5. Verify math spans remain unmodified across edits.

---

## 4. Authoring 7-Pillar Qualiopi Labs

Every practical lab in `04_ateliers_et_tp/` must conform to LightPath's **7 Qualiopi Pillars**:
1. **Metadata & Bloom Level:** Lab ID, timed duration, Bloom cognitive objective.
2. **Prerequisites & Idempotent Setup:** Target instance and repeatable seed baseline.
3. **Real-World Production Scenario:** E-commerce, banking, logistics, or Sakila/Pagila rental workflows (no trivial `foo/bar` tables).
4. **Step-by-Step Instructions:** Explicit context boundaries (`Bash` vs `psql` vs `Python`).
5. **Expected Outputs & Reference Plans:** Console outputs and reference `EXPLAIN` plans.
6. **Automated Validation Oracle:** Deterministic SQL/DO block confirming completion (`VALIDATION_OK` / boolean pass).
7. **Solution & REX Debriefing:** Production insights, common pitfalls, and DBA field experience.

### How `MarkdownParser::splitLabEnonceAndCorrige()` Works
- **Challenge Spoilers:** Solution blocks preceded by bulleted markers (`**Requête Solution :**`, `**Commandes Solutions :**`, `**Solutions Analytiques :**`, `**Corrigés Techniques :**`, `**Corrigés SQL :**`, etc.) are wrapped in collapsible `<details>` elements in full view and grouped in the Corrigé tab.
- **Section Split:** The lab splits automatically into **Énoncé** and **Corrigé** at the first `H2` matching `## Corrigé`, `## Commande de Validation`, `## Script de Validation`, `## Validation Automatisée`, or `## Solution` (with optional leading step number), while preserving any `"🌟 Pour Aller Plus Loin"` section inside the Énoncé.
- **Trainer Solution Lock:** When a trainer activates `lock_solutions` in Classroom Broadcast (or applies the `exam_lockdown` / `lecture` preset), [public/index.php](../public/index.php) and the modular `LabApiController` / `LearnerController` dispatch strip Corrigé tabs and disable `api_run_lab_solution` for non-staff learners.
- **34-Lab Readiness & Interactive Workbench:** Every lab (`01..29`, `sql_01..sql_05`) is mapped in `LabSqlRunner::checkLabReadiness()` (`api_lab_readiness` / `php bin/lab-runner.php readiness <uid> <lab_id>`) against its target database (`dvdrental` vs `formation_pg`), required PG18 extensions, container status, and deterministic SQL oracle (`getLabOracles()`), alongside per-user sandbox savepoints (`api_lab_savepoint`), bounded `pgbench` runs (`api_lab_pgbench`), live PG18 diagnostics (`api_lab_pg_diag`), and `Ctrl+Enter` SQL execution.

---

## 5. Slide Export & Course Packaging CLIs

### Export Studio 16:9 Slide Caches (`bin/export_slides_cache.py`)
Generates `public/assets/slides_cache/<module_id>/slide_001.png..slide_NNN.png` plus `manifest.json` by aligning PDF slide text metadata 1:1 with PowerPoint speaker notes (`135` DPI PNGs):

```bash
python bin/export_slides_cache.py
```

- Slides are categorized into `title`, `objectives`, `concept`, `summary`, `quiz`, and `lab` types and displayed in the interactive slide carousel inside module reader pages.
- Note: [MarkdownParser::parseModuleFile()](../src/Course/MarkdownParser.php) checks `$manifestMtime` on `public/assets/slides_cache/<module_id>/manifest.json` so updated slide decks invalidate module caches automatically.

### Sync & Package Course Metadata (`bin/sync-course.php`)

```bash
php bin/sync-course.php <course-slug> <source-directory>
```

- Sanitizes `<course-slug>` (`preg_replace('/[^a-zA-Z0-9_\-]/', '', ...)`, defaulting to `postgresql-18`), verifies `<source-directory>` exists, and writes `storage/courses/<slug>/manifest.json`.

### Catalog Cache Invalidation
- `CourseRepository::computeCatalogFingerprint()` tracks directory/file modification timestamps under fingerprint prefix `v8_rca164`.
- Administrators can trigger `CourseRepository::clearCatalogCache(): int` from **Administration -> Système** (`action=admin_clear_cache`) to flush `catalog_cache_*.json` files and inspect the removed file count in the audit trail.
