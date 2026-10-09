import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/data/quick_unlock_store.dart';
import 'support/fakes.dart';
import 'support/quick_unlock_fakes.dart';

void main() {
  testWidgets(
    'setup validates confirmation, and lock guards every private route',
    (tester) async {
      final settings = MemoryUnlockSettings();
      final store = QuickUnlockStore(
        tokens: MemoryTokenStore(),
        settings: settings,
        device: FakeDeviceAuthenticator(),
        now: () => DateTime.utc(2026, 10, 6),
      );
      await store.initialize();
      final fixture = await buildTestDependencies(tokenStore: store);
      final auth = fixture.dependencies.auth;
      await auth.login(email: 'ada@example.test', password: 'test-password');
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();
      final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
      router.go('/account/security');
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField).first, '739152');
      await tester.enterText(find.byType(TextFormField).last, '111111');
      await tester.ensureVisible(find.text('Enable quick unlock'));
      await tester.tap(find.text('Enable quick unlock'));
      await tester.pumpAndSettle();
      expect(find.text('PINs must match.'), findsOneWidget);
      expect(store.enabled, isFalse);
      await tester.runAsync(
        () => auth.configureQuickUnlock('739152', biometrics: true),
      );
      await tester.pumpAndSettle();
      expect(find.text('Ready for your next drive'), findsOneWidget);

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text('Unlock your drive.'), findsOneWidget);
      expect(find.text('Explore'), findsNothing);
      for (final route in [
        '/account',
        '/account/security',
        '/activity',
        '/charge',
        '/kyc',
        '/stations/test',
        '/login',
        '/unknown',
      ]) {
        router.go(route);
        await tester.pumpAndSettle();
        expect(router.routeInformationProvider.value.uri.path, '/unlock');
      }
      await tester.tap(find.text('Use fingerprint / Face ID'));
      await tester.pumpAndSettle();
      expect(auth.status, AuthStatus.authenticated);
      expect(router.routeInformationProvider.value.uri.path, '/account');
      auth.lock();
      await tester.pumpAndSettle();
      await tester.tap(find.text('Forgot PIN? Sign in with password'));
      await tester.pumpAndSettle();
      expect(find.text('Sign in to your drive.'), findsOneWidget);
      expect(store.enabled, isFalse);
      expect(settings.value, isNull);
    },
  );
}
