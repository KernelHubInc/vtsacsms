import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_marker_mapper.dart';

import 'support/fakes.dart';

void main() {
  test('station marker preserves exact coordinates and identifier', () {
    final marker = stationMapMarker(sampleStation, selected: true);

    expect(marker.id, sampleStation.id);
    expect(marker.position.latitude, sampleStation.latitude);
    expect(marker.position.longitude, sampleStation.longitude);
    expect(marker.status, StationMarkerStatus.stale);
    expect(marker.selected, isTrue);
    expect(stationMarkerAsset(marker), 'pin-other-operator');
  });

  test('selected available Power Solutions marker uses the brand pin', () {
    const station = Station(
      id: 'owned',
      siteId: 'site',
      name: 'Owned station',
      siteName: 'Site',
      latitude: 14.6,
      longitude: 120.9,
      timezone: 'Asia/Manila',
      siteType: 'retail',
      operatorId: 'operator',
      operatorName: 'Power Solutions',
      availability: StationAvailability.available,
      isStale: false,
      maximumPowerW: 7000,
      openNow: true,
      connectors: [],
    );
    final marker = stationMapMarker(station, selected: true);

    expect(stationMarkerAsset(marker), 'pin-selected');
  });
}
