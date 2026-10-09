import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/data/quick_unlock_store.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';
import 'support/fakes.dart';
import 'support/quick_unlock_fakes.dart';

void main() {
  final now = DateTime.utc(2026, 10, 6);
  final token = TokenBundle(
    accessToken: 'test-token',
    expiresAt: DateTime.utc(2027),
    tenantId: 'tenant-a',
    deviceId: 'device-a',
  );
  late MemoryTokenStore tokens;
  late MemoryUnlockSettings settings;
  late FakeDeviceAuthenticator device;
  late QuickUnlockStore store;
  late String enrolledSettings;

  QuickUnlockStore makeStore() => QuickUnlockStore(
    tokens: tokens,
    settings: settings,
    device: device,
    now: () => now,
  );

  setUpAll(() async {
    tokens = MemoryTokenStore()..value = token;
    settings = MemoryUnlockSettings();
    device = FakeDeviceAuthenticator();
    store = makeStore();
    await store.initialize();
    await store.configure('739152', biometrics: true);
    enrolledSettings = settings.value!;
  });
  setUp(() async {
    tokens = MemoryTokenStore()..value = token;
    settings = MemoryUnlockSettings()..value = enrolledSettings;
    device = FakeDeviceAuthenticator();
    store = makeStore();
    await store.initialize();
  });

  test(
    'cold start gates API tokens; correct PIN restores the tenant-bound token',
    () async {
      expect(await store.read(), isNull);
      expect(settings.value, isNot(contains('739152')));
      expect(await store.unlock(pin: '739152'), isTrue);
      expect((await store.read())?.tenantId, 'tenant-a');
      store.lock();
      expect(await store.read(), isNull);
      await expectLater(store.write(token), throwsA(isA<AppFailure>()));
    },
  );

  test(
    'five wrong PIN attempts survive restart and clear the saved session',
    () async {
      for (var attempt = 1; attempt <= 4; attempt++) {
        expect(await store.unlock(pin: '000000'), isFalse);
        expect(await store.read(), isNull);
        store = makeStore();
        await store.initialize();
        expect(
          (jsonDecode(settings.value!) as Map<String, dynamic>)['attempts'],
          attempt,
        );
      }
      await expectLater(
        store.unlock(pin: '000000'),
        throwsA(isA<AppFailure>()),
      );
      expect(tokens.value, isNull);
      expect(settings.value, isNull);
    },
  );

  test(
    'cancelled biometrics leaves session locked and PIN retry available',
    () async {
      device.accepted = false;
      expect(await store.unlock(), isFalse);
      expect(await store.read(), isNull);
      expect(
        (jsonDecode(settings.value!) as Map<String, dynamic>)['attempts'],
        0,
      );
      expect(await store.unlock(pin: '739152'), isTrue);
    },
  );

  test('backgrounding invalidates an in-flight biometric result', () async {
    device.pending = Completer<bool>();
    final result = store.unlock();
    store.lock();
    device.pending!.complete(true);
    expect(await result, isFalse);
    expect(await store.read(), isNull);
  });

  for (final replacement in [
    TokenBundle(
      accessToken: 'same-id-other-tenant',
      expiresAt: DateTime.utc(2027),
      tenantId: 'tenant-b',
      deviceId: 'device-a',
    ),
    TokenBundle(
      accessToken: 'other-device',
      expiresAt: DateTime.utc(2027),
      tenantId: 'tenant-a',
      deviceId: 'device-b',
    ),
    TokenBundle(
      accessToken: 'expired',
      expiresAt: now,
      tenantId: 'tenant-a',
      deviceId: 'device-a',
    ),
  ]) {
    test('rejects ${replacement.accessToken} after local proof', () async {
      tokens.value = replacement;
      await expectLater(store.unlock(), throwsA(isA<AppFailure>()));
      expect(tokens.value, isNull);
      expect(store.enabled, isFalse);
    });
  }

  test('corrupt settings never bypass the lock', () async {
    settings.value = '{"attempts":"broken"}';
    store = makeStore();
    await store.initialize();
    expect(await store.read(), isNull);
    expect(tokens.value, isNull);
  });

  test('cannot test a PIN when its attempt cannot be persisted', () async {
    settings.failWrites = true;
    await expectLater(store.unlock(pin: '739152'), throwsStateError);
    expect(await store.read(), isNull);
  });

  test('unsupported device cannot unlock a saved session', () async {
    device.supported = false;
    expect(await store.unlock(pin: '739152'), isFalse);
    expect(await store.read(), isNull);
  });

  test(
    'storage read failure offers password recovery without opening the account',
    () async {
      settings.failReads = true;
      store = makeStore();
      final auth = AuthController(
        repository: FakeAuthRepository(),
        tokens: store,
      );
      await auth.restore();
      expect(auth.status, AuthStatus.locked);
      expect(auth.failure, isNotNull);
      expect(await store.read(), isNull);
      await auth.usePassword();
      expect(auth.status, AuthStatus.guest);
      expect(tokens.value, isNull);
      auth.dispose();
    },
  );

  test('PIN-only setup succeeds without enrolled biometrics', () async {
    await store.clear();
    tokens.value = token;
    device.enrolled = false;
    await expectLater(
      store.configure('739152', biometrics: true),
      throwsA(isA<AppFailure>()),
    );
    expect(settings.value, isNull);
    await store.configure('739152', biometrics: false);
    store.lock();
    expect(await store.unlock(), isFalse);
    expect(await store.unlock(pin: '739152'), isTrue);
  });

  test('locked restore never calls the identity endpoint', () async {
    final auth = AuthController(
      repository: FakeAuthRepository(),
      tokens: store,
    );
    await auth.restore();
    expect(auth.status, AuthStatus.locked);
    expect(auth.user, isNull);
    auth.dispose();
  });

  test(
    'backgrounding during server validation cannot reopen the account',
    () async {
      final repo = FakeAuthRepository(signedIn: true)
        ..currentUserCompleter = Completer<UserProfile>();
      final auth = AuthController(repository: repo, tokens: store);
      await auth.restore();
      final result = auth.unlock();
      await Future<void>.delayed(Duration.zero);
      auth.lock();
      repo.currentUserCompleter!.complete(repo.user);
      expect(await result, isFalse);
      expect(auth.status, AuthStatus.locked);
      expect(auth.user, isNull);
      expect(await store.read(), isNull);
      auth.dispose();
    },
  );

  test(
    'restored password sessions cannot silently enroll quick unlock',
    () async {
      await store.clear();
      tokens.value = token;
      final auth = AuthController(
        repository: FakeAuthRepository(signedIn: true),
        tokens: store,
      );
      await auth.restore();
      expect(
        await auth.configureQuickUnlock('739152', biometrics: false),
        isFalse,
      );
      expect(settings.value, isNull);
      auth.dispose();
    },
  );

  test(
    'valid biometric proof still requires the server identity check',
    () async {
      final auth = AuthController(
        repository: FakeAuthRepository(signedIn: true),
        tokens: store,
      );
      await auth.restore();
      expect(await auth.unlock(), isTrue);
      expect(auth.status, AuthStatus.authenticated);
      expect(auth.canConfigureQuickUnlock, isFalse);
      auth.lock();
      expect(auth.user, isNull);
      expect(auth.status, AuthStatus.locked);
      expect(await store.read(), isNull);
      auth.dispose();
    },
  );

  for (final kind in [
    FailureKind.offline,
    FailureKind.timeout,
    FailureKind.server,
    FailureKind.unauthenticated,
    FailureKind.forbidden,
  ]) {
    test(
      'server ${kind.name} never opens the account after local proof',
      () async {
        final repo = FakeAuthRepository(
          signedIn: true,
        )..currentUserFailure = AppFailure(kind: kind, message: 'Test failure');
        final auth = AuthController(repository: repo, tokens: store);
        await auth.restore();
        expect(await auth.unlock(), isFalse);
        expect(
          auth.status,
          kind == FailureKind.unauthenticated || kind == FailureKind.forbidden
              ? AuthStatus.guest
              : AuthStatus.locked,
        );
        expect(await store.read(), isNull);
        expect(auth.user, isNull);
        auth.dispose();
      },
    );
  }

  test(
    'password recovery removes PIN, biometrics and saved credentials',
    () async {
      final auth = AuthController(
        repository: FakeAuthRepository(signedIn: true),
        tokens: store,
      );
      await auth.restore();
      await auth.usePassword();
      expect(auth.status, AuthStatus.guest);
      expect(store.enabled, isFalse);
      expect(tokens.value, isNull);
      expect(settings.value, isNull);
      expect(
        await auth.login(email: 'ada@example.test', password: 'test-password'),
        isTrue,
      );
      expect(auth.canConfigureQuickUnlock, isTrue);
      auth.dispose();
    },
  );

  test(
    'unverified server identity still routes through email verification',
    () async {
      final repo = FakeAuthRepository(signedIn: true)
        ..currentUserFailure = const AppFailure(
          kind: FailureKind.forbidden,
          code: 'email_unverified',
          message: 'Verify your email',
        );
      final auth = AuthController(repository: repo, tokens: store);
      await auth.restore();
      expect(await auth.unlock(), isTrue);
      expect(auth.needsEmailVerification, isTrue);
      auth.dispose();
    },
  );
}
