import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';
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
  });
}
