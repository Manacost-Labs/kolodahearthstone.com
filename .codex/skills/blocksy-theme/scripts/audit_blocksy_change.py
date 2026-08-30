#!/usr/bin/env python3
"""Reject unsafe Blocksy parent-theme and generated-file edits."""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path


FORBIDDEN = (
    "wordpress/themes/blocksy/",
    "wp-content/themes/blocksy/",
    "wordpress/cache/",
    "wp-content/cache/",
)


def changed_paths(repo: Path, staged: bool) -> list[str]:
    command = ["git", "diff", "--name-only"]
    if staged:
        command.append("--cached")
    output = subprocess.check_output(command, cwd=repo, text=True)
    return [line.strip() for line in output.splitlines() if line.strip()]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", type=Path, default=Path.cwd())
    parser.add_argument("--base", default="HEAD")
    parser.add_argument("--include-untracked", action="store_true")
    parser.add_argument("--staged", action="store_true")
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()

    paths = changed_paths(args.repo, args.staged)
    if args.include_untracked:
        output = subprocess.check_output(
            ["git", "ls-files", "--others", "--exclude-standard"],
            cwd=args.repo,
            text=True,
        )
        paths.extend(line.strip() for line in output.splitlines() if line.strip())

    violations = [path for path in sorted(set(paths)) if any(path.startswith(prefix) for prefix in FORBIDDEN)]
    if violations:
        print("Blocksy parent/generated paths are protected:", file=sys.stderr)
        print("\n".join(f"- {path}" for path in violations), file=sys.stderr)
        return 1
    print(f"Blocksy audit: {len(set(paths))} changed path(s), no protected edits")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
