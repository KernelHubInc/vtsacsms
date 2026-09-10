import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/activity_screens.dart';

import 'support/fakes.dart';

void main() {
  testWidgets('driver sees camera rationale and can use manual entry', (
    tester,
  ) async {
    final fixture = await buildTestDependencies(signedIn: true);
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Scan charger code'));
    await tester.pumpAndSettle();

    expect(find.text('Scan only when you choose'), findsOneWidget);
    expect(find.textContaining('Camera video is not stored'), findsOneWidget);
    expect(find.text('Charger code (required)'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'VSTA-ABC_123');
    await tester.tap(find.text('Validate connector'));
    await tester.pumpAndSettle();

    expect(find.text('REVIEW BEFORE START'), findsOneWidget);
    expect(find.text('Standard charging'), findsOneWidget);
    expect(find.text('Estimated preauthorization'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Visa ending 4242'), 240);
    expect(find.text('Visa ending 4242'), findsOneWidget);
  });

  testWidgets('vehicle mismatch is advisory and start stays pending', (
    tester,
  ) async {
    final fixture = await buildTestDependencies(signedIn: true);
    await fixture.dependencies.vehicles.save(
      nickname: 'City EV',
      manufacturer: 'Example',
      model: 'One',
      connectorStandards: const {'CHAdeMO'},
      isDefault: true,
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Scan charger code'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'VSTA-ABC_123');
    await tester.tap(find.text('Validate connector'));
    await tester.pumpAndSettle();

    expect(find.text('COMPATIBILITY WARNING'), findsOneWidget);
    expect(find.textContaining('does not list CCS2'), findsOneWidget);

    await tester.scrollUntilVisible(find.text('Review and request start'), 300);
    await tester.tap(find.text('Review and request start'));
    await tester.pumpAndSettle();
    expect(find.text('Start despite compatibility warning?'), findsOneWidget);
    await tester.tap(find.text('Request start'));
    await tester.pumpAndSettle();

    expect(find.text('Waiting for physical start'), findsOneWidget);
    expect(find.textContaining('charging is not active until'), findsOneWidget);
    expect(fixture.charging.startCalls, 1);
  });

  testWidgets('live session presents authoritative metrics and stop action', (
    tester,
  ) async {
    final repository = FakeChargingRepository()
      ..active = sampleChargingSession(
        state: ChargingSessionState.charging,
        energyWh: 6420,
        durationSeconds: 754,
        currentPowerW: 48750,
        aggregateVersion: 4,
      );
    repository.sessions[repository.active!.id] = repository.active!;
    final fixture = await buildTestDependencies(
      signedIn: true,
      chargingRepository: repository,
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    await tester.tap(find.text('6.42 kWh · Charging'));
    await tester.pumpAndSettle();

    expect(find.text('Charging in progress'), findsOneWidget);
    expect(find.text('6.42 kWh'), findsOneWidget);
    expect(find.text('00:12:34'), findsOneWidget);
    expect(find.text('48.8 kW'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Stop charging'), 300);
    expect(find.text('Stop charging'), findsOneWidget);
  });

  testWidgets(
    'delayed payment remains pending without a duplicate pay action',
    (tester) async {
      final repository = FakeChargingRepository()
        ..active = sampleChargingSession(
          state: ChargingSessionState.completed,
          paymentState: PaymentState.capturePending,
          energyWh: 9300,
          durationSeconds: 1110,
          aggregateVersion: 6,
          finalCost: const Money(minorUnits: 27900, currency: 'PHP'),
        );
      repository.sessions[repository.active!.id] = repository.active!;
      final fixture = await buildTestDependencies(
        signedIn: true,
        chargingRepository: repository,
      );
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Payment processing'));
      await tester.pumpAndSettle();

      expect(find.text('Payment confirmation pending'), findsOneWidget);
      expect(
        find.textContaining('will not create a duplicate charge'),
        findsOneWidget,
      );
      expect(find.text('Pay again'), findsNothing);
    },
  );

  testWidgets('history opens documents, refund review, and issue reporting', (
    tester,
  ) async {
    final completed = sampleChargingSession(
      state: ChargingSessionState.completed,
      paymentState: PaymentState.captured,
      energyWh: 12000,
      durationSeconds: 1500,
      aggregateVersion: 8,
      finalCost: const Money(minorUnits: 36000, currency: 'PHP'),
      receipt: ChargingDocument(
        id: 'receipt-1',
        reference: 'OR-2026-0001',
        downloadUrl: Uri.parse('https://example.test/receipt'),
      ),
      invoice: ChargingDocument(
        id: 'invoice-1',
        reference: 'INV-2026-0001',
        downloadUrl: Uri.parse('https://example.test/invoice'),
      ),
      refundable: true,
    );
    final repository = FakeChargingRepository()
      ..historyResult = ChargingHistoryPage(
        sessions: [completed],
        nextCursor: null,
      )
      ..sessions[completed.id] = completed;
    final fixture = await buildTestDependencies(
      signedIn: true,
      chargingRepository: repository,
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Activity'));
    await tester.pumpAndSettle();
    expect(find.textContaining('12.00 kWh'), findsOneWidget);

    await tester.tap(find.text('Station A'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.textContaining('OR-2026-0001'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.textContaining('OR-2026-0001'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.textContaining('INV-2026-0001'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.textContaining('INV-2026-0001'), findsOneWidget);

    await tester.scrollUntilVisible(
      find.text('Request a refund review'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('Request a refund review'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byType(TextField),
      'The billed energy needs review.',
    );
    await tester.tap(find.text('Submit review request'));
    await tester.pumpAndSettle();

    expect(find.text('Request received'), findsOneWidget);
    expect(repository.refundCalls, 1);
    expect(repository.lastRefundSessionId, completed.id);
  });

  testWidgets('issue report carries the session reference without card data', (
    tester,
  ) async {
    final repository = FakeChargingRepository();
    final fixture = await buildTestDependencies(
      signedIn: true,
      chargingRepository: repository,
    );
    const sessionId = '01K0M0JJ5X0M0JJ5X0M0JJ5X0Y';
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: IssueReportScreen(
          dependencies: fixture.dependencies,
          sessionId: sessionId,
        ),
      ),
    );

    expect(find.textContaining('Do not include card numbers'), findsOneWidget);
    await tester.enterText(
      find.byType(TextField),
      'The connector stopped unexpectedly.',
    );
    await tester.tap(find.text('Submit issue'));
    await tester.pumpAndSettle();

    expect(find.text('Issue submitted'), findsOneWidget);
    expect(repository.issueCalls, 1);
    expect(repository.lastIssueSessionId, sessionId);

    await tester.pumpWidget(const SizedBox.shrink());
    fixture.dependencies.dispose();
  });
}
