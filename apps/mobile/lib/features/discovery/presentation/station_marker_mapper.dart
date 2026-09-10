import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';

StationMapMarker stationMapMarker(Station station, {required bool selected}) =>
    StationMapMarker(
      id: station.id,
      position: MapPosition(station.latitude, station.longitude),
      title: station.siteName,
      status: station.isStale
          ? StationMarkerStatus.stale
          : switch (station.availability) {
              StationAvailability.available => StationMarkerStatus.available,
              StationAvailability.busy => StationMarkerStatus.busy,
              StationAvailability.faulted => StationMarkerStatus.faulted,
              StationAvailability.offline => StationMarkerStatus.offline,
              StationAvailability.stale => StationMarkerStatus.stale,
              StationAvailability.unknown => StationMarkerStatus.unknown,
            },
      selected: selected,
    );
