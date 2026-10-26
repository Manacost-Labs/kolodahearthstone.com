"""Disposable Blocksy verification; no production database or settings are used."""

from __future__ import annotations

import json
import io
import os
import re
import secrets
import shutil
import subprocess
import time
import tarfile
import zipfile
from pathlib import Path

from shared_core import files, mapped_files, policy, run

PHP = "/opt/php84/bin/php"
WP = "/usr/local/bin/wp"
DB_IMAGE = "mariadb:10.11@sha256:ce66c7be32a03aabe7241d0a10993a2db827ef652a35d25727d92a832ac8ef73"
URL = "http://127.0.0.1:18798"
LIVE = Path("/var/www/koloda/data/www/kolodahearthstone.ru")


def fixture_sources(state):
    cache = state / "cache"
    cache.mkdir(exist_ok=True)
    version = re.search(
        r"\$wp_version\s*=\s*'([0-9.]+)'",
        (LIVE / "wp-includes/version.php").read_text(),
    ).group(1)
    theme_version = re.search(
        r"(?mi)^Version:\s*([0-9.]+)",
        (LIVE / "wp-content/themes/blocksy/style.css").read_text(),
    ).group(1)
    core = cache / ("core-" + version)
    if not core.exists():
        try:
            run(
                [
                    PHP,
                    WP,
                    "core",
                    "download",
                    "--version=" + version,
                    "--skip-content",
                    "--path=" + str(core),
                    "--quiet",
                ],
                timeout=360,
            )
            run(
                [
                    PHP,
                    WP,
                    "core",
                    "verify-checksums",
                    "--version=" + version,
                    "--path=" + str(core),
                    "--quiet",
                ],
                timeout=120,
            )
        except Exception:
            if core.exists():
                shutil.rmtree(core)
            raise
    theme = cache / ("blocksy-" + theme_version)
    if not theme.exists():
        archive = cache / ("blocksy-" + theme_version + ".zip")
        run(
            [
                "curl",
                "-fsS",
                "--max-time",
                "60",
                "--max-filesize",
                "33554432",
                "https://downloads.wordpress.org/theme/blocksy."
                + theme_version
                + ".zip",
                "-o",
                str(archive),
            ],
            timeout=70,
        )
        temporary = cache / "theme-unpack"
        temporary.mkdir(exist_ok=False)
        try:
            with zipfile.ZipFile(archive) as package:
                for member in package.infolist():
                    if (
                        not member.filename.startswith("blocksy/")
                        or not (temporary / member.filename)
                        .resolve()
                        .is_relative_to(temporary.resolve())
                        or (member.external_attr >> 16) & 0o170000 == 0o120000
                    ):
                        raise ValueError("Unsafe official theme archive")
                package.extractall(temporary)
            os.rename(temporary / "blocksy", theme)
        finally:
            shutil.rmtree(temporary)
    return core, theme


def wait_database(docker, name):
    for _ in range(60):
        try:
            result = run(
                [
                    *docker,
                    "exec",
                    name,
                    "sh",
                    "-c",
                    'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -h127.0.0.1 -N -e "SELECT 1"',
                ],
                timeout=5,
            )
            if result.strip() == b"1":
                return
        except RuntimeError:
            pass
        time.sleep(1)
    raise RuntimeError("Disposable authenticated database is not ready")


def verify(candidate, source, state, controller):
    core, theme = fixture_sources(state)
    site = state / "stage-site"
    if site.exists():
        shutil.rmtree(site)
    shutil.copytree(core, site)
    baseline = state / "stage-baseline"
    if baseline.exists():
        shutil.rmtree(baseline)
    baseline.mkdir()
    with tarfile.open(
        fileobj=io.BytesIO(
            run(["git", "archive", "HEAD", "--", "wordpress"], cwd=candidate)
        )
    ) as archive:
        for member in archive.getmembers():
            destination = baseline / member.name
            if not destination.resolve().is_relative_to(baseline.resolve()) or not (
                member.isfile() or member.isdir()
            ):
                raise ValueError("Unsafe fixture baseline archive")
            if member.isdir():
                destination.mkdir(parents=True, exist_ok=True)
            else:
                destination.parent.mkdir(parents=True, exist_ok=True)
                with archive.extractfile(member) as source_file:
                    destination.write_bytes(source_file.read())
    password, admin = secrets.token_hex(24), secrets.token_hex(24)
    env_path = state / "stage.env"
    env_path.write_text(
        f"MARIADB_ROOT_PASSWORD={password}\nMARIADB_DATABASE=wordpress\n"
    )
    env_path.chmod(0o600)
    config = "<?php\n"
    for key, value in {
        "DB_NAME": "wordpress",
        "DB_USER": "root",
        "DB_PASSWORD": password,
        "DB_HOST": "127.0.0.1:18797",
        "DB_CHARSET": "utf8mb4",
        "DB_COLLATE": "",
        "WP_HOME": URL,
        "WP_SITEURL": URL,
        "WP_ENVIRONMENT_TYPE": "local",
        "HS_ARTICLE_CARDS_LOCAL_SOURCE": "koloda",
        "WP_ACCESSIBLE_HOSTS": "art.hearthstonejson.com,api.hearthstonejson.com,api.kolodahearthstone.com",
    }.items():
        config += f"define({json.dumps(key)},{json.dumps(value)});\n"
    config += "define('DISABLE_WP_CRON',true);define('WP_DEBUG',true);define('WP_DEBUG_DISPLAY',false);define('WP_REDIS_DISABLED',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('MANACOST_PERF_ENABLED',false);\n"
    config += "$table_prefix='sync_';define('ABSPATH',__DIR__.'/');require ABSPATH.'wp-settings.php';\n"
    (site / "wp-config.php").write_text(config)
    (site / "wp-config.php").chmod(0o600)
    docker = ["docker"]
    try:
        run(docker + ["info"], timeout=20)
    except RuntimeError:
        docker = ["sudo", "-n", "docker"]
    name = "koloda-shared-plugin-stage"
    # Fixed, controller-owned name and loopback-only ports; never prune Docker.
    existing = run(docker + ["ps", "-aq", "--filter", "name=^/" + name + "$"]).strip()
    if existing:
        raise RuntimeError(
            "Owned staging container already exists; inspect interrupted run"
        )
    web = None
    log = (state / "stage-http.log").open("wb")
    try:
        run(
            docker
            + [
                "run",
                "-d",
                "--rm",
                "--name",
                name,
                "--label",
                "koloda-shared-plugin-stage=true",
                "--cpus=1",
                "--memory=384m",
                "--tmpfs",
                "/var/lib/mysql",
                "-p",
                "127.0.0.1:18797:3306",
                "--env-file",
                str(env_path),
                DB_IMAGE,
            ],
            timeout=120,
        )
        wait_database(docker, name)

        def wp(*args):
            return run([PHP, WP, "--path=" + str(site), *args], timeout=180)

        wp(
            "core",
            "install",
            "--url=" + URL,
            "--title=Shared plugin verification",
            "--admin_user=sync-admin",
            "--admin_password=" + admin,
            "--admin_email=sync@example.invalid",
            "--skip-email",
        )
        shutil.copytree(baseline / "wordpress", site / "wp-content", dirs_exist_ok=True)
        for adapter in ["plausible-analytics.php", "manacost-cache-purge.php"]:
            (site / "wp-content/mu-plugins" / adapter).unlink(missing_ok=True)
        shutil.copytree(theme, site / "wp-content/themes/blocksy")
        # Some legacy common vendor plugins were listed but not stored in Git.
        # Only the admitted common packages may fill those fixture-only gaps.
        available = []
        for entry in policy(controller)["plugins"]:
            if entry["kind"] != "plugin":
                continue
            directory = site / "wp-content/plugins" / entry["slug"]
            if not directory.exists():
                for path, content in mapped_files(source, entry).items():
                    dest = site / "wp-content" / path.removeprefix("wordpress/")
                    dest.parent.mkdir(parents=True, exist_ok=True)
                    dest.write_bytes(content)
            available.append(entry["slug"])
        wp("theme", "activate", "blocksy")
        wp("plugin", "activate", *available)
        # Exercise an upgrade of synthetic old data, rather than only a fresh install.
        for entry in policy(controller)["plugins"]:
            if files(candidate, entry["paths"]) == files(baseline, entry["paths"]):
                continue
            for path in entry["paths"]:
                original = candidate / path
                destination = site / "wp-content" / path.removeprefix("wordpress/")
                if not original.exists():
                    continue
                if destination.is_dir():
                    shutil.rmtree(destination)
                elif destination.exists():
                    destination.unlink()
                if original.is_dir():
                    shutil.copytree(original, destination)
                else:
                    destination.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(original, destination)
        info = json.loads(
            wp(
                "eval",
                "$id=wp_insert_post(['post_title'=>'Disposable tooltip','post_status'=>'publish','post_content'=>'[hs_card id=\"BE_222t\"]Безднокрыл[/hs_card]']);echo json_encode(['post'=>$id,'upload_nonce'=>wp_create_nonce('media-form')]);",
            )
        )
        # Native PHP 8.4, matching production, with a fresh local-only database.
        web = subprocess.Popen(
            [PHP, "-S", "127.0.0.1:18798", "-t", str(site)], stdout=log, stderr=log
        )
        environment = dict(
            os.environ,
            SYNC_STAGE_ADMIN=admin,
            SYNC_STAGE_POST=str(info["post"]),
            SYNC_STAGE_DISPOSABLE="1",
            SYNC_STAGE_SOURCE=str(source),
            SYNC_STAGE_EDITORIAL="1" if (site / "wp-content/mu-plugins/hs-article-cards.php").exists() else "0",
        )
        result = subprocess.run(
            ["node", str(controller / "ops/shared-stage-browser.mjs")],
            env=environment,
            capture_output=True,
            timeout=360,
        )
        if result.returncode:
            raise RuntimeError(
                "Disposable browser failed: "
                + result.stderr.decode(errors="replace")
                .replace(admin, "<redacted>")
                .replace(password, "<redacted>")[-1200:]
            )
        readback = wp(
            "eval",
            "$q=new WP_Query(['post_type'=>'post','post_status'=>'draft','s'=>'Shared automatic draft']);if(!$q->have_posts())WP_CLI::error('Draft missing');$p=$q->posts[0];if(strpos($p->post_content,'BE_222t')===false)WP_CLI::error('Draft content missing');echo 'draft-readback-ok';",
        )
        if b"draft-readback-ok" not in readback:
            raise RuntimeError("Draft readback failed")
        proof = json.loads(result.stdout)
        proof["wordpress"] = wp("core", "version").decode().strip()
        proof["theme_version"] = (
            wp("theme", "get", "blocksy", "--field=version").decode().strip()
        )
        return proof
    finally:
        if web:
            web.terminate()
            web.wait(timeout=15)
        log.close()
        run(docker + ["rm", "-f", name], timeout=30)
        env_path.unlink(missing_ok=True)
