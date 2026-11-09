import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/evcs_navigation.dart';

import 'support/fakes.dart';

void main() {
  testWidgets(
    'signed-in driver discovers bounded stations with stale indication',
    (tester) async {
      final fixture = await buildTestDependencies(signedIn: true);
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();

      expect(find.text('Where would you like to charge?'), findsOneWidget);
      expect(find.byType(PowerSolutionsLogo), findsNothing);
      expect(find.text('Harbor Exchange'), findsOneWidget);
      expect(find.text('Status stale'), findsOneWidget);
      expect(fixture.stations.lastBounds?.west, closeTo(120.8342, .00001));

      expect(find.byType(EvcsNavigationBar), findsOneWidget);
    },
  );

  testWidgets('driver starts at sign-in and reaches their account', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    expect(find.text('Power your next drive.'), findsOneWidget);
    await tester.tap(find.text('Sign in'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byType(TextFormField).first,
      'ada@example.test',
    );
    await tester.enterText(
      find.byType(TextFormField).last,
      'correct horse battery staple',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pumpAndSettle();

    expect(find.text('Where would you like to charge?'), findsOneWidget);
    await tester.tap(find.text('Profile'));
    await tester.pumpAndSettle();
    expect(find.text('Ada Driver'), findsOneWidget);
    expect(fixture.auth.signedIn, isTrue);
  });

  testWidgets(
    'redesigned Explore retains navigation to branded account and payment screens',
    (tester) async {
      final fixture = await buildTestDependencies(signedIn: true);
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();

      expect(find.text('Where would you like to charge?'), findsOneWidget);

      for (final (destination, title) in [
        ('Sessions', 'Activity'),
        ('Wallet', 'Wallet'),
        ('Profile', 'Account'),
      ]) {
        await tester.tap(find.text(destination));
        await tester.pumpAndSettle();

        final header = tester.widget<DriverTabPage>(find.byType(DriverTabPage));
        expect(header.title, title);
      }
    },
  );

  testWidgets('station details expose connectors, hours, and amenities', (
    tester,
  ) async {
    final fixture = await buildTestDependencies(signedIn: true);
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Harbor Exchange'));
    await tester.pumpAndSettle();

    expect(find.text('Choose a plug'), findsOneWidget);
    expect(find.text('CCS2 · CCS connector'), findsOneWidget);
    expect(find.textContaining('Connection unconfirmed'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('Station details & directions'),
      150,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('Station details & directions'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('Operating hours'),
      150,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Operating hours'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('Restroom'),
      250,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Restroom'), findsOneWidget);
  });
}
