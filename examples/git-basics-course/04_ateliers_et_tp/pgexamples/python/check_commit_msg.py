#!/usr/bin/env python3
"""Conventional Commit message validator for Git repositories and commit-msg hooks."""

import argparse
import pathlib
import re
import sys

# Enforce standard Conventional Commits subject format (<type>[optional scope]: <description>)
CONVENTIONAL_RE = re.compile(
    r"^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|merge)"
    r"(\([a-z0-9._/-]+\))?!?: .{3,72}$"
)


def validate_subject(subject: str) -> tuple[bool, str]:
    """Validate the first non-comment line of a Git commit message."""
    clean = subject.strip()
    if not clean:
        return False, "Commit subject must not be empty."
    if not CONVENTIONAL_RE.match(clean):
        return (
            False,
            "Invalid Conventional Commit format. Expected e.g. 'feat(scope): short summary'.",
        )
    return True, f"Valid Conventional Commit subject: {clean}"


def extract_subject_from_file(path: pathlib.Path) -> str:
    """Read the first non-empty, non-comment line from a Git commit message file."""
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        stripped = raw_line.strip()
        if stripped and not stripped.startswith("#"):
            return stripped
    return ""


def main() -> int:
    """Parse CLI arguments and validate either a direct message or commit-msg file."""
    parser = argparse.ArgumentParser(description="Validate a Conventional Commit message.")
    parser.add_argument("commit_file", nargs="?", help="Path to Git COMMIT_EDITMSG file")
    parser.add_argument("--message", "-m", help="Commit subject string to validate directly")
    args = parser.parse_args()

    if args.message is not None:
        subject = args.message
    elif args.commit_file is not None:
        file_path = pathlib.Path(args.commit_file)
        if not file_path.is_file():
            print(f"File not found: {file_path}", file=sys.stderr)
            return 2
        subject = extract_subject_from_file(file_path)
    else:
        parser.print_help()
        return 2

    ok, detail = validate_subject(subject)
    print(detail)
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
