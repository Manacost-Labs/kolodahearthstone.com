#!/usr/bin/env python3
"""Validate the canonical and synchronized AI skill trees."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SKILLS = ROOT / ".agents/skills"
REGISTRY = ROOT / "config/ai-skills.json"


def comparable(root: Path) -> dict[str, bytes]:
    result: dict[str, bytes] = {}
    for path in root.rglob("*"):
        if not path.is_file() or "__pycache__" in path.parts or path.suffix == ".pyc":
            continue
        result[path.relative_to(root).as_posix()] = path.read_bytes().replace(b"\r\n", b"\n")
    return result


def main() -> int:
    errors: list[str] = []
    try:
        registry = json.loads(REGISTRY.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        print(f"invalid skill registry: {exc}", file=sys.stderr)
        return 1

    expected = set(registry.get("included_skills", []))
    actual = {path.name for path in SKILLS.iterdir() if path.is_dir()}
    if expected != actual:
        errors.append(f"skill registry mismatch: expected={sorted(expected)} actual={sorted(actual)}")

    for name in sorted(actual):
        skill = SKILLS / name
        entry = skill / "SKILL.md"
        metadata = skill / "agents/openai.yaml"
        if not entry.is_file():
            errors.append(f"{name}: missing SKILL.md")
        if not metadata.is_file():
            errors.append(f"{name}: missing agents/openai.yaml")
        elif f"${name}" not in metadata.read_text(encoding="utf-8"):
            errors.append(f"{name}: openai.yaml default_prompt must mention ${name}")
        if entry.is_file():
            text = entry.read_text(encoding="utf-8")
            if not re.match(r"^---\nname:\s*[a-z0-9-]+\n", text):
                errors.append(f"{name}: malformed SKILL.md frontmatter")
            if "newspaper-tagdiv" in text or "hs-manacost.ru" in text:
                errors.append(f"{name}: contains stale project/theme reference")

    canonical = comparable(SKILLS)
    for target_name in (".claude/skills", ".codex/skills"):
        target = ROOT / target_name
        if comparable(target) != canonical:
            errors.append(f"{target_name}: not synchronized with .agents/skills")

    if errors:
        print("Skill audit failed:", file=sys.stderr)
        print("\n".join(f"- {error}" for error in errors), file=sys.stderr)
        return 1
    print(f"Skill audit: {len(actual)} skills, synchronized copies are current")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
