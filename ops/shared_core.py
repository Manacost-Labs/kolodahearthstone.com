"""Exact-source planning for the explicitly admitted shared plugin fleet."""

from __future__ import annotations

import hashlib
import json
import re
import shutil
import subprocess
from pathlib import Path, PurePosixPath

SHA = re.compile(r"^[0-9a-f]{40}$")


def run(args, cwd=None, timeout=300, input=None):
    try:
        result = subprocess.run(
            args, cwd=cwd, input=input, capture_output=True, timeout=timeout
        )
    except subprocess.TimeoutExpired:
        raise RuntimeError(Path(args[0]).name + " timed out") from None
    if result.returncode:
        # Arguments can contain disposable credentials. Never include argv.
        raise RuntimeError(
            f"{Path(args[0]).name} failed ({result.returncode}): "
            + result.stderr.decode(errors="replace")[-600:]
        )
    return result.stdout


def safe_path(value):
    path = PurePosixPath(value)
    if (
        path.is_absolute()
        or ".." in path.parts
        or not value.startswith(("wordpress/plugins/", "wordpress/mu-plugins/"))
    ):
        raise ValueError("Path outside admitted plugin roots")
    return path


def policy(root):
    data = json.loads((root / "config/shared-plugin-sync.json").read_text())
    seen = set()
    for entry in data["plugins"]:
        if (
            not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", entry["slug"])
            or entry["slug"] in seen
        ):
            raise ValueError("Invalid or duplicate plugin")
        seen.add(entry["slug"])
        if entry["kind"] not in ("plugin", "mu"):
            raise ValueError("Invalid plugin kind")
        for target, source in entry["paths"].items():
            safe_path(target)
            safe_path(source)
            expected = f"wordpress/plugins/{entry['slug']}"
            if entry["kind"] == "plugin" and not (
                target == expected or target.startswith(expected + "/")
            ):
                raise ValueError("Plugin destination escapes its own package")
            if entry["kind"] == "mu" and target not in (
                f"wordpress/mu-plugins/{entry['slug']}",
                f"wordpress/mu-plugins/{entry['slug']}.php",
            ):
                raise ValueError("MU destination escapes its own package")
    return data


def files(root, paths, prefix=""):
    result = {}
    for name in paths:
        safe_path(name)
        path = root / name.removeprefix(prefix)
        if path.is_symlink() or any(
            p.is_symlink() for p in path.parents if p != root and p.is_relative_to(root)
        ):
            raise ValueError("Symlink in plugin path")
        if not path.exists():
            continue
        for item in [path] if path.is_file() else sorted(path.rglob("*")):
            if item.is_symlink():
                raise ValueError("Symlink in plugin tree")
            if item.is_file() and "__pycache__" not in item.parts:
                result[prefix + item.relative_to(root).as_posix()] = item.read_bytes()
    return result


def mapped_files(source, entry):
    output = {}
    for target, origin in entry["paths"].items():
        found = files(source, [origin])
        if not found:
            raise ValueError(f"Missing source for {entry['slug']}")
        for name, content in found.items():
            output[target + name[len(origin) :]] = content
    if len(output) > 10000 or sum(map(len, output.values())) > 512 * 1024 * 1024:
        raise ValueError("Oversized plugin package")
    return output


def digest(contents, *, tree_order=False):
    value = hashlib.sha256()
    # Existing shared-plugin locks sort Path components, while group snapshots
    # use lexical full paths. Keep their independently persisted formats stable.
    key = (
        (lambda item: PurePosixPath(item[0]).parts)
        if tree_order
        else (lambda item: item[0])
    )
    for path, content in sorted(contents.items(), key=key):
        value.update(path.encode() + b"\0" + hashlib.sha256(content).digest() + b"\0")
    return value.hexdigest()


def import_plugin(source, target, entry, source_sha):
    if not SHA.fullmatch(source_sha):
        raise ValueError("Expected a full source SHA")
    incoming = mapped_files(source, entry)
    previous = files(target, entry["paths"])
    if previous == incoming:
        return False
    # Validate all source content before deleting any owned destination.
    for name in entry["paths"]:
        destination = target / name
        if destination.is_dir():
            shutil.rmtree(destination)
        elif destination.exists():
            destination.unlink()
    for name, content in incoming.items():
        destination = target / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(content)
    lock_path = target / "config/shared-plugin-lock.json"
    lock = json.loads(lock_path.read_text())
    if entry["kind"] == "plugin":
        prefix = f"wordpress/plugins/{entry['slug']}/"
        relative = {k.removeprefix(prefix): v for k, v in incoming.items()}
        version = next(
            (
                m.group(1).strip()
                for path, content in incoming.items()
                if "/" not in path.removeprefix(prefix)
                and path.endswith(".php")
                and b"Plugin Name:" in content[:8192]
                and (
                    m := re.search(
                        rb"(?mi)^\s*\*?\s*Version:\s*([^\r\n]+)", content[:8192]
                    )
                )
            ),
            b"unknown",
        ).decode()
        lock["plugins"][entry["slug"]] = {
            "version": version,
            "source_repository": "Manacost-Labs/hs-manacost.ru",
            "source_commit": source_sha,
            "tree_sha256": digest(relative, tree_order=True),
            "source_files": entry["paths"],
        }
        inventory_path = target / "config/wordpress-plugins.json"
        inventory = json.loads(inventory_path.read_text())
        for plugin in inventory["plugins"]:
            if plugin["slug"] == entry["slug"]:
                plugin["version"] = version
        inventory_path.write_text(
            json.dumps(inventory, ensure_ascii=False, indent=2) + "\n"
        )
        lock_path.write_text(json.dumps(lock, ensure_ascii=False, indent=2) + "\n")
    return True


def production_sha(log):
    values = set(re.findall(r"Deployed ([0-9a-f]{40}) to production\.", log))
    if len(values) != 1:
        raise ValueError("Production log has no unique deployed SHA")
    return values.pop()


def stage_import(root, entry, incoming):
    """Stage only canonical source files, including admitted vendor dependencies."""
    run(["git", "add", "-u", "--", *entry["paths"]], cwd=root)
    names = sorted(incoming)
    for offset in range(0, len(names), 128):
        run(["git", "add", "-f", "--", *names[offset : offset + 128]], cwd=root)
    indexed = {}
    for record in run(
        ["git", "ls-files", "--stage", "-z", "--", *entry["paths"]], cwd=root
    ).split(b"\0"):
        if not record:
            continue
        metadata, name = record.split(b"\t", 1)
        mode, oid, stage = metadata.split()
        if stage != b"0":
            raise RuntimeError("Unmerged plugin source in index")
        indexed[name.decode()] = oid.decode()
    expected = {
        name: hashlib.sha1(
            b"blob " + str(len(data)).encode() + b"\0" + data, usedforsecurity=False
        ).hexdigest()
        for name, data in incoming.items()
    }
    if indexed != expected:
        raise RuntimeError("Committed plugin snapshot differs from canonical source")
