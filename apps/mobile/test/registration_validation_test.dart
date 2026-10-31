import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/auth/presentation/registration_validation.dart';

import 'support/fakes.dart';
import 'support/form_fields.dart';
import 'support/registration_steps.dart';

void main() {
  Set<String> validate({
    String password = 'Password!2026',
    String? confirmation,
    String email = 'ada@example.test',
    DateTime? birthDate,
    bool pending = true,
    String plate = '',
  }) => registrationInvalidFields(
    firstName: 'Ada',
    middleName: '',
    lastName: 'Driver',
    birthDate: birthDate ?? DateTime(2008, 10, 30),
    today: DateTime.utc(2026, 10, 30),
    plate: plate,
    platePending: pending,
    email: email,
    password: password,
    passwordConfirmation: confirmation ?? password,
  );

  test(
    'registration accepts eighteenth birthday and optional pending plate',
    () {
      expect(validate(), isEmpty);
      expect(validate(birthDate: DateTime(2008, 10, 31)), {'birth_date'});
      expect(validate(pending: false), {'plate_number'});
      expect(validate(pending: false, plate: 'abc 123'), isEmpty);
    },
  );

  test(
    'password checks follow Laravel Unicode rules; highlights only bad fields',
    () {
      expect(validate(password: 'Password2026 '), {'password'});
      expect(validate(password: 'Password2026'), {'password'});
      expect(validate(email: 'bad'), {'email'});
      expect(validate(password: 'Pässword!2026'), isEmpty);
    },
  );

  test('password needs eight characters and every required character type', () {
    for (final password in ['Abcdef1!', 'Longer-Password-2026!', 'Äbcdef1€']) {
      expect(validate(password: password), isEmpty, reason: password);
    }
    for (final password in [
      'Abcde1!',
      'abcdef1!',
      'ABCDEF1!',
      'Abcdefg!',
      'Abcdef12',
      'Abcdef1 ',
    ]) {
      expect(validate(password: password), {'password'}, reason: password);
    }
  });

  test('confirmation must match exactly, including case and spaces', () {
    expect(validate(confirmation: ''), {'password_confirmation'});
    expect(validate(confirmation: 'password!2026'), {'password_confirmation'});
    expect(validate(confirmation: 'Password!2026 '), {'password_confirmation'});
    expect(validate(confirmation: 'Password!2026'), isEmpty);
  });

  testWidgets(
    'mismatched confirmation blocks registration and clears on correction',
    (tester) async {
      final fixture = await buildTestDependencies();
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create an account'));
      await tester.pumpAndSettle();
      await fillRegistrationSecurity(tester, confirmation: 'Different!2026');
      await pressRegistrationAction(tester, 'Continue');
      expect(fixture.auth.registrationRequested, isFalse);
      expect(
        tester
            .widgetList<VtsaTextField>(find.byType(VtsaTextField))
            .where((field) => field.invalid)
            .map((field) => field.label),
        ['Confirm password'],
      );
      expect(
        tester
            .widget<TextField>(textInput('Confirm password'))
            .decoration!
            .errorText,
        'Enter the same password in both fields.',
      );
      expect(textInput('Confirm password').hitTestable(), findsOneWidget);
      await tester.enterText(textInput('Confirm password'), 'Password!2026');
      await pressRegistrationAction(tester, 'Continue');
      expect(find.text('Step 2 of 3'), findsOneWidget);
      expect(fixture.auth.registrationRequested, isFalse);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'validate on blur, keep errors until corrected, and recheck confirmation',
    (tester) async {
      final fixture = await buildTestDependencies();
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create an account'));
      await tester.pumpAndSettle();
      String? error(String label) =>
          tester.widget<TextField>(textInput(label)).decoration!.errorText;
      expect(error('Email address'), isNull);
      await tester.enterText(textInput('Email address'), 'bad');
      await tester.pump();
      expect(error('Email address'), isNull);
      await tester.enterText(textInput('Password'), 'abc');
      await tester.pumpAndSettle();
      expect(error('Email address'), registrationFieldMessage('email'));
      await tester.enterText(textInput('Email address'), 'stillbad');
      await tester.pump();
      expect(error('Email address'), isNotNull);
      expect(error('Password'), registrationFieldMessage('password'));
      await tester.enterText(textInput('Email address'), 'ada@example.test');
      await tester.enterText(textInput('Password'), 'Abcdef1!');
      await tester.enterText(textInput('Confirm password'), 'Abcdef1!');
      await tester.pump();
      expect(error('Email address'), isNull);
      expect(error('Password'), isNull);
      await tester.enterText(textInput('Password'), 'Changed1!');
      await tester.pumpAndSettle();
      expect(
        error('Confirm password'),
        registrationFieldMessage('password_confirmation'),
      );
      await tester.enterText(textInput('Confirm password'), 'Changed1!');
      await tester.pump();
      expect(error('Confirm password'), isNull);
      expect(find.byIcon(Icons.check_circle), findsNWidgets(4));
      expect(fixture.auth.registrationCalls, 0);
      expect(tester.takeException(), isNull);
    },
  );

  for (final serverError in [false, true]) {
    testWidgets(
      'registration gives safe inline feedback only on rejected fields; server=$serverError',
      (tester) async {
        final fixture = await buildTestDependencies();
        if (serverError) {
          fixture.auth.registrationFailure = const AppFailure(
            kind: FailureKind.validation,
            message: 'Incorrect server message',
            fieldErrors: {
              'email': ['Internal validation text'],
            },
          );
        }
        await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Create an account'));
        await tester.pumpAndSettle();
        await fillRegistrationSecurity(
          tester,
          email: serverError ? 'ada@example.test' : 'invalid',
        );
        await pressRegistrationAction(tester, 'Continue');
        if (serverError) {
          await fillRegistrationPersonal(tester);
          await pressRegistrationAction(tester, 'Continue');
          await pressRegistrationAction(tester, 'Plate pending');
          await pressRegistrationAction(tester, 'Create account');
        }
        expect(find.text('Step 1 of 3'), findsOneWidget);
        final fields = tester.widgetList<VtsaTextField>(
          find.byType(VtsaTextField),
        );
        expect(
          fields.where((field) => field.invalid).map((field) => field.label),
          ['Email address'],
        );
        expect(
          fields.singleWhere((field) => field.label == 'Email address').error,
          serverError
              ? registrationServerFieldMessage('email')
              : registrationFieldMessage('email'),
        );
        expect(
          fields
              .where((field) => !field.invalid)
              .every((field) => field.error == null),
          isTrue,
        );
        expect(fixture.auth.registrationRequested, serverError);
        expect(find.text('Incorrect server message'), findsNothing);
        expect(find.text('Internal validation text'), findsNothing);
        await tester.enterText(
          textInput('Email address'),
          'fixed@example.test',
        );
        await tester.pump();
        expect(
          tester
              .widgetList<VtsaTextField>(find.byType(VtsaTextField))
              .any((field) => field.invalid),
          isFalse,
        );
        expect(tester.takeException(), isNull);
      },
    );
  }
}
