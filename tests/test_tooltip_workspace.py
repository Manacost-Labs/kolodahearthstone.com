"""Regression checks for the tabbed HS Tooltip editor workspace."""

from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]
WORKSPACE = ROOT / "wordpress/mu-plugins/koloda-tooltip-workspace.php"
WORKSPACE_JS = ROOT / "wordpress/mu-plugins/koloda-tooltip-workspace/workspace.js"
WORKSPACE_CSS = ROOT / "wordpress/mu-plugins/koloda-tooltip-workspace/workspace.css"


class TooltipWorkspaceTests(unittest.TestCase):
    def test_existing_tooltip_renderers_are_grouped_without_new_save_path(self) -> None:
        source = WORKSPACE.read_text(encoding="utf-8")

        self.assertIn("hs_smart_tooltip_render_search_box", source)
        self.assertIn("hs_smart_tooltip_render_bgs_search_box", source)
        self.assertIn("hs_smart_tooltip_render_toggle_box", source)
        self.assertIn("array('side', 'normal', 'advanced')", source)
        self.assertIn("remove_meta_box($box_id, $post->post_type, $context);", source)
        self.assertIn("'kh_tooltip_workspace'", source)
        self.assertIn("current_user_can('edit_post', $post->ID)", source)
        self.assertNotIn("update_post_meta", source)

    def test_workspace_assets_are_scoped_and_keep_keyboard_visible_focus(self) -> None:
        source = WORKSPACE.read_text(encoding="utf-8")
        script = WORKSPACE_JS.read_text(encoding="utf-8")
        stylesheet = WORKSPACE_CSS.read_text(encoding="utf-8")

        self.assertIn("array('post.php', 'post-new.php')", source)
        self.assertIn("aria-selected", script)
        self.assertIn("panel.hidden", script)
        self.assertIn("localStorage", script)
        self.assertIn("#kh_tooltip_workspace", stylesheet)
        self.assertIn(":focus-visible", stylesheet)


if __name__ == "__main__":
    unittest.main()
