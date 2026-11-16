# Security Policy

## Supported Versions

| Version | Supported |
| :--- | :---: |
| `2.0.x` | Yes |
| `< 2.0.0` | No |

## Reporting a Vulnerability

Please report suspected security vulnerabilities privately by emailing **contact@lightpath.fr** with `[SECURITY] lightpath-training-portal` in the subject line. **Do not** disclose security issues in public GitHub issues or pull requests prior to coordinated remediation.

Include in your report:
- Affected file path, HTTP route/action, or CLI entrypoint
- Minimal reproduction steps or proof-of-concept request
- Potential security impact (authentication, RBAC, SQL sandbox, CSP, file access)

## Response SLA

- **Acknowledgment:** Within **48 hours** of receipt.
- **Triage & Remediation Plan:** Communicated after reproduction and severity assessment.
- **Coordinated Disclosure:** Security advisories and changelogs are published once a verified fix is merged and released.

## Security Architecture Reference

For implementation details on threat boundaries, strict CSP (`script-src 'self' 'sha256-...'` and `style-src 'self'`), CSRF and same-origin verification, persistent rate limiting, RBAC & course scoping, SHA-256 tamper-evident audit hash chains, and PostgreSQL `lms_student` SQL sandbox isolation, see [docs/SECURITY.md](docs/SECURITY.md).
