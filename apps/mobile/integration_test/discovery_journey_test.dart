import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';

import '../test/support/fakes.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('driver explores station details and saves a favorite', (
    tester,
  ) async {
    final fixture = await buildTestDependencies(signedIn: true);
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Harbor Exchange'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('Availability needs a refresh'),
      150,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Availability needs a refresh'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('Station details & directions'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('Station details & directions'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('Add to favorites'),
      150,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add to favorites'));
    await tester.pumpAndSettle();

    expect(fixture.dependencies.favorites.contains(sampleStation.id), isTrue);
    expect(find.text('Remove from favorites'), findsOneWidget);
  });
}
