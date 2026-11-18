#!/usr/bin/env python3
"""Pure-Python Git repository hygiene and Qualiopi lab audit script."""

import argparse
import json
import pathlib
import re
import subprocess
import sys

CONFLICT_RE = re.compile(r"^(<<<<<<<|=======|>>>>>>>)", re.MULTILINE)
CONVENTIONAL_RE = re.compile(
    r"^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|merge)(\([a-z0-9._/-]+\))?!?: .+"
)


def run_git(repo: pathlib.Path, *args: str) -> subprocess.CompletedProcess[str]:
    """Run a read-only git command inside the target repository."""
    return subprocess.run(
        ["git", "-C", str(repo), *args],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        check=False,
    )


def audit_repository(repo: pathlib.Path) -> dict[str, object]:
    """Inspect branch, working tree cleanliness, .gitignore, commits, and conflict markers."""
    if not (repo / ".git").exists():
        return {"status": "error", "repo": str(repo), "error": "Not a Git working repository"}

    branch = run_git(repo, "branch", "--show-current").stdout.strip() or "DETACHED"
    porcelain = run_git(repo, "status", "--porcelain").stdout.splitlines()
    commit_count_raw = run_git(repo, "rev-list", "--count", "HEAD")
    commit_count = int(commit_count_raw.stdout.strip()) if commit_count_raw.returncode == 0 else 0
    last_subject = (
        run_git(repo, "log", "-1", "--pretty=%s").stdout.strip() if commit_count > 0 else ""
    )

    tracked_files = [
        line for line in run_git(repo, "ls-files").stdout.splitlines() if line.strip()
    ]
    conflict_files: list[str] = []
    for rel_path in tracked_files:
        candidate = repo / rel_path
        if candidate.is_file():
            try:
                content = candidate.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            if CONFLICT_RE.search(content):
                conflict_files.append(rel_path)

    has_gitignore = (repo / ".gitignore").is_file()
    conventional_ok = bool(CONVENTIONAL_RE.match(last_subject)) if last_subject else False
    healthy = has_gitignore and not porcelain and not conflict_files and commit_count > 0

    return {
        "status": "ok" if healthy else "warning",
        "repo": str(repo),
        "branch": branch,
        "commit_count": commit_count,
        "last_subject": last_subject,
        "last_subject_conventional": conventional_ok,
        "has_gitignore": has_gitignore,
        "dirty_entries": len(porcelain),
        "conflict_files": conflict_files,
    }


def main() -> int:
    """Parse CLI arguments and print a JSON repository hygiene report."""
    parser = argparse.ArgumentParser(description="Audit Git repository hygiene.")
    parser.add_argument("--repo", default=".", help="Path to target Git repository")
    args = parser.parse_args()

    repo_path = pathlib.Path(args.repo).resolve()
    report = audit_repository(repo_path)
    print(json.dumps(report, indent=2))
    return 0 if report.get("status") == "ok" else 1


if __name__ == "__main__":
    raise SystemExit(main())
