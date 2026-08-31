import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
STAGING_PROXY = ROOT / "ops/staging/proxy/test.kolodahearthstone.com.conf"


class ProxyUploadStreamingTest(unittest.TestCase):
    def test_staging_proxy_streams_upload_bodies_to_the_origin(self) -> None:
        config = STAGING_PROXY.read_text(encoding="utf-8")

        self.assertIn("proxy_request_buffering off;", config)
        self.assertIn("proxy_buffering off;", config)


if __name__ == "__main__":
    unittest.main()
