"""Reconcile one plugin per cycle from a proven Manacost production release."""

from __future__ import annotations

import argparse
import fcntl
import json
import os
import shutil
import sys
import tarfile
import time
from pathlib import Path

from shared_core import (
    SHA,
    digest,
    files,
    import_plugin,
    mapped_files,
    policy,
    production_sha,
    run,
    stage_import,
)
from shared_stage import verify

CONTROLLER = Path(__file__).resolve().parents[1]
STATE = Path("/var/lib/koloda-plugin-sync")


def gh(*args):
    # Use the service account's existing scoped credential store, never a token in logs.
    return run(["gh", *args], timeout=120).decode()


def approved_source(repository):
    result = json.loads(
        gh(
            "api",
            f"repos/{repository}/actions/workflows/promote-production.yml/runs?per_page=1",
        )
    )
    latest = result["workflow_runs"][0]
    if latest["status"] != "completed" or latest["conclusion"] != "success":
        raise RuntimeError(
            "Latest Manacost production promotion is not successful; sync deferred"
        )
    jobs = json.loads(
        gh("api", f"repos/{repository}/actions/runs/{latest['id']}/jobs?per_page=100")
    )["jobs"]
    deploy = next(
        j for j in jobs if j["name"] == "deploy" and j["conclusion"] == "success"
    )
    return production_sha(
        gh("api", f"repos/{repository}/actions/jobs/{deploy['id']}/logs")
    ), latest["id"]


def checkout(repository, local, directory):
    if not directory.exists():
        run(
            ["git", "clone", "--shared", "--no-checkout", str(local), str(directory)],
            timeout=120,
        )
        run(
            [
                "git",
                "remote",
                "set-url",
                "origin",
                "https://github.com/" + repository + ".git",
            ],
            cwd=directory,
        )
        run(["git", "config", "user.name", "Manacost Plugin Sync"], cwd=directory)
        run(
            ["git", "config", "user.email", "plugin-sync@users.noreply.github.com"],
            cwd=directory,
        )
    run(["git", "fetch", "origin"], cwd=directory, timeout=180)


def source_archive(repository, sha, destination, paths):
    if destination.exists():
        return
    archive = STATE / "source.tar"
    with archive.open("wb") as output:
        import subprocess

        result = subprocess.run(
            ["git", "archive", sha, "--", *paths],
            cwd=repository,
            stdout=output,
            stderr=subprocess.PIPE,
            timeout=120,
        )
        if result.returncode:
            raise RuntimeError("Source archive failed")
    destination.mkdir()
    with tarfile.open(archive) as bundle:
        for member in bundle.getmembers():
            path = destination / member.name
            if not path.resolve().is_relative_to(destination.resolve()) or not (
                member.isfile() or member.isdir()
            ):
                raise ValueError("Unsafe source archive member")
            if member.isdir():
                path.mkdir(parents=True, exist_ok=True)
            else:
                path.parent.mkdir(parents=True, exist_ok=True)
                with bundle.extractfile(member) as source:
                    path.write_bytes(source.read())
    archive.unlink()


def report(**values):
    (STATE / "status.json").write_text(
        json.dumps(values, ensure_ascii=False, indent=2) + "\n"
    )
    print(json.dumps(values, ensure_ascii=False), flush=True)


def cycle(apply):
    data = policy(CONTROLLER)
    source_sha, source_run = approved_source(data["source_repository"])
    source_repo, target = STATE / "source-repo", STATE / "target-repo"
    checkout(
        data["source_repository"],
        Path("/srv/projects/wordpress/hs-manacost.ru"),
        source_repo,
    )
    checkout(
        data["consumer_repository"],
        Path("/srv/projects/wordpress/kolodahearthstone.com"),
        target,
    )
    base = run(["git", "rev-parse", "origin/main"], cwd=target).decode().strip()
    # Do not replace another operator's working tree or an interrupted candidate.
    pending = STATE / "pending.json"
    if pending.exists():
        if not apply:
            report(
                state="dry run; pending PR unchanged",
                pending=json.loads(pending.read_text()),
            )
            return
        work = json.loads(pending.read_text())
        candidate = Path(work["candidate"])
        entry = next(e for e in data["plugins"] if e["slug"] == work["slug"])
        pr = json.loads(
            gh(
                "pr",
                "view",
                str(work["pr"]),
                "--repo",
                data["consumer_repository"],
                "--json",
                "state,headRefOid,statusCheckRollup,mergeCommit",
            )
        )
        if pr["state"] == "CLOSED":
            raise RuntimeError("Sync PR was closed; operator review required")
        if pr["state"] == "OPEN":
            checks = pr["statusCheckRollup"]
            if not any(
                c.get("name") == "Quality" and c.get("conclusion") == "SUCCESS"
                for c in checks
            ) or any(
                c.get("conclusion") not in ("SUCCESS", "NEUTRAL", "SKIPPED")
                for c in checks
            ):
                report(
                    state="waiting for CI",
                    plugin=entry["slug"],
                    pr=work["pr"],
                    source_sha=work["source_sha"],
                )
                return
            if base != work["base"]:
                raise RuntimeError("Target main changed; candidate must be reverified")
            gh(
                "pr",
                "merge",
                str(work["pr"]),
                "--repo",
                data["consumer_repository"],
                "--squash",
                "--match-head-commit",
                work["head"],
            )
            pr = json.loads(
                gh(
                    "pr",
                    "view",
                    str(work["pr"]),
                    "--repo",
                    data["consumer_repository"],
                    "--json",
                    "mergeCommit",
                )
            )
        merged = pr["mergeCommit"]["oid"]
        run(["git", "fetch", "origin"], cwd=target)
        staged_tree = run(["git", "rev-parse", "HEAD^{tree}"], cwd=candidate).strip()
        if (
            staged_tree
            != run(["git", "rev-parse", merged + "^{tree}"], cwd=target).strip()
        ):
            raise RuntimeError("Merged tree differs from isolated verified tree")
        run(["git", "checkout", "--detach", merged], cwd=candidate)
        proof = json.loads((candidate / "stage-proof.json").read_text())
        proof["target_sha"] = merged
        (candidate / "stage-proof.json").write_text(json.dumps(proof, indent=2) + "\n")
        result = json.loads(
            run(
                [
                    "sudo",
                    "-n",
                    "/usr/bin/python3",
                    str(CONTROLLER / "ops/shared_install.py"),
                    "--candidate",
                    str(candidate),
                    "--slug",
                    entry["slug"],
                ],
                timeout=900,
            )
        )
        history = STATE / "history"
        history.mkdir(exist_ok=True)
        shutil.move(
            str(candidate / "stage-proof.json"), str(history / (merged + ".json"))
        )
        pending.unlink()
        run(["git", "worktree", "remove", str(candidate)], cwd=target)
        report(
            state="synchronized",
            plugin=entry["slug"],
            source_sha=work["source_sha"],
            target_sha=merged,
            pr=work["pr"],
            result=result,
        )
        return
    candidate = STATE / "candidate"
    if candidate.exists():
        raise RuntimeError(
            "Unfinished candidate without pending record; inspect before retry"
        )
    run(["git", "worktree", "add", "--detach", str(candidate), base], cwd=target)
    source = STATE / ("source-" + source_sha)
    origins = sorted({p for e in data["plugins"] for p in e["paths"].values()})
    source_archive(source_repo, source_sha, source, origins)
    changes = [
        entry
        for entry in data["plugins"]
        if mapped_files(source, entry) != files(candidate, entry["paths"])
    ]
    if not changes or not apply:
        report(
            state="up to date" if not changes else "dry run",
            source_sha=source_sha,
            source_run=source_run,
            pending_plugins=[e["slug"] for e in changes],
        )
        run(["git", "worktree", "remove", str(candidate)], cwd=target)
        return
    entry = changes[0]
    report(
        state="verifying isolated candidate",
        plugin=entry["slug"],
        source_sha=source_sha,
    )
    import_plugin(source, candidate, entry, source_sha)
    impact = json.loads(
        run(
            [
                "python3",
                str(
                    candidate
                    / ".agents/skills/wordpress-change-impact/scripts/analyze_change_impact.py"
                ),
                "--base",
                "origin/main",
                "--format",
                "json",
            ],
            cwd=candidate,
        )
    )
    if impact["manual_review_required"]:
        raise RuntimeError(
            "Package includes unclassified paths; admission review required"
        )
    run(["make", "check"], cwd=candidate, timeout=300)
    stage = verify(candidate, source, STATE, CONTROLLER)
    branch = "codex/auto-shared-" + entry["slug"] + "-" + source_sha[:10]
    run(["git", "checkout", "-b", branch], cwd=candidate)
    stage_import(candidate, entry, mapped_files(source, entry))
    run(
        [
            "git",
            "add",
            "config/shared-plugin-lock.json",
            "config/wordpress-plugins.json",
        ],
        cwd=candidate,
    )
    run(
        ["/home/debian/server/tools/ai-quality/bin/ai-security-check", "staged"],
        cwd=candidate,
        timeout=120,
    )
    run(
        [
            "git",
            "commit",
            "-m",
            "sync: " + entry["slug"] + " from verified Manacost " + source_sha[:12],
        ],
        cwd=candidate,
        timeout=120,
    )
    head = run(["git", "rev-parse", "HEAD"], cwd=candidate).decode().strip()
    proof = {
        "target_sha": head,
        "source_sha": source_sha,
        "plugin": entry["slug"],
        "plugin_digest": digest(files(candidate, entry["paths"])),
        "checks": stage,
    }
    (candidate / "stage-proof.json").write_text(json.dumps(proof, indent=2) + "\n")
    run(
        ["git", "push", "origin", "HEAD:refs/heads/" + branch],
        cwd=candidate,
        timeout=180,
    )
    body = STATE / "pr-body.md"
    body.write_text(
        "Synchronize "
        + entry["slug"]
        + " from successful Manacost production run "
        + str(source_run)
        + ", commit `"
        + source_sha
        + "`.\n\nOne package only. Code and source digest are updated; runtime settings, licenses and site adapters are preserved.\n\nValidated: make check, staged secret scan, native PHP 8.4 / disposable Blocksy, tooltip at 320/1440 px, native draft save/readback and duplicate media uploads with unchanged originals.\n\nPromotion uses the identical verified tree, a restore-tested private database backup, scoped code backup, regional probes and automatic code rollback.\n"
    )
    url = gh(
        "pr",
        "create",
        "--repo",
        data["consumer_repository"],
        "--base",
        "main",
        "--head",
        branch,
        "--title",
        "Sync " + entry["slug"] + " with Manacost production",
        "--body-file",
        str(body),
    ).strip()
    pr = int(url.rsplit("/", 1)[1])
    pending.write_text(
        json.dumps(
            {
                "candidate": str(candidate),
                "slug": entry["slug"],
                "pr": pr,
                "head": head,
                "base": base,
                "source_sha": source_sha,
            },
            indent=2,
        )
        + "\n"
    )
    report(
        state="waiting for CI",
        plugin=entry["slug"],
        pr=pr,
        url=url,
        source_sha=source_sha,
    )


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    STATE.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (STATE / "sync.lock").open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        try:
            cycle(args.apply)
        except Exception as error:
            report(state="failed; production not advanced", error=str(error)[:1500])
            raise


if __name__ == "__main__":
    main()
