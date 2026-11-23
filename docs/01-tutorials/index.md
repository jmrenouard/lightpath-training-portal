# Tutorials — Hands-On Learning Labs

Welcome to the **LightPath Training Portal** learning-oriented tutorials. Each tutorial guides you through a safe, reproducible exercise inside an isolated local environment.

> **Quadrant Focus:** Tutorials teach core platform workflows step by step. For production procedures, use [How-To Guides](../02-how-to-guides/index.md). For parameter tables and routes, use [Reference](../03-reference/index.md). For design rationale, use [Explanation](../04-explanation/index.md).

## Learning Path

| Order | Tutorial | Target Outcome | Environment |
| :---: | :--- | :--- | :--- |
| **01** | [Local Quickstart Sandbox](01-local-quickstart-sandbox.md) | Boot the zero-dependency LMS on `localhost:8080`, retrieve auto-generated credentials, and test the bundled `postgresql-18` and `git-basics` packs | PHP `>= 8.3` + `LMS_DOCKER_FORCE_SIMULATED=1` |
| **02** | [First Docker PostgreSQL 18 Lab](02-first-docker-pg18-lab.md) | Launch an isolated `lp_pg18_user_<id>` container, seed Sakila and `pgbench`, create a savepoint, run a bounded benchmark, and pass a lab oracle | Local Docker / WSL2 (`postgres:18`) |
| **03** | [Author a Custom Course Pack](03-author-custom-course-pack.md) | Scaffold a custom course directory, author a module with a QCM quiz and 7-pillar lab, sync its manifest, and mount it in the portal | Local workspace sandbox |

## Next Steps

- Execute production and day-2 procedures: [How-To Guides](../02-how-to-guides/index.md)
- Look up environment variables, routes, and CLI flags: [Reference](../03-reference/index.md)
- Study architecture and security boundaries: [Explanation](../04-explanation/index.md)
- Return to the portal home page: [Documentation Portal](../index.md)
