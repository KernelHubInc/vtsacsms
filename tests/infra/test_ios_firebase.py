import importlib.util
import json
from pathlib import Path
import plistlib
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('ios_firebase', ROOT / 'scripts/prepare-ios-firebase.py')
ios = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ios)
ARCHIVE_SPEC = importlib.util.spec_from_file_location('ios_archive', ROOT / 'scripts/verify-ios-archive.py')
archive = importlib.util.module_from_spec(ARCHIVE_SPEC)
ARCHIVE_SPEC.loader.exec_module(archive)


class IosFirebaseTest(unittest.TestCase):
    def config(self, **extra):
        return plistlib.dumps(dict(PROJECT_ID='evcs-power-solutions', BUNDLE_ID=ios.BUNDLE_ID,
                                  GOOGLE_APP_ID='1:123:ios:fixture', GCM_SENDER_ID='123', API_KEY='fixture', **extra))

    def test_production_rejects_staging_and_mismatched_bundle(self):
        for key, value in [('PROJECT_ID', 'evcs-power-solutions-staging'), ('BUNDLE_ID', 'com.vtsa.vtsa_mobile')]:
            config = plistlib.loads(self.config())
            config[key] = value
            with self.assertRaisesRegex(ValueError, 'does not match'):
                ios.validate(plistlib.dumps(config), 'production')

    def test_secret_keys_are_rejected_and_no_values_appear_in_errors(self):
        with self.assertRaisesRegex(ValueError, 'never a service-account'):
            ios.validate(self.config(private_key='DO_NOT_LOG_THIS'), 'production')
        with self.assertRaisesRegex(ValueError, 'Invalid Firebase'):
            ios.validate(b'not a plist', 'production')

    def test_production_preparation_preserves_live_charging_and_payment_flags(self):
        with TemporaryDirectory() as directory:
            root = Path(directory)
            path = ios.prepare(self.config(), 'production', root)
            defines = json.loads(path.read_text())
            self.assertEqual(defines['API_BASE_URL'], 'https://evcspowersolutions.com')
            self.assertEqual(defines['FEATURE_REMOTE_CHARGING'], 'true')
            self.assertEqual(defines['FEATURE_REAL_PAYMENTS'], 'true')
            self.assertEqual(defines['FEATURE_SIMULATED_PAYMENTS'], 'false')
            self.assertNotIn('API_KEY', defines)
            self.assertEqual(defines['GOOGLE_SIGN_IN_ENABLED'], 'true')
            info = plistlib.loads((root / 'apps/mobile/ios/Runner/Info.plist').read_bytes())
            self.assertEqual(info['GIDClientID'], defines['GOOGLE_SIGN_IN_IOS_CLIENT_ID'])
            self.assertIn(ios.reversed_client_id(info['GIDClientID']), info['CFBundleURLTypes'][0]['CFBundleURLSchemes'])
            self.assertTrue((root / 'apps/mobile/ios/Runner/GoogleService-Info.plist').is_file())

    def test_google_setup_preserves_app_links_and_is_repeatable(self):
        with TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / 'apps/mobile/ios/Runner/Info.plist'
            path.parent.mkdir(parents=True)
            path.write_bytes(plistlib.dumps({'CFBundleURLTypes': [{'CFBundleURLSchemes': ['vtsa']}]}))
            ios.prepare(self.config(), 'production', root)
            ios.prepare(self.config(), 'production', root)
            info = plistlib.loads(path.read_bytes())
            self.assertEqual(len(info['CFBundleURLTypes']), 2)
            self.assertEqual(info['CFBundleURLTypes'][0]['CFBundleURLSchemes'], ['vtsa'])
            self.assertEqual(info['GIDServerClientID'], ios.build_defines('production')['GOOGLE_SIGN_IN_SERVER_CLIENT_ID'])

    def test_mismatched_google_oauth_client_fails_before_writing(self):
        with TemporaryDirectory() as directory:
            root = Path(directory)
            with self.assertRaisesRegex(ValueError, 'approved Google sign-in client'):
                ios.prepare(self.config(CLIENT_ID='another.apps.googleusercontent.com'), 'production', root)
            self.assertFalse((root / 'apps/mobile').exists())

    def test_staging_ios_does_not_reuse_production_oauth_client(self):
        defines = ios.build_defines('staging')
        self.assertEqual(defines['GOOGLE_SIGN_IN_ENABLED'], 'false')
        self.assertNotIn('GOOGLE_SIGN_IN_IOS_CLIENT_ID', defines)

    def test_native_target_embeds_config_and_has_apple_push_entitlements(self):
        runner = ROOT / 'apps/mobile/ios/Runner'
        entitlements = plistlib.loads((runner / 'Runner.entitlements').read_bytes())
        self.assertEqual(entitlements['aps-environment'], '$(APS_ENVIRONMENT)')
        info = plistlib.loads((runner / 'Info.plist').read_bytes())
        self.assertIn('remote-notification', info['UIBackgroundModes'])
        self.assertNotEqual(info.get('FirebaseAppDelegateProxyEnabled'), False)
        project = (runner.parent / 'Runner.xcodeproj/project.pbxproj').read_text()
        self.assertIn('/* Firebase configuration */,', project)
        self.assertIn('UNLOCALIZED_RESOURCES_FOLDER_PATH/GoogleService-Info.plist', project)
        self.assertIn('APS_ENVIRONMENT = production', (runner.parent / 'Flutter/Release.xcconfig').read_text())
        self.assertIn('APS_ENVIRONMENT = development', (runner.parent / 'Flutter/Debug.xcconfig').read_text())

    def test_archive_requires_matching_firebase_and_production_apple_signing(self):
        with TemporaryDirectory() as directory:
            app = Path(directory)
            (app / 'GoogleService-Info.plist').write_bytes(self.config())
            defines = ios.build_defines('production')
            client = defines['GOOGLE_SIGN_IN_IOS_CLIENT_ID']
            info = {'CFBundleIdentifier': ios.BUNDLE_ID, 'GIDClientID': client,
                    'GIDServerClientID': defines['GOOGLE_SIGN_IN_SERVER_CLIENT_ID'],
                    'CFBundleURLTypes': [{'CFBundleURLSchemes': [ios.reversed_client_id(client)]}]}
            (app / 'Info.plist').write_bytes(plistlib.dumps(info))
            entitlements = {'aps-environment': 'production', 'application-identifier': 'TEAM.' + ios.BUNDLE_ID}
            archive.verify(app, entitlements, 'production')
            with self.assertRaisesRegex(ValueError, 'production APNs'):
                archive.verify(app, dict(entitlements, **{'aps-environment': 'development'}), 'production')
            with self.assertRaisesRegex(ValueError, 'development signing'):
                archive.verify(app, dict(entitlements, **{'get-task-allow': True}), 'production')
            info['CFBundleURLTypes'] = []
            (app / 'Info.plist').write_bytes(plistlib.dumps(info))
            with self.assertRaisesRegex(ValueError, 'callback scheme'):
                archive.verify(app, entitlements, 'production')


if __name__ == '__main__':
    unittest.main()
