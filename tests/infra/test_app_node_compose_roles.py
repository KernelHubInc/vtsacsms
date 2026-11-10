"""Keep web-only nodes from consuming jobs or gateway events."""

import os
import json
from pathlib import Path
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
COMPOSE = ROOT / "infra/cluster/compose.app.yaml"
ENV_FILE = ROOT / ".env.app.production.example"
STAGING_ENV_FILE = ROOT / ".env.app.staging.example"


def services(profiles: str) -> set[str]:
    env = os.environ.copy()
    env["COMPOSE_PROFILES"] = profiles
    result = subprocess.run(
        ["docker", "compose", "--env-file", str(ENV_FILE), "-f", str(COMPOSE),
         "config", "--services"],
        cwd=ROOT, env=env, capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode:
        raise AssertionError(result.stderr)
    return set(result.stdout.splitlines())


class AppNodeComposeRolesTest(unittest.TestCase):
    def test_google_sign_in_is_disabled_without_deployment_configuration(self):
        for source in (STAGING_ENV_FILE, ENV_FILE):
            env = os.environ.copy()
            for key in ("GOOGLE_SIGN_IN_ENABLED", "GOOGLE_SIGN_IN_TENANT_ID",
                        "GOOGLE_SIGN_IN_SERVER_CLIENT_ID", "GOOGLE_SIGN_IN_ANDROID_CLIENT_ID"):
                env.pop(key, None)
            result = subprocess.run(
                ["docker", "compose", "--env-file", str(source), "-f", str(COMPOSE),
                 "config", "--format", "json"],
                cwd=ROOT, env=env, capture_output=True, text=True, timeout=30, check=True,
            )
            configured = json.loads(result.stdout)["services"]["platform"]["environment"]
            self.assertEqual(configured["GOOGLE_SIGN_IN_ENABLED"], "false")
            for key in ("GOOGLE_SIGN_IN_TENANT_ID", "GOOGLE_SIGN_IN_SERVER_CLIENT_ID",
                        "GOOGLE_SIGN_IN_ANDROID_CLIENT_ID"):
                self.assertEqual(configured[key], "")

    def test_google_settings_reach_each_enabled_laravel_service(self):
        settings = {
            "GOOGLE_SIGN_IN_ENABLED": "true",
            "GOOGLE_SIGN_IN_TENANT_ID": "01J0000000VTSADEMA00000000",
            "GOOGLE_SIGN_IN_SERVER_CLIENT_ID": "staging-server.apps.googleusercontent.com",
            "GOOGLE_SIGN_IN_ANDROID_CLIENT_ID": "staging-android.apps.googleusercontent.com",
        }
        env = {**os.environ, **settings, "COMPOSE_PROFILES": "worker,scheduler,ocpp"}
        result = subprocess.run(
            ["docker", "compose", "--env-file", str(STAGING_ENV_FILE), "-f", str(COMPOSE),
             "config", "--format", "json"],
            cwd=ROOT, env=env, capture_output=True, text=True, timeout=30, check=True,
        )
        configured = json.loads(result.stdout)["services"]
        for name in ("platform", "worker", "charging-worker", "scheduler",
                     "ocpp-event-consumer", "ocpp-authorization-consumer"):
            for key, value in settings.items():
                self.assertEqual(configured[name]["environment"].get(key), value, name)

    def test_staging_on_app02_is_web_only(self):
        env = os.environ.copy()
        env.pop("COMPOSE_PROFILES", None)
        result = subprocess.run(
            ["docker", "compose", "--env-file", str(STAGING_ENV_FILE),
             "-f", str(COMPOSE), "config", "--services"],
            cwd=ROOT, env=env, capture_output=True, text=True, timeout=30,
            check=True,
        )
        self.assertEqual(set(result.stdout.splitlines()), {"platform", "web"})

    def test_staging_can_share_the_existing_gateway_network(self):
        env = os.environ.copy()
        env["APP_NETWORK_NAME"] = "vtsa-csms-staging_default"
        env["APP_NETWORK_EXTERNAL"] = "true"
        result = subprocess.run(
            ["docker", "compose", "--env-file", str(STAGING_ENV_FILE),
             "-f", str(COMPOSE), "config", "--format", "json"],
            cwd=ROOT, env=env, capture_output=True, text=True, timeout=30,
            check=True,
        )
        self.assertEqual(json.loads(result.stdout)["networks"]["application"], {
            "name": "vtsa-csms-staging_default", "ipam": {}, "external": True,
        })

    def test_web_node_does_not_run_jobs_or_gateway_consumers(self):
        self.assertEqual(services(""), {"platform", "web"})

    def test_primary_node_isolates_charging_jobs_from_reports(self):
        self.assertEqual(services("worker,scheduler"),
                         {"platform", "web", "worker", "charging-worker", "scheduler"})

    def test_ocpp_services_require_explicit_profile(self):
        self.assertEqual(services("ocpp"),
                         {"platform", "web", "ocpp-gateway",
                          "ocpp-event-consumer", "ocpp-authorization-consumer"})

    def test_command_processes_do_not_inherit_the_web_healthcheck(self):
        env = os.environ.copy()
        env["COMPOSE_PROFILES"] = "worker,scheduler,ocpp"
        result = subprocess.run(
            ["docker", "compose", "--env-file", str(ENV_FILE), "-f", str(COMPOSE),
             "config", "--format", "json"],
            cwd=ROOT, env=env, capture_output=True, text=True, timeout=30, check=True,
        )
        configured = json.loads(result.stdout)["services"]
        self.assertIn("--queue=default,reports,invitations", configured["worker"]["command"])
        self.assertIn("--queue=charging", configured["charging-worker"]["command"])
        self.assertIn("--timeout=60", configured["charging-worker"]["command"])
        self.assertIn("--timeout=120", configured["worker"]["command"])
        self.assertEqual(configured["worker"]["environment"]["REDIS_QUEUE_RETRY_AFTER"], "180")
        for name in ("worker", "charging-worker", "scheduler", "ocpp-event-consumer",
                     "ocpp-authorization-consumer"):
            self.assertTrue(configured[name]["healthcheck"]["disable"], name)


if __name__ == "__main__":
    unittest.main()
