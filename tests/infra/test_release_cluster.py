import os
import subprocess
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "release-cluster.sh"
SHA = "a" * 40


@unittest.skipIf(os.name == "nt", "Bash release behavior is exercised by Linux CI")
class ClusterReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "key").write_text("test", encoding="utf-8")
        (self.root / "known_hosts").write_text("test", encoding="utf-8")
        self.log = self.root / "commands.log"
        self.env = os.environ | {
            "PATH": f"{self.root}{os.pathsep}{os.environ['PATH']}",
            "VTSA_CLUSTER_DEPLOY_KEY": str(self.root / "key"),
            "VTSA_CLUSTER_KNOWN_HOSTS": str(self.root / "known_hosts"),
            "VTSA_RELEASE_LOCK": str(self.root / "release.lock"),
            "TEST_COMMAND_LOG": str(self.log),
            "TEST_SHA": SHA,
        }
        self.write_command("ssh-keygen", "exit 0")
        self.write_command("flock", "exit 0")
        self.write_command("curl", 'printf "curl %s\\n" "$*" >> "$TEST_COMMAND_LOG"')
        self.write_command(
            "ssh",
            '''printf 'ssh %s\\n' "$*" >> "$TEST_COMMAND_LOG"
case "$*" in
  *current-app-image-tag*) printf 'sha-%s\\n' "$TEST_SHA" ;;
  *ls-remote*test_env*) printf '%s\\trefs/heads/test_env\\n' "$TEST_SHA" ;;
  *ls-remote*main*) printf '%s\\trefs/heads/main\\n' "$TEST_SHA" ;;
  *'rev-parse'*'{tree}'*) printf '%s\\n' "${TEST_TREE:-tree-one}" ;;
esac''',
        )

    def write_command(self, name, body):
        path = self.root / name
        path.write_text(f"#!/bin/sh\n{body}\n", encoding="utf-8")
        path.chmod(0o755)

    def run_release(self, environment, release=f"sha-{SHA}", mode=None):
        command = ["bash", str(SCRIPT), environment, release]
        if mode:
            command.append(mode)
        return subprocess.run(command, env=self.env, capture_output=True, text=True)

    def test_staging_deploys_only_app02_with_migration(self):
        result = self.run_release("staging")
        self.assertEqual(result.returncode, 0, result.stderr)
        commands = self.log.read_text(encoding="utf-8")
        self.assertIn(f"vtsa-deploy-app staging 'sha-{SHA}' --migrate", commands)
        self.assertNotIn("187.77.130.156", commands)

    def test_production_deploys_app01_then_app02_without_second_migration(self):
        result = self.run_release("production")
        self.assertEqual(result.returncode, 0, result.stderr)
        commands = self.log.read_text(encoding="utf-8")
        first = commands.index(f"vtsa-deploy-app production 'sha-{SHA}' --migrate")
        second = commands.index(f"vtsa-deploy-app production 'sha-{SHA}'", first + 1)
        self.assertLess(first, second)
        self.assertEqual(commands.count("--migrate"), 1)

    def test_check_mode_does_not_deploy(self):
        result = self.run_release("production", mode="--check")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("vtsa-deploy-app production", self.log.read_text(encoding="utf-8"))

    def test_invalid_release_stops_before_ssh(self):
        result = self.run_release("production", release="main")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.log.exists())


if __name__ == "__main__":
    unittest.main()
