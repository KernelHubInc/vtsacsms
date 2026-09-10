import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:vtsa_mobile/features/charging/data/active_session_store.dart';

void main() {
  test('stores only an account-scoped recovery pointer', () async {
    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final store = LocalActiveSessionStore(preferences);
    final reference = ActiveSessionReference(
      ownerId: 'driver-1',
      sessionId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0Y',
      savedAt: DateTime.utc(2026, 7, 26, 3),
    );

    await store.write(reference);

    expect((await store.read('driver-1'))?.sessionId, reference.sessionId);
    expect(await store.read('driver-2'), isNull);
    final encoded = preferences.getString('active_charging_session.v1')!;
    final fields = (jsonDecode(encoded) as Map<String, dynamic>).keys.toSet();
    expect(fields, {'owner_id', 'session_id', 'saved_at'});
    expect(encoded, isNot(contains('energy_wh')));
    expect(encoded, isNot(contains('payment')));
    expect(encoded, isNot(contains('tariff')));
  });
}
