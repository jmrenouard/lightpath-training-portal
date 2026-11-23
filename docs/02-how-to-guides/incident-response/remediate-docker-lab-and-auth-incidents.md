# Runbook: Remediate Docker Lab and Auth Incidents

Read the [Error and Alert Matrix](../../03-reference/troubleshooting-codes/error-and-alert-matrix.md) before you execute remediation commands.

## Preconditions

1. Make sure that you have `trainer` or `admin` access (`admin` is necessary for login throttle unlocks).
2. Make sure that you have SSH or host terminal access for CLI diagnostics with `bin/lab-runner.php`.
3. Record the incident identifier, affected user ID, and course slug before you run destructive container resets.

## Safety Instructions

**WARNING:** Do not run `stop-all`, `prune`, or `reset` during an active graded lab without notice. Container resets erase uncommitted student tables inside `lp_pg18_user_<id>`.

**CAUTION:** Never unlock an `email|ip` brute-force throttle before you confirm the user identity. Brute-force attacks target repeated login failures.

## Procedure

### Remediate Idle Containers and Host RAM Pressure

1. Inspect fleet capacity and idle container counts from the host CLI:

```bash
php bin/lab-runner.php fleet --json
```

2. Stop student containers idle for more than `3600` seconds:

```bash
php bin/lab-runner.php reap-idle 3600 --json
```

3. Remove exited containers to reclaim host disk space:

```bash
php bin/lab-runner.php prune --json
```

4. If you work from the web UI, open `?page=admin&tab=docker` and submit `admin_reap_idle_docker`.

### Heal Chaos Fault Tables and Lock Contention

1. Check if residual chaos tables (`chaos_orders`, `chaos_ledger`, `chaos_sessions`, `chaos_wal_burst`) block student queries.

2. Run the fleet-wide chaos heal command from the CLI:

```bash
php bin/lab-runner.php heal --json
```

3. Or open `?page=admin&tab=docker` and click the `admin_heal_chaos` button.

4. If one student database stays corrupted, restore the student named savepoint instead of a full reset:

```bash
php bin/lab-runner.php savepoint <uid> list --json
php bin/lab-runner.php restore <uid> <savepoint_name> formation_pg --json
```

### Diagnose Stuck WSL2 or Docker Daemon Timeouts

1. Test direct container status for the affected user ID:

```bash
php bin/lab-runner.php status <uid> --json
```

2. Verify `LMS_WSL_DISTRO` (default `Ubuntu-24.04`) on Windows hosts if `proc_open()` timeouts occur.

3. Pull or tag the lab image if container creation fails with a missing image error:

```bash
docker pull postgres:18
docker tag postgres:18 docker-pg-primary:18
```

4. If the Docker daemon stays unavailable during class, switch to `LMS_DOCKER_FORCE_SIMULATED=1` and restart PHP.

### Clear Authentication Lockouts (`email|ip` and `ip`)

1. Sign in as `admin` and open `?page=admin&tab=users`.

2. Locate the locked `email|ip` or `ip|<addr>` entry in the login throttle inspector.

3. Confirm that the lockout came from a legitimate learner instead of an automated attack.

4. Click the unlock button (`action=admin_unlock_throttle`) to clear the `300`-second lockout bucket.

5. If the user forgot their password, reset it via `action=admin_reset_password` (`>= 6` characters).

## Verification

1. Run readiness and diagnostic checks on the repaired student container:

```bash
php bin/lab-runner.php diag <uid> --json
php bin/lab-runner.php readiness <uid> <lab_id> --json
```

2. Make sure that `fleet --json` shows `running_user_containers` below `max_containers` (`40`).

3. Ask the affected learner to sign in and run `SELECT version();` in the SQL workbench.

## Rollback

If remediation leaves a container or service in a degraded state, do this rollback procedure:

1. Recreate an unusable student container from a clean `postgres:18` baseline:

```bash
php bin/lab-runner.php reset <uid> --json
php bin/lab-runner.php seed <uid> all --json
```

2. If you enabled `LMS_DOCKER_FORCE_SIMULATED=1` temporarily, unset it after Docker recovers and click `refresh_runtime`.

3. If you unlocked an account that shows continued failed attempts, suspend the account via `admin_toggle_user_active`.

**NOTE:** Suspended accounts lose active web sessions immediately through `Auth::isUserActive()`.

## Related Documentation

- Reference: [Error and Alert Matrix](../../03-reference/troubleshooting-codes/error-and-alert-matrix.md)
- Reference: [CLI Utilities and Exit Codes](../../03-reference/cli-and-scripts/cli-utilities-and-exit-codes.md)
- Explanation: [SQL Sandbox and Qualiopi Integrity](../../04-explanation/deep-dives/sql-sandbox-and-qualiopi-integrity.md)
- Explanation: [Zero-Dependency LMS Architecture](../../04-explanation/architecture-overview/zero-dependency-lms-architecture.md)
