import 'package:flutter/foundation.dart';
import 'package:local_auth/local_auth.dart';

abstract interface class DeviceAuthenticator {
  bool get supported;
  Future<bool> hasBiometrics();
  Future<bool> authenticate();
}

final class NativeDeviceAuthenticator implements DeviceAuthenticator {
  final LocalAuthentication _auth = LocalAuthentication();

  @override
  bool get supported =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS);

  @override
  Future<bool> hasBiometrics() async =>
      supported && (await _auth.getAvailableBiometrics()).isNotEmpty;

  @override
  Future<bool> authenticate() => _auth.authenticate(
    localizedReason: 'Unlock your Power Solutions account',
    biometricOnly: true,
  );
}
