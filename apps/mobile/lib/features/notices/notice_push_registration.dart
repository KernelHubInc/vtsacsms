import 'dart:async';

import 'package:dio/dio.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
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

enum NoticePushStatus {
  idle,
  enabling,
  enabled,
  disabling,
  disableFailed,
  blocked,
  failed,
  unsupported,
}

final class FirebaseNoticePushRegistration extends ChangeNotifier
    implements NoticePushRegistration {
  FirebaseNoticePushRegistration({
    required this.client,
    required this.messaging,
    required this.onNotice,
    this.onOpened,
    this.isBrowser = false,
    this.webOptedIn = false,
    this.saveWebPreference,
    this.vapidKey,
    this.serviceWorkerScriptPath,
  });

  final ApiClient client;
  final FirebaseMessaging messaging;
  final Future<void> Function() onNotice;
  final Future<void> Function()? onOpened;
  final bool isBrowser;
  bool webOptedIn;
  final Future<void> Function(bool)? saveWebPreference;
  final String? vapidKey;
  final String? serviceWorkerScriptPath;
  NoticePushStatus status = NoticePushStatus.idle;
  bool _busy = false;
  bool _disposed = false;
  Completer<void>? _registered;

  void _setStatus(NoticePushStatus value) {
    if (_disposed) return;
    status = value;
    notifyListeners();
  }

  Future<void> enable() => _register(prompt: true);

  Future<void> disable() async {
    if (_busy) return;
    _busy = true;
    _active = false;
    _setStatus(NoticePushStatus.disabling);
    webOptedIn = false;
    await saveWebPreference?.call(false);
    final removed = await _removeServerRegistration();
    var unsubscribed = false;
    try {
      await messaging.unregister();
      unsubscribed = true;
    } on Object {
      // Either successful removal stops this app's delivery; retry if both fail.
    }
    _setStatus(
      removed || unsubscribed
          ? NoticePushStatus.idle
          : NoticePushStatus.disableFailed,
    );
    _busy = false;
  }

  StreamSubscription<String>? _registrations;
  StreamSubscription<RemoteMessage>? _openedMessages;
  StreamSubscription<RemoteMessage>? _foregroundMessages;
  bool _active = false;

  @override
  Future<void> register() async {
    if (isBrowser && !webOptedIn) return;
    await _register(prompt: !isBrowser);
  }

  Future<void> _register({required bool prompt}) async {
    if (_busy || _disposed || status == NoticePushStatus.unsupported) return;
    _busy = true;
    _setStatus(NoticePushStatus.enabling);
    _registrations ??= messaging.onRegistered.listen((installationId) {
      if (_active) unawaited(_sendInstallationId(installationId));
    }, onError: (Object _) {});
    _openedMessages ??= FirebaseMessaging.onMessageOpenedApp.listen((_) {
      if (_active) unawaited((onOpened ?? onNotice)());
    });
    _foregroundMessages ??= FirebaseMessaging.onMessage.listen((_) {
      if (_active) unawaited(onNotice());
    });

    try {
      // The browser permission call must happen directly from the button gesture.
      final permission = prompt
          ? await messaging.requestPermission()
          : await messaging.getNotificationSettings();
      if (permission.authorizationStatus != AuthorizationStatus.authorized &&
          permission.authorizationStatus != AuthorizationStatus.provisional) {
        _active = false;
        await _removeServerRegistration();
        try {
          await messaging.unregister();
        } on Object {
          // Keep the permission-denied guidance even when offline cleanup fails.
        }
        _setStatus(
          permission.authorizationStatus == AuthorizationStatus.denied
              ? NoticePushStatus.blocked
              : NoticePushStatus.idle,
        );
        return;
      }
      if (_disposed) return;
      _active = true;
      _registered = Completer<void>();
      // Attach the error handler before an asynchronous registration can fail.
      final result = _registered!.future.timeout(const Duration(seconds: 25));
      await Future.wait([
        messaging.register(
          vapidKey: vapidKey,
          serviceWorkerScriptPath: serviceWorkerScriptPath,
        ),
        result,
      ]);
      if (!_active || _disposed) return;
      if (isBrowser) {
        webOptedIn = true;
        await saveWebPreference?.call(true);
      }
      _setStatus(NoticePushStatus.enabled);
      final openingMessage = await messaging.getInitialMessage();
      if (openingMessage != null && _active) await (onOpened ?? onNotice)();
    } on Object {
      _setStatus(NoticePushStatus.failed);
    } finally {
      _busy = false;
    }
  }

  Future<void> _sendInstallationId(String installationId) async {
    try {
      await client.dio.put<void>(
        '/api/v1/mobile/notification-device',
        data: {
          'platform': isBrowser ? 'web' : 'android',
          'installation_id': installationId,
        },
      );
      if (_registered?.isCompleted == false) _registered!.complete();
    } on DioException {
      if (_registered?.isCompleted == false) {
        _registered!.completeError(StateError('Registration failed'));
      }
      _setStatus(NoticePushStatus.failed);
    }
  }

  Future<bool> _removeServerRegistration() async {
    try {
      await client.dio.delete<void>('/api/v1/mobile/notification-device');
      return true;
    } on DioException {
      return false;
    }
  }

  @override
  Future<void> unregister() async {
    if (!_active) return;
    _active = false;
    if (_registered?.isCompleted == false) _registered!.complete();
    await _removeServerRegistration();
    try {
      await messaging.unregister();
    } on Object {
      // The server registration is also removed when its access token is revoked.
    }
  }

  @override
  void dispose() {
    _disposed = true;
    _active = false;
    unawaited(_registrations?.cancel());
    unawaited(_openedMessages?.cancel());
    unawaited(_foregroundMessages?.cancel());
    super.dispose();
  }
}
