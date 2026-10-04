import importlib.util
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("staging_notices", ROOT / "scripts/enable-staging-kyc.py")
notices = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(notices)


class StagingNoticesTest(unittest.TestCase):
    def test_env_update_preserves_secrets_and_unrelated_configuration(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / ".env.staging"
            path.write_text("# private\nAPP_ENV=demo\nKYC_REQUEST_SECRET='test-only-$value'\nKYC_ENABLED=false\n")
            notices.update_env(path, {"KYC_ENABLED": "true", "KYC_CONSENT_VERSION": "staging-test-v1"})
            result = path.read_text()
            self.assertIn("KYC_REQUEST_SECRET='test-only-$value'", result)
            self.assertIn("APP_ENV=demo", result)
            self.assertEqual(1, result.count("KYC_ENABLED="))
            self.assertIn("KYC_ENABLED='true'", result)
            notices.update_env(path, {"KYC_ENABLED": "false"})
            self.assertIn("KYC_ENABLED='false'", path.read_text())

    def test_ambiguous_env_is_not_changed(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / ".env.staging"
            original = "KYC_ENABLED=false\nKYC_ENABLED=true\n"
            path.write_text(original)
            with self.assertRaises(ValueError):
                notices.update_env(path, {"KYC_ENABLED": "true"})
            self.assertEqual(original, path.read_text())

    def test_notice_settings_use_the_supplied_origin_and_processor_retention(self):
        updates = notices.policy_updates("https://staging.example.test/", "staging-test-v1", "Test consent.", "7")
        self.assertEqual("https://staging.example.test/staging/kyc/consent", updates["KYC_CONSENT_URL"])
        self.assertEqual("7", updates["KYC_RETENTION_DAYS"])
        self.assertEqual("false", updates["KYC_AUTOMATIC_VERIFICATION_ENABLED"])
        self.assertNotIn("APP_ENV", updates)
        self.assertNotIn("KYC_REQUEST_SECRET", updates)

    def test_untrusted_origins_and_invalid_retention_are_rejected(self):
        for origin in ("http://example.test", "https://user:password@example.test", "https://example.test/path", "https://example.test/?secret=value"):
            with self.subTest(origin=origin), self.assertRaises(ValueError):
                notices.policy_updates(origin, "staging-test-v1", "Consent", 7)
        with self.assertRaises(ValueError):
            notices.policy_updates("https://example.test", "staging-test-v1", "Consent", 0)


if __name__ == "__main__":
    unittest.main()
