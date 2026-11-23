# Reference

Technical specifications, configuration matrices, HTTP and JSON API contracts, CLI command specifications, and error lookup tables for LightPath Training Portal (`v2.0.0`).

## Reference Sections

| Section | Coverage | Primary Audience |
| :--- | :--- | :--- |
| [Environment Variables and Feature Flags](configuration/environment-and-feature-flags.md) | Runtime environment variables, `config/app.php` defaults, `config/courses.php` registry, 10 feature flags, 4 deployment profiles, and PHP runtime requirements | Platform Administrators, DevOps Engineers |
| [HTTP Routes and JSON API Specification](api-and-routes/http-routes-and-json-api.md) | Security headers, page routes, `api_*` JSON contracts, download/export routes, authenticated form POST actions, and sandbox bounds | Integrators, Backend Engineers, QA Engineers |
| [CLI Utilities and Exit Codes](cli-and-scripts/cli-utilities-and-exit-codes.md) | `bin/lab-runner.php`, `bin/sync-course.php`, `bin/export_slides_cache.py`, `scripts/build_versioned_docs.py`, test runners, and exit codes | Operators, Instructors, CI Maintainers |
| [Error and Alert Matrix](troubleshooting-codes/error-and-alert-matrix.md) | HTTP status errors, auth lockouts, SQL guard rejections, Docker errors, PDO/audit chain alerts, and direct runbook remediation links | On-Call Operators, Instructors, Administrators |

## Related Documentation Quadrants

- Guided hands-on setup lessons: [Tutorials](../01-tutorials/index.md)
- Step-by-step operational procedures and runbooks: [How-To Guides](../02-how-to-guides/index.md)
- Architectural overview, ADRs, and internals: [Explanation](../04-explanation/index.md)
- Documentation portal home: [LightPath Training Portal Documentation](../index.md)
- Legacy single-file API summary: [API Reference](../API_REFERENCE.md)
