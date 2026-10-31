import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/features/auth/domain/auth_repository.dart';
import 'package:vtsa_mobile/features/auth/presentation/driver_date_field.dart';
import 'package:vtsa_mobile/features/auth/presentation/registration_layout.dart';

import 'support/fakes.dart';
import 'support/form_fields.dart';
import 'support/registration_steps.dart';

void main() {
  testWidgets(
    'Back preserves details; only the final step creates one account',
    (tester) async {
      final fixture = await buildTestDependencies();
      final pending = Completer<RegistrationResult>();
      fixture.auth.registrationCompleter = pending;
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create an account'));
      await tester.pumpAndSettle();
      await fillRegistrationSecurity(tester);
      await pressRegistrationAction(tester, 'Continue');
      expect(fixture.auth.registrationCalls, 0);
      await fillRegistrationPersonal(tester);
      await tester.enterText(textInput('Middle name · Optional'), 'M');
      await pressRegistrationAction(tester, 'Continue');
      expect(fixture.auth.registrationCalls, 0);
      await tester.enterText(textInput('Vehicle plate number'), 'ABC 123');
      await pressRegistrationAction(tester, 'Back');
      expect(find.text('Step 2 of 3'), findsOneWidget);
      expect(
        tester.widget<TextField>(textInput('First name')).controller!.text,
        'Ada',
      );
      expect(
        tester
            .widget<TextField>(textInput('Middle name · Optional'))
            .controller!
            .text,
        'M',
      );
      expect(
        tester.widget<DriverDateField>(find.byType(DriverDateField)).value,
        DateTime(1990),
      );
      await Navigator.of(
        tester.element(find.byType(RegistrationLayout)),
      ).maybePop();
      await tester.pumpAndSettle();
      expect(find.text('Step 1 of 3'), findsOneWidget);
      expect(
        tester.widget<TextField>(textInput('Email address')).controller!.text,
        'ada@example.test',
      );
      expect(
        tester
            .widget<TextField>(textInput('Confirm password'))
            .controller!
            .text,
        'Password!2026',
      );
      expect(
        tester.widget<TextField>(textInput('Password')).obscureText,
        isTrue,
      );
      await pressRegistrationAction(tester, 'Continue');
      await pressRegistrationAction(tester, 'Continue');
      expect(
        tester
            .widget<TextField>(textInput('Vehicle plate number'))
            .controller!
            .text,
        'ABC 123',
      );
      final submit = find.byType(FilledButton);
      await tester.ensureVisible(submit);
      await tester.pumpAndSettle();
      await tester.tap(submit);
      await tester.pump();
      expect(fixture.auth.registrationCalls, 1);
      expect(tester.widget<FilledButton>(submit).onPressed, isNull);
      expect(
        tester
            .widget<TextButton>(find.widgetWithText(TextButton, 'Back'))
            .onPressed,
        isNull,
      );
      await tester.tap(submit);
      await tester.pump();
      await Navigator.of(
        tester.element(find.byType(RegistrationLayout)),
      ).maybePop();
      await tester.pump();
      expect(find.text('Step 3 of 3'), findsOneWidget);
      expect(fixture.auth.registrationCalls, 1);
      pending.complete(const RegistrationResult(verificationEmailSent: true));
      await tester.pumpAndSettle();
      expect(find.text('One tap from verified.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('network failure keeps the final step and details for retry', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    fixture.auth.registrationFailure = const AppFailure(
      kind: FailureKind.offline,
      message: 'Unable to connect. Please try again.',
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Create an account'));
    await tester.pumpAndSettle();
    await fillRegistrationSecurity(tester);
    await pressRegistrationAction(tester, 'Continue');
    await fillRegistrationPersonal(tester);
    await pressRegistrationAction(tester, 'Continue');
    await tester.enterText(textInput('Vehicle plate number'), 'ABC 123');
    await pressRegistrationAction(tester, 'Create account');
    expect(find.text('Step 3 of 3'), findsOneWidget);
    expect(find.text('Unable to connect. Please try again.'), findsOneWidget);
    expect(
      tester
          .widget<TextField>(textInput('Vehicle plate number'))
          .controller!
          .text,
      'ABC 123',
    );
    expect(fixture.auth.registrationCalls, 1);
    fixture.auth.registrationFailure = null;
    await pressRegistrationAction(tester, 'Create account');
    expect(fixture.auth.registrationCalls, 2);
    expect(find.text('One tap from verified.'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
