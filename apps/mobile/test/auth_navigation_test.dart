import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';

import 'support/fakes.dart';

void main() {
  testWidgets('expired session returns to login from an open station', (
    tester,
  ) async {
    final fixture = await buildTestDependencies(signedIn: true);
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    if (find.byTooltip('Show charger list').evaluate().isNotEmpty) {
      await tester.tap(find.byTooltip('Show charger list'));
    }
    await tester.pumpAndSettle();
    await tester.tap(find.text('Harbor Exchange'));
    await tester.pumpAndSettle();
    fixture.dependencies.auth.sessionExpired();
    await tester.pumpAndSettle();
    expect(find.text('Welcome back.'), findsOneWidget);
    expect(
      find.text('Your session has ended. Sign in again to continue.'),
      findsOneWidget,
    );
    expect(find.text('Harbor Exchange'), findsNothing);
    expect(fixture.dependencies.auth.user, isNull);
  });

  testWidgets('first sign-in opens the map and setup stays optional', (
    tester,
  ) async {
    final fixture = await buildTestDependencies(onboardingComplete: false);
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    expect(find.text('Power your next drive.'), findsOneWidget);
    await tester.tap(find.text('Sign in'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byType(TextFormField).first,
      'ada@example.test',
    );
    await tester.enterText(find.byType(TextFormField).last, 'test-only');
    await tester.ensureVisible(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pumpAndSettle();
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
    expect(router.routeInformationProvider.value.uri.path, '/explore');
    expect(fixture.dependencies.bootstrap.onboardingComplete, isFalse);
    expect(find.text('A charger that fits your drive.'), findsNothing);
    router.go('/onboarding');
    await tester.pumpAndSettle();
    expect(find.text('Make EVCS your own.'), findsOneWidget);
    await tester.ensureVisible(find.text('Explore chargers'));
    await tester.tap(find.text('Explore chargers'));
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/explore');
    expect(fixture.dependencies.bootstrap.onboardingComplete, isTrue);
  });

  for (final onboardingComplete in [false, true]) {
    testWidgets('guest starts at welcome; onboarding=$onboardingComplete', (
      tester,
    ) async {
      final fixture = await buildTestDependencies(
        onboardingComplete: onboardingComplete,
      );
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();

      expect(find.text('Power your next drive.'), findsOneWidget);
      expect(find.text('Continue as guest'), findsNothing);
      expect(find.text('Explore'), findsNothing);
      expect(fixture.stations.lastBounds, isNull);
    });
  }

  testWidgets('guest cannot open browsing or account routes directly', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));

    for (final path in [
      '/explore',
      '/activity',
      '/wallet',
      '/account',
      '/favorites',
      '/stations/${sampleStation.id}',
      '/profile',
      '/vehicles',
      '/vehicles/new',
      '/vehicles/test/edit',
      '/kyc',
      '/charge',
      '/charging/test',
      '/activity/test',
      '/activity/test/refund',
      '/activity/test/issue',
      '/onboarding',
      '/unknown-screen',
    ]) {
      router.go(path);
      await tester.pumpAndSettle();
      expect(
        router.routeInformationProvider.value.uri.path,
        '/welcome',
        reason: path,
      );
      expect(find.text('Power your next drive.'), findsOneWidget);
    }
    expect(fixture.stations.lastBounds, isNull);

    for (final path in ['/register', '/forgot-password', '/verify-email']) {
      router.go(path);
      await tester.pumpAndSettle();
      expect(router.routeInformationProvider.value.uri.path, path);
    }
  });

  testWidgets('logout removes browsing history and blocks returning to it', (
    tester,
  ) async {
    final fixture = await buildTestDependencies(signedIn: true);
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
    router.push('/stations/${sampleStation.id}');
    await tester.pumpAndSettle();

    await fixture.dependencies.auth.logout();
    await tester.pumpAndSettle();
    expect(find.text('Power your next drive.'), findsOneWidget);
    expect(router.canPop(), isFalse);
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('Harbor Exchange'), findsNothing);
  });

  testWidgets('unverified sessions cannot browse through direct links', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    fixture.auth.user = const UserProfile(
      id: 'test-user',
      name: 'Test driver',
      email: 'driver@example.test',
      emailVerified: false,
    );
    await fixture.dependencies.auth.login(
      email: 'driver@example.test',
      password: 'test-only',
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
    for (final path in ['/explore', '/stations/${sampleStation.id}', '/kyc']) {
      router.go(path);
      await tester.pumpAndSettle();
      expect(find.text('One tap from verified.'), findsOneWidget);
      expect(router.routeInformationProvider.value.uri.path, '/verify-email');
    }
    expect(fixture.stations.lastBounds, isNull);
  });
}
