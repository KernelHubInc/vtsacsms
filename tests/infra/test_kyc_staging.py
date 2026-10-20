"""KYC-008/009: source staging must preserve private release safeguards and secrets."""

import base64
import importlib.util
import json
import os
import stat
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "kyc_staging", ROOT / "scripts/kyc-staging.py"
)
staging = importlib.util.module_from_spec(spec)
spec.loader.exec_module(staging)


def unprotected_values(path):
    return {
        line.split("=", 1)[0]: line.split("=", 1)[1].strip("'\"")
        for line in path.read_text().splitlines()
        if line and not line.startswith("#")
    }


class StagingSetupTest(unittest.TestCase):
    def test_prepare_is_private_non_destructive_and_has_independent_keys(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "kyc-staging.env"
            staging.prepare(ROOT, path)
            original = path.read_bytes()
            values = unprotected_values(path)
            self.assertEqual(values["KYC_ENVIRONMENT"], "staging")
            self.assertEqual(values["KYC_MODE"], "self_hosted")
            self.assertEqual(values["KYC_PRIVATE_BIND"], "")
            self.assertEqual(
                len(base64.urlsafe_b64decode(values["KYC_ENCRYPTION_KEY"])), 32
            )
            self.assertEqual(len(values["KYC_REQUEST_SECRET"]), 64)
            self.assertNotEqual(
                values["KYC_REQUEST_SECRET"], values["KYC_CALLBACK_SECRET"]
            )
            self.assertIn("UNCALIBRATED", path.read_text())
            if os.name == "posix":
                self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
                self.assertEqual(staging.validator.read_env(path), values)
            with self.assertRaises(ValueError):
                staging.prepare(ROOT, path)
            self.assertEqual(path.read_bytes(), original)
            with self.assertRaises(ValueError):
                staging.prepare(ROOT, Path(temporary) / "kyc-production.env")

    def test_source_guard_rejects_production_automatic_and_incomplete_live_config(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "kyc-staging.env"
            staging.prepare(ROOT, path)
            valid = unprotected_values(path)
            app = dict(
                DEPLOY_ENVIRONMENT="staging",
                KYC_ASSURANCE_PROFILE="optical_v1",
                KYC_AUTOMATIC_VERIFICATION_ENABLED="false",
            )
            with patch.object(staging.validator, "read_env", side_effect=[valid, app]):
                staging.guard(path, Path("staging.env"))
            for key, value, on_app in (
                ("DEPLOY_ENVIRONMENT", "production", True),
                ("KYC_AUTOMATIC_VERIFICATION_ENABLED", "true", True),
                ("KYC_AUTOMATIC_VERIFICATION_ENABLED", "yes", True),
                ("KYC_ASSURANCE_PROFILE", "issuer_v1", True),
                ("KYC_ENVIRONMENT", "production", False),
                ("KYC_MODE", "mock", False),
                ("KYC_LIVENESS_MODEL", "", False),
                ("KYC_LIVENESS_TURN_THRESHOLD", "", False),
            ):
                processor, application = dict(valid), dict(app)
                (application if on_app else processor)[key] = value
                with (
                    self.subTest(key=key, value=value),
                    patch.object(
                        staging.validator,
                        "read_env",
                        side_effect=[processor, application],
                    ),
                    self.assertRaises(ValueError),
                ):
                    staging.guard(path, Path("staging.env"))

    def test_snapshot_excludes_private_files_is_stable_and_tracks_source_changes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            service = root / staging.SERVICE
            (service / "app").mkdir(parents=True)
            (service / "private").mkdir()
            for name in (
                "Dockerfile",
                "pyproject.toml",
                "requirements.lock",
                "alembic.ini",
                "app/main.py",
            ):
                (service / name).write_bytes(b"source\r\n")
            for name in (
                ".env",
                ".env.staging",
                "private/evidence.jpg",
                "app/credentials.env",
            ):
                (service / name).write_text("must-not-be-included")
            files = staging.runtime_files(root)
            first = staging.write_archive(root, root / "a.tar", files, context=True)
            second = staging.write_archive(root, root / "b.tar", files, context=True)
            self.assertEqual(first, second)
            with tarfile.open(root / "a.tar") as archive:
                self.assertEqual(
                    set(archive.getnames()),
                    {
                        "Dockerfile",
                        "pyproject.toml",
                        "requirements.lock",
                        "alembic.ini",
                        "app/main.py",
                    },
                )
                self.assertEqual(archive.extractfile("app/main.py").read(), b"source\n")
            (service / "app/main.py").write_text("changed")
            self.assertNotEqual(
                first, staging.write_archive(root, root / "c.tar", files, context=True)
            )

    def test_transfer_bundle_contains_launcher_and_runtime_but_no_environment_or_evidence(
        self,
    ):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "bundle.tar.gz"
            digest = staging.package(ROOT, path)
            self.assertIn(digest, path.with_name("bundle.tar.gz.sha256").read_text())
            with tarfile.open(path) as archive:
                names = set(archive.getnames())
                self.assertIn("vtsa-kyc-staging/scripts/kyc-staging.sh", names)
                self.assertIn(
                    "vtsa-kyc-staging/services/kyc-service/app/live_capture.py", names
                )
                self.assertTrue(all(member.isfile() for member in archive.getmembers()))
                self.assertFalse(
                    any(
                        "/private/" in name or "/.env" in name or "/.git/" in name
                        for name in names
                    )
                )
            with self.assertRaises(ValueError):
                staging.package(ROOT, path)

    @unittest.skipUnless(os.name == "posix", "POSIX symlink fixture")
    def test_snapshot_rejects_symlinked_source(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "real.py").write_text("source")
            (root / "linked.py").symlink_to(root / "real.py")
            with self.assertRaises(ValueError):
                staging.write_archive(root, root / "bad.tar", [Path("linked.py")])


@unittest.skipUnless(
    os.name == "posix" and os.geteuid() == 0,
    "Run command flow tests in a disposable Linux root container",
)
class StagingCommandTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        root = Path(self.temporary.name)
        bundle = root / "bundle.tar.gz"
        staging.package(ROOT, bundle)
        with tarfile.open(bundle) as archive:
            archive.extractall(root, filter="data")
        self.repo = root / "vtsa-kyc-staging"
        # DNS/TLS are covered by deployment tests; command tests isolate ordering/failure behavior.
        validator = self.repo / "scripts/validate-kyc-deployment.py"
        validator.write_text(
            validator.read_text().split('if __name__ == "__main__":')[0]
        )
        self.env_file = root / "kyc-staging.env"
        staging.prepare(self.repo, self.env_file)
        self.app_file = root / "staging.env"
        self.app_file.write_text(
            "DEPLOY_ENVIRONMENT=staging\nKYC_ASSURANCE_PROFILE=optical_v1\nKYC_AUTOMATIC_VERIFICATION_ENABLED=false\n"
        )
        self.app_file.chmod(0o600)
        binaries = root / "bin"
        binaries.mkdir()
        fake = binaries / "docker"
        fake.write_text(
            f"#!{sys.executable}\n"
            + """import json, os, sys
args = sys.argv[1:]
with open(os.environ["STAGING_TEST_LOG"], "a") as stream:
    stream.write(json.dumps(dict(args=args, leaked=os.environ.get("COMPOSE_PROFILES"))) + "\\n")
failure = os.environ.get("STAGING_TEST_FAIL", "")
if failure and failure in args and (failure != "kyc-backup" or "run" in args):
    sys.exit(7)
if args and args[0] == "build":
    sys.stdin.buffer.read()
if "kyc-backup" in args and "run" in args:
    if failure != "empty-backup":
        print("synthetic-backup")
"""
        )
        fake.chmod(0o755)
        for name in ("ip", "flock"):
            stub = binaries / name
            stub.write_text("#!/bin/sh\nexit 0\n")
            stub.chmod(0o755)
        self.log = root / "calls.jsonl"
        self.environment = dict(
            os.environ,
            PATH=str(binaries) + os.pathsep + os.environ["PATH"],
            VTSA_REPO_DIR=str(self.repo),
            VTSA_KYC_ENV_FILE=str(self.env_file),
            VTSA_APP_ENV_FILE=str(self.app_file),
            STAGING_TEST_LOG=str(self.log),
            COMPOSE_PROFILES="injected-profile",
        )

    def run_command(self, *args, failure=""):
        self.environment["STAGING_TEST_FAIL"] = failure
        result = subprocess.run(
            ["bash", str(self.repo / "scripts/deploy-kyc.sh"), *args],
            env=self.environment,
            capture_output=True,
            text=True,
            timeout=30,
        )
        calls = (
            [json.loads(line) for line in self.log.read_text().splitlines()]
            if self.log.exists()
            else []
        )
        return result, calls

    def test_check_builds_snapshot_but_never_backs_up_migrates_or_replaces_services(
        self,
    ):
        result, calls = self.run_command("staging", "source", "--check")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(any(call["args"][0] == "build" for call in calls))
        self.assertTrue(any("app.deployment_check" in call["args"] for call in calls))
        self.assertFalse(
            any(
                "up" in call["args"]
                or (
                    "run" in call["args"]
                    and any(
                        name in call["args"] for name in ("kyc-backup", "kyc-migrate")
                    )
                )
                for call in calls
            )
        )
        for call in calls:
            if "version" not in call["args"]:
                self.assertIsNone(call["leaked"])
            if "--project-name" in call["args"]:
                self.assertIn("vtsa-kyc-staging", call["args"])

    def test_failures_abort_before_migration_and_up(self):
        for failure in ("build", "app.deployment_check", "kyc-backup", "empty-backup"):
            if self.log.exists():
                self.log.unlink()
            result, calls = self.run_command("staging", "source", failure=failure)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(
                any(
                    "kyc-migrate" in call["args"] or "up" in call["args"]
                    for call in calls
                )
            )

    def test_success_orders_preflight_backup_migration_then_up(self):
        result, calls = self.run_command("staging", "source")
        self.assertEqual(result.returncode, 0, result.stderr)
        run = [
            call["args"]
            for call in calls
            if "run" in call["args"] or "up" in call["args"]
        ]
        self.assertIn("app.deployment_check", run[0])
        self.assertIn("kyc-backup", run[1])
        self.assertIn("kyc-migrate", run[2])
        self.assertIn("up", run[3])

    def test_production_source_rejected_before_docker_is_called(self):
        result, calls = self.run_command("production", "source")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(calls, [])


if __name__ == "__main__":
    unittest.main()
