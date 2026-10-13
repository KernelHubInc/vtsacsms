import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/data/quick_unlock_store.dart';

import 'driver_journey_design_test.dart' show preview;
import 'support/fakes.dart';
import 'support/quick_unlock_fakes.dart';

void main() {
  setUpAll(() async {
    final root = Platform.environment['FLUTTER_ROOT']!;
    await (FontLoader('Roboto')..addFont(
          Future.value(
            ByteData.sublistView(
              File(
                '$root/bin/cache/artifacts/material_fonts/roboto-regular.ttf',
              ).readAsBytesSync(),
            ),
          ),
        ))
        .load();
    await (FontLoader('MaterialIcons')..addFont(
          Future.value(
            ByteData.sublistView(
              File(
                '$root/bin/cache/artifacts/material_fonts/materialicons-regular.otf',
              ).readAsBytesSync(),
            ),
          ),
        ))
        .load();
  });
  for (final (width, scale) in [(390.0, 1.0), (320.0, 1.6)]) {
    testWidgets('branded login and PIN remain usable at $width / $scale', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 844);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.textScaleFactorTestValue = scale;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      final store = QuickUnlockStore(
        tokens: MemoryTokenStore(),
        settings: MemoryUnlockSettings(),
        device: FakeDeviceAuthenticator(),
      );
      final fixture = await buildTestDependencies(tokenStore: store);
      await tester.pumpWidget(
        RepaintBoundary(
          key: const ValueKey('driver-preview'),
          child: VtsaApp(dependencies: fixture.dependencies),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('A better way to charge.'), findsOneWidget);
      await preview(tester, 'login-$width-$scale');
      await tester.ensureVisible(find.text('Create an account'));
      expect(tester.takeException(), isNull);
      await fixture.dependencies.auth.login(
        email: 'ada@example.test',
        password: 'fixture-password',
      );
      await tester.runAsync(
        () => fixture.dependencies.auth.configureQuickUnlock(
          '739152',
          biometrics: true,
        ),
      );
      fixture.dependencies.auth.lock();
      await tester.pumpAndSettle();
      expect(find.text('Unlock your drive.'), findsOneWidget);
      await preview(tester, 'pin-$width-$scale');
      await tester.ensureVisible(
        find.text('Forgot PIN? Sign in with password'),
      );
      await tester.tap(find.text('Forgot PIN? Sign in with password'));
      await tester.pumpAndSettle();
      expect(find.text('Sign in to your drive.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets(
    'reset request shows errors, blocks duplicate taps and confirms recovery',
    (tester) async {
      final fixture = await buildTestDependencies();
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();
      GoRouter.of(
        tester.element(find.byType(Scaffold).first),
      ).go('/forgot-password');
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), 'ada@example.test');
      fixture.auth.resetFailure = const AppFailure(
        kind: FailureKind.server,
        message: 'Please try again later.',
      );
      await tester.ensureVisible(find.text('Send reset link'));
      await tester.tap(find.text('Send reset link'));
      await tester.pumpAndSettle();
      expect(find.text('Please try again later.'), findsOneWidget);
      fixture.auth.resetFailure = null;
      fixture.auth.resetCompleter = Completer<void>();
      await tester.ensureVisible(find.text('Send reset link'));
      await tester.tap(find.text('Send reset link'));
      await tester.pump();
      expect(
        await fixture.dependencies.auth.requestPasswordReset(
          'ada@example.test',
        ),
        isFalse,
      );
      fixture.auth.resetCompleter!.complete();
      await tester.pumpAndSettle();
      expect(find.text('Check your inbox.'), findsOneWidget);
      expect(find.text('Please try again later.'), findsNothing);
    },
  );
}
