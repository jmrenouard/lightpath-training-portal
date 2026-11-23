# Tutorial: Run a Local Quickstart Sandbox

> **Learning Goal:** Boot LightPath Training Portal in a temporary local directory, sign in using auto-generated first-boot credentials, and explore a simulated SQL lab.
>
> **Environment Warning:** Run this tutorial only in an isolated local workspace with a temporary `LMS_DATA_DIR`. Never reuse tutorial storage directories or passwords in production.

## Prerequisites

Make sure your workstation satisfies these requirements before starting:

- PHP `>= 8.3` CLI with `ext-json` and `ext-pdo` enabled (`ext-mbstring` and `ext-zip` recommended)
- Free local TCP port `8080`
- Repository checked out locally and terminal shell (`bash` or `PowerShell`)

## What You Will Build

By the end of this tutorial, you will have:

1. Started the built-in PHP development server against an isolated `LMS_DATA_DIR` with `LMS_DOCKER_FORCE_SIMULATED=1`.
2. Located the CSPRNG credentials generated on first boot in `initial_admin_credentials.txt`.
3. Signed in, opened a PostgreSQL 18 module, and inspected simulated PG18 diagnostics in `cloud_simulated` mode.
4. Stopped the sandbox server and removed the temporary data directory cleanly.

## Step 1: Start an Isolated Local Sandbox

Create a temporary data directory outside production storage, enable deterministic simulated Docker labs, and launch the zero-dependency PHP server:

```bash
export LMS_DATA_DIR="$(mktemp -d)"
export LMS_DOCKER_FORCE_SIMULATED=1
php -S localhost:8080 -t public
```

In PowerShell, run the equivalent isolated startup commands:

```powershell
$env:LMS_DATA_DIR = Join-Path $env:TEMP ("lms-tutorial-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
New-Item -ItemType Directory -Path $env:LMS_DATA_DIR -Force | Out-Null
$env:LMS_DOCKER_FORCE_SIMULATED = "1"
php -S localhost:8080 -t public
```

Expected terminal output:

```text
[Mon Nov 23 10:00:00 2026] PHP 8.3.x Development Server (http://localhost:8080) started
```

> [!NOTE]
> The bundled `docker-compose.yml` file uses the same `LMS_DOCKER_FORCE_SIMULATED=1` default inside a non-root `php:8.3-cli-alpine3.21` container mounted to `lms_data`.

## Step 2: Trigger First Boot and Inspect Generated Credentials

Open a second terminal while keeping the PHP server running, then send one request to the public verification route to initialize `JsonStorage`:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8080/index.php?page=verify"
cat "$LMS_DATA_DIR/initial_admin_credentials.txt"
```

Expected output:

```text
200
# LightPath Training Portal - Initial Generated Credentials
# Generated at: 2026-11-23 10:00:05
# Rotate these passwords or delete this file after first sign-in.

admin   : jmrenouard@lightpath.fr / 8f2a...
trainer : formateur@lightpath.fr / 4c91...
student : stagiaire@lightpath.fr / a71b...
```

Notice that `JsonStorage::ensureDefaultUsers()` generates 24-hex-character random passwords on first boot (`mode 0600`) instead of hardcoding static passwords.

## Step 3: Sign In and Test the Simulated Workbench

Open `http://localhost:8080/` in your browser and sign in with the generated `stagiaire@lightpath.fr` (student) or `jmrenouard@lightpath.fr` (admin) credentials from Step 2.

1. Open the **PostgreSQL 18** track loaded automatically from `examples/demo-course/`.
2. Open **Module 01** (`module_01_introduction_postgresql_18`) and answer the formative quiz.
3. Navigate to **Lab 01** (`http://localhost:8080/index.php?page=lab&course=postgresql-18&id=lab_01_introduction_setup_postgresql_18`).
4. Click the **PG18 Diagnostics** (`#btn-pg-diag`) button in the lab workbench toolbar (or click **Schema Explorer** or **pgbench**) to inspect simulated telemetry via `api_lab_pg_diag`.

> [!NOTE]
> Arbitrary SQL execution in `#lab-sql-editor` requires a live Docker container (covered in [02. First Docker PostgreSQL 18 Lab](02-first-docker-pg18-lab.md)). When `LMS_DOCKER_FORCE_SIMULATED=1` is set, **PG18 Diagnostics**, **Schema Explorer**, **Savepoints**, **pgbench**, and **Readiness** return deterministic simulated payloads.

Expected diagnostic output fields in `cloud_simulated` mode:

```json
{
    "success": true,
    "simulated": true,
    "mode": "cloud_simulated",
    "container": "lp_pg18_user_3",
    "database": "formation_pg",
    "server_version": "PostgreSQL 18.0 (Cloud Simulated Sandbox)"
}
```

You can also switch courses from the dashboard to explore the bundled `git-basics` (`examples/git-basics-course/`) course pack with 3 modules and 3 practical exercises.

## Step 4: Clean Up the Sandbox

Return to the terminal running `php -S localhost:8080 -t public`, press `Ctrl+C` to stop the server, and remove the temporary `LMS_DATA_DIR` directory:

```bash
rm -rf "$LMS_DATA_DIR"
unset LMS_DATA_DIR LMS_DOCKER_FORCE_SIMULATED
```

Expected outcome:

- All temporary JSON stores (`users.json`, `progress.json`, `audit_logs.json`, and `initial_admin_credentials.txt`) are deleted from disk.
- Your repository working tree remains completely unmodified.

## Next Steps

- Run a live per-learner PostgreSQL 18 container lab: [02. First Docker PostgreSQL 18 Lab](02-first-docker-pg18-lab.md)
- Deploy the portal with Docker or PDO persistence: [How-To Guides](../02-how-to-guides/index.md)
- Inspect environment variables and default endpoints: [Reference](../03-reference/index.md)
- Understand dual storage and strict CSP architecture: [Explanation](../04-explanation/index.md)
