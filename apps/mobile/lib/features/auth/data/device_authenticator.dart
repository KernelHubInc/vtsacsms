import 'package:flutter/foundation.dart';
import 'package:local_auth/local_auth.dart';

enum DeviceBiometricKind { fingerprint, face, generic }

abstract interface class DeviceAuthenticator {
  bool get supported;
  Future<bool> hasBiometrics();
  Future<bool> authenticate();
  Future<DeviceBiometricKind> biometricKind();
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
  Future<DeviceBiometricKind> biometricKind() async {
    if (!supported) return DeviceBiometricKind.generic;
    final available = await _auth.getAvailableBiometrics();
    if (available.contains(BiometricType.face)) return DeviceBiometricKind.face;
    if (available.contains(BiometricType.fingerprint)) {
      return DeviceBiometricKind.fingerprint;
    }
    return DeviceBiometricKind.generic;
  }

  @override
  Future<bool> authenticate() => _auth.authenticate(
    localizedReason: 'Unlock your Power Solutions account',
    biometricOnly: true,
  );
}
