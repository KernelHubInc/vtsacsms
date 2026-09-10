import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/maps/map_configuration.dart';

void main() {
  test('unknown provider identifier resolves to OpenStreetMap', () {
    expect(MapProviderKind.parse('unsupported'), MapProviderKind.openstreetmap);
  });

  test('remote provider is used when no build-time override exists', () {
    final resolved = MapConfiguration.fromRemote(const {
      'provider': 'google',
      'defaultLatitude': 14.61,
      'defaultLongitude': 121.01,
    }, configuration());

    expect(resolved.requestedProvider, MapProviderKind.google);
    expect(resolved.provider, MapProviderKind.google);
    expect(resolved.defaultLatitude, 14.61);
    expect(resolved.defaultLongitude, 121.01);
  });

  test('explicit Google selection without readiness falls back safely', () {
    final local = configuration(
      requested: MapProviderKind.google,
      provider: MapProviderKind.openstreetmap,
      explicit: true,
      googleReady: false,
    );
    final resolved = MapConfiguration.fromRemote(const {
      'provider': 'openstreetmap',
    }, local);

    expect(resolved.requestedProvider, MapProviderKind.google);
    expect(resolved.provider, MapProviderKind.openstreetmap);
    expect(resolved.fallbackActive, isTrue);
    expect(resolved.status, 'google_not_configured');
  });
}

MapConfiguration configuration({
  MapProviderKind requested = MapProviderKind.openstreetmap,
  MapProviderKind provider = MapProviderKind.openstreetmap,
  bool explicit = false,
  bool googleReady = true,
}) => MapConfiguration(
  requestedProvider: requested,
  provider: provider,
  providerWasExplicit: explicit,
  defaultLatitude: 14.5995,
  defaultLongitude: 120.9842,
  defaultZoom: 11,
  minimumZoom: 3,
  maximumZoom: 19,
  tileUrlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  tileAttribution: '© OpenStreetMap contributors',
  tileMaximumNativeZoom: 19,
  clusteringEnabled: true,
  googleReady: googleReady,
);
