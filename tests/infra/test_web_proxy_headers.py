"""Guard the web proxy boundary used by direct health checks and the load balancer."""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
NGINX = ROOT / "apps/platform/docker/nginx/default.conf"


class WebProxyHeadersTest(unittest.TestCase):
    def test_forwarded_host_has_a_nonempty_fallback(self):
        config = NGINX.read_text(encoding="utf-8")
        match = re.search(r"fastcgi_param\s+HTTP_X_FORWARDED_HOST\s+([^;]+);", config)
        self.assertIsNotNone(match)
        self.assertIn(match.group(1).strip(), {"$host", "$http_host"})


if __name__ == "__main__":
    unittest.main()