import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/presentation/driver_date_field.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/domain/station_repository.dart';
import 'package:vtsa_mobile/features/vehicles/data/api_vehicle_repository.dart';
import 'package:vtsa_mobile/features/vehicles/data/local_vehicle_repository.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle.dart';

void main() {
  test('adult eligibility changes on the eighteenth birthday', () {
    final today = DateTime.utc(2026, 10, 8);
    expect(isAdult(DateTime(2008, 10, 8), today), isTrue);
    expect(isAdult(DateTime(2008, 10, 9), today), isFalse);
    expect(isAdult(DateTime(2027), today), isFalse);
    expect(isAdult(DateTime(2008, 2, 29), DateTime(2026, 2, 28)), isFalse);
    expect(dateOnly(DateTime(1990, 1, 2)), '1990-01-02');
  });

  test(
    'vehicle standard aliases match without guessing an unknown connector',
    () {
      const vehicle = Vehicle(
        id: 'test',
        nickname: 'Car',
        manufacturer: '',
        model: '',
        connectorStandards: {'Type 2', 'GB/T'},
      );
      expect(vehicle.supports('type_2'), isTrue);
      expect(vehicle.supports('gb_t'), isTrue);
      expect(vehicle.supports('CCS2'), isFalse);
    },
  );

  test('connector status is unknown on an older backend', () {
    final connector = StationConnector.fromJson({
      'standard': 'ccs2',
      'name': 'CCS2',
      'maximum_power_w': 50000,
    });
    expect(connector.statusLabel, 'Status unknown');
    final live = StationConnector.fromJson({
      'id': 'test',
      'standard': 'ccs2',
      'name': 'CCS2',
      'maximum_power_w': 50000,
      'availability': 'occupied',
    });
    expect(live.statusLabel, 'In use');
  });

  test(
    'vehicle API persists plate pending and does not send an owner override',
    () async {
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: MemoryTokenStore(),
      );
      final calls = <RequestOptions>[];
      const vehicle = Vehicle(
        id: '01J00000000000000000000001',
        nickname: 'Car',
        manufacturer: '',
        model: '',
        connectorStandards: {},
        platePending: true,
        isDefault: true,
      );
      client.dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (request, handler) {
            calls.add(request);
            handler.resolve(
              Response(
                requestOptions: request,
                statusCode: 200,
                data: request.method == 'GET'
                    ? {
                        'data': [vehicle.toJson()],
                      }
                    : null,
              ),
            );
          },
        ),
      );
      final repository = ApiVehicleRepository(client);
      await repository.save('owner-not-in-request', vehicle);
      expect((calls.single.data as Map)['plate_pending'], isTrue);
      expect((calls.single.data as Map).containsKey('plate_number'), isFalse);
      expect((calls.single.data as Map).containsKey('owner_id'), isFalse);
      expect((await repository.list('owner')).single.platePending, isTrue);
      await repository.remove('owner', vehicle.id);
      expect(calls.last.method, 'DELETE');
    },
  );

  test(
    'old local vehicles remain visible and are removed locally only after server save',
    () async {
      SharedPreferences.setMockInitialValues({});
      final legacy = LocalVehicleRepository(
        await SharedPreferences.getInstance(),
      );
      const vehicle = Vehicle(
        id: '01J00000000000000000000001',
        nickname: 'Old car',
        manufacturer: 'Test',
        model: 'Model',
        connectorStandards: {'Type 2'},
        isDefault: true,
      );
      await legacy.save('driver', vehicle);
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: MemoryTokenStore(),
      );
      var failSave = true;
      client.dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (request, handler) {
            if (request.method == 'PUT' && failSave) {
              handler.reject(
                DioException(
                  requestOptions: request,
                  type: DioExceptionType.badResponse,
                  response: Response(requestOptions: request, statusCode: 422),
                ),
              );
            } else {
              handler.resolve(
                Response(
                  requestOptions: request,
                  statusCode: 200,
                  data: {'data': <Object>[]},
                ),
              );
            }
          },
        ),
      );
      final repository = ApiVehicleRepository(client, legacy: legacy);
      expect((await repository.list('driver')).single.id, vehicle.id);
      expect(await repository.list('another-driver'), isEmpty);
      await expectLater(
        repository.save('driver', vehicle),
        throwsA(isA<Object>()),
      );
      expect(await legacy.list('driver'), hasLength(1));
      failSave = false;
      await repository.save('driver', vehicle);
      expect(await legacy.list('driver'), isEmpty);
    },
  );

  test('vehicle and amenity filters survive other filter changes', () {
    const filters = StationFilters(
      vehicleConnectors: ['Type 2', 'CCS2'],
      amenity: 'food',
    );
    final query = filters
        .copyWith(openNow: true, minimumPowerW: 22000)
        .toQuery();
    expect(query['connectors[]'], ['Type 2', 'CCS2']);
    expect(query['amenity'], 'food');
    expect(query['open_now'], isTrue);
  });
}
