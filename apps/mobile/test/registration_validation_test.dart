import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/auth/presentation/driver_date_field.dart';
import 'package:vtsa_mobile/features/auth/presentation/registration_validation.dart';

import 'support/fakes.dart';

void main() {
  Set<String> validate({
    String password = 'Password!2026',
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
      expect(validate(password: 'Password2026 '), isEmpty);
      expect(validate(password: 'Password2026'), {'password'});
      expect(validate(email: 'bad'), {'email'});
      expect(validate(password: 'Pässword!2026'), isEmpty);
    },
  );

  for (final serverError in [false, true]) {
    testWidgets(
      'registration outlines only invalid fields; server=$serverError',
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
        for (final entry in {
          'First name': 'Ada',
          'Last name': 'Driver',
          'Email address': serverError ? 'ada@example.test' : 'invalid',
          'Password': 'Password!2026',
        }.entries) {
          await tester.enterText(
            find.widgetWithText(TextField, '${entry.key} (required)'),
            entry.value,
          );
        }
        tester
            .widget<DriverDateField>(find.byType(DriverDateField))
            .onChanged(DateTime(1990));
        await tester.ensureVisible(find.text('Plate pending'));
        await tester.tap(find.text('Plate pending'));
        FocusManager.instance.primaryFocus?.unfocus();
        await tester.pumpAndSettle();
        await tester.ensureVisible(
          find.widgetWithText(FilledButton, 'Create account'),
        );
        await tester.tap(find.widgetWithText(FilledButton, 'Create account'));
        await tester.pumpAndSettle();
        final fields = tester.widgetList<VtsaTextField>(
          find.byType(VtsaTextField),
        );
        expect(
          fields.where((field) => field.invalid).map((field) => field.label),
          ['Email address'],
        );
        expect(fields.every((field) => field.error == null), isTrue);
        expect(fixture.auth.registrationRequested, serverError);
        expect(find.text('Incorrect server message'), findsNothing);
        expect(find.text('Internal validation text'), findsNothing);
        await tester.enterText(
          find.widgetWithText(TextField, 'Email address (required)'),
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
