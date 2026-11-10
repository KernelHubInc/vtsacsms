import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_budget_progress.dart';
import 'package:vtsa_mobile/features/charging/presentation/live_charging_screen.dart';
import 'driver_journey_design_test.dart' show preview;
import 'support/fakes.dart';
import 'support/test_fonts.dart';

void main() {
  setUpAll(loadTestFonts);
  for (final (width, scale) in [(390.0, 1.0), (320.0, 1.6), (1440.0, 1.0)]) {
    testWidgets('live budget uses server amounts at $width / $scale', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.textScaleFactorTestValue = scale;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      final repository = FakeChargingRepository()
        ..active = sampleChargingSession(
          state: ChargingSessionState.charging,
          reservedBudget: const Money(minorUnits: 100000, currency: 'PHP'),
          energyWh: 6420,
          durationSeconds: 32,
          currentPowerW: 7000,
        );
      final fixture = await buildTestDependencies(
        signedIn: true,
        chargingRepository: repository,
      );
      await fixture.dependencies.charging.recoverSession(repository.active!.id);
      await tester.pumpWidget(
        RepaintBoundary(
          key: const ValueKey('driver-preview'),
          child: MaterialApp(
            debugShowCheckedModeBanner: false,
            theme: VtsaTheme.light(),
            home: LiveChargingScreen(
              dependencies: fixture.dependencies,
              sessionId: repository.active!.id,
              now: () => repository.active!.startedAt!.add(
                const Duration(seconds: 32),
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('₱25.00'), findsOneWidget);
      expect(find.text('of ₱1,000.00 budget'), findsOneWidget);
      await preview(tester, 'charging-budget-$width-$scale');
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
      fixture.dependencies.dispose();
    });
  }

  testWidgets(
    'missing readings are not presented as zero spend or a paid receipt',
    (tester) async {
      final session = ChargingSession.fromJson({
        'id': 'session-test',
        'state': 'charging',
        'site_id': 'station-test',
        'connector_id': 'connector-test',
        'energy_wh': 0,
        'duration_seconds': 0,
        'requested_at': '2026-11-09T00:00:00Z',
        'updated_at': '2026-11-09T00:00:00Z',
        'aggregate_version': 1,
        'currency': 'PHP',
        'reserved_budget_minor': 100000,
        'payment_state': 'capture_pending',
      });
      await tester.pumpWidget(
        MaterialApp(
          theme: VtsaTheme.light(),
          home: Scaffold(
            body: SingleChildScrollView(
              child: Column(
                children: [
                  ChargingBudgetProgress(session: session),
                  ChargingReceiptSummary(session: session),
                ],
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Awaiting readings'), findsOneWidget);
      expect(find.text('Payment pending'), findsOneWidget);
      expect(find.text('Paid'), findsNothing);
      expect(find.text('Final amount pending'), findsOneWidget);
      expect(find.text('₱0.00'), findsNothing);
    },
  );
}
