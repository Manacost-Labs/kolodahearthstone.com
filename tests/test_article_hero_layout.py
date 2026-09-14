"""Regression checks for the standalone single-post hero layout."""

from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]
ARTICLE_STYLESHEET = ROOT / "wordpress/plugins/KolodaHearthstone Locker/assets/home-redesign/css/article-redesign.css"


class ArticleHeroLayoutTests(unittest.TestCase):
    def test_single_post_title_follows_the_featured_image_without_forced_case(self) -> None:
        css = ARTICLE_STYLESHEET.read_text(encoding="utf-8")
        hero = 'body.single-post .hero-section[data-type="type-2"]'

        self.assertIn(hero + " > figure", css)
        self.assertIn("position: relative;", css)
        self.assertIn(hero + " > .entry-header", css)
        self.assertIn("--theme-text-transform: none;", css)
        self.assertIn("text-transform: none;", css)


if __name__ == "__main__":
    unittest.main()
