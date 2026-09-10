import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_map_marker_cluster/flutter_map_marker_cluster.dart';
import 'package:latlong2/latlong.dart';
import 'package:vtsa_mobile/core/maps/map_configuration.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/features/discovery/application/discovery_controller.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_marker_mapper.dart';

class OpenStreetMapStationMap extends StatefulWidget {
  const OpenStreetMapStationMap({
    required this.discovery,
    required this.configuration,
    this.userPosition,
    this.onProviderFailure,
    this.tileProvider,
    super.key,
  });

  final DiscoveryController discovery;
  final MapConfiguration configuration;
  final MapPosition? userPosition;
  final VoidCallback? onProviderFailure;
  final TileProvider? tileProvider;

  @override
  State<OpenStreetMapStationMap> createState() =>
      _OpenStreetMapStationMapState();
}

class _OpenStreetMapStationMapState extends State<OpenStreetMapStationMap>
    implements MapViewportPort {
  final MapController _map = MapController();
  Timer? _viewportDebounce;
  String? _lastFocusedId;
  bool _tileFailed = false;
  bool _disposed = false;

  @override
  void initState() {
    super.initState();
    widget.discovery.addListener(_synchronizeSelection);
  }

  @override
  void didUpdateWidget(OpenStreetMapStationMap oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.discovery != widget.discovery) {
      oldWidget.discovery.removeListener(_synchronizeSelection);
      widget.discovery.addListener(_synchronizeSelection);
    }
    final position = widget.userPosition;
    if (oldWidget.userPosition != position && position != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!_disposed) {
          _map.move(
            LatLng(position.latitude, position.longitude),
            _map.camera.zoom < 13 ? 13 : _map.camera.zoom,
          );
        }
      });
    }
  }

  @override
  void dispose() {
    _disposed = true;
    _viewportDebounce?.cancel();
    widget.discovery.removeListener(_synchronizeSelection);
    _map.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final configuration = widget.configuration;
    final markers = [
      for (final station in widget.discovery.stations)
        stationMapMarker(
          station,
          selected: widget.discovery.selectedStationId == station.id,
        ),
    ];
    final mapMarkers = markers.map(_marker).toList(growable: false);

    return Semantics(
      label:
          'Interactive charging map. Use the list view for an accessible alternative.',
      child: Stack(
        children: [
          FlutterMap(
            mapController: _map,
            options: MapOptions(
              initialCenter: LatLng(
                configuration.defaultLatitude,
                configuration.defaultLongitude,
              ),
              initialZoom: configuration.defaultZoom,
              minZoom: configuration.minimumZoom,
              maxZoom: configuration.maximumZoom,
              keepAlive: true,
              onMapEvent: _onMapEvent,
            ),
            children: [
              TileLayer(
                urlTemplate: configuration.tileUrlTemplate,
                minZoom: configuration.minimumZoom,
                maxZoom: configuration.maximumZoom,
                maxNativeZoom: configuration.tileMaximumNativeZoom,
                tileProvider: widget.tileProvider,
                userAgentPackageName: 'com.vtsa.vtsa_mobile',
                keepBuffer: 2,
                panBuffer: 1,
                errorTileCallback: (_, _, _) {
                  if (!_disposed && mounted && !_tileFailed) {
                    setState(() => _tileFailed = true);
                    widget.onProviderFailure?.call();
                  }
                },
              ),
              if (configuration.clusteringEnabled)
                MarkerClusterLayerWidget(
                  options: MarkerClusterLayerOptions(
                    markers: mapMarkers,
                    maxClusterRadius: 52,
                    maxZoom: configuration.maximumZoom,
                    disableClusteringAtZoom: configuration.maximumZoom.floor(),
                    markerChildBehavior: true,
                    builder: (context, clusteredMarkers) => DecoratedBox(
                      decoration: const BoxDecoration(
                        color: Color(0xFF071B2D),
                        shape: BoxShape.circle,
                        boxShadow: [
                          BoxShadow(color: Colors.black26, blurRadius: 8),
                        ],
                      ),
                      child: Center(
                        child: Text(
                          '${clusteredMarkers.length}',
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    ),
                  ),
                )
              else
                MarkerLayer(markers: mapMarkers),
              if (widget.userPosition case final position?)
                MarkerLayer(
                  markers: [
                    Marker(
                      key: const ValueKey('openstreetmap-user-position'),
                      point: LatLng(position.latitude, position.longitude),
                      width: 28,
                      height: 28,
                      child: Semantics(
                        label: 'Your approximate location',
                        child: DecoratedBox(
                          decoration: const BoxDecoration(
                            color: Color(0xFF2563EB),
                            shape: BoxShape.circle,
                            border: Border(
                              top: BorderSide(color: Colors.white, width: 3),
                              right: BorderSide(color: Colors.white, width: 3),
                              bottom: BorderSide(color: Colors.white, width: 3),
                              left: BorderSide(color: Colors.white, width: 3),
                            ),
                            boxShadow: [
                              BoxShadow(color: Colors.black26, blurRadius: 6),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              Align(
                alignment: Alignment.bottomRight,
                child: SafeArea(
                  child: Container(
                    margin: const EdgeInsets.all(4),
                    padding: const EdgeInsets.symmetric(
                      horizontal: 5,
                      vertical: 3,
                    ),
                    color: Theme.of(
                      context,
                    ).colorScheme.surface.withValues(alpha: 0.9),
                    child: Text(
                      configuration.tileAttribution,
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                  ),
                ),
              ),
            ],
          ),
          if (_tileFailed)
            Positioned(
              left: 12,
              right: 12,
              top: 12,
              child: Material(
                color: Theme.of(context).colorScheme.errorContainer,
                borderRadius: BorderRadius.circular(12),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    'Map tiles are unavailable. Station results remain available in list view.',
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.onErrorContainer,
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Marker _marker(StationMapMarker marker) => Marker(
    key: ValueKey(marker.id),
    point: LatLng(marker.position.latitude, marker.position.longitude),
    width: marker.selected ? 50 : 44,
    height: marker.selected ? 50 : 44,
    child: Semantics(
      button: true,
      label: '${marker.title}, ${marker.status.name}',
      child: GestureDetector(
        key: ValueKey('openstreetmap-marker-${marker.id}'),
        onTap: () => widget.discovery.select(marker.id),
        child: Icon(
          Icons.location_pin,
          size: marker.selected ? 48 : 42,
          color: _markerColor(marker.status),
          shadows: const [Shadow(color: Colors.black38, blurRadius: 5)],
        ),
      ),
    ),
  );

  void _onMapEvent(MapEvent event) {
    if (event is! MapEventMoveEnd && event is! MapEventFlingAnimationEnd) {
      return;
    }
    _viewportDebounce?.cancel();
    _viewportDebounce = Timer(const Duration(milliseconds: 350), () {
      if (_disposed) return;
      final bounds = event.camera.visibleBounds;
      unawaited(
        widget.discovery.loadBounds(
          GeoBounds(
            west: bounds.west,
            south: bounds.south,
            east: bounds.east,
            north: bounds.north,
          ),
        ),
      );
    });
  }

  void _synchronizeSelection() {
    final id = widget.discovery.selectedStationId;
    if (_disposed || id == null || id == _lastFocusedId) return;
    final station = widget.discovery.byId(id);
    if (station != null) {
      _lastFocusedId = id;
      unawaited(focus(station.latitude, station.longitude));
    }
  }

  @override
  Future<void> focus(double latitude, double longitude) async {
    if (_disposed) return;
    _map.move(LatLng(latitude, longitude), _map.camera.zoom);
  }

  Color _markerColor(StationMarkerStatus status) => switch (status) {
    StationMarkerStatus.available => const Color(0xFF08785A),
    StationMarkerStatus.busy => const Color(0xFF8A5200),
    StationMarkerStatus.faulted => const Color(0xFFB4233F),
    StationMarkerStatus.offline => const Color(0xFF697187),
    StationMarkerStatus.stale => const Color(0xFFE39121),
    StationMarkerStatus.unknown => const Color(0xFF1459A6),
  };
}
