#!/usr/bin/env python3
"""Minimal psycopg3 connection and diagnostic demo for PostgreSQL 18."""

import os
import sys

try:
    import psycopg
except ImportError:
    psycopg = None


def main() -> int:
    """Connect to PostgreSQL using environment configuration and print server version."""
    if psycopg is None:
        print("psycopg package is not installed. Run: pip install psycopg[binary]", file=sys.stderr)
        return 1

    dsn = os.environ.get(
        "PG_DEMO_DSN",
        "host=localhost port=5432 dbname=formation_pg user=postgres",
    )

    with psycopg.connect(dsn, connect_timeout=5) as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT current_database(), current_user, version();")
            row = cur.fetchone()
            if row:
                print(f"Database : {row[0]}")
                print(f"User     : {row[1]}")
                print(f"Version  : {row[2]}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
