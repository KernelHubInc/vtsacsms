import 'package:flutter/material.dart';
import 'package:vtsa_mobile/core/maps/map_configuration.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/features/discovery/application/discovery_controller.dart';
import 'package:vtsa_mobile/features/discovery/presentation/google_station_map.dart';
import 'package:vtsa_mobile/features/discovery/presentation/openstreetmap_station_map.dart';

typedef StationMapBuilder =
    Widget Function(
      DiscoveryController discovery,
      MapConfiguration configuration,
      MapPosition? userPosition,
      VoidCallback onFailure,
    );

class StationMap extends StatelessWidget {
  const StationMap({
    required this.discovery,
    required this.configuration,
    required this.onProviderFailure,
    this.userPosition,
    this.openStreetMapBuilder,
    this.googleMapsBuilder,
    super.key,
  });

  final DiscoveryController discovery;
  final MapConfiguration configuration;
  final VoidCallback onProviderFailure;
  final MapPosition? userPosition;
  final StationMapBuilder? openStreetMapBuilder;
  final StationMapBuilder? googleMapsBuilder;

  @override
  Widget build(BuildContext context) {
    if (configuration.provider == MapProviderKind.google &&
        configuration.googleReady) {
      return (googleMapsBuilder ?? _google)(
        discovery,
        configuration,
        userPosition,
        onProviderFailure,
      );
    }
    if (!configuration.tileConfigurationValid) {
      return MapUnavailableState(
        title: 'Map configuration unavailable',
        description:
            'The tile provider is invalid. Station discovery remains available as a list.',
        onShowList: onProviderFailure,
      );
    }
    return (openStreetMapBuilder ?? _openStreetMap)(
      discovery,
      configuration,
      userPosition,
      onProviderFailure,
    );
  }

  static Widget _openStreetMap(
    DiscoveryController discovery,
    MapConfiguration configuration,
    MapPosition? userPosition,
    VoidCallback onFailure,
  ) => OpenStreetMapStationMap(
    discovery: discovery,
    configuration: configuration,
    userPosition: userPosition,
    onProviderFailure: onFailure,
  );

  static Widget _google(
    DiscoveryController discovery,
    MapConfiguration configuration,
    MapPosition? userPosition,
    VoidCallback onFailure,
  ) => GoogleStationMap(
    discovery: discovery,
    configuration: configuration,
    userPosition: userPosition,
    onProviderFailure: onFailure,
  );
}
