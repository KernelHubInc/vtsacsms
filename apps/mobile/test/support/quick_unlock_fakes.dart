import 'dart:async';

import 'package:vtsa_mobile/features/auth/data/device_authenticator.dart';
import 'package:vtsa_mobile/features/auth/data/quick_unlock_store.dart';

final class MemoryUnlockSettings implements UnlockSettingsStore {
  String? value;
  bool failWrites = false;
  bool failReads = false;
  @override
  Future<String?> read() async {
    if (failReads) throw StateError('Storage unavailable');
    return value;
  }

  @override
  Future<void> write(String value) async {
    if (failWrites) throw StateError('Storage unavailable');
    this.value = value;
  }

  @override
  Future<void> clear() async => value = null;
}

final class FakeDeviceAuthenticator implements DeviceAuthenticator {
  @override
  bool supported = true;
  bool enrolled = true;
  bool accepted = true;
  Completer<bool>? pending;
  DeviceBiometricKind kind = DeviceBiometricKind.fingerprint;
  int authenticationCalls = 0;
  @override
  Future<bool> hasBiometrics() async => enrolled;
  @override
  Future<bool> authenticate() async {
    authenticationCalls++;
    return pending?.future ?? accepted;
  }

  @override
  Future<DeviceBiometricKind> biometricKind() async => kind;
}
