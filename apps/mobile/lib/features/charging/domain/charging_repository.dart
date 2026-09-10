import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';

@immutable
final class StartChargingRequest {
  const StartChargingRequest({
    required this.connectorId,
    required this.quoteId,
    required this.paymentMethodId,
    required this.idempotencyKey,
  });

  final String connectorId;
  final String quoteId;
  final String paymentMethodId;
  final String idempotencyKey;
}

abstract interface class ChargingRepository {
  Future<ChargePreparation> prepare({
    required ChargerCode code,
    String? vehicleId,
  });
  Future<ChargingSession> start(StartChargingRequest request);
  Future<ChargingSession?> activeSession();
  Future<ChargingSession> getSession(String sessionId);
  Future<ChargingSession> stop({
    required String sessionId,
    required String idempotencyKey,
  });
  Future<ChargingSession> cancelStart({
    required String sessionId,
    required String reason,
  });
  Future<ChargingHistoryPage> history({String? cursor});
  Future<void> requestRefund({
    required String sessionId,
    required String reason,
    required String idempotencyKey,
  });
  Future<void> reportIssue({
    required String sessionId,
    required String category,
    required String description,
    required String idempotencyKey,
  });
}

abstract interface class SessionRealtimeGateway {
  Stream<ChargingSession> watch(String sessionId);
}

final class NoopSessionRealtimeGateway implements SessionRealtimeGateway {
  const NoopSessionRealtimeGateway();

  @override
  Stream<ChargingSession> watch(String sessionId) =>
      const Stream<ChargingSession>.empty();
}
