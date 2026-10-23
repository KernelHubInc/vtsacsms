import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/maps/device_location_service.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/discovery/presentation/discovery_screen.dart';
import 'package:vtsa_mobile/features/discovery/presentation/openstreetmap_station_map.dart';
import 'package:vtsa_mobile/features/wallet/wallet_screen.dart';

import 'driver_journey_design_test.dart' show preview;
import 'map_configuration_test.dart' show configuration;
import 'support/fakes.dart';
import 'support/test_fonts.dart';
import 'wallet_test.dart' show TestWallet;

void main() {
  setUpAll(loadTestFonts);

  for (final (width, scale) in [(390.0, 1.0), (320.0, 1.6)]) {
    testWidgets(
      'branded tabs preserve profile, history filters and wallet at $width / $scale',
      (tester) async {
        tester.view.physicalSize = Size(width, 844);
        tester.view.devicePixelRatio = 1;
        tester.platformDispatcher.textScaleFactorTestValue = scale;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
        final repository = FakeChargingRepository()
          ..historyResult = ChargingHistoryPage(
            sessions: [
              sampleChargingSession(
                id: 'completed',
                state: ChargingSessionState.completed,
                energyWh: 12000,
                finalCost: const Money(minorUnits: 1200, currency: 'PHP'),
              ),
              sampleChargingSession(
                id: 'pending',
                state: ChargingSessionState.starting,
                energyWh: 1000,
              ),
            ],
            nextCursor: null,
          );
        repository.active = repository.historyResult.sessions.first;
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
        expect(find.byType(PowerSolutionsLogo), findsOneWidget);
        await tester.tap(find.text('Account'));
        await tester.pumpAndSettle();
        expect(find.text('Ada Driver'), findsOneWidget);
        await preview(tester, 'account-${width.toInt()}-$scale');
        await tester.ensureVisible(find.text('View profile'));
        await tester.tap(find.text('View profile'));
        await tester.pumpAndSettle();
        expect(find.text('Profile'), findsOneWidget);
        await tester.pageBack();
        await tester.pumpAndSettle();
        await tester.tap(find.text('Activity').last);
        await tester.pumpAndSettle();
        await preview(tester, 'activity-${width.toInt()}-$scale');
        await tester.tap(find.widgetWithText(ChoiceChip, 'Completed'));
        await tester.pumpAndSettle();
        expect(find.textContaining('12.00 kWh'), findsOneWidget);
        expect(find.textContaining('1.00 kWh'), findsNothing);
        await tester.tap(find.widgetWithText(ChoiceChip, 'Pending'));
        await tester.pumpAndSettle();
        expect(find.textContaining('1.00 kWh'), findsOneWidget);
        expect(find.textContaining('12.00 kWh'), findsNothing);
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(const SizedBox.shrink());

        final wallet = TestWallet()
          ..book = 'live'
          ..paid = true;
        await tester.pumpWidget(
          RepaintBoundary(
            key: const ValueKey('driver-preview'),
            child: MaterialApp(
              debugShowCheckedModeBanner: false,
              theme: VtsaTheme.light(),
              home: WalletScreen(repository: wallet, allowRealPayments: true),
            ),
          ),
        );
        await tester.pumpAndSettle();
        await preview(tester, 'wallet-${width.toInt()}-$scale');
        await tester.ensureVisible(find.text('Add money'));
        await tester.tap(find.text('Add money'));
        await tester.pumpAndSettle();
        expect(find.byType(AlertDialog), findsOneWidget);
        expect(wallet.keys, isEmpty);
        Navigator.of(tester.element(find.byType(AlertDialog))).pop();
        await tester.pumpAndSettle();
        await tester.scrollUntilVisible(
          find.text('Transactions'),
          150,
          scrollable: find.byType(Scrollable).first,
        );
        await tester.tap(find.text('Transactions'));
        await tester.pumpAndSettle();
        expect(find.text('Balance activity'), findsOneWidget);
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(const SizedBox.shrink());
      },
    );
  }

  testWidgets(
    'map expands without resetting, GPS recenters repeatedly and denial is recoverable',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final location = _Location();
      final fixture = await buildTestDependencies(
        signedIn: true,
        maps: configuration(),
        location: location,
      );
      await tester.pumpWidget(
        RepaintBoundary(
          key: const ValueKey('driver-preview'),
          child: MaterialApp(
            debugShowCheckedModeBanner: false,
            theme: VtsaTheme.light(),
            home: Builder(
              builder: (context) => Theme(
                data: DriverTheme.of(context),
                child: DiscoveryScreen(
                  dependencies: fixture.dependencies,
                  mapBuilder: (discovery, config, position, failure) =>
                      OpenStreetMapStationMap(
                        discovery: discovery,
                        configuration: config,
                        userPosition: position,
                        onProviderFailure: failure,
                        tileProvider: _Tiles(),
                      ),
                ),
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(
        tester.getSize(find.byType(FlutterMap)).height,
        greaterThanOrEqualTo(300),
      );
      expect(location.calls, 1);
      var map = tester.widget<FlutterMap>(find.byType(FlutterMap));
      expect(map.mapController!.camera.center.latitude, closeTo(14.61, .001));
      expect(map.mapController!.camera.center.longitude, closeTo(121.01, .001));
      await preview(tester, 'explore-map-390');
      final mapState = tester.state(find.byType(OpenStreetMapStationMap));
      await tester.ensureVisible(find.byTooltip('Expand map'));
      await tester.tap(find.byTooltip('Expand map'));
      await tester.pumpAndSettle();
      expect(tester.getSize(find.byType(FlutterMap)).height, greaterThan(600));
      expect(
        tester.state(find.byType(OpenStreetMapStationMap)),
        same(mapState),
      );
      await tester.tap(find.byTooltip('Show my location'));
      await tester.pumpAndSettle();
      expect(location.calls, 2);
      map = tester.widget<FlutterMap>(find.byType(FlutterMap));
      expect(map.mapController!.camera.center.latitude, closeTo(14.61, .001));
      await tester.drag(find.byType(FlutterMap), const Offset(90, 80));
      await tester.pumpAndSettle();
      await tester.tap(find.byTooltip('Show my location'));
      await tester.pumpAndSettle();
      map = tester.widget<FlutterMap>(find.byType(FlutterMap));
      expect(map.mapController!.camera.center.latitude, closeTo(14.61, .001));
      expect(map.options.interactionOptions.flags & InteractiveFlag.rotate, 0);
      await preview(tester, 'expanded-map-390');
      location.allowed = false;
      await tester.tap(find.byTooltip('Show my location'));
      await tester.pumpAndSettle();
      expect(
        find.text('Location is unavailable or permission was not granted.'),
        findsOneWidget,
      );
      await tester.tap(find.byTooltip('Close expanded map'));
      await tester.pumpAndSettle();
      expect(find.byTooltip('Expand map'), findsOneWidget);
      expect(
        tester.state(find.byType(OpenStreetMapStationMap)),
        same(mapState),
      );
      await tester.tap(find.byTooltip('Expand map'));
      await tester.pumpAndSettle();
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.byTooltip('Expand map'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );
}

class _Location implements DeviceLocationService {
  int calls = 0;
  bool allowed = true;
  @override
  Future<MapPosition?> currentPosition() async {
    calls++;
    return allowed ? const MapPosition(14.61, 121.01) : null;
  }
}

class _Tiles extends TileProvider {
  @override
  ImageProvider getImage(TileCoordinates coordinates, TileLayer options) =>
      MemoryImage(TileProvider.transparentImage);
}
