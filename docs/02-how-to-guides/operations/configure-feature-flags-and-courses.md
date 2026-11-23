# How-To: Configure Feature Flags and Courses

Read the [Environment and Feature Flags](../../03-reference/configuration/environment-and-feature-flags.md) reference before you modify platform flags or course definitions.

## Preconditions

1. Make sure that you sign in with an `admin` account (`trainer` accounts receive HTTP `403`).
2. Make sure that every custom course directory sits inside the LMS workspace directory.
3. Make sure that you export a backup JSON snapshot before you apply a new feature profile.

## Safety Instructions

**CAUTION:** Export the current feature configuration JSON before you apply a profile or overwrite flags. Unintended profile changes disable `docker_labs` and dependent `chaos_engineering` across all courses.

## Procedure

### Export a Configuration Backup and Apply a Profile

1. Open `?page=admin&tab=system` in the administrator panel.

2. Click the JSON export button (`?action=export_features_json`) and save the file locally.

3. To apply a preset profile (`admin_apply_feature_profile`), click one of the four profile buttons:
   - `full_enterprise`
   - `classroom_workshop`
   - `exam_lockdown`
   - `static_hosting_nodocker`

4. To customize individual toggles, select or clear checkboxes in the feature flag form and submit `admin_save_features`.

5. If you disable `docker_labs`, note that `FeatureManager` automatically turns off `chaos_engineering`.

### Set Per-Course Feature Overrides

1. Stay on `?page=admin&tab=system` and locate the per-course override card.

2. Select the target course slug in the `override_course` list and load its state.

3. Change individual flags to `inherit`, `1`, or `0`, then submit `admin_save_course_features`.

4. To restore global defaults for that course, click the reset button (`reset_course_features=1`).

### Register a Custom Course and Flush Caches

1. Synchronize the new course directory from the CLI to write its manifest:

```bash
php bin/sync-course.php <course-slug> <source-directory>
```

2. If the pack contains `.pptx` or `_slides.pdf` files, generate Studio slide PNG caches:

```bash
python bin/export_slides_cache.py
```

3. Open `?page=admin&tab=courses` and fill in `slug`, `title`, `category`, `duration`, and `source_root`.

4. Submit the `admin_add_course` form (do not use reserved slug `postgresql-18`).

5. Open `?page=admin&tab=system` and submit `admin_clear_cache` to remove stale `catalog_cache_*.json` files.

## Verification

1. Open the dashboard (`index.php?page=dashboard&course=<course-slug>`) and make sure that all modules and labs load.

2. Make sure that disabled features hide their sidebar links and return HTTP `403` on API calls.

3. Run the verification test suite if you modified `config/courses.php` or `config/features.php`:

```bash
php tests/run_tests.php
```

## Rollback

If a feature profile or custom course breaks expected behavior, do this rollback procedure:

1. Open `?page=admin&tab=system`.

2. Open the JSON import panel (`admin_import_features_json`), paste the backup JSON from Step 2, and submit.

3. To revert a per-course override, select the course slug and click `reset_course_features=1`.

4. To remove a problematic custom course, open `?page=admin&tab=courses` and submit `admin_delete_course`.

5. Run `admin_clear_cache` to rebuild clean catalog caches.

**NOTE:** Custom courses persist in `custom_courses.json` inside `LMS_DATA_DIR`, separate from `config/courses.php`.

## Related Documentation

- Reference: [Environment and Feature Flags](../../03-reference/configuration/environment-and-feature-flags.md)
- Reference: [CLI Utilities and Exit Codes](../../03-reference/cli-and-scripts/cli-utilities-and-exit-codes.md)
- Explanation: [Zero-Dependency LMS Architecture](../../04-explanation/architecture-overview/zero-dependency-lms-architecture.md)
- Explanation: [ADR-002 Versioned Diataxis MkDocs Release](../../04-explanation/design-decisions/adr-002-versioned-diataxis-mkdocs-publishing.md)
