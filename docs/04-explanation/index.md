# Explanation

Architectural overviews, Architecture Decision Records (ADRs), and technical deep dives explaining the design trade-offs, security boundaries, and integrity guarantees of LightPath Training Portal (`v2.0.0`).

## Explanation Sections

| Document | Scope | Key Concepts |
| :--- | :--- | :--- |
| [Zero-Dependency LMS Architecture](architecture-overview/zero-dependency-lms-architecture.md) | System topology, domain controller dispatch, dual JSON/PDO concurrency, course-pack caching, and CSSOM frontend shell | `public/index.php`, `StorageInterface`, `mutateFile()`, `CourseRepository`, `MarkdownParser`, strict CSP UI |
| [ADR-001: Zero-Dependency, Zero-CDN, and Strict SHA-256 CSP](design-decisions/adr-001-zero-dependency-zero-cdn-strict-csp.md) | Why the LMS runtime avoids Composer/Node vendor dependencies, prohibits external CDNs, and enforces `style-src 'self'` | Zero-vendor PHP 8.3, offline classroom resilience, SHA-256 unload shim, standalone document CSP isolation |
| [ADR-002: Versioned Diataxis MkDocs Publishing](design-decisions/adr-002-versioned-diataxis-mkdocs-publishing.md) | Why documentation uses 4 Diataxis quadrants, ASD-STE100 procedural checks, immutable tag overlays, and SFTP publishing to IONOS | Diataxis governance, `scripts/build_versioned_docs.py`, `scripts/mkdocs_hooks.py`, `versions.json`, `.htaccess` |
| [Deep Dive: SQL Sandbox Isolation and Qualiopi Integrity](deep-dives/sql-sandbox-and-qualiopi-integrity.md) | Lexical SQL guard mechanics, PostgreSQL 18 role isolation, scoring formulas, and tamper-evident SHA-256 audit hash chaining | `LabSqlRunner::stripCommentsAndLiterals()`, `lms_student`, `Gamification`, `verifyAuditIntegrity()`, offline QR SVG |

## Related Documentation Quadrants

- Guided hands-on setup lessons: [Tutorials](../01-tutorials/index.md)
- Goal-oriented operational procedures: [How-To Guides](../02-how-to-guides/index.md)
- Exact configuration, API, CLI, and alert specs: [Reference](../03-reference/index.md)
- Documentation portal home: [LightPath Training Portal Documentation](../index.md)
- Legacy single-file architecture summary: [Architecture](../ARCHITECTURE.md)
