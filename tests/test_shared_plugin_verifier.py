from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "shared_plugin_verifier", ROOT / "ops/verify-shared-plugin.py"
)
assert SPEC and SPEC.loader
VERIFIER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VERIFIER)


class SharedPluginVerifierTest(unittest.TestCase):
    def test_every_locked_plugin_is_checked(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            plugins = root / "wordpress/plugins"
            (plugins / "first-plugin").mkdir(parents=True)
            (plugins / "second-plugin").mkdir()
            (plugins / "first-plugin/main.php").write_text("first", encoding="utf-8")
            second_file = plugins / "second-plugin/main.php"
            second_file.write_text("second", encoding="utf-8")
            lock = {
                "plugins": {
                    slug: {"tree_sha256": VERIFIER.tree_digest(plugins / slug)}
                    for slug in ("first-plugin", "second-plugin")
                }
            }
            (root / "config").mkdir()
            (root / "config/shared-plugin-lock.json").write_text(
                json.dumps(lock), encoding="utf-8"
            )

            self.assertEqual([], VERIFIER.verify_plugins(root))
            second_file.write_text("tampered", encoding="utf-8")
            self.assertTrue(
                any("second-plugin" in error for error in VERIFIER.verify_plugins(root))
            )

    def test_lock_rejects_path_traversal(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "config").mkdir()
            (root / "config/shared-plugin-lock.json").write_text(
                json.dumps({"plugins": {"../outside": {"tree_sha256": "0" * 64}}}),
                encoding="utf-8",
            )
            self.assertTrue(VERIFIER.verify_plugins(root))

    def test_plugin_symlink_cannot_import_files_outside_its_tree(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            plugin = root / "wordpress/plugins/shared-plugin"
            plugin.mkdir(parents=True)
            outside = root / "outside.php"
            outside.write_text("outside", encoding="utf-8")
            (plugin / "main.php").write_text("inside", encoding="utf-8")
            (root / "config").mkdir()
            (root / "config/shared-plugin-lock.json").write_text(
                json.dumps({"plugins": {"shared-plugin": {"tree_sha256": VERIFIER.tree_digest(plugin)}}}),
                encoding="utf-8",
            )
            (plugin / "outside.php").symlink_to(outside)
            self.assertTrue(VERIFIER.verify_plugins(root))

    def test_plugin_directory_cannot_be_a_symlink(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            outside = root / "outside"
            outside.mkdir()
            (outside / "main.php").write_text("outside", encoding="utf-8")
            plugins = root / "wordpress/plugins"
            plugins.mkdir(parents=True)
            (plugins / "shared-plugin").symlink_to(outside, target_is_directory=True)
            (root / "config").mkdir()
            (root / "config/shared-plugin-lock.json").write_text(
                json.dumps({"plugins": {"shared-plugin": {"tree_sha256": VERIFIER.tree_digest(outside)}}}),
                encoding="utf-8",
            )
            self.assertTrue(VERIFIER.verify_plugins(root))


if __name__ == "__main__":
    unittest.main()
