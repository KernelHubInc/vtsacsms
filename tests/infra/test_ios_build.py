import importlib.util
import os
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('ios_build', ROOT / 'scripts/build-ios.py')
ios = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ios)


class IosBuildTest(unittest.TestCase):
    def args(self, *extra):
        return ios.parser().parse_args(['--mode', 'ipa', '--version', '1.0.16', '--build-number', '17', *extra])

    def test_windows_stops_before_running_build_tools(self):
        with patch.object(ios.platform, 'system', return_value='Windows'), patch.object(ios.subprocess, 'run') as run:
            self.assertEqual(ios.main(['--mode', 'ipa', '--version', '1.0.16', '--build-number', '17']), 1)
            run.assert_not_called()

    def test_signed_export_requires_real_export_settings(self):
        with patch.object(ios.platform, 'system', return_value='Darwin'), patch.object(ios.shutil, 'which', return_value='/tool'):
            with self.assertRaisesRegex(ValueError, 'export-options'):
                ios.preflight(self.args())

    def test_ipa_command_never_disables_codesigning(self):
        args = self.args('--export-options', str(ROOT / 'export.plist'))
        command = ios.commands(args)[-1]
        self.assertEqual(command[:4], ['flutter', 'build', 'ipa', '--release'])
        self.assertNotIn('--no-codesign', command)
        self.assertIn('--build-number=17', command)

    def test_previous_export_cannot_be_reported_as_new(self):
        with TemporaryDirectory() as directory:
            path = Path(directory) / 'old.ipa'
            path.write_bytes(b'old fixture')
            os.utime(path, (100, 100))
            with self.assertRaisesRegex(ValueError, 'newly exported'):
                ios.fresh_ipa(Path(directory), 101)
            self.assertEqual(ios.fresh_ipa(Path(directory), 99), path)

    def test_simulator_build_does_not_claim_to_export_an_ipa(self):
        args = ios.parser().parse_args(['--mode', 'simulator', '--version', '1.0.16', '--build-number', '17'])
        self.assertEqual(ios.commands(args)[-1][:5], ['flutter', 'build', 'ios', '--simulator', '--debug'])


if __name__ == '__main__':
    unittest.main()
