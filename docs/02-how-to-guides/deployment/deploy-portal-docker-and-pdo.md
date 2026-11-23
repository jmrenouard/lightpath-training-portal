# How-To: Deploy Portal With Docker and PDO

Read the [Environment and Feature Flags](../../03-reference/configuration/environment-and-feature-flags.md) reference before you start this deployment procedure.

## Preconditions

1. Make sure that you have root or `sudo` access on the target host.
2. Make sure that PHP `>= 8.3` with `ext-json`, `ext-mbstring`, and `ext-pdo` is active.
3. Make sure that `ext-pdo_pgsql` is active if you select PostgreSQL `PdoStorage`.
4. Make sure that Docker Engine and Docker Compose v2 are active for container deployments.

## Safety Instructions

**CAUTION:** Do not add a global `Content-Security-Policy` header in Nginx or Apache. Duplicate headers break the SHA-256 script hash and standalone HTML previews.

**WARNING:** Set `LMS_INIT_ADMIN_PASSWORD` and `LMS_CERT_HMAC_SECRET` before public network exposure. Default secrets allow unauthorized administrative access and data loss.

## Procedure

### Deploy With Docker Compose

1. Copy `.env.example` to `.env` in the repository root.

```bash
cp .env.example .env
```

2. Edit `.env` to set `LMS_PORT`, `LMS_STORAGE_DRIVER`, and `LMS_INIT_ADMIN_PASSWORD`.

3. If the host has no Docker socket for student containers, set `LMS_DOCKER_FORCE_SIMULATED=1`.

4. Start the non-root `php:8.3-cli-alpine3.21` service in detached mode:

```bash
docker compose up -d
```

### Configure PostgreSQL PdoStorage

1. Connect to your PostgreSQL 18 server as a database superuser.

2. Create a non-superuser role and an application database:

```sql
CREATE ROLE lms_app WITH LOGIN PASSWORD 'REPLACE_WITH_STRONG_SECRET';
CREATE DATABASE lightpath_lms OWNER lms_app;
```

3. Set the PDO environment variables in your service unit or FastCGI block:

```bash
export LMS_STORAGE_DRIVER=pdo
export LMS_PDO_DSN="pgsql:host=127.0.0.1;port=5432;dbname=lightpath_lms"
export LMS_PDO_USER="lms_app"
export LMS_PDO_PASS="REPLACE_WITH_STRONG_SECRET"
```

4. Send one HTTP request to `index.php?page=verify` so `PdoStorage::initSchema()` creates `lms_kv_store`.

### Configure Nginx and Pre-Pull Lab Images

1. Point the Nginx `root` directive directly to the `public` directory.

2. Add a `location ~ /\.` rule that denies all access to hidden files.

3. Pass `HTTP_X_FORWARDED_PROTO` to `php8.3-fpm` so TLS detection activates HSTS and secure cookies.

4. Omit any Nginx `Content-Security-Policy` header so PHP sends `Security::sendSecurityHeaders()` directly.

5. Pre-pull the PostgreSQL 18 lab image on hosts with live student containers:

```bash
docker pull postgres:18
docker tag postgres:18 docker-pg-primary:18
```

6. Schedule the idle reaper and container prune cron tasks in `/etc/cron.d/lightpath-lms`:

```cron
*/15 * * * * www-data /usr/bin/php /var/www/lightpath-training-portal/bin/lab-runner.php reap-idle 3600 --json >> /var/log/lightpath-lms/reaper.log 2>&1
30 2 * * * www-data /usr/bin/php /var/www/lightpath-training-portal/bin/lab-runner.php prune --json >> /var/log/lightpath-lms/prune.log 2>&1
```

## Verification

1. Run the verification endpoint check and make sure that HTTP `200` returns:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8080/index.php?page=verify"
```

2. Run the automated PHP test suites before production traffic starts:

```bash
php tests/run_tests.php
php tests/test_functional_http.php
```

3. Sign in as administrator and open `?page=admin&tab=system`.

4. Make sure that all seven system health checks show `OK`.

## Rollback

If an error occurs after deployment or PDO migration, do this rollback procedure:

1. Stop the updated container or PHP-FPM site:

```bash
docker compose down
```

2. Restore the last `LMS_DATA_DIR` backup or PostgreSQL dump:

```bash
pg_restore --clean --if-exists -h 127.0.0.1 -U lms_app -d lightpath_lms /var/backups/lightpath_lms.dump
```

3. Switch `LMS_STORAGE_DRIVER` back to `json` if the relational store fails at boot.

4. Delete stale `catalog_cache_*.json` files in `LMS_DATA_DIR` and restart the service.

**NOTE:** Once `PdoStorage` connects at startup, runtime write errors do not fall back to JSON files.

## Related Documentation

- Reference: [Environment and Feature Flags](../../03-reference/configuration/environment-and-feature-flags.md)
- Reference: [CLI Utilities and Exit Codes](../../03-reference/cli-and-scripts/cli-utilities-and-exit-codes.md)
- Explanation: [Zero-Dependency LMS Architecture](../../04-explanation/architecture-overview/zero-dependency-lms-architecture.md)
- Explanation: [ADR-001 Zero-Dependency, Zero-CDN, and Strict CSP](../../04-explanation/design-decisions/adr-001-zero-dependency-zero-cdn-strict-csp.md)
