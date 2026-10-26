"""Privileged, bounded installation and rollback of one admitted plugin."""

from __future__ import annotations

import argparse
import ctypes
import datetime
import hashlib
import json
import os
import secrets
import shutil
import subprocess
import time
from pathlib import Path

from shared_core import digest, files, policy, run
from shared_stage import DB_IMAGE, PHP, WP, wait_database

RUNTIME = Path("/var/www/koloda/data/www/kolodahearthstone.ru/wp-content")
STATE = Path("/var/lib/koloda-plugin-sync/runtime.json")
BACKUPS = Path("/var/backups/koloda-shared-plugin-auto")


def wp_args(*args):
    return [
        "sudo",
        "-n",
        "-u",
        "koloda",
        "env",
        "REQUEST_URI=/",
        PHP,
        WP,
        "--path=" + str(RUNTIME.parent),
        *args,
    ]


def probe():
    run(
        wp_args(
            "--skip-themes",
            "eval",
            "if(wp_parse_url(home_url(),PHP_URL_HOST)!=='kolodahearthstone.com')WP_CLI::error('Wrong site');if(function_exists('w3tc_flush_posts'))w3tc_flush_posts(['only'=>'pagecache']);echo 'runtime-ok';",
        ),
        timeout=60,
    )
    for address in ["151.80.21.140", "194.67.92.242", "186.246.28.244"]:
        for host, expected in [
            ("kolodahearthstone.com", "200"),
            ("kolodahearthstone.ru", "301"),
        ]:
            code = run(
                [
                    "curl",
                    "-sS",
                    "--max-time",
                    "20",
                    "--resolve",
                    host + ":443:" + address,
                    "-o",
                    "/dev/null",
                    "-w",
                    "%{http_code}",
                    "https://" + host + "/?shared_plugin_probe=" + secrets.token_hex(4),
                ]
            ).decode()
            if code != expected:
                raise RuntimeError(
                    "Live regional probe failed: "
                    + host
                    + " via "
                    + address
                    + " returned "
                    + code
                )


def restore_drill(backup):
    """Restore a protected SQL export in an unexposed disposable database."""
    dump = backup / "database-before.sql"
    command = wp_args(
        "--skip-plugins",
        "--skip-themes",
        "--quiet",
        "db",
        "export",
        "-",
        "--single-transaction",
        "--skip-lock-tables",
        "--skip-extended-insert",
        "--complete-insert",
        "--hex-blob",
        "--order-by-primary",
        "--skip-comments",
        "--skip-dump-date",
    )
    with dump.open("wb") as output:
        result = subprocess.run(
            command, stdout=output, stderr=subprocess.PIPE, timeout=180
        )
    dump.chmod(0o600)
    if result.returncode or not 1024 < dump.stat().st_size <= 512 * 1024 * 1024:
        raise RuntimeError(
            "Protected database export failed or exceeds automatic restore limit"
        )
    env = backup / "restore.env"
    env.write_text(
        "MARIADB_ROOT_PASSWORD="
        + secrets.token_hex(24)
        + "\nMARIADB_DATABASE=wordpress\n"
    )
    env.chmod(0o600)
    name = "koloda-shared-plugin-restore"
    if run(["docker", "ps", "-aq", "--filter", "name=^/" + name + "$"]).strip():
        raise RuntimeError("Owned restore container already exists")

    def insert_hash(stream):
        value = hashlib.sha256()
        for line in stream:
            if line.startswith(b"INSERT INTO "):
                value.update(line)
        return value.hexdigest()

    try:
        run(
            [
                "docker",
                "run",
                "-d",
                "--rm",
                "--name",
                name,
                "--network=none",
                "--cpus=1",
                "--memory=1g",
                "--tmpfs",
                "/var/lib/mysql",
                "--env-file",
                str(env),
                DB_IMAGE,
            ],
            timeout=120,
        )
        wait_database(["docker"], name)
        with dump.open("rb") as source:
            restored = subprocess.run(
                [
                    "docker",
                    "exec",
                    "-i",
                    name,
                    "sh",
                    "-c",
                    'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb wordpress',
                ],
                stdin=source,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
                timeout=180,
            )
        if restored.returncode:
            error_path = backup / "restore-error.log"
            error_path.write_bytes(restored.stderr)
            error_path.chmod(0o600)
            raise RuntimeError(
                "Disposable database restore failed; protected diagnostic retained"
            )
        with dump.open("rb") as original:
            tables = sum(line.startswith(b"CREATE TABLE ") for line in original)
        restored_tables = int(
            run(
                [
                    "docker",
                    "exec",
                    name,
                    "sh",
                    "-c",
                    'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -N -e \'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema="wordpress" AND table_type="BASE TABLE"\'',
                ]
            )
        )
        if tables < 12 or restored_tables != tables:
            raise RuntimeError("Restored WordPress schema count mismatch")
        after = backup / "restored-inserts.sql"
        with after.open("wb") as output:
            result = subprocess.run(
                [
                    "docker",
                    "exec",
                    name,
                    "sh",
                    "-c",
                    'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb-dump wordpress --no-create-info --skip-extended-insert --complete-insert --hex-blob --order-by-primary --skip-comments --skip-dump-date',
                ],
                stdout=output,
                stderr=subprocess.PIPE,
                timeout=180,
            )
        if result.returncode:
            raise RuntimeError("Restored database readback failed")
        with dump.open("rb") as before, after.open("rb") as following:
            if insert_hash(before) != insert_hash(following):
                raise RuntimeError("Database restore row checksum mismatch")
        after.chmod(0o600)
        (backup / "restore-verified.json").write_text(
            json.dumps({"verified": True, "method": "all restored INSERT rows SHA256"})
        )
    finally:
        run(["docker", "rm", "-f", name], timeout=30)
        env.unlink(missing_ok=True)


def exchange(first, second):
    library = ctypes.CDLL(None, use_errno=True)
    if library.renameat2(-100, os.fsencode(first), -100, os.fsencode(second), 2):
        raise OSError(ctypes.get_errno(), "Atomic directory exchange failed")


def redis_dropin(candidate, slug):
    live = RUNTIME / "object-cache.php"
    if slug != "redis-cache" or not live.is_file() or live.is_symlink():
        return None
    if b"https://wordpress.org/plugins/redis-cache/" not in live.read_bytes()[:8192]:
        return None
    source = candidate / "wordpress/plugins/redis-cache/includes/object-cache.php"
    if not source.is_file():
        raise RuntimeError("Redis drop-in source moved; review required")
    return live, source


def install(candidate, entry, data):
    state = json.loads(STATE.read_text())
    mutable = set(data["mutable_paths"])
    before = files(RUNTIME, entry["paths"], prefix="wordpress/")
    incoming = files(candidate, entry["paths"])
    code = lambda values: {k: v for k, v in values.items() if k not in mutable}
    initial = (
        entry.get("allow_initial_install") is True
        and entry["kind"] == "mu"
        and state.get(entry["slug"]) in (None, digest({}))
        and not before
    )
    expected = digest({}) if initial else state.get(entry["slug"])
    if digest(code(before)) != expected:
        raise RuntimeError(
            "Live plugin drift detected; refusing to overwrite local changes"
        )
    dropin = redis_dropin(candidate, entry["slug"])
    dropin_change = bool(dropin and dropin[0].read_bytes() != dropin[1].read_bytes())
    if code(before) == code(incoming) and not dropin_change:
        return {
            "slug": entry["slug"],
            "installed": False,
            "reason": "live code already matches",
        }
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = BACKUPS / (stamp + "-" + entry["slug"])
    backup.mkdir(parents=True, mode=0o700)
    os.chmod(backup.parent, 0o700)
    restore_drill(backup)
    if files(RUNTIME, entry["paths"], prefix="wordpress/") != before:
        raise RuntimeError("Live files changed while verifying the backup")
    installed = []
    try:
        for name in entry["paths"]:
            relative = name.removeprefix("wordpress/")
            live = RUNTIME / relative
            if live.is_symlink():
                raise RuntimeError("Linked live plugin root")
            if not live.exists():
                if not initial:
                    raise RuntimeError("Missing live plugin root")
                source = candidate / name
                owner = live.parent.stat()
                temporary = live.with_name("." + live.name + ".initial-" + secrets.token_hex(6))
                if source.is_dir():
                    shutil.copytree(source, temporary)
                    paths = [temporary, *temporary.rglob("*")]
                else:
                    shutil.copy2(source, temporary)
                    paths = [temporary]
                for path in paths:
                    os.chown(path, owner.st_uid, owner.st_gid)
                    path.chmod(0o755 if path.is_dir() else 0o644)
                os.replace(temporary, live)
                installed.append((live, None, None))
                continue
            saved = backup / relative
            saved.parent.mkdir(parents=True, exist_ok=True)
            original = live.stat()
            if live.is_dir():
                shutil.copytree(live, saved)
            else:
                shutil.copy2(live, saved)
            temporary = live.with_name(
                "." + live.name + ".shared-" + secrets.token_hex(6)
            )
            source = candidate / name
            if live.is_dir():
                shutil.copytree(source, temporary)
                for path in mutable:
                    if (
                        path.startswith(name + "/")
                        and (RUNTIME / path.removeprefix("wordpress/")).is_file()
                    ):
                        shutil.copy2(
                            RUNTIME / path.removeprefix("wordpress/"),
                            temporary / path.removeprefix(name + "/"),
                        )
                for path in [temporary, *temporary.rglob("*")]:
                    os.chown(path, original.st_uid, original.st_gid)
                    path.chmod(0o755 if path.is_dir() else 0o644)
                exchange(temporary, live)
                installed.append((live, saved, original))
                os.rename(
                    temporary, backup / (entry["slug"] + "-exchanged-" + live.name)
                )
            else:
                shutil.copy2(source, temporary)
                os.chown(temporary, original.st_uid, original.st_gid)
                temporary.chmod(original.st_mode & 0o777)
                os.replace(temporary, live)
                installed.append((live, saved, original))
        if dropin_change:
            live, source = dropin
            saved = backup / "object-cache.php"
            original = live.stat()
            shutil.copy2(live, saved)
            installed.append((live, saved, original))
            run(
                wp_args(
                    "--skip-themes",
                    "eval",
                    "if(!function_exists('redis_object_cache'))WP_CLI::error('Redis updater unavailable');$plugin=redis_object_cache();if(!is_callable([$plugin,'update_dropin']))WP_CLI::error('Redis updater changed');$plugin->update_dropin();",
                ),
                timeout=60,
            )
            if live.read_bytes() != source.read_bytes():
                raise RuntimeError("Redis drop-in update mismatch")
        if code(files(RUNTIME, entry["paths"], prefix="wordpress/")) != code(incoming):
            raise RuntimeError("Installed plugin digest mismatch")
        run(["systemctl", "reload", "php-fpm84"])
        probe()
    except BaseException:
        for live, saved, original in reversed(installed):
            if saved is None:
                if live.is_dir():
                    shutil.rmtree(live)
                else:
                    live.unlink()
                continue
            if live.is_dir():
                recovery = live.with_name(
                    "." + live.name + ".rollback-" + secrets.token_hex(6)
                )
                shutil.copytree(saved, recovery)
                for path in [recovery, *recovery.rglob("*")]:
                    os.chown(path, original.st_uid, original.st_gid)
                exchange(live, recovery)
            else:
                shutil.copy2(saved, live)
                os.chown(live, original.st_uid, original.st_gid)
        run(["systemctl", "reload", "php-fpm84"])
        probe()
        raise
    state[entry["slug"]] = digest(code(incoming))
    temporary = STATE.with_suffix(".next")
    temporary.write_text(json.dumps(state, indent=2) + "\n")
    temporary.chmod(0o600)
    os.replace(temporary, STATE)
    return {"slug": entry["slug"], "installed": True, "backup": str(backup)}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--initialize", action="store_true")
    parser.add_argument("--candidate", type=Path)
    parser.add_argument("--slug")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise RuntimeError("Installer requires root")
    controller = Path(__file__).resolve().parents[1]
    data = policy(controller)
    if args.initialize:
        if STATE.exists():
            raise RuntimeError("Live baseline already initialized")
        state = {
            e["slug"]: digest(
                {
                    k: v
                    for k, v in files(RUNTIME, e["paths"], prefix="wordpress/").items()
                    if k not in data["mutable_paths"]
                }
            )
            for e in data["plugins"]
        }
        STATE.write_text(json.dumps(state, indent=2) + "\n")
        STATE.chmod(0o600)
        print(json.dumps({"registered": len(state)}))
        return
    entry = next(e for e in data["plugins"] if e["slug"] == args.slug)
    if (
        args.candidate.resolve()
        != Path("/var/lib/koloda-plugin-sync/candidate").resolve()
    ):
        raise RuntimeError("Only the owned candidate worktree may be installed")
    git = ["git", "-c", "safe.directory=" + str(args.candidate.resolve())]
    proof = json.loads((args.candidate / "stage-proof.json").read_text())
    if (
        run([*git, "rev-parse", "HEAD"], cwd=args.candidate).decode().strip()
        != proof["target_sha"]
    ):
        raise RuntimeError("Candidate changed since verification")
    if run(
        [*git, "status", "--porcelain", "--", *entry["paths"]], cwd=args.candidate
    ).strip():
        raise RuntimeError("Candidate source is dirty")
    if digest(files(args.candidate, entry["paths"])) != proof["plugin_digest"]:
        raise RuntimeError("Candidate package differs from tested package")
    actual_core = (
        run(wp_args("--skip-plugins", "--skip-themes", "core", "version"))
        .decode()
        .strip()
    )
    actual_theme = (
        run(
            wp_args(
                "--skip-plugins",
                "--skip-themes",
                "theme",
                "get",
                "blocksy",
                "--field=version",
            )
        )
        .decode()
        .strip()
    )
    if (
        actual_core != proof["checks"]["wordpress"]
        or actual_theme != proof["checks"]["theme_version"]
    ):
        raise RuntimeError("Live core/theme changed after isolated verification")
    run(
        wp_args(
            "--skip-plugins",
            "--skip-themes",
            "eval",
            "if(wp_parse_url(home_url(),PHP_URL_HOST)!=='kolodahearthstone.com')WP_CLI::error('Wrong installation');",
        )
    )
    print(json.dumps(install(args.candidate, entry, data)))


if __name__ == "__main__":
    main()
