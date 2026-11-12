import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/activity_screens.dart';
import 'package:vtsa_mobile/features/charging/presentation/live_charging_screen.dart';

import 'driver_journey_design_test.dart' show preview;
import 'support/fakes.dart';
import 'support/test_fonts.dart';

void main() {
  setUpAll(loadTestFonts);

  testWidgets('a rejected start opens a popup and can be retried', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    const message =
        'Plug in your vehicle and wait for the charger to show it is connected, then try again.';
    final repository = FakeChargingRepository()
      ..startFailure = const AppFailure(
        kind: FailureKind.validation,
        code: 'charging_request_rejected',
        message: message,
        correlationId: 'plug-in-reference',
      );
    final fixture = await buildTestDependencies(
      signedIn: true,
      chargingRepository: repository,
    );
    await tester.pumpWidget(
      RepaintBoundary(
        key: const ValueKey('driver-preview'),
        child: VtsaApp(dependencies: fixture.dependencies),
      ),
    );
    await tester.pumpAndSettle();
    GoRouter.of(
      tester.element(find.text('Where would you like to charge?')),
    ).go('/charge?code=lumina-test');
    await tester.pumpAndSettle();
    expect(find.text('Ready to charge'), findsOneWidget);

    final start = find.text('Start charging');
    final alternative = find.text('Can’t swipe? Start with a button');
    await tester.scrollUntilVisible(
      alternative,
      200,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(alternative);
    await tester.pumpAndSettle();
    await tester.ensureVisible(start);
    await tester.tap(start);
    await tester.pumpAndSettle();

    final dialog = find.byType(AlertDialog);
    expect(dialog, findsOneWidget);
    expect(
      find.descendant(of: dialog, matching: find.text(message)),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: dialog,
        matching: find.text('Reference plug-in-reference'),
      ),
      findsOneWidget,
    );
    expect(repository.startCalls, 1);
    expect(repository.active, isNull);
    await preview(tester, 'charging-start-error-popup');

    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();
    expect(find.text(message), findsNothing);
    repository.startFailure = null;
    await tester.ensureVisible(start);
    await tester.tap(start);
    await tester.pumpAndSettle();
    expect(repository.startCalls, 2);
    expect(find.text('Waiting for physical start'), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets(
    'session clock ticks independently and uses confirmed final time',
    (tester) async {
      final session = sampleChargingSession(
        state: ChargingSessionState.charging,
        durationSeconds: 30,
      );
      final repository = FakeChargingRepository()
        ..sessions[session.id] = session;
      final fixture = await buildTestDependencies(
        signedIn: true,
        chargingRepository: repository,
      );
      final charging = fixture.dependencies.charging;
      await charging.recoverSession(session.id);
      final initialTime = session.startedAt!.add(const Duration(seconds: 30));
      var now = initialTime;
      await tester.pumpWidget(
        MaterialApp(
          theme: VtsaTheme.light(),
          home: LiveChargingScreen(
            dependencies: fixture.dependencies,
            sessionId: session.id,
            now: () => now,
          ),
        ),
      );
      try {
        await tester.pump();
        expect(find.text('00:00:30'), findsOneWidget);
        final cost = find.text('₱25.00');
        expect(cost, findsOneWidget);

        now = initialTime.add(const Duration(seconds: 1));
        await tester.pump(const Duration(seconds: 1));
        expect(find.text('00:00:31'), findsOneWidget);
        expect(cost, findsOneWidget);
        expect(charging.session!.durationSeconds, 30);

        repository.sessions[session.id] = sampleChargingSession(
          state: ChargingSessionState.stopping,
          durationSeconds: 30,
          aggregateVersion: 2,
        );
        await charging.refresh();
        now = initialTime.add(const Duration(seconds: 2));
        await tester.pump(const Duration(seconds: 1));
        expect(find.text('00:00:32'), findsOneWidget);

        repository.sessions[session.id] = sampleChargingSession(
          state: ChargingSessionState.stopping,
          durationSeconds: 31,
          aggregateVersion: 3,
          anomalyFlags: const ['charger_disconnected'],
        );
        await charging.refresh();
        await tester.pump();
        expect(find.text('00:00:31'), findsOneWidget);
        now = initialTime.add(const Duration(seconds: 4));
        await tester.pump(const Duration(seconds: 2));
        expect(find.text('00:00:31'), findsOneWidget);

        repository.sessions[session.id] = sampleChargingSession(
          state: ChargingSessionState.stopping,
          durationSeconds: 31,
          aggregateVersion: 4,
        );
        await charging.refresh();
        await tester.pump();
        expect(find.text('00:00:34'), findsOneWidget);
        now = initialTime.add(const Duration(seconds: 5));
        await tester.pump(const Duration(seconds: 1));
        expect(find.text('00:00:35'), findsOneWidget);

        repository.sessions[session.id] = sampleChargingSession(
          state: ChargingSessionState.completed,
          durationSeconds: 33,
          aggregateVersion: 5,
          paymentState: PaymentState.captured,
        );
        await charging.refresh();
        await tester.pump();
        expect(find.text('00:00:33'), findsOneWidget);
        now = initialTime.add(const Duration(seconds: 7));
        await tester.pump(const Duration(seconds: 3));
        expect(find.text('00:00:33'), findsOneWidget);
      } finally {
        await tester.pumpWidget(const SizedBox.shrink());
        fixture.dependencies.dispose();
      }
    },
  );
  testWidgets(
    'prepaid live metrics follow fresh meter polls beside the clock',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      const budget = Money(minorUnits: 5000, currency: 'PHP');
      final pending = sampleChargingSession(
        state: ChargingSessionState.charging,
        reservedBudget: budget,
        estimatedCost: null,
      );
      final repository = FakeChargingRepository()
        ..sessions[pending.id] = pending;
      final fixture = await buildTestDependencies(
        signedIn: true,
        chargingRepository: repository,
      );
      final charging = fixture.dependencies.charging;
      await charging.recoverSession(pending.id);
      var now = pending.startedAt!;
      try {
        await tester.pumpWidget(
          MaterialApp(
            theme: VtsaTheme.light(),
            home: LiveChargingScreen(
              dependencies: fixture.dependencies,
              sessionId: pending.id,
              now: () => now,
            ),
          ),
        );
        expect(
          find.text('Waiting for an energy reading from the charger.'),
          findsOneWidget,
        );
        expect(find.text('ESTIMATED COST'), findsOneWidget);
        expect(find.text('Pending'), findsOneWidget);

        final first = sampleChargingSession(
          state: ChargingSessionState.charging,
          energyWh: 123,
          energyReportedAt: now.add(const Duration(seconds: 1)),
          updatedAt: now.add(const Duration(seconds: 1)),
          reservedBudget: budget,
          estimatedCost: const Money(minorUnits: 250, currency: 'PHP'),
        );
        repository.sessions[pending.id] = first;
        now = now.add(const Duration(seconds: 2));
        await tester.pump(const Duration(seconds: 2));
        await tester.pump();
        expect(find.text('0.123 kWh'), findsOneWidget);
        expect(find.text('₱2.50'), findsOneWidget);
        expect(find.text('00:00:02'), findsOneWidget);
        expect(find.textContaining('Last energy reading'), findsOneWidget);

        repository.sessions[pending.id] = sampleChargingSession(
          state: ChargingSessionState.charging,
          energyWh: 124,
          energyReportedAt: now.add(const Duration(seconds: 1)),
          updatedAt: now.add(const Duration(seconds: 1)),
          reservedBudget: budget,
          estimatedCost: const Money(minorUnits: 252, currency: 'PHP'),
        );
        now = now.add(const Duration(seconds: 2));
        await tester.pump(const Duration(seconds: 2));
        await tester.pump();
        expect(find.text('0.124 kWh'), findsOneWidget);
        expect(find.text('₱2.52'), findsOneWidget);

        repository.sessions[pending.id] = first;
        now = now.add(const Duration(seconds: 2));
        await tester.pump(const Duration(seconds: 2));
        await tester.pump();
        expect(find.text('0.124 kWh'), findsOneWidget);
        expect(find.text('₱2.52'), findsOneWidget);
        expect(find.text('00:00:06'), findsOneWidget);
      } finally {
        await tester.pumpWidget(const SizedBox.shrink());
        fixture.dependencies.dispose();
      }
    },
  );
  for (final width in [320.0, 390.0]) {
    testWidgets('pending start can be cancelled before timeout at $width', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final repository = FakeChargingRepository()
        ..active = sampleChargingSession()
        ..cancelResult = sampleChargingSession(
          cancellationRequested: true,
          paymentState: PaymentState.requiresAction,
          aggregateVersion: 2,
        );
      final fixture = await buildTestDependencies(
        signedIn: true,
        chargingRepository: repository,
      );
      await fixture.dependencies.charging.recoverSession(repository.active!.id);
      await tester.pumpWidget(
        MaterialApp(
          theme: VtsaTheme.light(),
          home: RepaintBoundary(
            key: const ValueKey('driver-preview'),
            child: LiveChargingScreen(
              dependencies: fixture.dependencies,
              sessionId: repository.active!.id,
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Cancel pending start'), findsOneWidget);
      await tester.tap(find.text('Cancel pending start'));
      await tester.pumpAndSettle();
      expect(repository.cancelCalls, 0);
      await tester.ensureVisible(find.text('Cancel start request'));
      await tester.tap(find.text('Cancel start request'));
      await tester.pumpAndSettle();
      expect(repository.cancelCalls, 1);
      expect(find.text('Cancellation requested'), findsOneWidget);
      expect(find.text('Cancel pending start'), findsNothing);
      expect(fixture.dependencies.charging.hasUnfinishedSession, isTrue);
      expect(tester.takeException(), isNull);
      await preview(tester, 'start-cancellation-${width.toInt()}');
      await tester.pumpWidget(const SizedBox.shrink());
      fixture.dependencies.dispose();
    });
  }

  testWidgets('history failure is shown instead of claiming no sessions', (
    tester,
  ) async {
    final repository = FakeChargingRepository()
      ..historyFailure = const AppFailure(
        kind: FailureKind.server,
        message: 'History service unavailable.',
      );
    final fixture = await buildTestDependencies(
      signedIn: true,
      chargingRepository: repository,
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sessions'));
    await tester.pumpAndSettle();
    expect(find.text('History service unavailable.'), findsOneWidget);
    expect(find.byType(AlertDialog), findsOneWidget);
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();
    expect(find.text('No sessions in this view'), findsNothing);
    repository.historyFailure = null;
    await fixture.dependencies.charging.loadHistory();
    await tester.pumpAndSettle();
    expect(find.text('History service unavailable.'), findsNothing);
    expect(find.text('No sessions in this view'), findsOneWidget);
  });

  testWidgets(
    'unavailable issue submission shows recovery and preserves the reference',
    (tester) async {
      final repository = FakeChargingRepository()
        ..issueFailure = const AppFailure(
          kind: FailureKind.notFound,
          message: 'Not found',
        );
      final fixture = await buildTestDependencies(
        signedIn: true,
        chargingRepository: repository,
      );
      await tester.pumpWidget(
        MaterialApp(
          theme: VtsaTheme.light(),
          home: IssueReportScreen(
            dependencies: fixture.dependencies,
            sessionId: 'session-for-support',
          ),
        ),
      );
      await tester.enterText(
        find.byType(TextField),
        'The charger needs operator assistance.',
      );
      await tester.scrollUntilVisible(
        find.text('Submit issue'),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('Submit issue'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Your issue has not been submitted.'),
        findsOneWidget,
      );
      expect(find.text('Copy session reference'), findsOneWidget);
      expect(find.text('Issue submitted'), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
      fixture.dependencies.dispose();
    },
  );

  testWidgets('driver sees camera rationale and can use manual entry', (
    tester,
  ) async {
    final fixture = await buildTestDependencies(signedIn: true);
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Scan charger code'));
    await tester.pumpAndSettle();

    expect(find.text('Scan the charger QR code'), findsOneWidget);
    expect(find.textContaining('Camera video is not stored'), findsOneWidget);
    expect(find.text('Charger code (required)'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'VSTA-ABC_123');
    await tester.scrollUntilVisible(
      find.text('Validate connector'),
      180,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Validate connector'));
    await tester.pumpAndSettle();

    expect(find.text('Ready to charge'), findsOneWidget);
    expect(find.text('Standard charging'), findsOneWidget);
    expect(find.text('Budget limit'), findsOneWidget);
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
    await tester.scrollUntilVisible(
      find.text('Validate connector'),
      180,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Validate connector'));
    await tester.pumpAndSettle();

    await tester.scrollUntilVisible(find.byType(CheckboxListTile), 240);
    expect(find.byType(CheckboxListTile), findsOneWidget);
    expect(find.textContaining('does not list CCS2'), findsOneWidget);

    expect(fixture.charging.startCalls, 0);
    await tester.ensureVisible(find.byType(CheckboxListTile));
    await tester.tap(find.byType(CheckboxListTile));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Can’t swipe? Start with a button'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Start charging'));
    await tester.pumpAndSettle();
    expect(find.text('Waiting for physical start'), findsOneWidget);
    expect(find.textContaining('charging is not active until'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('Charging timeline & vehicle'),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Vehicle battery'), findsNothing);
    expect(find.text('Not reported by charger'), findsNothing);
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
        batteryPercentage: 64,
        batteryReportedAt: DateTime.now(),
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
    expect(find.text('6.420 kWh'), findsOneWidget);
    expect(find.text('SESSION TIME'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('Charging timeline & vehicle'),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Started'), findsOneWidget);
    expect(find.text('Not ended'), findsOneWidget);
    expect(find.text('Vehicle battery'), findsOneWidget);
    expect(find.text('64%'), findsOneWidget);
    expect(find.text('48.8 kW'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('End charging'),
      -300,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('End charging'), findsOneWidget);
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

      expect(find.text('Finalizing wallet payment'), findsOneWidget);
      expect(find.textContaining('deducting the charge once'), findsOneWidget);
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
      batteryPercentage: 80,
      batteryReportedAt: DateTime.utc(2026, 7, 26, 3, 30),
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

    await tester.tap(find.text('Sessions'));
    await tester.pumpAndSettle();
    expect(find.textContaining('12.00 kWh'), findsOneWidget);

    await tester.tap(find.text('Station A'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('Charging timeline'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Start confirmed'), findsOneWidget);
    expect(find.text('End confirmed'), findsOneWidget);
    expect(find.text('Last charger-reported battery'), findsOneWidget);
    expect(find.text('80%'), findsOneWidget);
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
