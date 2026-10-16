from __future__ import annotations

import importlib.util
import json
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("gallery_shared_verifier", ROOT / "ops/verify-shared-plugin.py")
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)


class ApiGalleryPortTest(unittest.TestCase):
    def assert_reviewed_dependencies(self) -> None:
        self.assertEqual(verifier.verify_plugins(ROOT), [])
        lock = json.loads((ROOT / "config/shared-plugin-lock.json").read_text())["plugins"]
        for slug in ("hs-api-gallery", "manacost-koloda-api"):
            self.assertRegex(lock[slug]["source_commit"], r"^[0-9a-f]{40}$")
            plugin = ROOT / "wordpress/plugins" / slug
            files = {path.relative_to(plugin).as_posix() for path in plugin.rglob("*") if path.is_file()}
            mapped = set()
            # Initial imports list individual files; the automation maps file/directory roots.
            for target in lock[slug]["source_files"]:
                path = ROOT / target if target.startswith("wordpress/plugins/") else plugin / target
                self.assertTrue(path.exists())
                paths = path.rglob("*") if path.is_dir() else (path,)
                mapped.update(item.relative_to(plugin).as_posix() for item in paths if item.is_file())
            self.assertEqual(files, mapped)

    def test_both_plugin_dependencies_match_reviewed_trees(self) -> None:
        self.assert_reviewed_dependencies()

    def test_later_import_accepts_new_sha_and_directory_root_mappings(self) -> None:
        lock = json.loads((ROOT / "config/shared-plugin-lock.json").read_text())
        for slug in ("hs-api-gallery", "manacost-koloda-api"):
            lock["plugins"][slug]["source_commit"] = "f" * 40
            plugin = ROOT / "wordpress/plugins" / slug
            lock["plugins"][slug]["source_files"] = {
                path.relative_to(ROOT).as_posix(): "canonical/source" for path in plugin.iterdir()
            }
        with mock.patch.object(json, "loads", return_value=lock):
            self.assert_reviewed_dependencies()

    def test_regular_plugin_asset_paths_resolve_in_the_preserved_structure(self) -> None:
        plugin = ROOT / "wordpress/plugins/hs-api-gallery"
        for asset in ("editor.css", "editor.js", "ratings.css", "ratings.js"):
            self.assertTrue((plugin / "hs-api-gallery" / asset).is_file())
        self.assertTrue((plugin / "hs-api-gallery.php").is_file())


if __name__ == "__main__":
    unittest.main()
