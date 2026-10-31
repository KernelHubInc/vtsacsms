import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/features/auth/presentation/driver_date_field.dart';

import 'form_fields.dart';

Future<void> fillRegistrationSecurity(
  WidgetTester tester, {
  String email = 'ada@example.test',
  String password = 'Password!2026',
  String? confirmation,
}) async {
  await tester.enterText(textInput('Email address'), email);
  await tester.enterText(textInput('Password'), password);
  await tester.enterText(
    textInput('Confirm password'),
    confirmation ?? password,
  );
}

Future<void> fillRegistrationPersonal(WidgetTester tester) async {
  await tester.enterText(textInput('First name'), 'Ada');
  await tester.enterText(textInput('Last name'), 'Driver');
  tester
      .widget<DriverDateField>(find.byType(DriverDateField))
      .onChanged(DateTime(1990, 1, 1));
}

Future<void> pressRegistrationAction(WidgetTester tester, String label) async {
  FocusManager.instance.primaryFocus?.unfocus();
  await tester.pumpAndSettle();
  final action = find.text(label);
  await tester.ensureVisible(action);
  await tester.pumpAndSettle();
  await tester.tap(action);
  await tester.pumpAndSettle();
}
