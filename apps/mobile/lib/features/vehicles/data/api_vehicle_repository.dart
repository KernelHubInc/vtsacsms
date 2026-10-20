import 'package:dio/dio.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle_repository.dart';

final class ApiVehicleRepository implements VehicleRepository {
  const ApiVehicleRepository(
    this.client, {
    this.legacy,
    this.accountId,
    this.shared,
  });
  final String? Function()? accountId;
  final bool Function()? shared;
  Options get _options =>
      Options(headers: {'X-Charging-Account-ID': ?accountId?.call()});
  final VehicleRepository? legacy;
  final ApiClient client;

  @override
  Future<List<Vehicle>> list(String ownerId) async {
    final isShared = shared?.call() == true;
    try {
      final response = await client.dio.get<Map<String, dynamic>>(
        '/api/v1/vehicles',
        options: _options,
      );
      final remote = (response.data!['data'] as List)
          .map(
            (item) => Vehicle.fromJson(Map<String, dynamic>.from(item as Map)),
          )
          .toList();
      final local = isShared
          ? <Vehicle>[]
          : await legacy?.list(ownerId) ?? <Vehicle>[];
      final merged = [
        ...remote,
        ...local.where((item) => !remote.any((saved) => saved.id == item.id)),
      ];
      final defaultId =
          remote.where((item) => item.isDefault).firstOrNull?.id ??
          local.where((item) => item.isDefault).firstOrNull?.id;
      return merged
          .map((item) => item.copyWith(isDefault: item.id == defaultId))
          .toList();
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<void> save(String ownerId, Vehicle vehicle) async {
    final isShared = shared?.call() == true;
    try {
      await client.dio.put<void>(
        '/api/v1/vehicles/${vehicle.id}',
        data: vehicle.toJson(),
        options: _options,
      );
      if (!isShared) await legacy?.remove(ownerId, vehicle.id);
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<void> remove(String ownerId, String vehicleId) async {
    final isShared = shared?.call() == true;
    try {
      try {
        await client.dio.delete<void>(
          '/api/v1/vehicles/$vehicleId',
          options: _options,
        );
      } on DioException catch (error) {
        final local = isShared
            ? <Vehicle>[]
            : await legacy?.list(ownerId) ?? <Vehicle>[];
        if (error.response?.statusCode != 404 ||
            !local.any((item) => item.id == vehicleId)) {
          rethrow;
        }
      }
      if (!isShared) await legacy?.remove(ownerId, vehicleId);
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }
}
