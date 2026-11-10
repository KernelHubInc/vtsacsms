import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:vtsa_mobile/features/vehicles/application/vehicles_controller.dart';
import 'package:vtsa_mobile/features/vehicles/data/local_vehicle_repository.dart';

void main() {
  test(
    'vehicle selection survives reload without changing the saved default',
    () async {
      SharedPreferences.setMockInitialValues({});
      final preferences = await SharedPreferences.getInstance();
      final vehicles = VehiclesController(
        repository: LocalVehicleRepository(preferences),
        ownerId: () => 'owner-a',
      );
      addTearDown(vehicles.dispose);
      await vehicles.save(
        id: 'vehicle-a',
        nickname: 'Daily',
        manufacturer: 'BYD',
        model: 'ATTO 3',
        connectorStandards: {'Type 2'},
        isDefault: true,
      );
      await vehicles.save(
        id: 'vehicle-b',
        nickname: 'Weekend',
        manufacturer: 'Kia',
        model: 'EV6',
        connectorStandards: {'CCS2'},
        isDefault: false,
      );
      expect(vehicles.selectedVehicle?.id, 'vehicle-a');
      expect(vehicles.selectVehicle('vehicle-b'), isTrue);
      await vehicles.load();
      expect(vehicles.selectedVehicle?.id, 'vehicle-b');
      expect(vehicles.defaultVehicle?.id, 'vehicle-a');
      expect(vehicles.selectVehicle('unknown'), isFalse);
      expect(vehicles.selectedVehicle?.id, 'vehicle-b');
      await vehicles.remove('vehicle-b');
      expect(vehicles.selectedVehicle?.id, 'vehicle-a');
    },
  );

  test('account changes cannot reuse another garage selection', () async {
    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    var owner = 'owner-a';
    final vehicles = VehiclesController(
      repository: LocalVehicleRepository(preferences),
      ownerId: () => owner,
    );
    addTearDown(vehicles.dispose);
    await vehicles.save(
      id: 'same-id',
      nickname: 'Private car',
      manufacturer: 'BYD',
      model: 'ATTO 3',
      connectorStandards: {'Type 2'},
      isDefault: true,
    );
    vehicles.selectVehicle('same-id');
    owner = 'owner-b';
    expect(vehicles.selectedVehicle, isNull);
    expect(vehicles.selectVehicle('same-id'), isFalse);
    await vehicles.load();
    expect(vehicles.selectedVehicle, isNull);
    await vehicles.save(
      id: 'same-id',
      nickname: 'Shared car',
      manufacturer: 'Kia',
      model: 'EV6',
      connectorStandards: {'CCS2'},
      isDefault: true,
    );
    expect(vehicles.selectedVehicle?.nickname, 'Shared car');
    owner = 'owner-a';
    expect(vehicles.selectedVehicle, isNull);
    await vehicles.load();
    expect(vehicles.selectedVehicle?.nickname, 'Private car');
  });
}
