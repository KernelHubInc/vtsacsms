import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/core/ids/ulid_generator.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle_repository.dart';

final class VehiclesController extends ChangeNotifier {
  factory VehiclesController({
    required VehicleRepository repository,
    required String Function() ownerId,
    UlidGenerator? ids,
  }) => VehiclesController._(repository, ownerId, ids ?? UlidGenerator());

  VehiclesController._(this._repository, this._ownerId, this._ids);

  final VehicleRepository _repository;
  final String Function() _ownerId;
  final UlidGenerator _ids;
  List<Vehicle> _vehicles = const [];
  bool _loading = false;

  List<Vehicle> get vehicles => _vehicles;
  bool get isLoading => _loading;
  Vehicle? get defaultVehicle =>
      _vehicles.where((vehicle) => vehicle.isDefault).firstOrNull;

  Future<void> load() async {
    _loading = true;
    notifyListeners();
    _vehicles = await _repository.list(_ownerId());
    _loading = false;
    notifyListeners();
  }

  Future<void> save({
    String? id,
    required String nickname,
    required String manufacturer,
    required String model,
    String? variant,
    required Set<String> connectorStandards,
    required bool isDefault,
  }) async {
    final vehicle = Vehicle(
      id: id ?? _ids.next(),
      nickname: nickname.trim(),
      manufacturer: manufacturer.trim(),
      model: model.trim(),
      variant: variant?.trim(),
      connectorStandards: connectorStandards,
      isDefault: isDefault,
    );
    await _repository.save(_ownerId(), vehicle);
    await load();
  }

  Future<void> remove(String id) async {
    await _repository.remove(_ownerId(), id);
    await load();
  }
}
