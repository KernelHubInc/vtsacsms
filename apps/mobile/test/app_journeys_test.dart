import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_app_bar.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';

import 'support/fakes.dart';

void main() {
  testWidgets('guest discovers bounded station list with stale indication', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    expect(find.text('Find your next charge'), findsOneWidget);
    expect(find.byType(PowerSolutionsLogo), findsOneWidget);
    expect(find.text('Harbor Exchange'), findsOneWidget);
    expect(find.text('Status stale'), findsOneWidget);
    expect(fixture.stations.lastBounds?.west, 120.85);

    final appBar = tester.widget<AppBar>(find.byType(AppBar));
    expect(appBar.toolbarHeight, 96);
  });

  testWidgets('driver can sign in from Account', (tester) async {
    final fixture = await buildTestDependencies();
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Account'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sign in'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.widgetWithText(TextField, 'Email address (required)'),
      'ada@example.test',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Password (required)'),
      'correct horse battery staple',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pumpAndSettle();

    expect(find.text('Ada Driver'), findsOneWidget);
    expect(fixture.auth.signedIn, isTrue);
  });

  testWidgets('primary destinations share the branded navigation header', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    expect(find.byType(PowerSolutionsAppBar), findsOneWidget);

    for (final destination in ['Activity', 'Wallet', 'Account']) {
      await tester.tap(find.text(destination));
      await tester.pumpAndSettle();

      final header = tester.widget<PowerSolutionsAppBar>(
        find.byType(PowerSolutionsAppBar),
      );
      expect(header.title, destination);
    }
  });

  testWidgets('station details expose connectors, hours, and amenities', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Harbor Exchange'));
    await tester.pumpAndSettle();

    expect(find.text('Connectors'), findsOneWidget);
    expect(find.textContaining('CCS2'), findsOneWidget);
    expect(find.text('Operating hours'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Restroom'), 250);
    expect(find.text('Restroom'), findsOneWidget);
  });
}
