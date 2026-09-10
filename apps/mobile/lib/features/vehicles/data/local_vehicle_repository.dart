import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle_repository.dart';

final class LocalVehicleRepository implements VehicleRepository {
  LocalVehicleRepository(this._preferences);

  final SharedPreferences _preferences;

  String _key(String ownerId) => 'vehicles.v1.$ownerId';

  @override
  Future<List<Vehicle>> list(String ownerId) async {
    final encoded = _preferences.getString(_key(ownerId));
    if (encoded == null) {
      return const [];
    }
    try {
      return (jsonDecode(encoded) as List<Object?>)
          .map((item) => Vehicle.fromJson(item! as Map<String, dynamic>))
          .toList(growable: false);
    } on Object {
      await _preferences.remove(_key(ownerId));
      return const [];
    }
  }

  @override
  Future<void> remove(String ownerId, String vehicleId) async {
    final vehicles = (await list(
      ownerId,
    )).where((vehicle) => vehicle.id != vehicleId).toList();
    await _write(ownerId, vehicles);
  }

  @override
  Future<void> save(String ownerId, Vehicle vehicle) async {
    final vehicles = (await list(ownerId)).toList();
    final index = vehicles.indexWhere((item) => item.id == vehicle.id);
    if (vehicle.isDefault) {
      for (var itemIndex = 0; itemIndex < vehicles.length; itemIndex++) {
        vehicles[itemIndex] = vehicles[itemIndex].copyWith(isDefault: false);
      }
    }
    if (index == -1) {
      vehicles.add(vehicle);
    } else {
      vehicles[index] = vehicle;
    }
    await _write(ownerId, vehicles);
  }

  Future<void> _write(String ownerId, List<Vehicle> vehicles) =>
      _preferences.setString(
        _key(ownerId),
        jsonEncode(vehicles.map((vehicle) => vehicle.toJson()).toList()),
      );
}
