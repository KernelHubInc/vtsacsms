import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';

void main() {
  test('parses PostgreSQL decimal coordinates returned as strings', () {
    final station = Station.fromJson({
      'id': '01J00000000000000000000001',
      'site_id': '01J00000000000000000000002',
      'name': 'Demo Charger',
      'site_name': 'Demo Site',
      'address_line_1': '1 Demo Avenue',
      'latitude': '14.579400',
      'longitude': '121.035900',
      'timezone': 'Asia/Manila',
      'site_type': 'public_parking',
      'operator_id': '01J00000000000000000000003',
      'operator_name': 'Demo Operator',
      'availability': 'available',
      'is_stale': false,
      'status_observed_at': null,
      'maximum_power_w': 22000,
      'open_now': true,
      'connectors': <Object?>[],
      'distance_m': null,
      'amenities': <Object?>[],
    });

    expect(station.latitude, 14.5794);
    expect(station.longitude, 121.0359);
  });
}
