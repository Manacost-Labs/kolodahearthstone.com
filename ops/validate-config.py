#!/usr/bin/env python3
"""Validate redacted site and plugin manifests without reading runtime secrets."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SECRET = re.compile(r"(?i)(api[_-]?key|client[_-]?secret|password|private[_-]?key|access[_-]?token|webhook[_-]?secret)")
REDACTION_POLICY_PATH = "plugin-settings-policy.json/always_redact_key_patterns"
SOURCE_ROOT_PATH = "wordpress-contracts.json/scope/source_roots/"


def load(name: str) -> object:
    path = ROOT / "config" / name
    return json.loads(path.read_text(encoding="utf-8"))


def walk(value: object, path: str = "") -> list[str]:
    errors: list[str] = []
    if isinstance(value, dict):
        for key, child in value.items():
            if SECRET.search(key):
                errors.append(f"secret-like key in config: {path}/{key}")
            errors.extend(walk(child, f"{path}/{key}"))
    elif isinstance(value, list):
        for index, child in enumerate(value):
            errors.extend(walk(child, f"{path}/{index}"))
    elif (
        isinstance(value, str)
        and SECRET.search(value)
        and not path.startswith(REDACTION_POLICY_PATH + "/")
        and not path.startswith(SOURCE_ROOT_PATH)
    ):
        errors.append(f"secret-like value in config: {path}")
    return errors


def main() -> int:
    errors: list[str] = []
    for name in ("site.json", "blocksy-theme.json", "wordpress-plugins.json", "shared-plugin-lock.json", "plugin-settings-policy.json", "ai-skills.json", "wordpress-contracts.json"):
        try:
            errors.extend(walk(load(name), name))
        except (OSError, json.JSONDecodeError) as exc:
            errors.append(f"{name}: {exc}")

    site = load("site.json")
    if site["site"]["canonical_url"] != "https://kolodahearthstone.com":
        errors.append("canonical URL must be kolodahearthstone.com")
    if site["site"]["staging_url"] != "https://test.kolodahearthstone.com":
        errors.append("staging URL must be test.kolodahearthstone.com")
    lock = load("shared-plugin-lock.json")
    tooltip = lock["plugins"]["hs-tooltip"]
    if len(tooltip["source_commit"]) != 40 or not re.fullmatch(r"[0-9a-f]{40}", tooltip["source_commit"]):
        errors.append("hs-tooltip source_commit must be a full SHA")
    if len(tooltip["tree_sha256"]) != 64 or not re.fullmatch(r"[0-9a-f]{64}", tooltip["tree_sha256"]):
        errors.append("hs-tooltip tree_sha256 must be a SHA256")

    if errors:
        print("Config validation failed:", file=sys.stderr)
        print("\n".join(f"- {error}" for error in errors), file=sys.stderr)
        return 1
    print("Config validation: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
