import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/domain/google_auth.dart';
import 'package:vtsa_mobile/features/auth/presentation/welcome_screen.dart';

import 'driver_journey_design_test.dart' show preview;
import 'google_sign_in_test.dart' show TestGoogleProvider, TestGoogleRepository;
import 'support/fakes.dart';
import 'support/test_fonts.dart';

void main() {
  setUpAll(loadTestFonts);
  const googleButton = ValueKey('continue-with-google');

  testWidgets('welcome hides Google without an available provider', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    expect(find.text('Power your next drive.'), findsOneWidget);
    expect(find.byKey(googleButton), findsNothing);
    expect(find.text('Sign in'), findsOneWidget);
    expect(find.text('Create account'), findsOneWidget);
  });

  for (final (width, height, scale, dark) in [
    (390.0, 844.0, 1.0, false),
    (320.0, 640.0, 1.6, true),
  ]) {
    testWidgets('welcome Google fits $width x $height / $scale / dark=$dark', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, height);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.textScaleFactorTestValue = scale;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      final fixture = await buildTestDependencies(
        authRepository: TestGoogleRepository(),
        googleIdentityProvider: TestGoogleProvider(),
      );
      addTearDown(fixture.dependencies.dispose);
      await tester.pumpWidget(
        RepaintBoundary(
          key: const ValueKey('driver-preview'),
          child: MaterialApp(
            debugShowCheckedModeBanner: false,
            theme: dark ? VtsaTheme.dark() : VtsaTheme.light(),
            home: WelcomeScreen(dependencies: fixture.dependencies),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(googleButton));
      expect(find.text('Continue with Google'), findsOneWidget);
      expect(find.text('Sign in'), findsOneWidget);
      expect(find.text('Create account'), findsOneWidget);
      await preview(tester, 'welcome-google-$width-$height-$scale');
      await tester.ensureVisible(find.text('Help center'));
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('welcome Google signs a linked user straight into Explore', (
    tester,
  ) async {
    final provider = TestGoogleProvider();
    final repository = TestGoogleRepository();
    final fixture = await buildTestDependencies(
      authRepository: repository,
      googleIdentityProvider: provider,
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    expect(find.text('Power your next drive.'), findsOneWidget);
    await tester.ensureVisible(find.byKey(googleButton));
    await tester.tap(find.byKey(googleButton));
    await tester.pumpAndSettle();
    expect(fixture.dependencies.auth.status, AuthStatus.authenticated);
    expect(provider.calls, 1);
    expect(repository.loginCalls, 1);
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
    expect(router.routeInformationProvider.value.uri.path, '/explore');
    expect(tester.takeException(), isNull);
  });

  testWidgets('welcome Google opens profile signup and clears proof on back', (
    tester,
  ) async {
    final repository = TestGoogleRepository()
      ..profile = const GoogleProfile(
        email: 'ada@example.test',
        firstName: 'Ada',
        lastName: 'Driver',
      );
    final fixture = await buildTestDependencies(
      authRepository: repository,
      googleIdentityProvider: TestGoogleProvider(),
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(googleButton));
    await tester.tap(find.byKey(googleButton));
    await tester.pumpAndSettle();
    expect(find.text('Personal information'), findsOneWidget);
    expect(find.text('Create a password'), findsNothing);
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
    router.pop();
    await tester.pumpAndSettle();
    expect(find.text('Power your next drive.'), findsOneWidget);
    expect(fixture.dependencies.auth.googleRegistration, isNull);
    expect(fixture.dependencies.auth.status, AuthStatus.guest);
    expect(tester.takeException(), isNull);
  });

  testWidgets('welcome Google confirms an existing password before linking', (
    tester,
  ) async {
    final repository = TestGoogleRepository()..linkRequired = true;
    final fixture = await buildTestDependencies(
      authRepository: repository,
      googleIdentityProvider: TestGoogleProvider(),
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(googleButton));
    await tester.tap(find.byKey(googleButton));
    await tester.pumpAndSettle();
    expect(find.text('Connect your existing account'), findsOneWidget);
    final password = find.byKey(const ValueKey('google-link-password'));
    await tester.ensureVisible(password);
    await tester.enterText(password, 'wrong');
    await tester.ensureVisible(find.text('Connect Google account'));
    await tester.tap(find.text('Connect Google account'));
    await tester.pumpAndSettle();
    expect(find.text('Confirm your existing password.'), findsOneWidget);
    expect(fixture.dependencies.auth.status, AuthStatus.guest);
    await tester.ensureVisible(password);
    await tester.enterText(password, 'ExistingPass1!');
    await tester.ensureVisible(find.text('Connect Google account'));
    await tester.tap(find.text('Connect Google account'));
    await tester.pumpAndSettle();
    expect(fixture.dependencies.auth.status, AuthStatus.authenticated);
    expect(tester.takeException(), isNull);
  });

  testWidgets('welcome Google cancellation restores all entry choices', (
    tester,
  ) async {
    final provider = TestGoogleProvider()..pending = Completer<String?>();
    final repository = TestGoogleRepository();
    final fixture = await buildTestDependencies(
      authRepository: repository,
      googleIdentityProvider: provider,
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(googleButton));
    await tester.tap(find.byKey(googleButton));
    await tester.pump();
    expect(
      tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Sign in'))
          .onPressed,
      isNull,
    );
    expect(
      tester
          .widget<OutlinedButton>(
            find.widgetWithText(OutlinedButton, 'Create account'),
          )
          .onPressed,
      isNull,
    );
    provider.pending!.complete(null);
    await tester.pumpAndSettle();
    expect(fixture.dependencies.auth.status, AuthStatus.guest);
    expect(fixture.dependencies.auth.failure, isNull);
    expect(repository.loginCalls, 0);
    expect(find.text('Power your next drive.'), findsOneWidget);
    expect(
      tester.widget<OutlinedButton>(find.byKey(googleButton)).onPressed,
      isNotNull,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('welcome Google failures stay visible and allow email fallback', (
    tester,
  ) async {
    final provider = TestGoogleProvider()
      ..failure = const AppFailure(
        kind: FailureKind.server,
        message: 'Google is temporarily unavailable. Try again.',
      );
    final fixture = await buildTestDependencies(
      authRepository: TestGoogleRepository(),
      googleIdentityProvider: provider,
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(googleButton));
    await tester.tap(find.byKey(googleButton));
    await tester.pumpAndSettle();
    expect(
      find.text('Google is temporarily unavailable. Try again.'),
      findsOneWidget,
    );
    await tester.ensureVisible(find.text('Sign in'));
    await tester.tap(find.text('Sign in'));
    await tester.pumpAndSettle();
    expect(find.text('Welcome back.'), findsOneWidget);
    expect(fixture.dependencies.auth.failure, isNull);
    expect(tester.takeException(), isNull);
  });
}
