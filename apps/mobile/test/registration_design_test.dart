import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/features/auth/presentation/driver_date_field.dart';
import 'package:vtsa_mobile/features/auth/presentation/registration_layout.dart';

import 'driver_journey_design_test.dart' show preview;
import 'support/fakes.dart';
import 'support/form_fields.dart';
import 'support/registration_steps.dart';
import 'support/test_fonts.dart';

void main() {
  setUpAll(loadTestFonts);

  for (final (width, height, scale, dark) in [
    (402.0, 1320.0, 1.0, false),
    (320.0, 640.0, 1.6, false),
    (1024.0, 900.0, 1.0, false),
    (402.0, 844.0, 1.0, true),
  ]) {
    testWidgets('registration fits $width x $height at $scale; dark=$dark', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, height);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.textScaleFactorTestValue = scale;
      tester.platformDispatcher.platformBrightnessTestValue = dark
          ? Brightness.dark
          : Brightness.light;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
      final fixture = await buildTestDependencies();
      await tester.pumpWidget(
        RepaintBoundary(
          key: const ValueKey('driver-preview'),
          child: VtsaApp(dependencies: fixture.dependencies),
        ),
      );
      await tester.pumpAndSettle();
      GoRouter.of(tester.element(find.byType(Scaffold))).go('/register');
      await tester.pumpAndSettle();
      expect(find.text('Create your account.'), findsOneWidget);
      expect(find.text('At least 8 characters'), findsOneWidget);
      expect(find.textContaining('12 characters'), findsNothing);
      expect(find.textContaining('SECTION '), findsNothing);
      expect(find.text('LET’S GET YOU MOVING'), findsNothing);
      final titles = [
        'Account security',
        'Personal information',
        'Vehicle details',
      ];
      final icons = ['shield-check', 'user-round', 'car-front'];
      for (var step = 0; step < 3; step++) {
        expect(find.text('Step ${step + 1} of 3'), findsOneWidget);
        expect(find.byType(RegistrationSection), findsOneWidget);
        expect(
          tester
              .widget<RegistrationSection>(find.byType(RegistrationSection))
              .title,
          titles[step],
        );
        expect(
          find.byWidgetPredicate(
            (widget) =>
                widget is RegistrationIcon && widget.name == icons[step],
          ),
          findsOneWidget,
        );
        expect(
          find.text('Create account'),
          step == 2 ? findsOneWidget : findsNothing,
        );
        await preview(
          tester,
          'registration-step-${step + 1}-$width-$scale-$dark',
        );
        await pressRegistrationAction(
          tester,
          step == 2 ? 'Create account' : 'Continue',
        );
        expect(find.text('Step ${step + 1} of 3'), findsOneWidget);
        expect(fixture.auth.registrationRequested, isFalse);
        expect(tester.takeException(), isNull);
        if (step == 0) {
          await fillRegistrationSecurity(tester);
          await pressRegistrationAction(tester, 'Continue');
        } else if (step == 1) {
          expect(
            tester
                .widget<DriverDateField>(find.byType(DriverDateField))
                .invalid,
            isTrue,
          );
          await fillRegistrationPersonal(tester);
          await pressRegistrationAction(tester, 'Continue');
        }
      }
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets(
    'registration keeps inputs below the status bar and above the keyboard',
    (tester) async {
      tester.view.physicalSize = const Size(402, 844);
      tester.view.devicePixelRatio = 1;
      tester.view.padding = const FakeViewPadding(top: 44, bottom: 24);
      tester.view.viewPadding = const FakeViewPadding(top: 44, bottom: 24);
      addTearDown(tester.view.reset);
      final fixture = await buildTestDependencies();
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();
      GoRouter.of(tester.element(find.byType(Scaffold))).go('/register');
      await tester.pumpAndSettle();
      tester.view.viewInsets = const FakeViewPadding(bottom: 300);
      await tester.pumpAndSettle();
      final password = textInput('Password');
      await tester.ensureVisible(password);
      await tester.pumpAndSettle();
      final viewport = tester.getRect(find.byType(SingleChildScrollView));
      final input = tester.getRect(password);
      expect(viewport.top, 44);
      expect(viewport.bottom, 544);
      expect(input.top, greaterThanOrEqualTo(viewport.top));
      expect(input.bottom, lessThanOrEqualTo(viewport.bottom));
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'password visibility, pending plate, policy links and sign in work',
    (tester) async {
      final opened = <String>[];
      const channel = MethodChannel('plugins.flutter.io/url_launcher');
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(channel, (call) async {
            if (call.method == 'launch') {
              opened.add((call.arguments as Map)['url'] as String);
            }
            return true;
          });
      addTearDown(
        () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
            .setMockMethodCallHandler(channel, null),
      );
      final fixture = await buildTestDependencies();
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();
      GoRouter.of(tester.element(find.byType(Scaffold))).go('/register');
      await tester.pumpAndSettle();
      await tester.enterText(textInput('Password'), 'Abcdef1!');
      expect(
        tester.widget<TextField>(textInput('Password')).obscureText,
        isTrue,
      );
      await tester.enterText(textInput('Confirm password'), 'Abcdef1!');
      await tester.ensureVisible(find.text('Show passwords'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Show passwords'));
      await tester.pumpAndSettle();
      expect(
        tester.widget<TextField>(textInput('Password')).obscureText,
        isFalse,
      );
      expect(
        tester.widget<TextField>(textInput('Confirm password')).obscureText,
        isFalse,
      );
      expect(
        tester.widget<TextField>(textInput('Password')).controller!.text,
        'Abcdef1!',
      );
      await tester.tap(find.text('Hide passwords'));
      await tester.pumpAndSettle();
      expect(
        tester.widget<TextField>(textInput('Password')).obscureText,
        isTrue,
      );
      await tester.enterText(textInput('Email address'), 'ada@example.test');
      await pressRegistrationAction(tester, 'Continue');
      await fillRegistrationPersonal(tester);
      await pressRegistrationAction(tester, 'Continue');
      await tester.ensureVisible(find.text('Plate pending'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Plate pending'));
      await tester.pumpAndSettle();
      expect(textInput('Vehicle plate number'), findsNothing);
      for (final label in ['Terms of service', 'Privacy policy']) {
        await tester.ensureVisible(find.text(label));
        await tester.pumpAndSettle();
        await tester.tap(find.text(label));
        await tester.pumpAndSettle();
      }
      expect(opened, [
        fixture.dependencies.environment.apiBaseUrl
            .resolve('/terms-of-service')
            .toString(),
        fixture.dependencies.environment.apiBaseUrl
            .resolve('/privacy-policy')
            .toString(),
      ]);
      await tester.ensureVisible(find.text('Sign in').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Sign in').last);
      await tester.pumpAndSettle();
      expect(find.text('Sign in to your drive.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
}
