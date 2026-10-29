import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/charging/presentation/vehicle_battery_progress.dart';

import 'driver_journey_design_test.dart' show preview;
import 'support/test_fonts.dart';

void main() {
  setUpAll(loadTestFonts);
  final instant = DateTime.utc(2026, 10, 29, 8);

  Future<void> mount(
    WidgetTester tester, {
    int? percentage = 64,
    DateTime? reportedAt,
    bool connected = true,
    bool ended = false,
    DateTime Function()? now,
    bool dark = false,
    double scale = 1,
  }) => tester.pumpWidget(
    RepaintBoundary(
      key: const ValueKey('driver-preview'),
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: dark ? VtsaTheme.dark() : VtsaTheme.light(),
        home: MediaQuery(
          data: MediaQueryData(textScaler: TextScaler.linear(scale)),
          child: Scaffold(
            body: SingleChildScrollView(
              child: VehicleBatteryProgress(
                percentage: percentage,
                reportedAt: reportedAt,
                connected: connected,
                sessionEnded: ended,
                now: now ?? () => instant,
              ),
            ),
          ),
        ),
      ),
    ),
  );

  testWidgets('reported percentage drives the ring and accessible value', (
    tester,
  ) async {
    final semantics = tester.ensureSemantics();
    await mount(tester, reportedAt: instant);
    expect(find.text('64%'), findsOneWidget);
    expect(find.text('Latest battery reading'), findsOneWidget);
    expect(
      tester
          .widget<CircularProgressIndicator>(
            find.byType(CircularProgressIndicator),
          )
          .value,
      .64,
    );
    expect(find.bySemanticsLabel('Vehicle battery'), findsOneWidget);
    expect(
      tester.getSemantics(find.bySemanticsLabel('Vehicle battery')).value,
      '64 percent. Latest battery reading',
    );
    await mount(tester, percentage: 82, reportedAt: instant);
    expect(find.text('82%'), findsOneWidget);
    expect(find.text('64%'), findsNothing);
    semantics.dispose();
  });

  for (final percentage in [null, -1, 101]) {
    testWidgets('missing or invalid percentage $percentage stays unknown', (
      tester,
    ) async {
      await mount(tester, percentage: percentage, reportedAt: instant);
      expect(find.text('Not reported by charger'), findsOneWidget);
      expect(find.text('—'), findsOneWidget);
      expect(find.text('0%'), findsNothing);
      expect(
        tester
            .widget<CircularProgressIndicator>(
              find.byType(CircularProgressIndicator),
            )
            .value,
        0,
      );
      await tester.pumpAndSettle();
    });
  }

  for (final percentage in [0, 100]) {
    testWidgets(
      'valid endpoint $percentage is displayed without ending session',
      (tester) async {
        await mount(tester, percentage: percentage, reportedAt: instant);
        expect(find.text('$percentage%'), findsOneWidget);
        expect(find.text('Latest battery reading'), findsOneWidget);
        expect(find.text('Session complete'), findsNothing);
      },
    );
  }

  testWidgets('reading becomes last known without another server update', (
    tester,
  ) async {
    var now = instant;
    await mount(tester, reportedAt: instant, now: () => now);
    expect(find.text('Latest battery reading'), findsOneWidget);
    now = instant.add(const Duration(minutes: 6));
    await tester.pump(const Duration(seconds: 30));
    expect(find.text('Last known battery level'), findsOneWidget);
    expect(find.text('64%'), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(minutes: 1));
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'offline, missing timestamps and future timestamps are not live',
    (tester) async {
      await mount(tester, reportedAt: instant, connected: false);
      expect(find.text('Last known battery level'), findsOneWidget);
      await mount(tester);
      expect(find.text('Last known battery level'), findsOneWidget);
      await mount(tester, reportedAt: instant.add(const Duration(minutes: 1)));
      expect(find.text('Last known battery level'), findsOneWidget);
      await mount(tester, reportedAt: instant, ended: true);
      expect(find.text('Last reported battery level'), findsOneWidget);
    },
  );

  for (final dark in [false, true]) {
    for (final (width, scale) in [(320.0, 2.0), (390.0, 1.0), (900.0, 1.0)]) {
      testWidgets(
        'battery card fits width $width with dark=$dark and large text',
        (tester) async {
          tester.view.physicalSize = Size(width, 900);
          tester.view.devicePixelRatio = 1;
          addTearDown(tester.view.resetPhysicalSize);
          addTearDown(tester.view.resetDevicePixelRatio);
          await mount(tester, reportedAt: instant, dark: dark, scale: scale);
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          await preview(tester, 'battery-${width.toInt()}-$dark');
        },
      );
    }
  }
}
