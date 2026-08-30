from __future__ import annotations

import json
import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class RepositoryContractsTest(unittest.TestCase):
    def read_json(self, name: str) -> dict:
        return json.loads((ROOT / "config" / name).read_text(encoding="utf-8"))

    def test_domain_policy_keeps_com_canonical_and_ru_redirect(self) -> None:
        site = self.read_json("site.json")["site"]
        self.assertEqual(site["canonical_url"], "https://kolodahearthstone.com")
        self.assertEqual(site["legacy_redirect_url"], "https://kolodahearthstone.ru")
        self.assertEqual(site["staging_url"], "https://test.kolodahearthstone.com")

    def test_blocksy_is_the_only_theme_source(self) -> None:
        theme = self.read_json("blocksy-theme.json")["theme"]
        self.assertEqual(theme["slug"], "blocksy")
        self.assertEqual(theme["child_theme"], "wordpress/themes/blocksy-child")
        self.assertFalse((ROOT / "wordpress/themes/Newspaper_new").exists())

    def test_shared_tooltip_lock_is_pinned(self) -> None:
        tooltip = self.read_json("shared-plugin-lock.json")["plugins"]["hs-tooltip"]
        self.assertRegex(tooltip["source_commit"], re.compile(r"^[0-9a-f]{40}$"))
        self.assertRegex(tooltip["tree_sha256"], re.compile(r"^[0-9a-f]{64}$"))
        self.assertIn("Manacost-Labs/hs-manacost.ru", tooltip["source_repository"])

    def test_no_secrets_or_runtime_data_are_tracked(self) -> None:
        tracked = set()
        for path in ROOT.rglob("*"):
            if not path.is_file() or ".git" in path.parts:
                continue
            tracked.add(path.name)
            self.assertNotIn(path.name, {".env", "wp-config.php"})
            self.assertNotIn("uploads", path.parts)
            self.assertNotIn("cache", path.parts)
        self.assertTrue(tracked)


if __name__ == "__main__":
    unittest.main()
