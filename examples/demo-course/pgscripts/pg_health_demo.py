#!/usr/bin/env python3
"""Pure-Python standard-library PostgreSQL TCP health probe for demo environments."""

import argparse
import json
import socket
import time


def check_tcp_port(host: str, port: int, timeout: float = 2.0) -> dict[str, object]:
    """Measure TCP handshake reachability and latency against a PostgreSQL listener."""
    started = time.perf_counter()
    try:
        with socket.create_connection((host, port), timeout=timeout):
            latency_ms = round((time.perf_counter() - started) * 1000.0, 2)
            return {
                "status": "ok",
                "host": host,
                "port": port,
                "latency_ms": latency_ms,
            }
    except OSError as exc:
        latency_ms = round((time.perf_counter() - started) * 1000.0, 2)
        return {
            "status": "unreachable",
            "host": host,
            "port": port,
            "latency_ms": latency_ms,
            "error": str(exc),
        }


def main() -> int:
    """Parse CLI arguments and output a JSON health summary."""
    parser = argparse.ArgumentParser(description="Probe a PostgreSQL TCP endpoint.")
    parser.add_argument("--host", default="127.0.0.1", help="Target hostname or IP")
    parser.add_argument("--port", type=int, default=5432, help="Target TCP port")
    parser.add_argument("--timeout", type=float, default=2.0, help="Socket timeout in seconds")
    args = parser.parse_args()

    report = check_tcp_port(args.host, args.port, args.timeout)
    print(json.dumps(report, indent=2))
    return 0 if report["status"] == "ok" else 1


if __name__ == "__main__":
    raise SystemExit(main())
