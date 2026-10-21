import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';

import 'support/fakes.dart';
import 'support/test_fonts.dart';

const availableStation = Station(
  id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0Q',
  siteId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0R',
  name: 'Harbor Exchange',
  siteName: 'Harbor Exchange',
  address: '1 Current Street',
  latitude: 14.58,
  longitude: 120.98,
  timezone: 'Asia/Manila',
  siteType: 'retail',
  operatorId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0S',
  operatorName: 'Northline Charging',
  availability: StationAvailability.available,
  connectionStatus: 'online',
  isStale: false,
  statusObservedAt: null,
  maximumPowerW: 7000,
  openNow: true,
  hoursKnown: true,
  connectors: [
    StationConnector(
      id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0T',
      standard: 'Type 2',
      name: 'Port 01',
      maximumPowerW: 7000,
      availability: 'available',
    ),
    StationConnector(
      id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0U',
      standard: 'CCS2',
      name: 'Port 02',
      maximumPowerW: 50000,
      availability: 'occupied',
      reportedStatus: 'charging',
    ),
  ],
);

Future<void> preview(WidgetTester tester, String name) async {
  if (!const bool.fromEnvironment('DRIVER_PREVIEWS')) return;
  await tester.runAsync(() async {
    for (final image in tester.widgetList<Image>(find.byType(Image))) {
      await precacheImage(
        image.image,
        tester.element(find.byType(Image).first),
      );
    }
  });
  await tester.pumpAndSettle();
  final boundary = tester.renderObject<RenderRepaintBoundary>(
    find.byKey(const ValueKey('driver-preview')),
  );
  await tester.runAsync(() async {
    final image = await boundary.toImage();
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    final folder = Directory('../../.cache/driver-design-preview')
      ..createSync(recursive: true);
    File(
      '${folder.path}/$name.png',
    ).writeAsBytesSync(bytes!.buffer.asUint8List());
    image.dispose();
  });
}

void main() {
  setUpAll(loadTestFonts);

  for (final (width, scale) in [(320.0, 1.0), (390.0, 1.0), (320.0, 1.6)]) {
    testWidgets(
      'driver journey selects a plug and reviews real controller pricing at $width / $scale',
      (tester) async {
        tester.view.physicalSize = Size(width, 844);
        tester.view.devicePixelRatio = 1;
        tester.platformDispatcher.textScaleFactorTestValue = scale;
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final fixture = await buildTestDependencies(
          signedIn: true,
          stations: [availableStation],
        );
        await tester.pumpWidget(
          RepaintBoundary(
            key: const ValueKey('driver-preview'),
            child: VtsaApp(dependencies: fixture.dependencies),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Where would you like to charge?'), findsOneWidget);
        await preview(tester, 'explore-${width.toInt()}-$scale');
        await tester.enterText(find.byType(TextField), 'not-a-station');
        await tester.pumpAndSettle();
        expect(find.text('No chargers in this view'), findsOneWidget);
        await tester.enterText(find.byType(TextField), 'Harbor');
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.text('Harbor Exchange').first);
        await tester.pumpAndSettle();
        await tester.tap(find.text('Harbor Exchange').first);
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.text('CCS2 · Port 02'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('CCS2 · Port 02'));
        await tester.pumpAndSettle();
        expect(find.text('Choose a plug to continue'), findsOneWidget);
        await tester.ensureVisible(find.text('Type 2 · Port 01'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Type 2 · Port 01'));
        await tester.pumpAndSettle();
        await preview(tester, 'station-${width.toInt()}-$scale');
        await tester.ensureVisible(find.text('Continue with Type 2'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Continue with Type 2'));
        await tester.pumpAndSettle();
        expect(find.text('You’re nearly ready'), findsOneWidget);
        expect(fixture.charging.prepareCalls, 0);
        expect(fixture.charging.startCalls, 0);
        await preview(tester, 'ready-${width.toInt()}-$scale');
        await tester.scrollUntilVisible(
          find.text('Review charging estimate'),
          200,
          scrollable: find.byType(Scrollable).first,
        );
        await tester.ensureVisible(find.text('Review charging estimate'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Review charging estimate'));
        await tester.pumpAndSettle();
        expect(fixture.charging.prepareCalls, 1);
        expect(
          fixture.charging.lastPreparedCode,
          availableStation.connectors.first.id,
        );
        expect(fixture.charging.startCalls, 0);
        expect(
          fixture.dependencies.charging.preparation!.quote.name,
          'Standard charging',
        );
        await tester.scrollUntilVisible(
          find.text('Review and request start'),
          200,
          scrollable: find.byType(Scrollable).first,
        );
        await tester.ensureVisible(find.text('Review and request start'));
        await tester.pumpAndSettle();
        await tester.pumpAndSettle();
        await tester.tap(find.text('Review and request start'));
        await tester.pumpAndSettle();
        expect(find.text('Request start'), findsOneWidget);
        await tester.tap(find.text('Request start'));
        await tester.pumpAndSettle();
        expect(fixture.charging.startCalls, 1);
        expect(find.text('Waiting for physical start'), findsOneWidget);
        expect(find.text('You’re charging'), findsNothing);
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(const SizedBox.shrink());
      },
    );
  }

  testWidgets('active session confirms stop and keeps pending state honest', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final repository = FakeChargingRepository()
      ..active = sampleChargingSession(
        state: ChargingSessionState.charging,
        energyWh: 6420,
        durationSeconds: 754,
        currentPowerW: 7000,
      );
    repository.sessions[repository.active!.id] = repository.active!;
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
    await tester.tap(find.text('6.42 kWh · Charging'));
    await tester.pumpAndSettle();
    expect(find.text('6.42 kWh'), findsOneWidget);
    await preview(tester, 'active-390');
    await tester.scrollUntilVisible(
      find.text('End charging'),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('End charging'));
    await tester.pumpAndSettle();
    expect(repository.stopCalls, 0);
    await tester.tap(find.text('Request stop'));
    await tester.pumpAndSettle();
    expect(repository.stopCalls, 1);
    expect(find.text('Stop requested'), findsOneWidget);
    expect(find.text('Session complete'), findsNothing);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
