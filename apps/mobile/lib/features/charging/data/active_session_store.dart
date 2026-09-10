import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

final class ActiveSessionReference {
  const ActiveSessionReference({
    required this.ownerId,
    required this.sessionId,
    required this.savedAt,
  });

  factory ActiveSessionReference.fromJson(Map<String, dynamic> json) =>
      ActiveSessionReference(
        ownerId: json['owner_id']! as String,
        sessionId: json['session_id']! as String,
        savedAt: DateTime.parse(json['saved_at']! as String).toUtc(),
      );

  final String ownerId;
  final String sessionId;
  final DateTime savedAt;

  Map<String, Object> toJson() => {
    'owner_id': ownerId,
    'session_id': sessionId,
    'saved_at': savedAt.toUtc().toIso8601String(),
  };
}

abstract interface class ActiveSessionStore {
  Future<ActiveSessionReference?> read(String ownerId);
  Future<void> write(ActiveSessionReference reference);
  Future<void> clear(String ownerId);
}

final class LocalActiveSessionStore implements ActiveSessionStore {
  LocalActiveSessionStore(this._preferences);

  static const _key = 'active_charging_session.v1';
  final SharedPreferences _preferences;

  @override
  Future<void> clear(String ownerId) async {
    final reference = await read(ownerId);
    if (reference != null) {
      await _preferences.remove(_key);
    }
  }

  @override
  Future<ActiveSessionReference?> read(String ownerId) async {
    final encoded = _preferences.getString(_key);
    if (encoded == null) {
      return null;
    }
    try {
      final reference = ActiveSessionReference.fromJson(
        jsonDecode(encoded) as Map<String, dynamic>,
      );
      return reference.ownerId == ownerId ? reference : null;
    } on Object {
      await _preferences.remove(_key);
      return null;
    }
  }

  @override
  Future<void> write(ActiveSessionReference reference) =>
      _preferences.setString(_key, jsonEncode(reference.toJson()));
}

final class MemoryActiveSessionStore implements ActiveSessionStore {
  ActiveSessionReference? reference;

  @override
  Future<void> clear(String ownerId) async {
    if (reference?.ownerId == ownerId) {
      reference = null;
    }
  }

  @override
  Future<ActiveSessionReference?> read(String ownerId) async =>
      reference?.ownerId == ownerId ? reference : null;

  @override
  Future<void> write(ActiveSessionReference value) async => reference = value;
}
