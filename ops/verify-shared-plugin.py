#!/usr/bin/env python3
"""Verify the checked-in shared plugin tree against its redacted lock entry."""

from __future__ import annotations

import hashlib
import json
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def tree_digest(path: Path) -> str:
    digest = hashlib.sha256()
    files = sorted(
        candidate
        for candidate in path.rglob("*")
        if candidate.is_file() and ".git" not in candidate.parts
    )
    for candidate in files:
        relative = candidate.relative_to(path).as_posix().encode("utf-8")
        digest.update(relative)
        digest.update(b"\0")
        digest.update(hashlib.sha256(candidate.read_bytes()).digest())
        digest.update(b"\0")
    return digest.hexdigest()


def main() -> int:
    lock = json.loads((ROOT / "config/shared-plugin-lock.json").read_text(encoding="utf-8"))
    entry = lock["plugins"]["hs-tooltip"]
    plugin = ROOT / "wordpress/plugins/hs-tooltip"
    if not plugin.is_dir():
        print("shared plugin check failed: hs-tooltip source is missing", file=sys.stderr)
        return 1

    actual = tree_digest(plugin)
    expected = entry["tree_sha256"]
    if actual != expected:
        print(f"shared plugin check failed: expected {expected}, got {actual}", file=sys.stderr)
        return 1

    print(f"shared plugin check: hs-tooltip {entry['version']} ({actual})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
