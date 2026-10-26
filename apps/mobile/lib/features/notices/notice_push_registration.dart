import 'dart:async';

import 'package:dio/dio.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';

abstract interface class NoticePushRegistration {
  Future<void> register();
  Future<void> unregister();
  void dispose();
}

final class NoopNoticePushRegistration implements NoticePushRegistration {
  const NoopNoticePushRegistration();

  @override
  Future<void> register() async {}

  @override
  Future<void> unregister() async {}

  @override
  void dispose() {}
}

final class FirebaseNoticePushRegistration implements NoticePushRegistration {
  FirebaseNoticePushRegistration({
    required this.client,
    required this.messaging,
    required this.onNotice,
  });

  final ApiClient client;
  final FirebaseMessaging messaging;
  final Future<void> Function() onNotice;
  StreamSubscription<String>? _registrations;
  StreamSubscription<RemoteMessage>? _openedMessages;
  StreamSubscription<RemoteMessage>? _foregroundMessages;
  bool _active = false;

  @override
  Future<void> register() async {
    _active = true;
    _registrations ??= messaging.onRegistered.listen((installationId) {
      if (_active) unawaited(_sendInstallationId(installationId));
    }, onError: (Object _) {});
    _openedMessages ??= FirebaseMessaging.onMessageOpenedApp.listen((_) {
      if (_active) unawaited(onNotice());
    });
    _foregroundMessages ??= FirebaseMessaging.onMessage.listen((_) {
      if (_active) unawaited(onNotice());
    });

    try {
      final permission = await messaging.requestPermission();
      if (permission.authorizationStatus != AuthorizationStatus.authorized &&
          permission.authorizationStatus != AuthorizationStatus.provisional) {
        await _removeServerRegistration();
        await messaging.unregister();
        return;
      }
      await messaging.register();
      final openingMessage = await messaging.getInitialMessage();
      if (openingMessage != null && _active) await onNotice();
    } on Object {
      // Notices still load from the API when Firebase or connectivity is unavailable.
    }
  }

  Future<void> _sendInstallationId(String installationId) async {
    try {
      await client.dio.put<void>(
        '/api/v1/mobile/notification-device',
        data: {'platform': 'android', 'installation_id': installationId},
      );
    } on DioException {
      // A later sign-in or token refresh retries registration.
    }
  }

  Future<void> _removeServerRegistration() async {
    try {
      await client.dio.delete<void>('/api/v1/mobile/notification-device');
    } on DioException {
      // The local installation is already unregistered from FCM.
    }
  }

  @override
  Future<void> unregister() async {
    if (!_active) return;
    _active = false;
    try {
      await messaging.unregister();
    } on Object {
      // The server registration is also removed when its access token is revoked.
    }
  }

  @override
  void dispose() {
    _active = false;
    unawaited(_registrations?.cancel());
    unawaited(_openedMessages?.cancel());
    unawaited(_foregroundMessages?.cancel());
  }
}
