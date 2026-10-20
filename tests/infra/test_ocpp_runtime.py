import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    "ocpp_runtime", Path(__file__).resolve().parents[2] / "scripts/ocpp-runtime.py"
)
runtime = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runtime)


class OcppRuntimeTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.path = Path(self.directory.name) / "upstream.conf"
        self.config = {
            "upstream_file": str(self.path),
            "ports": {"docker": 9002, "native": 9003},
            "internal_port": 9004,
            "service": "vtsa-ocpp",
            "container": "gateway",
        }
        self.original = runtime.upstream("docker", 9002)
        self.path.write_text(self.original)

    def test_switch_checks_both_routes_before_stopping_previous_runtime(self):
        calls = []
        with (
            patch.object(runtime, "run", side_effect=lambda *args: calls.append(args)),
            patch.object(
                runtime,
                "wait_ready",
                side_effect=lambda *args: calls.append(("ready", *args)),
            ),
            patch.object(
                runtime,
                "check_internal",
                side_effect=lambda config: calls.append(("commands",)),
            ),
        ):
            runtime.switch(self.config, "native")
        self.assertEqual(runtime.active_mode(self.config), "native")
        self.assertLess(
            calls.index(("ready", 9004, "native")),
            calls.index(("docker", "stop", "--time", "30", "gateway")),
        )
        self.assertLess(
            calls.index(("commands",)),
            calls.index(("docker", "stop", "--time", "30", "gateway")),
        )

    def test_nginx_failure_restores_configuration_and_keeps_previous_runtime(self):
        calls = []

        def command(*args):
            calls.append(args)
            if args == ("nginx", "-t") and calls.count(args) == 1:
                raise RuntimeError("Invalid nginx configuration")

        with (
            patch.object(runtime, "run", side_effect=command),
            patch.object(runtime, "wait_ready"),
            patch.object(runtime, "check_internal"),
            self.assertRaises(RuntimeError),
        ):
            runtime.switch(self.config, "native")
        self.assertEqual(self.path.read_text(), self.original)
        self.assertNotIn(("docker", "stop", "--time", "30", "gateway"), calls)
        self.assertIn(("systemctl", "stop", "vtsa-ocpp"), calls)

    def test_command_route_failure_rolls_back(self):
        with (
            patch.object(runtime, "run"),
            patch.object(runtime, "wait_ready"),
            patch.object(
                runtime, "check_internal", side_effect=RuntimeError("Route unavailable")
            ),
            self.assertRaises(RuntimeError),
        ):
            runtime.switch(self.config, "native")
        self.assertEqual(runtime.active_mode(self.config), "docker")

    def test_unready_candidate_does_not_change_nginx(self):
        with (
            patch.object(runtime, "run"),
            patch.object(
                runtime, "wait_ready", side_effect=RuntimeError("Redis unavailable")
            ),
            self.assertRaises(RuntimeError),
        ):
            runtime.switch(self.config, "native")
        self.assertEqual(self.path.read_text(), self.original)

    def test_unknown_configuration_is_not_overwritten(self):
        self.path.write_text("upstream externally_managed {}")
        with self.assertRaises(RuntimeError):
            runtime.switch(self.config, "native")
        self.assertEqual(self.path.read_text(), "upstream externally_managed {}")

    def test_docker_switch_disables_native_startup(self):
        self.path.write_text(runtime.upstream("native", 9003))
        with (
            patch.object(runtime, "run") as command,
            patch.object(runtime, "wait_ready"),
            patch.object(runtime, "check_internal"),
        ):
            runtime.switch(self.config, "docker")
        command.assert_any_call("systemctl", "disable", "vtsa-ocpp")
        command.assert_any_call(
            "docker", "update", "--restart=unless-stopped", "gateway"
        )
        self.assertEqual(runtime.active_mode(self.config), "docker")


if __name__ == "__main__":
    unittest.main()
