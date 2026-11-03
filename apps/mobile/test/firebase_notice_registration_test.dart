import 'dart:async';

import 'package:dio/dio.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/notices/notice_push_registration.dart';
import 'package:vtsa_mobile/features/notices/web_push_card.dart';

class _Settings extends Fake implements NotificationSettings {
  _Settings(this.authorizationStatus);
  @override
  final AuthorizationStatus authorizationStatus;
}

class _Messaging extends Fake implements FirebaseMessaging {
  final registrations = StreamController<String>.broadcast();
  AuthorizationStatus status = AuthorizationStatus.authorized;
  int registered = 0;
  int unregistered = 0;
  int prompts = 0;
  String? usedVapid;
  String? usedWorker;
  bool failUnregister = false;
  RemoteMessage? opening;

  @override
  Stream<String> get onRegistered => registrations.stream;

  @override
  Future<NotificationSettings> requestPermission({
    bool alert = true,
    bool announcement = false,
    bool badge = true,
    bool carPlay = false,
    bool criticalAlert = false,
    bool provisional = false,
    bool sound = true,
    bool providesAppNotificationSettings = false,
  }) async {
    prompts++;
    return _Settings(status);
  }

  @override
  Future<NotificationSettings> getNotificationSettings() async =>
      _Settings(status);

  @override
  Future<void> register({
    String? vapidKey,
    String? serviceWorkerScriptPath,
  }) async {
    registered++;
    usedVapid = vapidKey;
    usedWorker = serviceWorkerScriptPath;
    registrations.add('test-firebase-installation');
  }

  @override
  Future<void> unregister() async {
    if (failUnregister) throw StateError('Offline');
    unregistered++;
  }

  @override
  Future<RemoteMessage?> getInitialMessage() async => opening;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test(
    'failed browser opt-out stays retryable and is not reported as off',
    () async {
      final dio = Dio()
        ..interceptors.add(
          InterceptorsWrapper(
            onRequest: (request, handler) {
              handler.reject(
                DioException(
                  requestOptions: request,
                  type: DioExceptionType.connectionError,
                ),
              );
            },
          ),
        );
      final messaging = _Messaging()..failUnregister = true;
      final push = FirebaseNoticePushRegistration(
        client: ApiClient(
          baseUrl: Uri.parse('https://example.test'),
          tokenStore: MemoryTokenStore(),
          dio: dio,
        ),
        messaging: messaging,
        onNotice: () async {},
        isBrowser: true,
        webOptedIn: true,
      );
      await push.disable();
      expect(push.status, NoticePushStatus.disableFailed);
      expect(push.webOptedIn, isFalse);
      messaging.failUnregister = false;
      await push.disable();
      expect(push.status, NoticePushStatus.idle);
      push.dispose();
      await messaging.registrations.close();
    },
  );

  testWidgets('browser card enables from a tap and fits a narrow phone', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final dio = Dio()
      ..interceptors.add(
        InterceptorsWrapper(
          onRequest: (request, handler) {
            handler.resolve(
              Response<void>(requestOptions: request, statusCode: 204),
            );
          },
        ),
      );
    final messaging = _Messaging();
    final push = FirebaseNoticePushRegistration(
      client: ApiClient(
        baseUrl: Uri.parse('https://example.test'),
        tokenStore: MemoryTokenStore(),
        dio: dio,
      ),
      messaging: messaging,
      onNotice: () async {},
      isBrowser: true,
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: WebPushCard(push: push)),
      ),
    );
    expect(find.text('Enable notifications'), findsOneWidget);
    expect(messaging.prompts, 0);
    await tester.tap(find.text('Enable notifications'));
    await tester.pumpAndSettle();
    expect(
      find.text('Notifications are enabled for this browser.'),
      findsOneWidget,
    );
    expect(find.text('Turn off notifications'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.tap(find.text('Turn off notifications'));
    await tester.pumpAndSettle();
    expect(find.text('Enable notifications'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    push.dispose();
    await messaging.registrations.close();
  });

  test(
    'browser opt-in is explicit, persisted, and uses the scoped worker',
    () async {
      final requests = <RequestOptions>[];
      final dio = Dio()
        ..interceptors.add(
          InterceptorsWrapper(
            onRequest: (request, handler) {
              requests.add(request);
              handler.resolve(
                Response<void>(requestOptions: request, statusCode: 204),
              );
            },
          ),
        );
      final messaging = _Messaging();
      var saved = false;
      final push = FirebaseNoticePushRegistration(
        client: ApiClient(
          baseUrl: Uri.parse('https://example.test'),
          tokenStore: MemoryTokenStore(),
          dio: dio,
        ),
        messaging: messaging,
        onNotice: () async {},
        isBrowser: true,
        vapidKey: 'public-vapid-key',
        serviceWorkerScriptPath: '/mobile/push/firebase-messaging-sw.js',
        saveWebPreference: (enabled) async {
          saved = enabled;
        },
      );
      await push.register();
      expect(messaging.prompts, 0);
      expect(requests, isEmpty);
      await push.enable();
      expect(messaging.prompts, 1);
      expect(push.status, NoticePushStatus.enabled);
      expect(saved, isTrue);
      expect((requests.single.data as Map<String, dynamic>)['platform'], 'web');
      expect(messaging.usedVapid, 'public-vapid-key');
      expect(messaging.usedWorker, '/mobile/push/firebase-messaging-sw.js');
      await push.register();
      expect(messaging.prompts, 1);
      await push.disable();
      expect(saved, isFalse);
      expect(requests.last.method, 'DELETE');
      final count = requests.length;
      await push.register();
      expect(requests.length, count);
      push.dispose();
      await messaging.registrations.close();
    },
  );

  test(
    'browser registration failure does not report notifications enabled',
    () async {
      final dio = Dio()
        ..interceptors.add(
          InterceptorsWrapper(
            onRequest: (request, handler) {
              handler.reject(
                DioException(
                  requestOptions: request,
                  type: DioExceptionType.connectionError,
                ),
              );
            },
          ),
        );
      final messaging = _Messaging();
      final push = FirebaseNoticePushRegistration(
        client: ApiClient(
          baseUrl: Uri.parse('https://example.test'),
          tokenStore: MemoryTokenStore(),
          dio: dio,
        ),
        messaging: messaging,
        onNotice: () async {},
        isBrowser: true,
      );
      await push.enable();
      expect(push.status, NoticePushStatus.failed);
      expect(push.webOptedIn, isFalse);
      push.dispose();
      await messaging.registrations.close();
    },
  );

  test(
    'Firebase installation registers and logout removes server registration',
    () async {
      final requests = <RequestOptions>[];
      final registrationSent = Completer<void>();
      final dio = Dio()
        ..interceptors.add(
          InterceptorsWrapper(
            onRequest: (request, handler) {
              requests.add(request);
              if (request.method == 'PUT' && !registrationSent.isCompleted) {
                registrationSent.complete();
              }
              handler.resolve(
                Response<void>(requestOptions: request, statusCode: 204),
              );
            },
          ),
        );
      final messaging = _Messaging()
        ..opening = const RemoteMessage(messageId: 'test');
      var opened = 0;
      final push = FirebaseNoticePushRegistration(
        client: ApiClient(
          baseUrl: Uri.parse('https://example.test'),
          tokenStore: MemoryTokenStore(),
          dio: dio,
        ),
        messaging: messaging,
        onNotice: () async {},
        onOpened: () async {
          opened++;
        },
      );
      await push.register();
      await registrationSent.future.timeout(const Duration(seconds: 5));
      expect(requests.single.method, 'PUT');
      expect(requests.single.data, {
        'platform': 'android',
        'installation_id': 'test-firebase-installation',
      });
      expect(opened, 1);
      await push.unregister();
      expect(requests.last.method, 'DELETE');
      expect(messaging.unregistered, 1);
      messaging.registrations.add('late-event-after-logout');
      await Future<void>.delayed(Duration.zero);
      expect(requests.length, 2);
      push.dispose();
      await messaging.registrations.close();
    },
  );

  test(
    'denied permission removes registration without requesting a new one',
    () async {
      final methods = <String>[];
      final dio = Dio()
        ..interceptors.add(
          InterceptorsWrapper(
            onRequest: (request, handler) {
              methods.add(request.method);
              handler.resolve(
                Response<void>(requestOptions: request, statusCode: 204),
              );
            },
          ),
        );
      final messaging = _Messaging()..status = AuthorizationStatus.denied;
      final push = FirebaseNoticePushRegistration(
        client: ApiClient(
          baseUrl: Uri.parse('https://example.test'),
          tokenStore: MemoryTokenStore(),
          dio: dio,
        ),
        messaging: messaging,
        onNotice: () async {},
      );
      await push.register();
      expect(messaging.registered, 0);
      expect(messaging.unregistered, 1);
      expect(methods, ['DELETE']);
      messaging.registrations.add('late-event-after-denial');
      await Future<void>.delayed(Duration.zero);
      expect(methods, ['DELETE']);
      push.dispose();
      await messaging.registrations.close();
    },
  );
}
