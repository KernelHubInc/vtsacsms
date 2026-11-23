from pathlib import Path
import json
import plistlib
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
PODFILE = ROOT / 'apps/mobile/ios/Podfile'


class IosLocationPermissionsTest(unittest.TestCase):
    def test_geolocator_excludes_always_authorization(self):
        self.assertIn('BYPASS_PERMISSION_LOCATION_ALWAYS=1', PODFILE.read_text())

    def test_location_permission_stays_foreground_only(self):
        info = plistlib.loads((ROOT / 'apps/mobile/ios/Runner/Info.plist').read_bytes())
        self.assertTrue(info.get('NSLocationWhenInUseUsageDescription', '').strip())
        self.assertNotIn('NSLocationAlwaysAndWhenInUseUsageDescription', info)
        self.assertNotIn('NSLocationAlwaysUsageDescription', info)
        self.assertNotIn('location', info.get('UIBackgroundModes', []))

    @unittest.skipUnless(shutil.which('ruby'), 'The CI iOS check requires Ruby to execute the Podfile hook.')
    def test_pod_hook_preserves_flags_and_only_changes_geolocator(self):
        ruby = r'''
require 'json'
Config = Struct.new(:name, :build_settings)
Target = Struct.new(:name, :build_configurations)
Project = Struct.new(:targets)
Installer = Struct.new(:pods_project)
$flutter_targets = []
def flutter_additional_ios_build_settings(target)
  $flutter_targets << target.name
end
def post_install(&hook)
  $hook = hook
end
source = File.read(ARGV.fetch(0))
start = source.index('post_install do |installer|') or abort('Missing post-install hook')
eval(source[start..], TOPLEVEL_BINDING, ARGV.fetch(0))
configs = [
  Config.new('Debug', {}),
  Config.new('Release', {'GCC_PREPROCESSOR_DEFINITIONS' => ['$(inherited)', 'EXISTING=1']}),
  Config.new('Profile', {'GCC_PREPROCESSOR_DEFINITIONS' => '$(inherited) EXISTING_STRING=1'})
]
other = Config.new('Release', {'GCC_PREPROCESSOR_DEFINITIONS' => ['UNCHANGED=1']})
targets = [Target.new('geolocator_apple', configs), Target.new('AnotherPod', [other])]
installer = Installer.new(Project.new(targets))
2.times { $hook.call(installer) }
puts JSON.generate({configs: configs.map(&:build_settings), other: other.build_settings, flutter_targets: $flutter_targets})
'''
        result = subprocess.run(['ruby', '-e', ruby, str(PODFILE)], text=True, capture_output=True, check=True)
        actual = json.loads(result.stdout)
        flags = [config['GCC_PREPROCESSOR_DEFINITIONS'] for config in actual['configs']]
        self.assertEqual(flags, [
            ['$(inherited)', 'BYPASS_PERMISSION_LOCATION_ALWAYS=1'],
            ['$(inherited)', 'EXISTING=1', 'BYPASS_PERMISSION_LOCATION_ALWAYS=1'],
            '$(inherited) EXISTING_STRING=1 BYPASS_PERMISSION_LOCATION_ALWAYS=1',
        ])
        self.assertEqual(actual['other'], {'GCC_PREPROCESSOR_DEFINITIONS': ['UNCHANGED=1']})
        self.assertEqual(actual['flutter_targets'], ['geolocator_apple', 'AnotherPod'] * 2)


if __name__ == '__main__':
    unittest.main()
