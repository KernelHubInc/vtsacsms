"""Exercise the actual merged Compose deployment with synthetic credentials only."""

import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from urllib.parse import urlsplit, unquote

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "ocpp_staging", ROOT / "scripts/ocpp-staging.py"
)
setup = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(setup)
TENANT = "01J00000000000000000000001"
CHARGER = "01J00000000000000000000002"
HASH = "$argon2id$v=19$m=65536,t=3,p=4$c3ludGhldGlj$aGFzaA"


class OcppStagingTest(unittest.TestCase):
    def test_existing_override_is_preserved_before_the_security_overlay(self):
        override = Path("/etc/vtsa-csms/compose.staging-kyc-dns.yaml")
        with patch.object(setup, "EXTRA_COMPOSE_FILES", [override]):
            command = setup.compose()
            self.assertIn(str(override), command)
            self.assertLess(
                command.index(str(override)),
                command.index(str(ROOT / "infra/compose.ocpp-staging.yaml")),
            )
            self.assertIn(str(override), setup.compose(False))

    def setUp(self):
        self.values = {
            "COMPOSE_PROJECT_NAME": "vtsa-ocpp-test",
            "APP_ENV": "staging",
            "APP_DEBUG": "false",
            "APP_URL": "https://staging.example.test",
            "APP_KEY": "synthetic",
            "POSTGRES_PASSWORD": "synthetic",
            "POSTGRES_DB": "vtsa_test",
            "POSTGRES_USER": "vtsa_test",
            "REDIS_PASSWORD": "synthetic:@/with$special%characters",
            "MINIO_ROOT_USER": "synthetic",
            "MINIO_ROOT_PASSWORD": "synthetic",
            "MINIO_BUCKET": "synthetic",
            "REDIS_HOST": "redis",
            "REDIS_PORT": "6379",
            # Unsafe local values must not leak through the overlay.
            "OCPP_PUBLIC_BIND_ADDRESS": "0.0.0.0",
            "OCPP_PUBLIC_PORT": "19002",
            "OCPP_REQUIRE_TLS": "false",
            "OCPP_DEVELOPMENT_ALLOW_UNAUTHENTICATED": "true",
            "OCPP_ALLOWED_COMMANDS": "Reset,RemoteStartTransaction",
            "KYC_ENABLED": "true",
            "KYC_REQUEST_SECRET": "synthetic-kyc-must-survive",
        }

    def render(self, overlay=True):
        files = [ROOT / "infra/compose.yaml", ROOT / "infra/compose.ocpp-staging.yaml"]
        variables = set(
            re.findall(
                r"\$\{([A-Z][A-Z0-9_]*)", "\n".join(p.read_text() for p in files)
            )
        )
        environment = {
            k: v
            for k, v in os.environ.items()
            if k not in variables and not k.startswith("COMPOSE_")
        }
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory) / "base.env"
            private = Path(directory) / "ocpp.env"
            base.write_text(setup.dotenv(self.values))
            private.write_text(
                setup.dotenv(
                    setup.settings(self.values, "CP-STAGING", TENANT, CHARGER, HASH)
                )
            )
            args = [
                "docker",
                "compose",
                "--profile",
                "milestone2",
                "--env-file",
                str(base),
            ]
            if overlay:
                args += ["--env-file", str(private)]
            args += ["-f", str(files[0]), "-f", str(ROOT / "infra/compose.kyc.yaml")]
            if overlay:
                args += ["-f", str(files[1])]
            result = subprocess.run(
                args + ["config", "--format", "json"],
                capture_output=True,
                text=True,
                env=environment,
                cwd=directory,
                timeout=30,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            return setup.runtime_configuration(json.loads(result.stdout))

    def test_staging_overlay_replaces_public_port_and_aligns_consumers(self):
        config = self.render()
        setup.validate(config)
        gateway = config["services"]["ocpp-gateway"]
        self.assertEqual(
            gateway["ports"],
            [
                {
                    "mode": "ingress",
                    "host_ip": "127.0.0.1",
                    "target": 9000,
                    "published": "9002",
                    "protocol": "tcp",
                }
            ],
        )
        self.assertIn("health/ready", gateway["healthcheck"]["test"][-1])
        self.assertEqual(gateway["restart"], "unless-stopped")
        self.assertEqual(
            config["services"]["platform"]["command"],
            ["php", "-S", "0.0.0.0:8000", "-t", "public", "server.php"],
        )
        registry = json.loads(gateway["environment"]["OCPP_CHARGER_REGISTRY_JSON"])
        self.assertEqual(registry["CP-STAGING"]["basic_password_hash"], HASH)
        for name in setup.SERVICES[1:]:
            self.assertEqual(
                config["services"][name]["image"], "vtsa-ocpp-test-ocpp-core:staging"
            )
            self.assertEqual(config["services"][name]["restart"], "unless-stopped")
        for name in setup.CORE + setup.SERVICES[1:]:
            self.assertIn('exec "$$@"', config["services"][name]["entrypoint"][2])
        for name in setup.CORE:
            self.assertEqual(
                config["services"][name]["environment"]["KYC_ENABLED"], "true"
            )
            self.assertEqual(
                config["services"][name]["environment"]["KYC_REQUEST_SECRET"],
                "synthetic-kyc-must-survive",
            )
        self.assertEqual(
            config["services"]["platform"]["environment"]["REDIS_PASSWORD"],
            self.values["REDIS_PASSWORD"],
        )

    def test_base_configuration_reproduces_the_deployment_blocker(self):
        config = self.render(False)
        with self.assertRaises(ValueError):
            setup.validate(config)
        gateway = config["services"]["ocpp-gateway"]["environment"]
        self.assertNotIn("GATEWAY_REDIS_KEY_PREFIX", gateway)
        self.assertEqual(gateway["GATEWAY_REDIS_URL"], "redis://redis:6379/0")

    def test_redis_password_is_encoded_once_and_internal_token_is_preserved(self):
        self.values["OCPP_GATEWAY_INTERNAL_TOKEN"] = "existing-synthetic-token"
        result = setup.settings(self.values, "CP-STAGING", TENANT, CHARGER, HASH)
        self.assertEqual(
            unquote(urlsplit(result["GATEWAY_REDIS_URL"]).password),
            self.values["REDIS_PASSWORD"],
        )
        self.assertEqual(
            result["GATEWAY_INTERNAL_API_TOKEN"], "existing-synthetic-token"
        )

    def test_rejects_production_and_cross_stream_mismatch(self):
        config = self.render()
        config["services"]["platform"]["environment"]["APP_ENV"] = "production"
        with self.assertRaises(ValueError):
            setup.validate(config)
        config["services"]["platform"]["environment"]["APP_ENV"] = "staging"
        config["services"]["ocpp-event-consumer"]["environment"][
            "OCPP_GATEWAY_EVENT_STREAM"
        ] = "vtsa:production:ocpp:events:v1"
        with self.assertRaises(ValueError):
            setup.validate(config)

    def test_rejects_invalid_binding_and_basic_username(self):
        for identity, tenant, charger in [
            ("CP:001", TENANT, CHARGER),
            ("CP/001", TENANT, CHARGER),
            ("CP-001", "bad", CHARGER),
            ("CP-001", TENANT, "bad"),
        ]:
            with (
                self.subTest(identity=identity, tenant=tenant, charger=charger),
                self.assertRaises(ValueError),
            ):
                setup.settings(self.values, identity, tenant, charger, HASH)

    def test_generated_dotenv_rejects_line_injection(self):
        for value in ["one\ntwo", "one\rtwo", "one'two"]:
            with self.assertRaises(ValueError):
                setup.dotenv({"KEY": value})

    def test_enroll_preserves_existing_device_and_refuses_identity_or_asset_reuse(self):
        first = {
            "tenant_id": TENANT,
            "charger_id": CHARGER,
            "enabled": True,
            "basic_password_hash": HASH,
        }
        second = {
            **first,
            "tenant_id": "01J00000000000000000000003",
            "charger_id": "01J00000000000000000000004",
        }
        registry = {"CP-FIRST": first}
        merged = setup.add_registration(registry, "CP-SECOND", second)
        self.assertEqual(merged, {"CP-FIRST": first, "CP-SECOND": second})
        self.assertEqual(registry, {"CP-FIRST": first})
        with self.assertRaises(ValueError):
            setup.add_registration(registry, "CP-FIRST", second)
        with self.assertRaises(ValueError):
            setup.add_registration(registry, "CP-OTHER", first)

    def test_registry_update_keeps_secrets_and_creates_protected_backup(self):
        with tempfile.TemporaryDirectory() as directory:
            private = Path(directory) / ".env.staging.ocpp"
            old = setup.dotenv(
                {
                    "GATEWAY_INTERNAL_API_TOKEN": "unchanged-token",
                    "GATEWAY_REDIS_URL": "redis://unchanged",
                    "OCPP_CHARGER_REGISTRY_JSON": "{}",
                }
            )
            private.write_text(old)
            registry = {"CP-NEW": {"basic_password_hash": HASH}}
            with patch.object(setup, "PRIVATE", private):
                backup = setup.save_registry(registry, old)
            self.assertEqual(backup.read_text(), old)
            self.assertIn(
                "GATEWAY_INTERNAL_API_TOKEN='unchanged-token'", private.read_text()
            )
            self.assertIn(HASH, private.read_text())
            with patch.object(setup, "PRIVATE", private), self.assertRaises(ValueError):
                setup.save_registry(registry, old)

    def test_verify_requires_gateway_and_both_consumers_to_be_healthy(self):
        rows = [
            {"Service": name, "State": "running", "Health": "healthy"}
            for name in setup.SERVICES
        ]
        setup.validate_service_status(json.dumps(rows))
        setup.validate_service_status("\n".join(json.dumps(row) for row in rows))
        for raw in [
            "",
            json.dumps(rows[:2]),
            json.dumps([{**row, "State": "restarting"} for row in rows]),
            json.dumps([{**row, "Health": "unhealthy"} for row in rows]),
        ]:
            with self.subTest(raw=raw), self.assertRaises(ValueError):
                setup.validate_service_status(raw)


if __name__ == "__main__":
    unittest.main()
