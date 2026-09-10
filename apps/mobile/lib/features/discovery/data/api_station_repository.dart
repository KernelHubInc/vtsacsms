import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/domain/station_repository.dart';

final class ApiStationRepository implements StationRepository {
  ApiStationRepository(this._client);

  final ApiClient _client;

  @override
  Future<StationSearchResult> inBounds({
    required GeoBounds bounds,
    required StationFilters filters,
  }) => _search({...bounds.toQuery(), ...filters.toQuery(), 'limit': 250});

  @override
  Future<StationSearchResult> nearby({
    required double latitude,
    required double longitude,
    required int radiusM,
    required StationFilters filters,
  }) => _search({
    'latitude': latitude,
    'longitude': longitude,
    'radius_m': radiusM,
    ...filters.toQuery(),
    'limit': 100,
  });

  Future<StationSearchResult> _search(Map<String, Object> query) async {
    try {
      final response = await _client.dio.get<Map<String, dynamic>>(
        '/api/v1/public/stations',
        queryParameters: query,
      );
      final body = response.data!;
      final stations = (body['data']! as List<Object?>)
          .map((item) => Station.fromJson(item! as Map<String, dynamic>))
          .toList(growable: false);
      final meta = body['meta']! as Map<String, dynamic>;
      return StationSearchResult(
        stations: stations,
        generatedAt: DateTime.parse(meta['generated_at']! as String).toUtc(),
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }
}
