import 'dart:async';

import 'package:flutter/material.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:vtsa_mobile/core/maps/map_configuration.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/discovery/application/discovery_controller.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_marker_mapper.dart';

class GoogleStationMap extends StatefulWidget {
  const GoogleStationMap({
    required this.discovery,
    required this.configuration,
    this.userPosition,
    required this.onProviderFailure,
    super.key,
  });

  final DiscoveryController discovery;
  final MapConfiguration configuration;
  final MapPosition? userPosition;
  final VoidCallback onProviderFailure;

  @override
  State<GoogleStationMap> createState() => _GoogleStationMapState();
}

class _GoogleStationMapState extends State<GoogleStationMap>
    implements MapViewportPort {
  GoogleMapController? _map;
  Timer? _idleDebounce;
  String? _lastFocusedId;

  static const _clusterId = ClusterManagerId('stations');

  @override
  void initState() {
    super.initState();
    widget.discovery.addListener(_synchronizeSelection);
  }

  @override
  void didUpdateWidget(GoogleStationMap oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.discovery != widget.discovery) {
      oldWidget.discovery.removeListener(_synchronizeSelection);
      widget.discovery.addListener(_synchronizeSelection);
    }
    final position = widget.userPosition;
    if (oldWidget.userPosition != position && position != null) {
      _map?.animateCamera(
        CameraUpdate.newLatLngZoom(
          LatLng(position.latitude, position.longitude),
          13,
        ),
      );
    }
  }

  @override
  void dispose() {
    _idleDebounce?.cancel();
    widget.discovery.removeListener(_synchronizeSelection);
    _map?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Semantics(
    label:
        'Interactive charging map. Use the list view for an accessible alternative.',
    child: GoogleMap(
      initialCameraPosition: CameraPosition(
        target: LatLng(
          widget.configuration.defaultLatitude,
          widget.configuration.defaultLongitude,
        ),
        zoom: widget.configuration.defaultZoom,
      ),
      minMaxZoomPreference: MinMaxZoomPreference(
        widget.configuration.minimumZoom,
        widget.configuration.maximumZoom,
      ),
      compassEnabled: true,
      mapToolbarEnabled: false,
      myLocationButtonEnabled: false,
      zoomControlsEnabled: false,
      onMapCreated: (controller) => _map = controller,
      onCameraIdle: _scheduleBoundsLoad,
      clusterManagers: widget.configuration.clusteringEnabled
          ? {const ClusterManager(clusterManagerId: _clusterId)}
          : <ClusterManager>{},
      markers: {
        if (widget.userPosition case final position?)
          Marker(
            markerId: const MarkerId('vtsa-user-position'),
            position: LatLng(position.latitude, position.longitude),
            infoWindow: const InfoWindow(title: 'Your approximate location'),
            icon: BitmapDescriptor.defaultMarkerWithHue(
              BitmapDescriptor.hueAzure,
            ),
            zIndexInt: 3,
          ),
        for (final station in widget.discovery.stations)
          Marker(
            markerId: MarkerId(station.id),
            position: LatLng(station.latitude, station.longitude),
            clusterManagerId: widget.configuration.clusteringEnabled
                ? _clusterId
                : null,
            infoWindow: InfoWindow(
              title: station.siteName,
              snippet: station.isStale
                  ? 'Status stale'
                  : station.availability.name,
            ),
            icon: BitmapDescriptor.defaultMarkerWithHue(
              _markerHue(
                stationMapMarker(
                  station,
                  selected: widget.discovery.selectedStationId == station.id,
                ).status,
              ),
            ),
            zIndexInt: widget.discovery.selectedStationId == station.id ? 2 : 1,
            onTap: () => widget.discovery.select(station.id),
          ),
      },
    ),
  );

  Future<void> _scheduleBoundsLoad() async {
    _idleDebounce?.cancel();
    _idleDebounce = Timer(const Duration(milliseconds: 350), () async {
      final controller = _map;
      if (controller == null) {
        return;
      }
      try {
        final region = await controller.getVisibleRegion();
        await widget.discovery.loadBounds(
          GeoBounds(
            west: region.southwest.longitude,
            south: region.southwest.latitude,
            east: region.northeast.longitude,
            north: region.northeast.latitude,
          ),
        );
      } on StateError {
        widget.onProviderFailure();
      }
    });
  }

  void _synchronizeSelection() {
    final id = widget.discovery.selectedStationId;
    if (id == null || id == _lastFocusedId) {
      return;
    }
    final station = widget.discovery.byId(id);
    if (station != null) {
      _lastFocusedId = id;
      focus(station.latitude, station.longitude);
    }
  }

  @override
  Future<void> focus(double latitude, double longitude) async {
    await _map?.animateCamera(
      CameraUpdate.newLatLng(LatLng(latitude, longitude)),
    );
  }

  double _markerHue(StationMarkerStatus status) {
    return switch (status) {
      StationMarkerStatus.available => BitmapDescriptor.hueGreen,
      StationMarkerStatus.busy => BitmapDescriptor.hueViolet,
      StationMarkerStatus.faulted => BitmapDescriptor.hueRed,
      StationMarkerStatus.offline => BitmapDescriptor.hueRose,
      StationMarkerStatus.stale => BitmapDescriptor.hueOrange,
      StationMarkerStatus.unknown => BitmapDescriptor.hueAzure,
    };
  }
}

class MapUnavailableState extends StatelessWidget {
  const MapUnavailableState({
    required this.onShowList,
    this.title = 'Interactive map unavailable',
    this.description =
        'The selected map provider could not start. Station discovery remains available as a list.',
    super.key,
  });

  final VoidCallback onShowList;
  final String title;
  final String description;

  @override
  Widget build(BuildContext context) => Center(
    child: VtsaEmptyState(
      icon: Icons.map_outlined,
      title: title,
      description: description,
      action: VtsaButton(label: 'View as list', onPressed: onShowList),
    ),
  );
}
