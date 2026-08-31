from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wordpress/mu-plugins/hs-media-upload-accelerator.php"


class MediaUploadAcceleratorTest(unittest.TestCase):
    def run_php(self, script: str) -> dict:
        completed = subprocess.run(
            ["/opt/php84/bin/php", "-r", script],
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        return json.loads(completed.stdout)

    def test_async_upload_defers_only_heavy_sizes_and_queues_background_work(self) -> None:
        script = f"""
        define('ABSPATH', '/');
        $hooks = [];
        function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {{ $GLOBALS['hooks'][$tag] = [$callback, $accepted_args]; }}
        function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {{ $GLOBALS['hooks'][$tag] = [$callback, $accepted_args]; }}
        function wp_doing_ajax() {{ return defined('DOING_AJAX') && DOING_AJAX; }}
        function wp_get_registered_image_subsizes() {{ return [
            'thumbnail' => ['width' => 150],
            'large' => ['width' => 1024],
            '1536x1536' => ['width' => 1536],
            '2048x2048' => ['width' => 2048],
        ]; }}
        function get_post_type($id) {{ return 'attachment'; }}
        function wp_attachment_is_image($id) {{ return true; }}
        function as_enqueue_async_action($hook, $args, $group, $unique) {{ $GLOBALS['queued'] = [$hook, $args, $group, $unique]; return 1; }}
        function wp_schedule_single_event($timestamp, $hook, $args) {{ $GLOBALS['scheduled'] = [$timestamp, $hook, $args]; return true; }}
        function wp_next_scheduled($hook, $args) {{ return false; }}
        require {json.dumps(str(PLUGIN))};
        $_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/async-upload.php';
        $sizes = [
            'thumbnail' => ['width' => 150],
            'large' => ['width' => 1024],
            '1536x1536' => ['width' => 1536],
            '2048x2048' => ['width' => 2048],
        ];
        $filtered = Manacost_Media_Upload_Accelerator::filter_sizes($sizes, [], 42);
        $metadata = ['width' => 2400, 'height' => 1800, 'sizes' => ['thumbnail' => [], 'large' => []]];
        Manacost_Media_Upload_Accelerator::queue_after_metadata($metadata, 42, 'create');
        Manacost_Media_Upload_Accelerator::flush_pending_queue();
        echo json_encode(['sizes' => array_keys($filtered), 'queued' => $GLOBALS['queued'] ?? null]);
        """
        result = self.run_php(script)
        self.assertEqual(result["sizes"], ["thumbnail", "large"])
        self.assertEqual(
            result["queued"],
            [
                "manacost_media_upload_accelerator_generate_subsizes",
                [42, 0],
                "manacost-media-upload-accelerator",
                False,
            ],
        )

    def test_burst_upload_queues_every_attachment(self) -> None:
        script = f"""
        define('ABSPATH', '/');
        $queued = [];
        function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {{}}
        function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {{}}
        function wp_doing_ajax() {{ return false; }}
        function wp_get_registered_image_subsizes() {{ return [
            'thumbnail' => ['width' => 150],
            '1536x1536' => ['width' => 1536],
        ]; }}
        function wp_attachment_is_image($id) {{ return true; }}
        function as_enqueue_async_action($hook, $args, $group, $unique) {{
            $GLOBALS['queued'][] = [$hook, $args, $group, $unique];
            return count($GLOBALS['queued']);
        }}
        require {json.dumps(str(PLUGIN))};
        $_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/async-upload.php';
        $metadata = ['sizes' => ['thumbnail' => []]];
        Manacost_Media_Upload_Accelerator::queue_after_metadata($metadata, 42, 'create');
        Manacost_Media_Upload_Accelerator::queue_after_metadata($metadata, 43, 'create');
        Manacost_Media_Upload_Accelerator::flush_pending_queue();
        echo json_encode($GLOBALS['queued']);
        """
        result = self.run_php(script)
        self.assertEqual(len(result), 2)
        self.assertEqual([action[1][0] for action in result], [42, 43])
        self.assertTrue(all(action[3] is False for action in result))

    def test_non_upload_requests_keep_all_sizes(self) -> None:
        script = f"""
        define('ABSPATH', '/');
        $hooks = [];
        function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {{}}
        function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {{}}
        function wp_doing_ajax() {{ return false; }}
        require {json.dumps(str(PLUGIN))};
        $_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/post.php';
        $sizes = ['thumbnail' => [], '1536x1536' => [], '2048x2048' => []];
        echo json_encode(array_keys(Manacost_Media_Upload_Accelerator::filter_sizes($sizes, [], 42)));
        """
        completed = subprocess.run(
            ["/opt/php84/bin/php", "-r", script],
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual(json.loads(completed.stdout), ["thumbnail", "1536x1536", "2048x2048"])

    def test_metadata_updates_do_not_enqueue_the_worker_again(self) -> None:
        script = f"""
        define('ABSPATH', '/');
        function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {{}}
        function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {{}}
        function wp_doing_ajax() {{ return false; }}
        function wp_attachment_is_image($id) {{ return true; }}
        function wp_get_registered_image_subsizes() {{ return ['1536x1536' => ['width' => 1536]]; }}
        function as_enqueue_async_action($hook, $args, $group, $unique) {{ $GLOBALS['queued'] = true; return 1; }}
        require {json.dumps(str(PLUGIN))};
        $_SERVER['SCRIPT_FILENAME'] = '/srv/www/wp-admin/async-upload.php';
        $metadata = ['sizes' => []];
        Manacost_Media_Upload_Accelerator::queue_after_metadata($metadata, 42, 'update');
        Manacost_Media_Upload_Accelerator::flush_pending_queue();
        echo json_encode(isset($GLOBALS['queued']));
        """
        completed = subprocess.run(
            ["/opt/php84/bin/php", "-r", script],
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual(json.loads(completed.stdout), False)


if __name__ == "__main__":
    unittest.main()
