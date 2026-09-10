import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/core/maps/map_configuration.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';

final class MapConfigurationRepository {
  const MapConfigurationRepository(this._client);

  final ApiClient _client;

  Future<MapConfiguration> resolve(MapConfiguration local) async {
    try {
      final response = await _client.dio.get<Map<String, dynamic>>(
        '/api/v1/app/config',
        options: Options(extra: const {'anonymous': true}),
      );
      final data = response.data?['data'];
      final map = data is Map<String, dynamic> ? data['map'] : null;
      if (map is! Map<String, dynamic>) {
        return local.withStatus('invalid_remote_configuration');
      }
      return MapConfiguration.fromRemote(map, local);
    } on DioException catch (error) {
      debugPrint(
        'Map configuration endpoint unavailable: ${error.type.name}; local defaults active.',
      );
      return local.withStatus('local_configuration_active');
    } on FormatException {
      return local.withStatus('invalid_remote_configuration');
    }
  }
}
