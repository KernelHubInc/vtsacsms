import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

@immutable
final class TokenBundle {
  const TokenBundle({
    required this.accessToken,
    required this.expiresAt,
    required this.tenantId,
    required this.deviceId,
    this.refreshToken,
  });

  factory TokenBundle.fromJson(Map<String, dynamic> json) => TokenBundle(
    accessToken: json['access_token']! as String,
    refreshToken: json['refresh_token'] as String?,
    expiresAt: DateTime.parse(json['expires_at']! as String).toUtc(),
    tenantId: json['tenant_id']! as String,
    deviceId: json['device_id']! as String,
  );

  final String accessToken;
  final String? refreshToken;
  final DateTime expiresAt;
  final String tenantId;
  final String deviceId;

  Map<String, Object?> toJson() => {
    'access_token': accessToken,
    'refresh_token': refreshToken,
    'expires_at': expiresAt.toUtc().toIso8601String(),
    'tenant_id': tenantId,
    'device_id': deviceId,
  };
}

abstract interface class TokenStore {
  Future<TokenBundle?> read();
  Future<void> write(TokenBundle tokens);
  Future<void> clear();
}

final class SecureTokenStore implements TokenStore {
  SecureTokenStore(this._storage);

  static const _key = 'vtsa.auth.tokens.v1';
  final FlutterSecureStorage _storage;

  @override
  Future<void> clear() => _storage.delete(key: _key);

  @override
  Future<TokenBundle?> read() async {
    final value = await _storage.read(key: _key);
    if (value == null) {
      return null;
    }
    try {
      return TokenBundle.fromJson(jsonDecode(value) as Map<String, dynamic>);
    } on Object {
      await clear();
      return null;
    }
  }

  @override
  Future<void> write(TokenBundle tokens) =>
      _storage.write(key: _key, value: jsonEncode(tokens.toJson()));
}

final class MemoryTokenStore implements TokenStore {
  TokenBundle? value;

  @override
  Future<void> clear() async => value = null;

  @override
  Future<TokenBundle?> read() async => value;

  @override
  Future<void> write(TokenBundle tokens) async => value = tokens;
}
