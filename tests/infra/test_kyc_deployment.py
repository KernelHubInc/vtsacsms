"""Private deployment regression tests; synthetic configuration only."""

import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("validate_kyc", ROOT / "scripts/validate-kyc-deployment.py")
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)


class KycDeploymentTest(unittest.TestCase):
    def values(self, environment="production"):
        values = dict(KYC_ENVIRONMENT=environment, KYC_DEPLOY_HOSTNAME="test-node", KYC_MODE="local",
                      KYC_INTERNAL_HOST="kyc.test", KYC_PRIVATE_BIND="10.77.0.2", KYC_PRIVATE_PORT="8443",
                      KYC_ALLOWED_CIDRS="10.77.0.2/32 10.77.0.3/32", KYC_TLS_CERT="/cert", KYC_TLS_KEY="/key",
                      KYC_CA_BUNDLE="/ca", KYC_MODEL_DIRECTORY="/models", KYC_REQUEST_SECRET="a" * 40,
                      KYC_CALLBACK_SECRET="b" * 40, KYC_ENCRYPTION_KEY="test-only", KYC_STORAGE="s3",
                      KYC_S3_ENDPOINT="https://storage.test", KYC_S3_BUCKET=f"kyc-{environment}",
                      AWS_ACCESS_KEY_ID="test-only", AWS_SECRET_ACCESS_KEY="test-only",
                      KYC_CALLBACK_URL="https://platform.test/api/v1/webhooks/kyc",
                      KYC_DATABASE_URL=f"postgresql+psycopg://vtsa_kyc_{environment}:test@database.test/vtsa_kyc_{environment}?sslmode=verify-full&sslrootcert=/run/kyc/ca.pem")
        app = dict(DEPLOY_ENVIRONMENT=environment, KYC_REQUEST_SECRET="a" * 40, KYC_CALLBACK_SECRET="b" * 40,
                   KYC_SERVICE_URL="https://kyc.test:8443")
        return values, app

    def check(self, values, app, environment="production", resolve=lambda host: {"10.77.0.2"}):
        validator.validate(values, app, environment, "test-node", resolve)

    def test_each_environment_can_target_its_own_private_stack(self):
        for environment in ("production", "staging"):
            self.check(*self.values(environment), environment)

    def test_public_wildcard_loopback_or_metadata_binds_are_rejected(self):
        for address in ("0.0.0.0", "8.8.8.8", "127.0.0.1", "169.254.169.254", "::"):
            values, app = self.values()
            values["KYC_PRIVATE_BIND"] = address
            with self.assertRaises(ValueError):
                self.check(values, app)

    def test_public_dns_or_mixed_dns_is_rejected(self):
        for addresses in ({"8.8.8.8"}, {"10.77.0.2", "8.8.8.8"}):
            with self.assertRaises(ValueError):
                self.check(*self.values(), resolve=lambda host: addresses)

    def test_wrong_environment_host_database_and_secret_fail_closed(self):
        for key, value in (("KYC_ENVIRONMENT", "staging"), ("KYC_DEPLOY_HOSTNAME", "wrong-host"),
                           ("KYC_REQUEST_SECRET", "wrong"), ("KYC_ALLOWED_CIDRS", "0.0.0.0/0"),
                           ("KYC_MODE", "mock"), ("KYC_DATABASE_URL", "postgresql+psycopg://core:test@db/core")):
            values, app = self.values()
            values[key] = value
            with self.assertRaises(ValueError):
                self.check(values, app)

    def test_compose_publishes_only_private_tls_and_isolates_environments(self):
        for environment in ("staging", "production"):
            values, _ = self.values(environment)
            with tempfile.TemporaryDirectory() as directory:
                filename = Path(directory) / "kyc.env"
                values.update(KYC_IMAGE_TAG="sha-" + "a" * 40, KYC_ENV_FILE=str(filename))
                filename.write_text("\n".join(f"{key}='{value}'" for key, value in values.items()))
                process_env = {key: value for key, value in os.environ.items()
                               if not key.startswith(("KYC_", "COMPOSE_", "AWS_"))}
                result = subprocess.run(["docker", "compose", "--profile", "*", "--env-file", str(filename),
                                         "-f", str(ROOT / "infra/cluster/compose.kyc.yaml"), "config", "--format", "json"],
                                        env=process_env, capture_output=True, text=True, timeout=30)
                self.assertEqual(result.returncode, 0, result.stderr)
                config = json.loads(result.stdout)
                self.assertEqual(config["name"], f"vtsa-kyc-{environment}")
                for name, service in config["services"].items():
                    if name == "kyc-ingress":
                        self.assertEqual(service["ports"][0]["host_ip"], "10.77.0.2")
                        self.assertEqual(service["ports"][0]["target"], 8443)
                        self.assertEqual(service["cap_drop"], ["ALL"])
                        self.assertEqual(service["cap_add"], ["NET_BIND_SERVICE"])
                    else:
                        self.assertFalse(service.get("ports"), name)
                    if name in {"kyc-api", "kyc-worker", "kyc-beat", "kyc-migrate"}:
                        self.assertEqual(service["tmpfs"], ["/tmp:size=128m,mode=1777"])
                self.assertTrue(config["networks"]["backend"]["internal"])
                self.assertEqual(set(config["services"]["kyc-redis"]["networks"]), {"backend"})
                self.assertNotIn("kyc-postgres", config["services"])
                self.assertEqual(config["services"]["kyc-api"]["environment"]["KYC_ENVIRONMENT"], environment)

    def test_enabled_optical_profile_requires_live_models_and_thresholds(self):
        values, app = self.values()
        app.update(KYC_ENABLED="true", KYC_ASSURANCE_PROFILE="optical_v1")
        with self.assertRaises(ValueError):
            self.check(values, app)
        values.update(KYC_MODE="self_hosted", KYC_LIVENESS_MODEL="/models/anti-spoof-mn3.onnx",
                      KYC_LIVENESS_SHA256="a" * 64, KYC_LIVENESS_ACCEPT_THRESHOLD="0.9",
                      KYC_LIVENESS_REJECT_THRESHOLD="0.1", KYC_LIVENESS_CENTER_TOLERANCE="0.1",
                      KYC_LIVENESS_TURN_THRESHOLD="0.3")
        self.check(values, app)
        del values["KYC_LIVENESS_TURN_THRESHOLD"]
        with self.assertRaises(ValueError):
            self.check(values, app)

    def test_app_compose_forwards_profile_and_readiness_to_every_app_process(self):
        for environment in ("staging", "production"):
            process_env = {key: value for key, value in os.environ.items()
                           if not key.startswith(("KYC_", "COMPOSE_"))}
            process_env.update(KYC_ASSURANCE_PROFILE="optical_v1", KYC_AUTOMATIC_VERIFICATION_ENABLED="true")
            result = subprocess.run(["docker", "compose", "--env-file", str(ROOT / f".env.app.{environment}.example"),
                                     "-f", str(ROOT / "infra/cluster/compose.app.yaml"), "config", "--format", "json"],
                                    env=process_env, capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr)
            config = json.loads(result.stdout)
            for name in ("platform", "worker", "scheduler"):
                self.assertEqual(config["services"][name]["environment"]["KYC_ASSURANCE_PROFILE"], "optical_v1")
                self.assertEqual(config["services"][name]["environment"]["KYC_AUTOMATIC_VERIFICATION_ENABLED"], "true")


if __name__ == "__main__":
    unittest.main()
