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

    def test_primary_node_runs_the_single_worker_and_scheduler(self):
        self.assertEqual(services("worker,scheduler"),
                         {"platform", "web", "worker", "scheduler"})

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
        self.assertIn("--queue=charging,default,reports", configured["worker"]["command"])
        self.assertIn("--timeout=120", configured["worker"]["command"])
        self.assertEqual(configured["worker"]["environment"]["REDIS_QUEUE_RETRY_AFTER"], "180")
        for name in ("worker", "scheduler", "ocpp-event-consumer",
                     "ocpp-authorization-consumer"):
            self.assertTrue(configured[name]["healthcheck"]["disable"], name)


if __name__ == "__main__":
    unittest.main()
