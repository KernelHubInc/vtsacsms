import 'dart:async';

import 'package:dio/dio.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/notices/notice_push_registration.dart';

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
  }) async => _Settings(status);

  @override
  Future<void> register({
    String? vapidKey,
    String? serviceWorkerScriptPath,
  }) async {
    registered++;
    registrations.add('test-firebase-installation');
  }

  @override
  Future<void> unregister() async {
    unregistered++;
  }

  @override
  Future<RemoteMessage?> getInitialMessage() async => opening;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

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
