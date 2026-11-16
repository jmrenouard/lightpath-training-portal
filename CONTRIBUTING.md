# Contributing to LightPath Training Portal

Thank you for contributing to **LightPath Training Portal**. Keep contributions minimal, secure, and self-contained.

## Architecture & Core Invariants

1. **Zero-Dependency PHP `>= 8.3`:** Core code runs on standard PHP `>= 8.3` (`ext-json`, `ext-pdo`) using the custom PSR-4 autoloader in [src/bootstrap.php](src/bootstrap.php). Optional extensions (`ext-mbstring`, `ext-zip`, `ext-pdo_pgsql`, `ext-pdo_sqlite`) have pure-PHP fallbacks or graceful degradation—do not require runtime Composer or Node packages.
2. **Self-Hosted Assets Only (Zero External CDNs):** All scripts, stylesheets, diagramming tools, and icons must be local. Never introduce external CDN URLs.
3. **Strict Content Security Policy (Zero Inline Styles or Handlers):**
   - Never add inline `style="..."` attributes or `on*=...` event handlers in `src/` or `templates/`.
   - Place styling in [public/assets/css/app.css](public/assets/css/app.css) and delegated event listeners in [public/assets/js/app.js](public/assets/js/app.js).
4. **100% English Source Code Comments:** All PHP, JavaScript, CSS, Python, SQL, and shell comments/docstrings—as well as GitHub issues, commits, and PRs—must be strictly written in technical English (enforced by [tests/test_coverage_audit.php](tests/test_coverage_audit.php)).
5. **100% Public API Test Coverage:** Any new `public` method in `src/Core/`, `src/Course/`, `src/Docker/`, or `src/Http/` must be covered by [tests/run_tests.php](tests/run_tests.php) or [tests/test_functional_http.php](tests/test_functional_http.php).

## Local Development Setup

```bash
# Enable local git hooks (blocks direct commits/pushes to main)
git config core.hooksPath .githooks

# Start local PHP server with the bundled MIT demo course-pack
php -S localhost:8080 -t public
```

## Running Verification Suites

Run the full PHP verification pipeline (`run_tests.php`, `test_functional_http.php`, and `test_coverage_audit.php`):

```bash
composer test:all
```

Additional adversarial and browser E2E checks:

```bash
for f in tests/test_adversarial_b*.php; do php "$f"; done
npm run test:e2e
```

## Git Workflow & Conventional Commits

- Open or reference a GitHub issue before implementing changes.
- Always work on a dedicated branch (`feat/...`, `fix/...`, `docs/...`, `chore/...`)—never commit directly to `main`.
- Use English [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `chore:`, `test:`).

## Pull Request Checklist

- [ ] `composer test:all` passes cleanly (`run_tests.php`, `test_functional_http.php`, `test_coverage_audit.php`)
- [ ] `php -l` passes with zero syntax errors across all modified PHP files
- [ ] Zero inline `style=` or `on*=` attributes and zero external CDN URLs introduced
- [ ] All source comments, commit messages, and PR descriptions are strictly in English
- [ ] Relevant documentation in `README.md`, `docs/`, or `CHANGELOG.md` is updated when behavior changes
