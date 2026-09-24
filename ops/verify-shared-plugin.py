#!/usr/bin/env python3
"""Verify the checked-in shared plugin tree against its redacted lock entry."""

from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SLUG = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")


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


def verify_plugins(root: Path) -> list[str]:
    lock = json.loads((root / "config/shared-plugin-lock.json").read_text(encoding="utf-8"))
    entries = lock.get("plugins")
    if not isinstance(entries, dict) or not entries:
        return ["shared plugin lock must contain plugins"]

    errors: list[str] = []
    for slug, entry in sorted(entries.items()):
        if not isinstance(slug, str) or not SLUG.fullmatch(slug):
            errors.append("shared plugin lock contains an invalid slug")
            continue
        if (
            not isinstance(entry, dict)
            or not isinstance(entry.get("tree_sha256"), str)
            or not SHA256.fullmatch(entry["tree_sha256"])
        ):
            errors.append(f"{slug}: invalid tree_sha256")
            continue
        plugin = root / "wordpress/plugins" / slug
        if not plugin.is_dir():
            errors.append(f"{slug}: source is missing")
            continue
        if plugin.is_symlink() or any(path.is_symlink() for path in plugin.rglob("*")):
            errors.append(f"{slug}: symlinks are not allowed in a shared plugin")
            continue
        if tree_digest(plugin) != entry["tree_sha256"]:
            errors.append(f"{slug}: source digest mismatch")
    return errors


def main() -> int:
    errors = verify_plugins(ROOT)
    if errors:
        for error in errors:
            print(f"shared plugin check failed: {error}", file=sys.stderr)
        return 1
    print("shared plugin check: all pinned plugins verified")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
