import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';

import '../test/support/fakes.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('driver explores station details and saves a favorite', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Harbor Exchange'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Add to favorites'));
    await tester.pumpAndSettle();

    expect(fixture.dependencies.favorites.contains(sampleStation.id), isTrue);
    expect(find.text('Treat availability as unknown'), findsOneWidget);
  });
}
