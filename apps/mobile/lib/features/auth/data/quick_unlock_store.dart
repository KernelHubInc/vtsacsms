import 'dart:convert';
import 'dart:math';

import 'package:cryptography/cryptography.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/data/device_authenticator.dart';

abstract interface class UnlockSettingsStore {
  Future<String?> read();
  Future<void> write(String value);
  Future<void> clear();
}

final class SecureUnlockSettingsStore implements UnlockSettingsStore {
  SecureUnlockSettingsStore(this._storage);
  final FlutterSecureStorage _storage;
  static const _key = 'vtsa.auth.quick_unlock.v1';

  @override
  Future<String?> read() => _storage.read(key: _key);
  @override
  Future<void> write(String value) => _storage.write(key: _key, value: value);
  @override
  Future<void> clear() => _storage.delete(key: _key);
}

/// The API client shares this store, so locked sessions cannot attach a token.
final class QuickUnlockStore implements LockableTokenStore {
  factory QuickUnlockStore({
    required TokenStore tokens,
    required UnlockSettingsStore settings,
    required DeviceAuthenticator device,
    DateTime Function()? now,
  }) => QuickUnlockStore._(tokens, settings, device, now ?? DateTime.now);

  QuickUnlockStore._(this._tokens, this._settings, this.device, this._now);

  final TokenStore _tokens;
  final UnlockSettingsStore _settings;
  final DeviceAuthenticator device;
  final DateTime Function() _now;
  Map<String, dynamic>? _config;
  bool _locked = true;
  bool _initialized = false;
  int _generation = 0;

  bool get enabled => _config != null;
  @override
  bool get locked => _locked;
  bool get biometricsEnabled => _config?['biometrics'] == true;
  int get generation => _generation;

  Future<void> initialize() async {
    if (_initialized) return;
    final value = await _settings.read();
    if (value != null) {
      try {
        final config = jsonDecode(value) as Map<String, dynamic>;
        if (config['version'] != 1 ||
            config['attempts'] is! int ||
            (config['attempts'] as int) < 0 ||
            (config['attempts'] as int) >= 5 ||
            base64Decode(config['salt'] as String).length != 32 ||
            base64Decode(config['hash'] as String).length != 32 ||
            config['tenant'] is! String ||
            config['device'] is! String ||
            config['biometrics'] is! bool) {
          throw const FormatException('Invalid unlock settings');
        }
        _config = config;
      } on Object {
        // A damaged attempt counter must never restore an unprotected session.
        await clear();
      }
    }
    _initialized = true;
    _locked = enabled;
  }

  Future<List<int>> _derive(String pin, List<int> salt) =>
      compute(_derivePin, (pin, salt));

  Future<void> configure(String pin, {required bool biometrics}) async {
    if (!device.supported ||
        _locked ||
        enabled ||
        !RegExp(r'^\d{6}$').hasMatch(pin)) {
      throw const AppFailure(
        kind: FailureKind.validation,
        message: 'Choose a six-digit PIN after signing in with your password.',
      );
    }
    final generation = _generation;
    final tokens = await _tokens.read();
    if (tokens == null || !tokens.expiresAt.isAfter(_now().toUtc())) {
      throw const AppFailure(
        kind: FailureKind.unauthenticated,
        message: 'Sign in again before setting up quick unlock.',
      );
    }
    if (biometrics &&
        (!await device.hasBiometrics() || !await _authenticate())) {
      throw const AppFailure(
        kind: FailureKind.forbidden,
        message:
            'Biometric setup was not completed. Enroll a fingerprint or Face ID in your phone settings, or use PIN only.',
      );
    }
    final random = Random.secure();
    final salt = List<int>.generate(32, (_) => random.nextInt(256));
    final hash = await _derive(pin, salt);
    if (generation != _generation) return;
    final config = <String, dynamic>{
      'version': 1,
      'salt': base64Encode(salt),
      'hash': base64Encode(hash),
      'tenant': tokens.tenantId,
      'device': tokens.deviceId,
      'biometrics': biometrics,
      'attempts': 0,
    };
    await _settings.write(jsonEncode(config));
    _config = config;
    if (generation != _generation) _locked = true;
  }

  Future<bool> _authenticate() async {
    return device.authenticate();
  }

  Future<bool> unlock({String? pin}) async {
    final config = _config;
    if (config == null || !device.supported) return false;
    final generation = _generation;
    bool accepted;
    if (pin == null) {
      accepted = biometricsEnabled && await _authenticate();
    } else {
      // Persist the attempt before checking; restarting cannot reset the budget.
      config['attempts'] = (config['attempts'] as int) + 1;
      await _settings.write(jsonEncode(config));
      final actual = await _derive(pin, base64Decode(config['salt'] as String));
      final expected = base64Decode(config['hash'] as String);
      var difference = 0;
      for (var i = 0; i < actual.length; i++) {
        difference |= actual[i] ^ expected[i];
      }
      accepted = difference == 0;
      if (!accepted && (config['attempts'] as int) >= 5) {
        await clear();
        throw const AppFailure(
          kind: FailureKind.unauthenticated,
          message:
              'Too many incorrect PIN attempts. Sign in with your password to set up quick unlock again.',
        );
      }
    }
    if (!accepted || generation != _generation) return false;
    final tokens = await _tokens.read();
    if (tokens == null ||
        tokens.tenantId != config['tenant'] ||
        tokens.deviceId != config['device'] ||
        !tokens.expiresAt.isAfter(_now().toUtc())) {
      await clear();
      throw const AppFailure(
        kind: FailureKind.unauthenticated,
        message: 'Your session has ended. Sign in with your password.',
      );
    }
    if (generation != _generation) return false;
    config['attempts'] = 0;
    await _settings.write(jsonEncode(config));
    if (generation != _generation) return false;
    _locked = false;
    return true;
  }

  void lock() {
    _generation++;
    if (enabled) _locked = true;
  }

  @override
  Future<TokenBundle?> read() async {
    if (!_initialized || _locked) return null;
    final generation = _generation;
    final tokens = await _tokens.read();
    return generation == _generation && !_locked ? tokens : null;
  }

  @override
  Future<void> write(TokenBundle tokens) async {
    if (_locked) {
      throw const AppFailure(
        kind: FailureKind.unauthenticated,
        message: 'Unlock your account to continue.',
      );
    }
    if (enabled &&
        (tokens.tenantId != _config!['tenant'] ||
            tokens.deviceId != _config!['device'])) {
      await clear();
    }
    await _tokens.write(tokens);
  }

  @override
  Future<void> clear() async {
    _generation++;
    _locked = true;
    await _tokens.clear();
    await _settings.clear();
    _config = null;
    _initialized = true;
    _locked = false;
  }
}

Future<List<int>> _derivePin((String, List<int>) input) async {
  final key = await Pbkdf2(
    macAlgorithm: Hmac.sha256(),
    iterations: 600000,
    bits: 256,
  ).deriveKey(secretKey: SecretKey(utf8.encode(input.$1)), nonce: input.$2);
  return key.extractBytes();
}
