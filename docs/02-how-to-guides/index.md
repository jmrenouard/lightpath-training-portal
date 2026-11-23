# How-To Guides — Operations and Runbooks

Operational procedures and incident runbooks for **LightPath Training Portal**. Every non-index guide in this quadrant follows **ASD-STE100 Issue 9** procedural English (`<= 20` words per sentence, active imperative mood, explicit `Verification` and `Rollback` sections).

> **Quadrant Focus:** Use these guides to complete production tasks and recover from incidents. For hands-on learning, use [Tutorials](../01-tutorials/index.md). For parameter tables, routes, and alert thresholds, use [Reference](../03-reference/index.md). For architecture and design trade-offs, use [Explanation](../04-explanation/index.md).

## Guides by Operational Area

### Deployment
- [Deploy Portal With Docker and PDO](deployment/deploy-portal-docker-and-pdo.md) — Provision `php:8.3` runtime, configure `JsonStorage` or PostgreSQL `PdoStorage`, enable Nginx/Apache strict CSP compatibility, and pre-pull lab images.

### Operations
- [Manage Cohorts and Qualiopi Exports](operations/manage-cohorts-and-qualiopi-exports.md) — Configure live classroom presets, monitor at-risk learners, countersign attendance, and generate OPCO CSV, Qualiopi JSON, and SCORM 1.2 exports.
- [Configure Feature Flags and Courses](operations/configure-feature-flags-and-courses.md) — Toggle the 10 modular flags, apply deployment profiles, configure per-course overrides, register custom courses, and flush catalog caches.

### Incident Response
- [Remediate Docker Lab and Auth Incidents](incident-response/remediate-docker-lab-and-auth-incidents.md) — Reclaim idle `lp_pg18_user_<id>` containers, heal chaos locks, diagnose stuck WSL2/Docker daemons, and clear `email|ip` login lockouts.

### Security
- [Harden CSP, Credentials, and Audit Chain](security/harden-csp-credentials-and-audit-chain.md) — Rotate first-boot passwords, configure `LMS_CERT_HMAC_SECRET`, preserve `style-src 'self'` strict CSP headers, and verify the SHA-256 audit hash chain.

## Cross-Quadrant Navigation

- Return to portal home: [Documentation Portal](../index.md)
- Browse CLI and API specifications: [Reference](../03-reference/index.md)
- Read architectural deep dives and ADRs: [Explanation](../04-explanation/index.md)
