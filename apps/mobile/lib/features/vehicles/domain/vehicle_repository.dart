import 'package:vtsa_mobile/features/vehicles/domain/vehicle.dart';

abstract interface class VehicleRepository {
  Future<List<Vehicle>> list(String ownerId);
  Future<void> save(String ownerId, Vehicle vehicle);
  Future<void> remove(String ownerId, String vehicleId);
}
