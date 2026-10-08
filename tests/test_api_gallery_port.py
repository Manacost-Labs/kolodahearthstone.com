from __future__ import annotations

import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("gallery_shared_verifier", ROOT / "ops/verify-shared-plugin.py")
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)


class ApiGalleryPortTest(unittest.TestCase):
    def test_both_plugin_dependencies_match_reviewed_trees(self) -> None:
        self.assertEqual(verifier.verify_plugins(ROOT), [])
        lock = json.loads((ROOT / "config/shared-plugin-lock.json").read_text())["plugins"]
        for slug in ("hs-api-gallery", "manacost-koloda-api"):
            self.assertEqual(lock[slug]["source_commit"], "99c7b6e7730265210d42172dda868fca6925c9bf")
            plugin = ROOT / "wordpress/plugins" / slug
            files = {path.relative_to(plugin).as_posix() for path in plugin.rglob("*") if path.is_file()}
            self.assertEqual(files, set(lock[slug]["source_files"]))

    def test_regular_plugin_asset_paths_resolve_in_the_preserved_structure(self) -> None:
        plugin = ROOT / "wordpress/plugins/hs-api-gallery"
        for asset in ("editor.css", "editor.js", "ratings.css", "ratings.js"):
            self.assertTrue((plugin / "hs-api-gallery" / asset).is_file())
        self.assertTrue((plugin / "hs-api-gallery.php").is_file())


if __name__ == "__main__":
    unittest.main()
