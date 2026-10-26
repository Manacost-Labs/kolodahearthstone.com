import json
import runpy
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "ops"))
import shared_core as core
import shared_install as installer
import shared_sync as sync
import shared_stage as stage


class SharedAutomationTest(unittest.TestCase):
    def test_editorial_snapshot_exports_helpers_without_reusing_plugin_only_cache(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            repository, state = root / "repository", root / "state"
            repository.mkdir()
            state.mkdir()
            core.run(["git", "init", "-q"], cwd=repository)
            selected = ["wordpress/mu-plugins/hs-article-cards.php",
                        "tests/article-cards/components.mjs",
                        "tests/article-cards/inline-image.mjs"]
            for name in [*selected, "unrelated.txt"]:
                path = repository / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("fixture")
            core.run(["git", "add", "."], cwd=repository)
            core.run(["git", "-c", "user.name=Test", "-c", "user.email=test@example.invalid",
                      "commit", "-qm", "fixture"], cwd=repository)
            sha = core.run(["git", "rev-parse", "HEAD"], cwd=repository).decode().strip()
            legacy = state / ("source-" + sha)
            legacy.mkdir()
            data = {"plugins": [{"slug": "hs-article-cards", "paths": {selected[0]: selected[0]}}]}
            with patch.object(sync, "STATE", state):
                snapshot = sync.source_snapshot(repository, sha, data)
                self.assertNotEqual(snapshot, legacy)
                self.assertEqual(snapshot, sync.source_snapshot(repository, sha, data))
            for name in selected:
                self.assertEqual((snapshot / name).read_text(), "fixture")
            self.assertFalse((snapshot / "unrelated.txt").exists())
            self.assertTrue(legacy.is_dir())

    def test_first_mu_install_and_rollback_preserve_unrelated_files(self):
        for fail in (False, True):
            with self.subTest(fail=fail), tempfile.TemporaryDirectory() as folder:
                root = Path(folder)
                runtime = root / "runtime"
                candidate = root / "candidate"
                (runtime / "mu-plugins").mkdir(parents=True)
                unrelated = runtime / "mu-plugins/unrelated.php"
                unrelated.write_text("preserve")
                paths = {"wordpress/mu-plugins/demo": "wordpress/mu-plugins/demo",
                         "wordpress/mu-plugins/demo.php": "wordpress/mu-plugins/demo.php"}
                entry = {"slug": "demo", "kind": "mu", "allow_initial_install": True, "paths": paths}
                (candidate / "wordpress/mu-plugins/demo").mkdir(parents=True)
                (candidate / "wordpress/mu-plugins/demo/main.php").write_text("<?php // assets")
                (candidate / "wordpress/mu-plugins/demo.php").write_text("<?php // loader")
                state = root / "runtime.json"
                state.write_text("{}")
                with patch.object(installer, "RUNTIME", runtime), patch.object(installer, "STATE", state), patch.object(installer, "BACKUPS", root / "backups"), patch.object(installer, "restore_drill") as restore, patch.object(installer, "run"), patch.object(installer, "probe", side_effect=[RuntimeError("probe failed"), None] if fail else None):
                    if fail:
                        with self.assertRaisesRegex(RuntimeError, "probe failed"):
                            installer.install(candidate, entry, {"mutable_paths": []})
                        self.assertFalse((runtime / "mu-plugins/demo.php").exists())
                        self.assertFalse((runtime / "mu-plugins/demo").exists())
                        self.assertEqual(json.loads(state.read_text()), {})
                    else:
                        result = installer.install(candidate, entry, {"mutable_paths": []})
                        self.assertTrue(result["installed"])
                        self.assertEqual(core.files(runtime, paths, prefix="wordpress/"), core.files(candidate, paths))
                        self.assertIn("demo", json.loads(state.read_text()))
                    restore.assert_called_once()
                self.assertEqual(unrelated.read_text(), "preserve")

    def test_first_mu_install_refuses_untracked_live_code(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "mu-plugins").mkdir()
            live = root / "mu-plugins/demo.php"
            live.write_text("operator code")
            state = root / "state.json"
            state.write_text("{}")
            entry = {"slug": "demo", "kind": "mu", "allow_initial_install": True,
                     "paths": {"wordpress/mu-plugins/demo.php": "wordpress/mu-plugins/demo.php"}}
            with patch.object(installer, "RUNTIME", root), patch.object(installer, "STATE", state), patch.object(installer, "restore_drill") as restore:
                with self.assertRaisesRegex(RuntimeError, "drift"):
                    installer.install(root / "candidate", entry, {"mutable_paths": []})
                restore.assert_not_called()
            self.assertEqual(live.read_text(), "operator code")

    def test_initial_mu_admission_requires_loader_last(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "config").mkdir()
            entry = {"slug": "demo", "kind": "mu", "allow_initial_install": True,
                     "paths": {"wordpress/mu-plugins/demo.php": "wordpress/mu-plugins/demo.php", "wordpress/mu-plugins/demo": "wordpress/mu-plugins/demo"}}
            (root / "config/shared-plugin-sync.json").write_text(json.dumps({"plugins": [entry]}))
            with self.assertRaisesRegex(ValueError, "assets before its loader"):
                core.policy(root)

    def test_canonical_vendor_files_survive_gitignore_without_staging_unrelated_data(
        self,
    ):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            core.run(["git", "init", "-q"], cwd=root)
            (root / ".gitignore").write_text("vendor/\nwp-config.php\n")
            source = {
                "wordpress/plugins/demo/main.php": b"<?php // plugin",
                "wordpress/plugins/demo/vendor/autoload.php": b"<?php // canonical dependency",
            }
            for name, data in source.items():
                (root / name).parent.mkdir(parents=True, exist_ok=True)
                (root / name).write_bytes(data)
            (root / "wp-config.php").write_text("do not stage")
            entry = {
                "slug": "demo",
                "paths": {"wordpress/plugins/demo": "wordpress/plugins/demo"},
            }
            core.stage_import(root, entry, source)
            self.assertEqual(
                source["wordpress/plugins/demo/vendor/autoload.php"],
                core.run(
                    ["git", "show", ":wordpress/plugins/demo/vendor/autoload.php"],
                    cwd=root,
                ),
            )
            self.assertNotIn(b"wp-config.php", core.run(["git", "ls-files"], cwd=root))

    def test_redis_update_never_takes_over_a_foreign_dropin(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "object-cache.php").write_text("Another cache provider")
            with patch.object(installer, "RUNTIME", root):
                self.assertIsNone(installer.redis_dropin(root, "redis-cache"))
                self.assertEqual(
                    "Another cache provider", (root / "object-cache.php").read_text()
                )

    def test_lock_digest_matches_existing_verifier_with_directory_and_same_prefix_file(
        self,
    ):
        verifier = runpy.run_path(
            str(Path(__file__).resolve().parents[1] / "ops/verify-shared-plugin.py")
        )
        contents = {"demo.php": b"bootstrap", "demo/main.php": b"implementation"}
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            for name, data in contents.items():
                (root / name).parent.mkdir(parents=True, exist_ok=True)
                (root / name).write_bytes(data)
            self.assertEqual(
                verifier["tree_digest"](root), core.digest(contents, tree_order=True)
            )

    def test_dry_run_cannot_promote_a_pending_pr(self):
        with tempfile.TemporaryDirectory() as folder:
            state = Path(folder)
            (state / "pending.json").write_text('{"pr":123}')
            with (
                patch.object(sync, "STATE", state),
                patch.object(sync, "approved_source", return_value=("a" * 40, 12)),
                patch.object(sync, "checkout"),
                patch.object(sync, "run", return_value=b"base"),
                patch.object(sync, "gh") as api,
            ):
                sync.cycle(False)
                api.assert_not_called()

    def test_database_readiness_requires_authenticated_select_not_just_ping(self):
        with (
            patch.object(
                stage, "run", side_effect=[b"Access denied\n", b"1\n"]
            ) as call,
            patch.object(stage.time, "sleep"),
        ):
            stage.wait_database(["docker"], "owned-fixture")
            self.assertEqual(2, call.call_count)

    def test_production_input_sha_is_read_from_successful_deploy_not_workflow_head(
        self,
    ):
        sha = "a" * 40
        self.assertEqual(
            sha,
            core.production_sha(
                "run head bbbbb\nDeployed " + sha + " to production. Backup: protected"
            ),
        )
        for value in [
            "",
            "Deployed main to production.",
            "Deployed "
            + sha
            + " to production.\nDeployed "
            + "b" * 40
            + " to production.",
        ]:
            with self.assertRaises(ValueError):
                core.production_sha(value)

    def test_rejects_paths_and_symlinks(self):
        for name in [
            "/tmp/file",
            "wordpress/plugins/../../wp-config.php",
            "wordpress/themes/blocksy",
        ]:
            with self.assertRaises(ValueError):
                core.safe_path(name)
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "wordpress/plugins").mkdir(parents=True)
            (root / "wordpress/plugins/plugin").symlink_to("/tmp")
            with self.assertRaises(ValueError):
                core.files(root, ["wordpress/plugins/plugin"])

    def test_import_is_complete_idempotent_and_preserves_other_plugin(self):
        with tempfile.TemporaryDirectory() as folder:
            source, target = Path(folder) / "source", Path(folder) / "target"
            for root in [source, target]:
                (root / "wordpress/plugins/demo").mkdir(parents=True)
            (source / "wordpress/plugins/demo/main.php").write_text(
                "<?php /*\nPlugin Name: Demo\nVersion: 2.0\n*/"
            )
            (source / "wordpress/plugins/demo/lib").mkdir()
            (source / "wordpress/plugins/demo/lib/nested.php").write_text(
                "Plugin Name: Nested\nVersion: 9.9"
            )
            (target / "wordpress/plugins/demo/stale.php").write_text("old")
            (target / "wordpress/plugins/other.php").write_text("preserved")
            (target / "config").mkdir()
            (target / "config/shared-plugin-lock.json").write_text('{"plugins":{}}')
            (target / "config/wordpress-plugins.json").write_text(
                '{"plugins":[{"slug":"demo","version":"1"}]}'
            )
            entry = {
                "slug": "demo",
                "kind": "plugin",
                "paths": {"wordpress/plugins/demo": "wordpress/plugins/demo"},
            }
            self.assertTrue(core.import_plugin(source, target, entry, "a" * 40))
            self.assertFalse((target / "wordpress/plugins/demo/stale.php").exists())
            self.assertEqual(
                "preserved", (target / "wordpress/plugins/other.php").read_text()
            )
            self.assertFalse(core.import_plugin(source, target, entry, "a" * 40))
            self.assertEqual(
                "2.0",
                json.loads((target / "config/wordpress-plugins.json").read_text())[
                    "plugins"
                ][0]["version"],
            )
            (source / "wordpress/plugins/demo").rename(
                source / "wordpress/plugins/missing"
            )
            before = core.files(target, entry["paths"])
            with self.assertRaises(ValueError):
                core.import_plugin(source, target, entry, "b" * 40)
            self.assertEqual(before, core.files(target, entry["paths"]))

    def test_registered_fleet_is_explicit_and_contains_requested_common_plugins(self):
        data = core.policy(Path(__file__).resolve().parents[1])
        slugs = {x["slug"] for x in data["plugins"]}
        self.assertEqual(16, len(slugs))
        self.assertTrue(
            {
                "wordfence",
                "redis-cache",
                "classic-editor",
                "cyr2lat",
                "shortcodes-ultimate",
                "tinymce-advanced",
                "hs-article-cards",
            }
            <= slugs
        )
        self.assertFalse({"td-composer", "wp-rocket", "blocksy-companion"} & slugs)

    def test_failed_or_pending_source_promotion_does_not_authorize_sync(self):
        for status, conclusion in [("completed", "failure"), ("in_progress", None)]:
            with patch.object(
                sync,
                "gh",
                return_value=json.dumps(
                    {"workflow_runs": [{"status": status, "conclusion": conclusion}]}
                ),
            ):
                with self.assertRaises(RuntimeError):
                    sync.approved_source("owner/repo")

    def test_live_drift_is_not_overwritten_and_failed_health_restores_original_code(
        self,
    ):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            live = root / "live"
            candidate = root / "candidate"
            state = root / "state.json"
            for directory in [
                live / "plugins/demo",
                candidate / "wordpress/plugins/demo",
            ]:
                directory.mkdir(parents=True)
            (live / "plugins/demo/main.php").write_text("old")
            (candidate / "wordpress/plugins/demo/main.php").write_text("new")
            entry = {
                "slug": "demo",
                "kind": "plugin",
                "paths": {"wordpress/plugins/demo": "wordpress/plugins/demo"},
            }
            original = core.files(live, entry["paths"], prefix="wordpress/")
            state.write_text(json.dumps({"demo": core.digest(original)}))
            data = {"mutable_paths": []}
            with (
                patch.object(installer, "RUNTIME", live),
                patch.object(installer, "STATE", state),
                patch.object(installer, "BACKUPS", root / "backups"),
                patch.object(installer, "restore_drill") as drill,
                patch.object(installer, "run"),
                patch.object(
                    installer, "probe", side_effect=[RuntimeError("bad health"), None]
                ) as health,
            ):
                (live / "plugins/demo/main.php").write_text("local edit")
                with self.assertRaisesRegex(RuntimeError, "drift"):
                    installer.install(candidate, entry, data)
                drill.assert_not_called()
                (live / "plugins/demo/main.php").write_text("old")
                with self.assertRaisesRegex(RuntimeError, "bad health"):
                    installer.install(candidate, entry, data)
                self.assertEqual(2, health.call_count)
                self.assertEqual(
                    original, core.files(live, entry["paths"], prefix="wordpress/")
                )
                self.assertEqual(
                    core.digest(original), json.loads(state.read_text())["demo"]
                )
