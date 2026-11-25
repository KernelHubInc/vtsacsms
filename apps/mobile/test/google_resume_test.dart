import 'dart:async';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/network/connectivity_service.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/domain/google_auth.dart';
import 'package:vtsa_mobile/features/favorites/data/local_favorites_repository.dart';
import 'package:vtsa_mobile/features/notices/driver_notices.dart';
import 'package:vtsa_mobile/features/vehicles/data/local_vehicle_repository.dart';

import 'support/fakes.dart';

final class _GoogleChooser extends GoogleIdentityProvider {
  final chosen = Completer<String?>();
  @override
  Future<String?> authenticate() => chosen.future;
}

final class _GoogleRepository extends FakeAuthRepository
    implements GoogleAuthRepository {
  @override
  Future<GoogleLoginResult> signInWithGoogle(
    String proof, {
    String? password,
  }) async => GoogleLoginResult.registration(
    const GoogleProfile(
      email: 'driver@example.test',
      firstName: 'Test',
      lastName: 'Driver',
    ),
  );

  @override
  Future<MobileAuthSession> registerWithGoogle(
    String proof,
    Map<String, Object?> profile,
  ) => throw UnimplementedError();
}

final class _RejectedNoticeAdapter implements HttpClientAdapter {
  final requests = <RequestOptions>[];
  Completer<void>? delay;
  @override
  void close({bool force = false}) {}
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? body,
    Future<void>? cancel,
  ) async {
    requests.add(options);
    if (delay != null) await delay!.future;
    return ResponseBody.fromString(
      '{"error":{"code":"unauthenticated","message":"Authentication is required."}}',
      401,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }
}

void main() {
  testWidgets(
    'returning from Google as a guest does not refresh private notices or expire sign-in',
    (tester) async {
      final fixture = await buildTestDependencies();
      addTearDown(fixture.dependencies.dispose);
      final preferences = await SharedPreferences.getInstance();
      final tokens = MemoryTokenStore();
      final adapter = _RejectedNoticeAdapter();
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: tokens,
        dio: Dio()..httpClientAdapter = adapter,
      );
      final chooser = _GoogleChooser();
      final dependencies = AppDependencies.fromParts(
        environment: fixture.dependencies.environment,
        preferences: preferences,
        tokenStore: tokens,
        connectivity: const AlwaysOnlineConnectivity(),
        authRepository: _GoogleRepository(),
        googleIdentityProvider: chooser,
        stationRepository: fixture.stations,
        vehicleRepository: LocalVehicleRepository(preferences),
        favoritesRepository: LocalFavoritesRepository(preferences),
        chargingRepository: fixture.charging,
        noticeRepository: ApiDriverNoticeRepository(client),
      );
      client.onSessionExpired = dependencies.auth.sessionExpired;
      await dependencies.initialize();
      await tester.pumpWidget(VtsaApp(dependencies: dependencies));
      await tester.pumpAndSettle();
      expect(adapter.requests, isEmpty);
      await tester.ensureVisible(find.text('Continue with Google'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Continue with Google'));
      await tester.pump();
      expect(dependencies.auth.isBusy, isTrue);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump(const Duration(milliseconds: 100));
      chooser.chosen.complete('fixture-google-proof');
      await tester.pumpAndSettle();
      expect(adapter.requests, isEmpty);
      expect(dependencies.auth.failure, isNull);
      expect(
        dependencies.auth.googleRegistration?.email,
        'driver@example.test',
      );
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  test(
    'a late guest-request 401 preserves a newly issued Google session',
    () async {
      final tokens = MemoryTokenStore();
      final adapter = _RejectedNoticeAdapter()..delay = Completer<void>();
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: tokens,
        dio: Dio()..httpClientAdapter = adapter,
      );
      var expired = false;
      client.onSessionExpired = () => expired = true;
      final request = client.dio.get<void>('/api/v1/mobile/notices');
      final checked = expectLater(request, throwsA(isA<DioException>()));
      while (adapter.requests.isEmpty) {
        await Future<void>.delayed(Duration.zero);
      }
      expect(
        adapter.requests.single.headers.containsKey('Authorization'),
        isFalse,
      );
      await tokens.write(
        TokenBundle(
          accessToken: 'new-google-session',
          expiresAt: DateTime.utc(2030),
          tenantId: 'fixture-tenant',
          deviceId: 'fixture-device',
        ),
      );
      adapter.delay!.complete();
      await checked;
      expect((await tokens.read())?.accessToken, 'new-google-session');
      expect(expired, isFalse);
    },
  );
}
