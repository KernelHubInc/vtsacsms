import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/core/maps/map_configuration.dart';

enum AppEnvironmentName { local, staging, production }

@immutable
final class AppEnvironment {
  const AppEnvironment({
    required this.name,
    required this.apiBaseUrl,
    required this.defaultTenantId,
    required this.googleMapsApiKey,
    this.maps = const MapConfiguration(
      requestedProvider: MapProviderKind.openstreetmap,
      provider: MapProviderKind.openstreetmap,
      defaultLatitude: 14.5995,
      defaultLongitude: 120.9842,
      defaultZoom: 11,
      minimumZoom: 3,
      maximumZoom: 19,
      tileUrlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
      tileAttribution: '© OpenStreetMap contributors',
      tileMaximumNativeZoom: 19,
      clusteringEnabled: true,
      googleReady: false,
    ),
  });

  factory AppEnvironment.fromDefines() {
    const environment = String.fromEnvironment(
      'APP_ENVIRONMENT',
      defaultValue: 'local',
    );
    const configuredApiBaseUrl = String.fromEnvironment('API_BASE_URL');
    final apiBaseUrl = resolveApiBaseUrl(configuredApiBaseUrl, isWeb: kIsWeb);

    final name = AppEnvironmentName.values.firstWhere(
      (value) => value.name == environment,
      orElse: () => throw StateError('Unsupported APP_ENVIRONMENT.'),
    );
    final baseUri = Uri.tryParse(apiBaseUrl);
    if (baseUri == null || !baseUri.hasScheme || !baseUri.hasAuthority) {
      throw StateError('API_BASE_URL must be an absolute URL.');
    }
    if (name != AppEnvironmentName.local && baseUri.scheme != 'https') {
      throw StateError('Non-local API_BASE_URL values must use HTTPS.');
    }

    return AppEnvironment(
      name: name,
      apiBaseUrl: baseUri,
      defaultTenantId: const String.fromEnvironment('DEFAULT_TENANT_ID'),
      googleMapsApiKey: const String.fromEnvironment('GOOGLE_MAPS_API_KEY'),
      maps: MapConfiguration.fromDefines(),
    );
  }

  final AppEnvironmentName name;
  final Uri apiBaseUrl;
  final String defaultTenantId;
  final String googleMapsApiKey;
  final MapConfiguration maps;

  bool get hasTenant => defaultTenantId.isNotEmpty;
  bool get hasGoogleMapsKey => googleMapsApiKey.isNotEmpty;

  @visibleForTesting
  static String resolveApiBaseUrl(String configured, {required bool isWeb}) {
    if (configured.trim().isNotEmpty) {
      return configured.trim();
    }

    return isWeb ? 'http://localhost:8000' : 'http://10.0.2.2:8000';
  }

  AppEnvironment withMaps(MapConfiguration configuration) => AppEnvironment(
    name: name,
    apiBaseUrl: apiBaseUrl,
    defaultTenantId: defaultTenantId,
    googleMapsApiKey: googleMapsApiKey,
    maps: configuration,
  );
}
