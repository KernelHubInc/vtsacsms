import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';

void main() {
  group('ChargerCode', () {
    test('accepts a printed code and VTSA deep link', () {
      expect(ChargerCode.parse('VSTA-ABC_123').value, 'VSTA-ABC_123');
      expect(
        ChargerCode.parse('vtsa:///charge/VSTA-ABC_123').value,
        'VSTA-ABC_123',
      );
    });

    test('rejects foreign links and malformed codes', () {
      expect(
        () => ChargerCode.parse('https://example.test/charge/VSTA-ABC_123'),
        throwsFormatException,
      );
      expect(() => ChargerCode.parse('short'), throwsFormatException);
    });
  });

  test('parses canonical states, UTC instants, and integer measurements', () {
    final session = ChargingSession.fromJson({
      'id': '01K0M0JJ5X0M0JJ5X0M0JJ5X0Y',
      'state': 'suspended_by_evse',
      'site_id': '01K0M0JJ5X0M0JJ5X0M0JJ5X0Q',
      'station_name': 'Station A',
      'connector_id': '01K0M0JJ5X0M0JJ5X0M0JJ5X0T',
      'connector_name': 'Bay 2',
      'energy_wh': 12345,
      'duration_seconds': 901,
      'current_power_w': 48750,
      'estimated_cost_minor': 12345,
      'currency': 'php',
      'requested_at': '2026-07-26T11:00:00+08:00',
      'aggregate_version': 7,
      'payment_state': 'capture_pending',
      'updated_at': '2026-07-26T03:15:01Z',
    });

    expect(session.state, ChargingSessionState.suspendedByEvse);
    expect(session.paymentState, PaymentState.capturePending);
    expect(session.energyWh, 12345);
    expect(session.durationSeconds, 901);
    expect(session.currentPowerW, 48750);
    expect(session.estimatedCost?.minorUnits, 12345);
    expect(session.estimatedCost?.currency, 'PHP');
    expect(session.requestedAt, DateTime.utc(2026, 7, 26, 3));
    expect(session.isPhysicalActive, isTrue);
    expect(session.canRequestRemoteStop, isTrue);
  });

  test(
    'a stopping session is active evidence but cannot request stop again',
    () {
      final session = ChargingSession.fromJson({
        'id': '01K0M0JJ5X0M0JJ5X0M0JJ5X0Y',
        'state': 'stopping',
        'site_id': '01K0M0JJ5X0M0JJ5X0M0JJ5X0Q',
        'connector_id': '01K0M0JJ5X0M0JJ5X0M0JJ5X0T',
        'energy_wh': 1000,
        'duration_seconds': 60,
        'requested_at': '2026-07-26T03:00:00Z',
        'aggregate_version': 3,
        'payment_state': 'authorized',
      });

      expect(session.isPhysicalActive, isTrue);
      expect(session.canRequestRemoteStop, isFalse);
    },
  );
}
