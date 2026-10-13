import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_widgets.dart';

Map<String, dynamic> stationPayload() => {
  'id': '01J00000000000000000000001',
  'site_id': '01J00000000000000000000002',
  'name': 'Physical charger',
  'site_name': 'Published site',
  'latitude': 14.6,
  'longitude': 121,
  'timezone': 'Asia/Manila',
  'site_type': 'retail',
  'operator_id': '01J00000000000000000000003',
  'operator_name': 'Operator',
  'availability': 'available',
  'connection_status': 'online',
  'last_seen_at': '2026-10-10T09:00:00Z',
  'is_stale': false,
  'maximum_power_w': 22000,
  'open_now': true,
  'connectors': [
    {
      'id': '01J00000000000000000000004',
      'standard': 'type2',
      'name': 'Type 2',
      'maximum_power_w': 22000,
      'availability': 'available',
    },
  ],
};

void main() {
  testWidgets('closing a station preview does not open its details', (
    tester,
  ) async {
    var closed = false;
    var opened = false;
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: Scaffold(
          body: StationCard(
            station: Station.fromJson(stationPayload()),
            onTap: () => opened = true,
            favorite: false,
            onFavorite: () {},
            onClose: () => closed = true,
            selected: true,
          ),
        ),
      ),
    );
    await tester.tap(find.byTooltip('Close station preview'));
    expect(closed, isTrue);
    expect(opened, isFalse);
  });
  test(
    'detailed occupied states remain distinct and expire with cached status',
    () {
      for (final entry in {
        'preparing': 'Preparing',
        'charging': 'Charging',
        'suspendedev': 'Paused by vehicle',
        'suspendedevse': 'Paused by charger',
        'finishing': 'Finishing',
      }.entries) {
        final payload = stationPayload()
          ..['availability'] = 'busy'
          ..['operational_status'] = 'busy'
          ..['connectors'] = [
            {
              'standard': 'TYPE_2',
              'name': 'Type 2',
              'maximum_power_w': 7000,
              'availability': 'occupied',
              'reported_status': entry.key,
            },
          ];
        final station = Station.fromJson(payload);
        expect(station.connectors.single.statusLabel, entry.value);
        expect(availabilityLabel(station), entry.value);
        expect(station.asStale().connectors.single.reportedStatus, isNull);
        expect(availabilityLabel(station.asStale()), 'Status stale');
      }
    },
  );
  test('online unavailable is distinct from offline, with legacy fallback', () {
    final payload = stationPayload()
      ..['availability'] = 'offline'
      ..['operational_status'] = 'unavailable';
    final station = Station.fromJson(payload);
    expect(availabilityLabel(station), 'Unavailable');
    expect(station.connectionLabel, 'Charger online');
    payload.remove('operational_status');
    expect(availabilityLabel(Station.fromJson(payload)), 'Offline');
  });

  test('stale snapshots retain location but make readiness unconfirmed', () {
    final original = Station.fromJson(stationPayload());
    final stale = original.asStale();
    expect(stale.id, original.id);
    expect(stale.latitude, original.latitude);
    expect(stale.lastSeenAt, original.lastSeenAt);
    expect(stale.connectionLabel, 'Connection unconfirmed');
    expect(availabilityLabel(stale), 'Status stale');
    expect(stale.connectors.single.statusLabel, 'Status stale');
    expect(original.connectors.single.statusLabel, 'Available');
  });

  testWidgets('card identifies the station and explains missing connectors', (
    tester,
  ) async {
    final station = Station.fromJson(
      stationPayload()
        ..['connectors'] = <Object?>[]
        ..['maximum_power_w'] = 0
        ..['availability'] = 'unknown',
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: Scaffold(
          body: StationCard(
            station: station,
            onTap: () {},
            favorite: false,
            onFavorite: () {},
          ),
        ),
      ),
    );
    expect(find.text('Physical charger'), findsOneWidget);
    expect(find.textContaining('Published site'), findsOneWidget);
    expect(find.text('Charger online'), findsOneWidget);
    expect(find.text('Connector details pending'), findsOneWidget);
    expect(find.text('Power not provided'), findsOneWidget);
  });

  testWidgets('connector tile shows unavailable rather than offline', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: const Scaffold(
          body: ConnectorCompatibilityTile(
            connector: StationConnector(
              standard: 'type2',
              name: 'Type 2',
              maximumPowerW: 22000,
              availability: 'unavailable',
            ),
          ),
        ),
      ),
    );
    expect(find.textContaining('Unavailable'), findsOneWidget);
    expect(find.textContaining('Offline'), findsNothing);
  });
}
