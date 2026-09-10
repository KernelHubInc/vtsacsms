import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';

import '../test/support/fakes.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('manual start, live charging, stop, and final receipt journey', (
    tester,
  ) async {
    final repository = FakeChargingRepository();
    final fixture = await buildTestDependencies(
      signedIn: true,
      chargingRepository: repository,
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Scan charger code'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'VSTA-ABC_123');
    await tester.tap(find.text('Validate connector'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Review and request start'), 300);
    await tester.tap(find.text('Review and request start'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Request start'));
    await tester.pumpAndSettle();
    expect(find.text('Waiting for physical start'), findsOneWidget);

    final sessionId = fixture.dependencies.charging.session!.id;
    repository.sessions[sessionId] = sampleChargingSession(
      id: sessionId,
      state: ChargingSessionState.charging,
      energyWh: 6400,
      durationSeconds: 720,
      currentPowerW: 50000,
      aggregateVersion: 2,
    );
    await fixture.dependencies.charging.refresh();
    await tester.pump();
    expect(find.text('Charging in progress'), findsOneWidget);

    await tester.ensureVisible(find.text('Stop charging'));
    await tester.tap(find.text('Stop charging'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Request stop'));
    await tester.pumpAndSettle();
    expect(find.text('Stop requested'), findsOneWidget);

    repository.sessions[sessionId] = sampleChargingSession(
      id: sessionId,
      state: ChargingSessionState.completed,
      paymentState: PaymentState.captured,
      energyWh: 6600,
      durationSeconds: 760,
      aggregateVersion: 5,
      finalCost: const Money(minorUnits: 19800, currency: 'PHP'),
      receipt: ChargingDocument(
        id: 'receipt-1',
        reference: 'OR-2026-0001',
        downloadUrl: Uri.parse('https://example.test/receipt'),
      ),
    );
    await fixture.dependencies.charging.refresh();
    await tester.pump();

    expect(find.text('Session complete'), findsOneWidget);
    expect(find.text('6.60 kWh'), findsOneWidget);
    expect(find.text('Open receipt'), findsOneWidget);
  });
}
