import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/data/device_authenticator.dart';
import 'package:vtsa_mobile/features/auth/data/quick_unlock_store.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';
import 'package:vtsa_mobile/features/auth/presentation/evcs_pin_entry.dart';
import 'package:vtsa_mobile/features/auth/presentation/welcome_screen.dart';

import 'driver_journey_design_test.dart' show preview;
import 'support/fakes.dart';
import 'support/quick_unlock_fakes.dart';
import 'support/test_fonts.dart';

void main() {
  setUpAll(loadTestFonts);

  for (final (width, height, scale) in [
    (393.0, 852.0, 1.0),
    (320.0, 640.0, 1.6),
    (1440.0, 900.0, 1.0),
  ]) {
    testWidgets('launch welcome remains usable at $width × $height / $scale', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, height);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.textScaleFactorTestValue = scale;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      await tester.pumpWidget(
        RepaintBoundary(
          key: const ValueKey('driver-preview'),
          child: MaterialApp(
            debugShowCheckedModeBanner: false,
            theme: VtsaTheme.light(),
            home: const WelcomeScreen(),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Help center'));
      expect(find.text('Power your next drive.'), findsOneWidget);
      expect(find.text('Create account'), findsOneWidget);
      expect(find.textContaining('Demo'), findsNothing);
      expect(find.textContaining('DESIGN PREVIEW'), findsNothing);
      await preview(tester, 'evcs-welcome-$width-$height-$scale');
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('sixth PIN digit submits once and waits for server identity', (
    tester,
  ) async {
    final device = FakeDeviceAuthenticator();
    final store = QuickUnlockStore(
      tokens: MemoryTokenStore(),
      settings: MemoryUnlockSettings(),
      device: device,
    );
    final fixture = await buildTestDependencies(tokenStore: store);
    final auth = fixture.dependencies.auth;

    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));

    await tester.pumpAndSettle();
    await auth.login(email: 'ada@example.test', password: 'fixture-password');
    await tester.pumpAndSettle();
    await tester.runAsync(
      () => auth.configureQuickUnlock('739152', biometrics: false),
    );
    auth.lock();
    await tester.pumpAndSettle();
    await tester.tap(find.text('App PIN'));
    await tester.pumpAndSettle();
    fixture.auth.currentUserStarted = Completer<void>();
    fixture.auth.currentUserCompleter = Completer<UserProfile>();
    final before = fixture.auth.currentUserCalls;
    for (final digit in ['7', '3', '9', '1', '5']) {
      await tester.tap(find.text(digit));
      await tester.pump();
    }
    expect(fixture.auth.currentUserCalls, before);
    final entry = tester.widget<EvcsPinEntry>(find.byType(EvcsPinEntry));
    expect(entry.length, 5);
    unawaited(entry.onDigit('2'));
    // Pump fake UI time while the native derivation isolate returns.
    while (!fixture.auth.currentUserStarted!.isCompleted && auth.isBusy) {
      await tester.runAsync(() => Future<void>.delayed(Duration.zero));
      await tester.pump();
    }
    expect(
      fixture.auth.currentUserCalls,
      before + 1,
      reason: auth.failure?.message,
    );
    expect(auth.status, AuthStatus.locked);
    expect(auth.isBusy, isTrue);
    expect(find.text('Explore'), findsNothing);
    fixture.auth.currentUserCompleter!.complete(fixture.auth.user);
    await tester.pumpAndSettle();
    expect(auth.status, AuthStatus.authenticated);
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
    expect(router.routeInformationProvider.value.uri.path, '/explore');
    expect(fixture.auth.currentUserCalls, before + 1);
  });

  testWidgets(
    'cancelled Face ID falls back to PIN without consuming an attempt',
    (tester) async {
      final device = FakeDeviceAuthenticator()..kind = DeviceBiometricKind.face;
      final settings = MemoryUnlockSettings();
      final store = QuickUnlockStore(
        tokens: MemoryTokenStore(),
        settings: settings,
        device: device,
      );
      final fixture = await buildTestDependencies(tokenStore: store);
      final auth = fixture.dependencies.auth;

      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));

      await tester.pumpAndSettle();
      await auth.login(email: 'ada@example.test', password: 'fixture-password');
      await tester.runAsync(
        () => auth.configureQuickUnlock('739152', biometrics: true),
      );
      device.accepted = false;
      auth.lock();
      await tester.pumpAndSettle();
      expect(find.text('Face ID'), findsOneWidget);
      await tester.tap(find.text('Face ID'));
      await tester.pumpAndSettle();
      expect(find.text('Unlock your drive.'), findsOneWidget);
      expect(auth.status, AuthStatus.locked);
      expect(
        (jsonDecode(settings.value!) as Map<String, dynamic>)['attempts'],
        0,
      );
      expect(find.text('Explore'), findsNothing);
    },
  );

  testWidgets('switch-account confirmation preserves or clears quick access', (
    tester,
  ) async {
    final settings = MemoryUnlockSettings();
    final store = QuickUnlockStore(
      tokens: MemoryTokenStore(),
      settings: settings,
      device: FakeDeviceAuthenticator(),
    );
    final fixture = await buildTestDependencies(tokenStore: store);
    final auth = fixture.dependencies.auth;

    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));

    await tester.pumpAndSettle();
    await auth.login(email: 'ada@example.test', password: 'fixture-password');
    await tester.runAsync(
      () => auth.configureQuickUnlock('739152', biometrics: false),
    );
    auth.lock();
    await tester.pumpAndSettle();
    await tester.tap(find.text('Switch account'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    expect(store.enabled, isTrue);
    expect(auth.status, AuthStatus.locked);
    await tester.tap(find.text('Switch account'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Switch account'));
    await tester.pumpAndSettle();
    expect(store.enabled, isFalse);
    expect(settings.value, isNull);
    expect(auth.status, AuthStatus.guest);
    expect(find.text('Welcome back.'), findsOneWidget);
    expect(find.byType(TextFormField), findsNWidgets(2));
  });
}
