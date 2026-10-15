import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class MediaUniqueFilenamesTest(unittest.TestCase):
    def test_second_import_observes_attachment_created_in_same_request(self):
        with tempfile.TemporaryDirectory() as directory:
            uploads = Path(directory) / "uploads"
            folder = uploads / "2026/10"
            folder.mkdir(parents=True)
            script = f"""
            define('ABSPATH', '/');
            function add_filter(...$args) {{}}
            function wp_get_upload_dir() {{ return ['basedir' => {json.dumps(str(uploads))}]; }}
            $wpdb = new class {{
                public $postmeta = 'wp_postmeta';
                public $files = [];
                public function esc_like($value) {{ return $value; }}
                public function prepare($sql, ...$args) {{ return $sql; }}
                public function get_col($sql) {{ return $this->files; }}
            }};
            require {json.dumps(str(ROOT / 'wordpress/mu-plugins/manacost-media-unique-filenames.php'))};
            Manacost_Media_Unique_Filenames::existing_files(null, {json.dumps(str(folder))}, 'card.png');
            $wpdb->files[] = '2026/10/card.png';
            echo Manacost_Media_Unique_Filenames::ensure_unique(
                'card.png', '.png', {json.dumps(str(folder))}, null, [], ''
            );
            """
            result = subprocess.run(["php", "-r", script], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout, "card-1.png")
