import 'package:flutter/foundation.dart';

enum MapProviderKind {
  openstreetmap,
  google;

  static MapProviderKind parse(String value) =>
      MapProviderKind.values
          .where((item) => item.name == value.trim())
          .firstOrNull ??
      MapProviderKind.openstreetmap;
}

@immutable
final class MapConfiguration {
  const MapConfiguration({
    required this.requestedProvider,
    required this.provider,
    required this.defaultLatitude,
    required this.defaultLongitude,
    required this.defaultZoom,
    required this.minimumZoom,
    required this.maximumZoom,
    required this.tileUrlTemplate,
    required this.tileAttribution,
    required this.tileMaximumNativeZoom,
    required this.clusteringEnabled,
    required this.googleReady,
    this.providerWasExplicit = false,
    this.fallbackActive = false,
    this.status = 'ready',
  });

  factory MapConfiguration.fromDefines() {
    const configuredProvider = String.fromEnvironment('MAP_PROVIDER');
    const googleKey = String.fromEnvironment('GOOGLE_MAPS_API_KEY');
    const googleReadyDefine = bool.fromEnvironment('GOOGLE_MAPS_READY');
    final googleReady = googleReadyDefine || googleKey.isNotEmpty;
    final requested = MapProviderKind.parse(
      configuredProvider.isEmpty ? 'openstreetmap' : configuredProvider,
    );
    final resolved = requested == MapProviderKind.google && !googleReady
        ? MapProviderKind.openstreetmap
        : requested;

    return MapConfiguration(
      requestedProvider: requested,
      provider: resolved,
      providerWasExplicit: configuredProvider.isNotEmpty,
      fallbackActive: resolved != requested,
      status: resolved != requested ? 'google_not_configured' : 'ready',
      defaultLatitude:
          double.tryParse(
            const String.fromEnvironment(
              'MAP_DEFAULT_LATITUDE',
              defaultValue: '14.5995',
            ),
          ) ??
          14.5995,
      defaultLongitude:
          double.tryParse(
            const String.fromEnvironment(
              'MAP_DEFAULT_LONGITUDE',
              defaultValue: '120.9842',
            ),
          ) ??
          120.9842,
      defaultZoom:
          double.tryParse(
            const String.fromEnvironment(
              'MAP_DEFAULT_ZOOM',
              defaultValue: '11',
            ),
          ) ??
          11,
      minimumZoom:
          double.tryParse(
            const String.fromEnvironment('MAP_MIN_ZOOM', defaultValue: '3'),
          ) ??
          3,
      maximumZoom:
          double.tryParse(
            const String.fromEnvironment('MAP_MAX_ZOOM', defaultValue: '19'),
          ) ??
          19,
      tileUrlTemplate: const String.fromEnvironment(
        'MAP_TILE_URL_TEMPLATE',
        defaultValue: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
      ),
      tileAttribution: const String.fromEnvironment(
        'MAP_TILE_ATTRIBUTION',
        defaultValue: '© OpenStreetMap contributors',
      ),
      tileMaximumNativeZoom: const int.fromEnvironment(
        'MAP_TILE_MAX_NATIVE_ZOOM',
        defaultValue: 19,
      ),
      clusteringEnabled: const bool.fromEnvironment(
        'MAP_MARKER_CLUSTERING',
        defaultValue: true,
      ),
      googleReady: googleReady,
    );
  }

  factory MapConfiguration.fromRemote(
    Map<String, dynamic> payload,
    MapConfiguration local,
  ) {
    final remoteProvider = MapProviderKind.parse(
      payload['provider'] as String? ?? 'openstreetmap',
    );
    final requested = local.providerWasExplicit
        ? local.requestedProvider
        : remoteProvider;
    final provider = requested == MapProviderKind.google && !local.googleReady
        ? MapProviderKind.openstreetmap
        : requested;

    return MapConfiguration(
      requestedProvider: requested,
      provider: provider,
      providerWasExplicit: local.providerWasExplicit,
      fallbackActive: provider != requested,
      status: provider != requested ? 'google_not_configured' : 'ready',
      defaultLatitude: _double(
        payload['defaultLatitude'],
        local.defaultLatitude,
      ),
      defaultLongitude: _double(
        payload['defaultLongitude'],
        local.defaultLongitude,
      ),
      defaultZoom: _double(payload['defaultZoom'], local.defaultZoom),
      minimumZoom: _double(payload['minimumZoom'], local.minimumZoom),
      maximumZoom: _double(payload['maximumZoom'], local.maximumZoom),
      tileUrlTemplate:
          payload['tileUrlTemplate'] as String? ?? local.tileUrlTemplate,
      tileAttribution:
          payload['tileAttribution'] as String? ?? local.tileAttribution,
      tileMaximumNativeZoom:
          (payload['tileMaximumNativeZoom'] as num?)?.toInt() ??
          local.tileMaximumNativeZoom,
      clusteringEnabled:
          payload['clusteringEnabled'] as bool? ?? local.clusteringEnabled,
      googleReady: local.googleReady,
    );
  }

  final MapProviderKind requestedProvider;
  final MapProviderKind provider;
  final bool providerWasExplicit;
  final bool fallbackActive;
  final String status;
  final double defaultLatitude;
  final double defaultLongitude;
  final double defaultZoom;
  final double minimumZoom;
  final double maximumZoom;
  final String tileUrlTemplate;
  final String tileAttribution;
  final int tileMaximumNativeZoom;
  final bool clusteringEnabled;
  final bool googleReady;

  bool get tileConfigurationValid =>
      Uri.tryParse(tileUrlTemplate)?.scheme == 'https' &&
      tileUrlTemplate.contains('{z}') &&
      tileUrlTemplate.contains('{x}') &&
      tileUrlTemplate.contains('{y}') &&
      tileAttribution.trim().isNotEmpty;

  MapConfiguration withStatus(String nextStatus) => MapConfiguration(
    requestedProvider: requestedProvider,
    provider: provider,
    providerWasExplicit: providerWasExplicit,
    fallbackActive: fallbackActive,
    status: nextStatus,
    defaultLatitude: defaultLatitude,
    defaultLongitude: defaultLongitude,
    defaultZoom: defaultZoom,
    minimumZoom: minimumZoom,
    maximumZoom: maximumZoom,
    tileUrlTemplate: tileUrlTemplate,
    tileAttribution: tileAttribution,
    tileMaximumNativeZoom: tileMaximumNativeZoom,
    clusteringEnabled: clusteringEnabled,
    googleReady: googleReady,
  );

  static double _double(Object? value, double fallback) =>
      value is num ? value.toDouble() : fallback;
}
