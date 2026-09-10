import 'package:dio/dio.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_repository.dart';

final class ApiChargingRepository implements ChargingRepository {
  ApiChargingRepository(this._client);

  final ApiClient _client;

  @override
  Future<ChargePreparation> prepare({
    required ChargerCode code,
    String? vehicleId,
  }) async {
    try {
      final response = await _client.dio.post<Map<String, dynamic>>(
        '/api/v1/mobile/charging/prepare',
        data: {'charger_code': code.value, 'vehicle_id': ?vehicleId},
      );
      return ChargePreparation.fromJson(
        response.data!['data']! as Map<String, dynamic>,
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<ChargingSession> start(StartChargingRequest request) async {
    try {
      final response = await _client.dio.post<Map<String, dynamic>>(
        '/api/v1/mobile/charging-sessions/remote-start',
        data: {
          'connector_id': request.connectorId,
          'quote_id': request.quoteId,
          'payment_method_id': request.paymentMethodId,
        },
        options: Options(headers: {'Idempotency-Key': request.idempotencyKey}),
      );
      final data = response.data!['data']! as Map<String, dynamic>;
      return ChargingSession.fromJson(
        data['session'] as Map<String, dynamic>? ?? data,
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<ChargingSession?> activeSession() async {
    try {
      final response = await _client.dio.get<Map<String, dynamic>>(
        '/api/v1/mobile/charging-sessions/active',
      );
      final data = response.data?['data'] as Map<String, dynamic>?;
      return data == null ? null : ChargingSession.fromJson(data);
    } on DioException catch (error) {
      if (error.response?.statusCode == 404) {
        return null;
      }
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<ChargingSession> getSession(String sessionId) async {
    try {
      final response = await _client.dio.get<Map<String, dynamic>>(
        '/api/v1/mobile/charging-sessions/$sessionId',
      );
      return ChargingSession.fromJson(
        response.data!['data']! as Map<String, dynamic>,
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<ChargingSession> stop({
    required String sessionId,
    required String idempotencyKey,
  }) async {
    try {
      final response = await _client.dio.post<Map<String, dynamic>>(
        '/api/v1/mobile/charging-sessions/$sessionId/remote-stop',
        options: Options(headers: {'Idempotency-Key': idempotencyKey}),
      );
      final data = response.data!['data']! as Map<String, dynamic>;
      return ChargingSession.fromJson(
        data['session'] as Map<String, dynamic>? ?? data,
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<ChargingSession> cancelStart({
    required String sessionId,
    required String reason,
  }) async {
    try {
      final response = await _client.dio.post<Map<String, dynamic>>(
        '/api/v1/mobile/charging-sessions/$sessionId/cancel',
        data: {'reason': reason},
      );
      return ChargingSession.fromJson(
        response.data!['data']! as Map<String, dynamic>,
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<ChargingHistoryPage> history({String? cursor}) async {
    try {
      final response = await _client.dio.get<Map<String, dynamic>>(
        '/api/v1/mobile/charging-sessions',
        queryParameters: {'cursor': ?cursor},
      );
      final body = response.data!;
      final meta = body['meta'] as Map<String, dynamic>?;
      return ChargingHistoryPage(
        sessions: (body['data']! as List<Object?>)
            .map(
              (item) => ChargingSession.fromJson(item! as Map<String, dynamic>),
            )
            .toList(growable: false),
        nextCursor: meta?['next_cursor'] as String?,
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<void> requestRefund({
    required String sessionId,
    required String reason,
    required String idempotencyKey,
  }) async {
    try {
      await _client.dio.post<void>(
        '/api/v1/mobile/charging-sessions/$sessionId/refund-requests',
        data: {'reason': reason},
        options: Options(headers: {'Idempotency-Key': idempotencyKey}),
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<void> reportIssue({
    required String sessionId,
    required String category,
    required String description,
    required String idempotencyKey,
  }) async {
    try {
      await _client.dio.post<void>(
        '/api/v1/mobile/support/issues',
        data: {
          'session_id': sessionId,
          'category': category,
          'description': description,
        },
        options: Options(headers: {'Idempotency-Key': idempotencyKey}),
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }
}
