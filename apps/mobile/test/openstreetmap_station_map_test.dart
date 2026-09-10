import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/features/discovery/application/discovery_controller.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/presentation/openstreetmap_station_map.dart';

import 'map_configuration_test.dart' show configuration;
import 'support/fakes.dart';

void main() {
  testWidgets(
    'OpenStreetMap renders attribution and selectable station marker',
    (tester) async {
      final discovery = DiscoveryController(FakeStationRepository());
      await discovery.loadBounds(
        const GeoBounds(west: 120.8, south: 14.4, east: 121.2, north: 14.8),
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SizedBox(
              width: 800,
              height: 600,
              child: OpenStreetMapStationMap(
                discovery: discovery,
                configuration: configuration(),
                userPosition: const MapPosition(14.61, 121.01),
                tileProvider: _TransparentTileProvider(),
              ),
            ),
          ),
        ),
      );
      await tester.pump();

      expect(find.text('© OpenStreetMap contributors'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('openstreetmap-user-position')),
        findsOneWidget,
      );
      final marker = find.byKey(
        ValueKey('openstreetmap-marker-${sampleStation.id}'),
      );
      expect(marker, findsOneWidget);
      await tester.tap(marker);
      await tester.pump();
      expect(discovery.selectedStationId, sampleStation.id);

      await tester.pumpWidget(const SizedBox.shrink());
      discovery.dispose();
    },
  );
}

final class _TransparentTileProvider extends TileProvider {
  @override
  ImageProvider getImage(TileCoordinates coordinates, TileLayer options) =>
      MemoryImage(TileProvider.transparentImage);
}
