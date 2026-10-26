import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';

StationMapMarker stationMapMarker(Station station, {required bool selected}) =>
    StationMapMarker(
      id: station.id,
      position: MapPosition(station.latitude, station.longitude),
      title: station.name,
      branded: const {
        'power solutions',
        'evcs power solutions',
      }.contains(station.operatorName.trim().toLowerCase()),
      status: station.isStale
          ? StationMarkerStatus.stale
          : switch (station.availability) {
              StationAvailability.available => StationMarkerStatus.available,
              StationAvailability.busy => StationMarkerStatus.busy,
              StationAvailability.faulted => StationMarkerStatus.faulted,
              StationAvailability.offline => StationMarkerStatus.offline,
              StationAvailability.unavailable => StationMarkerStatus.unknown,
              StationAvailability.stale => StationMarkerStatus.stale,
              StationAvailability.unknown => StationMarkerStatus.unknown,
            },
      selected: selected,
    );

String stationMarkerAsset(StationMapMarker marker) {
  if (!marker.branded) return 'pin-other-operator';
  if (marker.selected && marker.status == StationMarkerStatus.available) {
    return 'pin-selected';
  }
  return 'pin-${marker.status.name}';
}
