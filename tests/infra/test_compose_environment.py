"""Validate environment selection without starting services or reading private env files."""

import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ROOT / "infra/compose.yaml"
VARIABLES = set(re.findall(r"\$\{([A-Z][A-Z0-9_]*)", COMPOSE.read_text(encoding="utf-8")))


class ComposeEnvironmentTest(unittest.TestCase):
    def render(self, filename, values):
        environment = {key: value for key, value in os.environ.items()
                       if key not in VARIABLES and not key.startswith("COMPOSE_")}
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / filename
            path.write_text("\n".join(f"{key}='{value}'" for key, value in values.items()))
            return subprocess.run(
                ["docker", "compose", "--profile", "*", "--env-file", str(path),
                 "-f", str(COMPOSE), "config", "--format", "json"],
                cwd=directory, env=environment, capture_output=True, text=True,
                timeout=30, check=False,
            )

    def template(self, filename):
        values = {}
        for line in (ROOT / filename).read_text(encoding="utf-8").splitlines():
            if line.strip() and not line.lstrip().startswith("#"):
                key, value = line.split("=", 1)
                values[key] = value.strip().strip('"')
        for key in ("APP_KEY", "POSTGRES_PASSWORD", "REDIS_PASSWORD", "MINIO_ROOT_PASSWORD"):
            values[key] = "compose-test-only"
        return values

    def test_local_template_renders_without_staging_values(self):
        result = self.render(".env", self.template(".env.example"))
        self.assertEqual(result.returncode, 0, result.stderr)
        services = json.loads(result.stdout)["services"]
        self.assertEqual(services["platform"]["environment"]["APP_ENV"], "local")
        self.assertEqual(services["platform"]["environment"]["MAIL_HOST"], "mailpit")
        self.assertEqual(services["flutter-web"]["build"]["args"]["API_BASE_URL"], "http://localhost:8000")

    def test_staging_settings_reach_all_application_services_and_mobile_build(self):
        values = self.template(".env.staging.example")
        overrides = {
            "APP_URL": "https://staging.example.test", "DB_HOST": "database.example.test",
            "DB_DATABASE": "staging_test", "DB_USERNAME": "staging_test",
            "DB_PASSWORD": "external-test-only", "REDIS_HOST": "redis.example.test",
            "MAIL_USERNAME": "sender@example.test", "MAIL_PASSWORD": "test-only",
            "MAIL_FROM_ADDRESS": "sender@example.test", "TRUSTED_PROXIES": "10.0.0.10",
            "APP_HTTP_PORT": "8081", "MOBILE_WEB_PORT": "3001",
            "MOBILE_API_BASE_URL": "https://staging.example.test",
        }
        values.update(overrides)
        result = self.render(".env.staging", values)
        self.assertEqual(result.returncode, 0, result.stderr)
        services = json.loads(result.stdout)["services"]
        for service in ("platform", "worker", "scheduler", "ocpp-event-consumer", "ocpp-authorization-consumer"):
            with self.subTest(service=service):
                environment = services[service]["environment"]
                for key in ("APP_URL", "DB_HOST", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD",
                            "REDIS_HOST", "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM_ADDRESS", "TRUSTED_PROXIES"):
                    self.assertEqual(environment[key], values[key], key)
                self.assertEqual(environment["APP_ENV"], "staging")
                self.assertEqual(environment["APP_DEBUG"], "false")
                self.assertEqual(environment["MAIL_HOST"], "smtp.gmail.com")
                self.assertEqual(environment["MAIL_PORT"], "587")
                self.assertEqual(environment["FEATURE_DEMO_MODE"], "true")
                self.assertEqual(environment["FEATURE_REAL_PAYMENTS"], "false")
        mobile = services["flutter-web"]
        self.assertEqual(mobile["build"]["args"]["APP_ENVIRONMENT"], "staging")
        self.assertEqual(mobile["build"]["args"]["API_BASE_URL"], values["MOBILE_API_BASE_URL"])
        self.assertEqual(mobile["ports"][0]["published"], "3001")
        self.assertEqual(services["platform"]["ports"][0]["published"], "8081")

    def test_missing_app_key_fails_before_containers_start(self):
        values = self.template(".env.staging.example")
        del values["APP_KEY"]
        result = self.render(".env.staging", values)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("APP_KEY", result.stderr)


if __name__ == "__main__":
    unittest.main()
