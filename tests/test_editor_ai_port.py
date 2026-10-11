import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADAPTER = ROOT / 'wordpress/mu-plugins/koloda-editor-ai-access.php'

class EditorAiPortTest(unittest.TestCase):
    def gate(self, admin=False, existing=False):
        before = 'function hs_ew_is_enabled(): bool { return false; }' if existing else ''
        code = "namespace Manacost\\EditorAi { class Jobs {} } namespace { define('ABSPATH', '/'); function add_action($hook,$callback,$priority) { $callback(); } function current_user_can($cap) { return $cap === 'manage_options' && " + ('true' if admin else 'false') + "; } " + before + " require " + json.dumps(str(ADAPTER)) + "; echo hs_ew_is_enabled() ? 'yes' : 'no'; }"
        return subprocess.check_output(['php', '-r', code], text=True)

    def test_administrators_only(self):
        self.assertEqual(self.gate(admin=True), 'yes')
        self.assertEqual(self.gate(admin=False), 'no')

    def test_existing_rollout_is_preserved(self):
        self.assertEqual(self.gate(admin=True, existing=True), 'no')

    def test_shared_source_is_pinned(self):
        lock = json.loads((ROOT / 'config/shared-plugin-lock.json').read_text())['plugins']['hs-editor-ai']
        self.assertEqual(lock['source_commit'], 'e282ad647ca1a5f917dbbf42510ad6787877d664')
        self.assertTrue((ROOT / 'wordpress/plugins/hs-editor-ai/hs-editor-ai/class-admin.php').is_file())
