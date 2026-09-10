import 'package:flutter/foundation.dart';

@immutable
final class MapPosition {
  const MapPosition(this.latitude, this.longitude)
    : assert(latitude >= -90 && latitude <= 90),
      assert(longitude >= -180 && longitude <= 180);

  final double latitude;
  final double longitude;
}

@immutable
final class MapBounds {
  const MapBounds({
    required this.west,
    required this.south,
    required this.east,
    required this.north,
  });

  final double west;
  final double south;
  final double east;
  final double north;
}

enum StationMarkerStatus { available, busy, faulted, offline, stale, unknown }

@immutable
final class StationMapMarker {
  const StationMapMarker({
    required this.id,
    required this.position,
    required this.title,
    required this.status,
    required this.selected,
  });

  final String id;
  final MapPosition position;
  final String title;
  final StationMarkerStatus status;
  final bool selected;
}

abstract interface class MapViewportPort {
  Future<void> focus(double latitude, double longitude);
}

typedef MapBoundsChanged = Future<void> Function(MapBounds bounds);
typedef MapMarkerSelected = void Function(String markerId);
