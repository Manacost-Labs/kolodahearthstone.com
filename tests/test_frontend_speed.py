from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wordpress" / "mu-plugins" / "koloda-frontend-speed.php"
PLAUSIBLE = ROOT / "wordpress" / "mu-plugins" / "plausible-analytics.php"
DECKS = ROOT / "wordpress" / "plugins" / "wp-kolodahearthstone-decks" / "kolodahearthstone-decks.php"

STUBS = r'''
define('ABSPATH', '/');
class WP_Post { public $ID; function __construct($id) { $this->ID = $id; } }
class Scripts { public $registered = ['jquery' => 1, 'jquery-core' => 1, 'jquery-migrate' => 1]; public $data = [];
  function add_data($h, $k, $v) { $this->data[$h][$k] = $v; } }
$GLOBALS['hooks'] = [];
$GLOBALS['ctx'] = ['loop' => false, 'singular' => false, 'home' => false, 'main' => true, 'logged_in' => false, 'thumb' => 0];
function add_filter($name, $callback) { $GLOBALS['hooks'][$name] = $callback; }
function add_action($name, $callback) { $GLOBALS['hooks'][$name] = $callback; }
function is_admin() { return false; }
function is_feed() { return false; }
function is_customize_preview() { return false; }
function is_user_logged_in() { return $GLOBALS['ctx']['logged_in']; }
function in_the_loop() { return $GLOBALS['ctx']['loop']; }
function is_singular() { return $GLOBALS['ctx']['singular']; }
function is_home() { return $GLOBALS['ctx']['home']; }
function is_archive() { return false; }
function is_search() { return false; }
function is_main_query() { return $GLOBALS['ctx']['main']; }
function get_queried_object_id() { return 10; }
function get_post_thumbnail_id($id) { return $GLOBALS['ctx']['thumb']; }
function wp_scripts() { static $s; return $s ??= new Scripts(); }
'''


def run_php(body: str, defines: str = "") -> dict:
    script = STUBS + defines + "require $argv[1];\n" + body
    result = subprocess.run(["php", "-r", script, str(PLUGIN)], check=True, text=True, capture_output=True)
    return json.loads(result.stdout)


class FrontendSpeedTest(unittest.TestCase):
    def test_anonymous_navigation_prerenders_on_moderate_intent(self) -> None:
        data = run_php(r'''
        $configure = $GLOBALS['hooks']['wp_speculation_rules_configuration'];
        $exclude = $GLOBALS['hooks']['wp_speculation_rules_href_exclude_paths'];
        echo json_encode(['on' => $configure(['mode' => 'auto', 'eagerness' => 'auto']), 'off' => $configure(null),
          'paths' => $exclude(['/custom/*']), 'twice' => $exclude($exclude(['/custom/*']))]);
        ''')
        self.assertEqual({"mode": "prerender", "eagerness": "moderate"}, data["on"])
        self.assertIsNone(data["off"])
        for path in ["/custom/*", "/lichnyj-kabinet*", "/dobavlenie-kolody*", "/login*", "/logout*", "/feed*"]:
            self.assertIn(path, data["paths"])
        self.assertEqual(data["paths"], data["twice"])

    def test_each_behavior_has_a_kill_switch(self) -> None:
        data = run_php("echo json_encode(array_keys($GLOBALS['hooks']));",
                       "define('KOLODA_PRERENDER_ENABLED', false); define('KOLODA_VIEW_TRANSITIONS_ENABLED', false);"
                       "define('KOLODA_LEADING_IMAGE_ENABLED', false); define('KOLODA_FOOTER_JQUERY_ENABLED', false);")
        self.assertEqual([], data)

    def test_view_transition_respects_reduced_motion(self) -> None:
        data = run_php("ob_start(); $GLOBALS['hooks']['wp_head'](); echo json_encode(ob_get_clean());")
        self.assertIn("@media (prefers-reduced-motion:no-preference){@view-transition{navigation:auto}", data)
        self.assertIn("::view-transition-old(root){animation:none}", data)
        self.assertNotIn("transform", data)

    def test_only_the_first_listing_cover_is_eager_and_high_priority(self) -> None:
        data = run_php(r'''
        $f = $GLOBALS['hooks']['wp_get_attachment_image_attributes'];
        $GLOBALS['ctx'] = ['loop' => true, 'singular' => false, 'home' => true, 'main' => true, 'logged_in' => false, 'thumb' => 0];
        echo json_encode([$f(['class' => 'a'], new WP_Post(5)), $f(['class' => 'a'], new WP_Post(5)), $f(['class' => 'b'], new WP_Post(6))]);
        ''')
        first, repeat, second = data
        self.assertEqual(("eager", "high"), (first["loading"], first["fetchpriority"]))
        self.assertEqual(first, repeat)
        self.assertNotIn("loading", second)
        self.assertNotIn("fetchpriority", second)

    def test_article_cover_is_eager_but_other_article_images_are_not(self) -> None:
        data = run_php(r'''
        $f = $GLOBALS['hooks']['wp_get_attachment_image_attributes'];
        $GLOBALS['ctx'] = ['loop' => false, 'singular' => true, 'home' => false, 'main' => true, 'logged_in' => false, 'thumb' => 7];
        echo json_encode([$f([], new WP_Post(9)), $f([], new WP_Post(7))]);
        ''')
        self.assertEqual([], data[0])
        self.assertEqual("high", data[1]["fetchpriority"])

    def test_jquery_moves_to_footer_for_anonymous_visitors_only(self) -> None:
        data = run_php(r'''
        $GLOBALS['hooks']['wp_enqueue_scripts']();
        echo json_encode(wp_scripts()->data);
        ''')
        self.assertEqual({h: {"group": 1} for h in ["jquery", "jquery-core", "jquery-migrate"]}, data)
        logged_in = run_php(r'''
        $GLOBALS['ctx']['logged_in'] = true;
        $GLOBALS['hooks']['wp_enqueue_scripts']();
        echo json_encode(wp_scripts()->data);
        ''')
        self.assertEqual([], logged_in)

    def test_article_events_wait_for_a_shown_page(self) -> None:
        source = PLAUSIBLE.read_text(encoding="utf-8")
        self.assertIn('echo "function run(){\\n";', source)
        self.assertIn("if(document.prerendering){document.addEventListener('prerenderingchange',run,{once:true});}else{run();}", source)
        self.assertLess(source.find('echo "function run(){'), source.find("addEventListener('pagehide',sendDepth)"))


    def test_article_views_are_counted_only_for_shown_pages(self) -> None:
        source = DECKS.read_text(encoding="utf-8")
        counter = source[source.find('fd.append("action","hs_count_view")'):source.find("return $stats . $content;")]
        self.assertIn('if(document.prerendering){document.addEventListener("prerenderingchange",start,{once:true});}else{start();}', counter)
        self.assertNotIn("}else{send();}' .\n        '})();", counter)

if __name__ == "__main__":
    unittest.main()
