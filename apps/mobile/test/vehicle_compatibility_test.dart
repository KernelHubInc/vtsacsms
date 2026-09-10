import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle.dart';

void main() {
  test('connector compatibility is case-insensitive', () {
    const vehicle = Vehicle(
      id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0M',
      nickname: 'Daily driver',
      manufacturer: 'Example',
      model: 'EV',
      connectorStandards: {'CCS2', 'Type 2'},
    );

    expect(vehicle.supports('ccs2'), isTrue);
    expect(vehicle.supports('CHAdeMO'), isFalse);
  });

  test('canonical vehicle JSON round-trips connector standards', () {
    const vehicle = Vehicle(
      id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0M',
      nickname: 'Daily driver',
      manufacturer: 'Example',
      model: 'EV',
      connectorStandards: {'Type 2', 'CCS2'},
      isDefault: true,
    );

    final restored = Vehicle.fromJson(vehicle.toJson());
    expect(restored.connectorStandards, {'CCS2', 'Type 2'});
    expect(restored.isDefault, isTrue);
  });
}
