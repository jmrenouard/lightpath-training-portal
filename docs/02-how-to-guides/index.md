# How-To Guides — Operations and Runbooks

Operational procedures and incident runbooks for this portal. Every non-index guide follows **ASD-STE100 Issue 9** rules with Verification and Rollback sections.

> **Quadrant Focus:** Use these guides to complete tasks and recover from incidents. For hands-on learning, use [Tutorials](../01-tutorials/index.md). For tables and routes, use [Reference](../03-reference/index.md). For architecture, use [Explanation](../04-explanation/index.md).

## Guides by Operational Area

### Deployment
- [Deploy Portal With Docker and PDO](deployment/deploy-portal-docker-and-pdo.md) — Provision PHP 8.3, configure JsonStorage or PDO, and pre-pull images.

### Operations
- [Manage Cohorts and Qualiopi Exports](operations/manage-cohorts-and-qualiopi-exports.md) — Configure presets, monitor learners, countersign attendance, and export compliance files.
- [Configure Feature Flags and Courses](operations/configure-feature-flags-and-courses.md) — Toggle flags, apply profiles, register custom courses, and flush caches.

### Incident Response
- [Remediate Docker Lab and Auth Incidents](incident-response/remediate-docker-lab-and-auth-incidents.md) — Reclaim idle containers, heal chaos locks, and clear login lockouts.

### Security
- [Harden CSP, Credentials, and Audit Chain](security/harden-csp-credentials-and-audit-chain.md) — Rotate passwords, set HMAC secrets, preserve CSP, and verify audit logs.

## Cross-Quadrant Navigation

- Return to portal home: [Documentation Portal](../index.md)
- Browse CLI and API specifications: [Reference](../03-reference/index.md)
- Read architectural deep dives and ADRs: [Explanation](../04-explanation/index.md)
