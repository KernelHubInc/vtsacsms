import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/config/app_environment.dart';

void main() {
  AppEnvironment environment({
    bool enabled = true,
    String server = 'web-client',
    String ios = 'ios-client',
  }) => AppEnvironment(
    name: AppEnvironmentName.production,
    apiBaseUrl: Uri.parse('https://example.test'),
    defaultTenantId: 'tenant',
    googleMapsApiKey: '',
    googleSignInEnabled: enabled,
    googleServerClientId: server,
    googleIosClientId: ios,
  );

  test(
    'configured Google sign-in supports native Android, iOS and browsers',
    () {
      final config = environment();
      for (final platform in [TargetPlatform.android, TargetPlatform.iOS]) {
        expect(
          config.supportsGoogleSignIn(isWeb: false, platform: platform),
          isTrue,
        );
      }
      expect(
        config.supportsGoogleSignIn(
          isWeb: true,
          platform: TargetPlatform.windows,
        ),
        isTrue,
      );
      expect(
        config.supportsGoogleSignIn(
          isWeb: false,
          platform: TargetPlatform.windows,
        ),
        isFalse,
      );
    },
  );

  test(
    'missing or disabled Google configuration fails closed for each client',
    () {
      for (final config in [
        environment(enabled: false),
        environment(server: ''),
      ]) {
        for (final platform in [TargetPlatform.android, TargetPlatform.iOS]) {
          expect(
            config.supportsGoogleSignIn(isWeb: false, platform: platform),
            isFalse,
          );
          expect(
            config.supportsGoogleSignIn(isWeb: true, platform: platform),
            isFalse,
          );
        }
      }
      final config = environment(ios: '');
      expect(
        config.supportsGoogleSignIn(isWeb: false, platform: TargetPlatform.iOS),
        isFalse,
      );
      expect(
        config.supportsGoogleSignIn(
          isWeb: false,
          platform: TargetPlatform.android,
        ),
        isTrue,
      );
    },
  );

  test(
    'resolving map configuration preserves Google authentication configuration',
    () {
      final config = environment();
      final resolved = config.withMaps(config.maps);
      expect(resolved.googleIosClientId, config.googleIosClientId);
      expect(resolved.googleServerClientId, config.googleServerClientId);
      expect(resolved.googleSignInEnabled, isTrue);
    },
  );
}
