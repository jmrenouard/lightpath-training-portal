# How-To: Manage Cohorts and Qualiopi Exports

Read the [HTTP Routes and JSON API](../../03-reference/api-and-routes/http-routes-and-json-api.md) reference before you execute trainer cohort exports.

## Preconditions

1. Make sure that your user role is `trainer` or `admin`.
2. Make sure that learners have active accounts and course enrollments for the target course slug.
3. Make sure that `scorm_export` is active in `FeatureManager` when you export SCORM 1.2 packages.

## Safety Instructions

**CAUTION:** Enable `lock_exam` and `lock_solutions` before a certification session starts. Unlocked solutions let learners read lab answers and run `api_run_lab_solution`.

## Procedure

### Apply Live Classroom Presets and Broadcasts

1. Sign in as `trainer` or `admin` and open `?page=admin&tab=cohort`.

2. Click one preset button in the preset toolbar to set the room state:
   - Click `lecture` (`admin_apply_classroom_preset`) to lock solutions and exams during instruction.
   - Click `guided_lab` to unlock solutions and keep the certification exam locked.
   - Click `exam_lockdown` to lock solutions and open the exam form for candidates.
   - Click `open_review` to unlock both solutions and exams for review.

3. Enter an announcement in the broadcast field and submit `save_classroom_state` to display a live banner.

4. Inspect the at-risk alert banner above the matrix to find enrolled learners below `20%` completion.

### Countersign Half-Day Attendance Slots

1. Locate the target learner row inside the cohort matrix table.

2. Click the attendance link (`index.php?page=attendance&course=<slug>&student_id=<uid>`) for that learner.

3. Review the learner half-day slots from `J1_AM` to `J11_PM`.

4. Submit the trainer countersignature form (`action=sign_trainer_attendance`) to seal the half-day attendance record.

### Generate OPCO, Qualiopi, and SCORM 1.2 Exports

1. Return to `?page=admin&tab=cohort` for the active course slug.

2. Click `export_cohort_csv` to download the `;`-delimited UTF-8 BOM cohort progression CSV.

3. Click `export_attendance_csv` to download the OPCO half-day attendance sheet.

4. Click `export_qualiopi_csv` to download consolidated diagnostics, exam scores, and hot or J+60 cold survey ratings.

5. Click `export_qualiopi_json` to download the structured Qualiopi audit dossier (`schema_version: 2.0`).

6. If external LMS import is necessary, click `export_scorm_manifest` or `export_scorm_zip`.

## Verification

1. Open the downloaded `export_qualiopi_json` file and make sure that `audit_integrity.valid` equals `true`.

2. Open the downloaded CSV exports in a spreadsheet viewer and make sure that formula cells start with `'`.

3. Open the downloaded SCORM 1.2 ZIP archive and make sure that `imsmanifest.xml` is present at the root.

4. Make sure that public certificate lookup works for a certified learner:

```bash
curl -s "http://127.0.0.1:8080/index.php?page=verify&code=LP-PG18-CERT-3-1A2B3C4D" | grep -i "certificat"
```

## Rollback

If you apply the wrong classroom preset or lock state, do this rollback procedure:

1. Open `?page=admin&tab=cohort`.

2. Click the `open_review` preset button (`admin_apply_classroom_preset`) to restore standard access.

3. Clear the broadcast field in `save_classroom_state` and submit the form to hide the banner.

4. If an attendance record has a mistake, re-submit `sign_trainer_attendance` with the corrected status.

**NOTE:** CSV and JSON export actions only read storage data and make zero state changes.

## Related Documentation

- Reference: [HTTP Routes and JSON API](../../03-reference/api-and-routes/http-routes-and-json-api.md)
- Reference: [CLI Utilities and Exit Codes](../../03-reference/cli-and-scripts/cli-utilities-and-exit-codes.md)
- Explanation: [SQL Sandbox and Qualiopi Integrity](../../04-explanation/deep-dives/sql-sandbox-and-qualiopi-integrity.md)
- Explanation: [Zero-Dependency LMS Architecture](../../04-explanation/architecture-overview/zero-dependency-lms-architecture.md)
