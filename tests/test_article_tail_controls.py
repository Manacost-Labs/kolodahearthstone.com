from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
TAIL_CONTROLS = ROOT / "wordpress" / "mu-plugins" / "hs-article-tail-controls.php"
FEEDBACK_MODULE = (
    ROOT
    / "wordpress"
    / "plugins"
    / "KolodaHearthstone Locker"
    / "svl-home-redesign.php"
)


class ArticleTailControlsTest(unittest.TestCase):
    def test_blocksy_author_and_navigation_are_disabled(self) -> None:
        controls = TAIL_CONTROLS.read_text(encoding="utf-8")

        self.assertIn("theme_mod_single_blog_post_has_author_box", controls)
        self.assertIn("theme_mod_single_blog_post_has_post_nav", controls)
        self.assertIn("return 'no';", controls)

    def test_article_feedback_is_disabled_before_rendering_or_submission(self) -> None:
        module = FEEDBACK_MODULE.read_text(encoding="utf-8")
        render_callback = module.split(
            "function svl_home_redesign_append_article_feedback($content) {", 1
        )[1].split("    $post_id = get_the_ID();", 1)[0]
        submit_callback = module.split(
            "function svl_home_redesign_submit_article_feedback() {", 1
        )[1].split("    if (!empty($_POST['company'])) {", 1)[0]

        self.assertIn("function svl_home_redesign_article_feedback_is_enabled()", module)
        self.assertIn("apply_filters('kh_article_feedback_enabled', false)", module)
        self.assertIn("!svl_home_redesign_article_feedback_is_enabled()", render_callback)
        self.assertIn("!svl_home_redesign_article_feedback_is_enabled()", submit_callback)
        self.assertIn("Оценка статьи сейчас отключена.", submit_callback)


if __name__ == "__main__":
    unittest.main()
