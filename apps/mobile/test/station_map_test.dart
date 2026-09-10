import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/maps/map_configuration.dart';
import 'package:vtsa_mobile/features/discovery/application/discovery_controller.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_map.dart';

import 'map_configuration_test.dart' show configuration;
import 'support/fakes.dart';

void main() {
  testWidgets('provider factory builds only OpenStreetMap by default', (
    tester,
  ) async {
    final discovery = DiscoveryController(FakeStationRepository());

    await tester.pumpWidget(
      MaterialApp(
        home: StationMap(
          discovery: discovery,
          configuration: configuration(),
          onProviderFailure: () {},
          openStreetMapBuilder: (_, _, _, _) => const Text('osm-adapter'),
          googleMapsBuilder: (_, _, _, _) => const Text('google-adapter'),
        ),
      ),
    );

    expect(find.text('osm-adapter'), findsOneWidget);
    expect(find.text('google-adapter'), findsNothing);
    discovery.dispose();
  });

  testWidgets('ready Google selection builds only the Google adapter', (
    tester,
  ) async {
    final discovery = DiscoveryController(FakeStationRepository());

    await tester.pumpWidget(
      MaterialApp(
        home: StationMap(
          discovery: discovery,
          configuration: configuration(
            requested: MapProviderKind.google,
            provider: MapProviderKind.google,
          ),
          onProviderFailure: () {},
          openStreetMapBuilder: (_, _, _, _) => const Text('osm-adapter'),
          googleMapsBuilder: (_, _, _, _) => const Text('google-adapter'),
        ),
      ),
    );

    expect(find.text('google-adapter'), findsOneWidget);
    expect(find.text('osm-adapter'), findsNothing);
    discovery.dispose();
  });

  testWidgets('invalid tile configuration keeps list fallback available', (
    tester,
  ) async {
    final discovery = DiscoveryController(FakeStationRepository());
    var fallbackRequested = false;
    final invalid = MapConfiguration(
      requestedProvider: MapProviderKind.openstreetmap,
      provider: MapProviderKind.openstreetmap,
      defaultLatitude: 14.5995,
      defaultLongitude: 120.9842,
      defaultZoom: 11,
      minimumZoom: 3,
      maximumZoom: 19,
      tileUrlTemplate: 'javascript:invalid',
      tileAttribution: '',
      tileMaximumNativeZoom: 19,
      clusteringEnabled: true,
      googleReady: false,
    );

    await tester.pumpWidget(
      MaterialApp(
        home: StationMap(
          discovery: discovery,
          configuration: invalid,
          onProviderFailure: () => fallbackRequested = true,
        ),
      ),
    );
    await tester.tap(find.text('View as list'));

    expect(fallbackRequested, isTrue);
    expect(find.text('Map configuration unavailable'), findsOneWidget);
    discovery.dispose();
  });
}
