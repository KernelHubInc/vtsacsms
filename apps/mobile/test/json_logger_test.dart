import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/logging/json_logger.dart';

void main() {
  test('emits structured JSON with a UTC timestamp', () {
    String? output;
    final logger = JsonLogger(
      sink: (message) => output = message,
      now: () => DateTime.utc(2026, 7, 20, 4, 30),
    );

    logger.info(
      'test_event',
      context: {'request_id': '01K0M0JJ5X0M0JJ5X0M0JJ5X0M'},
    );

    final payload = jsonDecode(output!) as Map<String, Object?>;
    expect(payload['timestamp'], '2026-07-20T04:30:00.000Z');
    expect(payload['level'], 'info');
    expect(payload['event'], 'test_event');
    expect(payload['request_id'], '01K0M0JJ5X0M0JJ5X0M0JJ5X0M');
  });
}
