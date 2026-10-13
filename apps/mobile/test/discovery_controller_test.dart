import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/features/discovery/application/discovery_controller.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/domain/station_repository.dart';

import 'station_status_test.dart' show stationPayload;
import 'support/fakes.dart';

void main() {
  test(
    'failed refresh keeps station visible with stale signals until recovery',
    () async {
      final repository = _RecoveringStationRepository();
      final controller = DiscoveryController(repository);
      await controller.loadNearby(latitude: 14.6, longitude: 121);
      expect(controller.stations.single.connectionStatus, 'online');
      repository.fail = true;
      await controller.refresh();
      expect(controller.stations.single.isStale, isTrue);
      expect(
        controller.stations.single.connectors.single.availability,
        'stale',
      );
      expect(
        controller.byId(controller.stations.single.id)!.connectionStatus,
        'unknown',
      );
      repository.fail = false;
      await controller.refresh();
      expect(controller.stations.single.isStale, isFalse);
      expect(controller.stations.single.connectionStatus, 'online');
      controller.dispose();
    },
  );

  testWidgets(
    'phone offline invalidates signals without marking charger offline',
    (tester) async {
      final controller = DiscoveryController(_RecoveringStationRepository());
      await controller.loadNearby(latitude: 14.6, longitude: 121);
      controller.startAutoRefresh(canRefresh: () => false);
      await tester.pump(const Duration(seconds: 5));
      expect(controller.stations.single.isStale, isTrue);
      expect(controller.stations.single.connectionStatus, 'unknown');
      controller.dispose();
    },
  );

  testWidgets(
    'a hanging refresh cannot keep cached availability live indefinitely',
    (tester) async {
      var now = DateTime.utc(2026, 10, 10);
      final repository = _RecoveringStationRepository();
      final controller = DiscoveryController(repository, now: () => now);
      await controller.loadNearby(latitude: 14.6, longitude: 121);
      repository.pending = Completer<StationSearchResult>();
      controller.startAutoRefresh();
      await tester.pump(const Duration(seconds: 5));
      now = now.add(const Duration(seconds: 15));
      await tester.pump(const Duration(seconds: 10));
      expect(controller.stations.single.isStale, isTrue);
      repository.pending!.complete(
        StationSearchResult(
          stations: [Station.fromJson(stationPayload())],
          generatedAt: now,
        ),
      );
      await tester.pump();
      expect(controller.stations.single.isStale, isFalse);
      controller.dispose();
    },
  );

  testWidgets(
    'refreshes nearby availability and stops when paused or disposed',
    (tester) async {
      final repository = _PollingStationRepository();
      final controller = DiscoveryController(repository);
      await controller.loadNearby(latitude: 14.6, longitude: 121);
      var online = true;
      controller.startAutoRefresh(canRefresh: () => online);
      expect(repository.calls, 1);
      await tester.pump(const Duration(seconds: 5));
      expect(repository.calls, 2);
      expect(controller.stations, isEmpty);
      expect(controller.isLoading, isFalse);
      online = false;
      await tester.pump(const Duration(seconds: 5));
      expect(repository.calls, 2);
      controller.stopAutoRefresh();
      online = true;
      await tester.pump(const Duration(seconds: 5));
      expect(repository.calls, 2);
      controller.startAutoRefresh();
      await tester.pump(const Duration(seconds: 5));
      expect(repository.calls, 3);
      controller.dispose();
      await tester.pump(const Duration(seconds: 10));
      expect(repository.calls, 3);
    },
  );

  test('response after disposal is ignored', () async {
    final repository = _DelayedStationRepository();
    final controller = DiscoveryController(repository);
    final pending = controller.loadBounds(
      const GeoBounds(west: 1, south: 1, east: 2, north: 2),
    );
    controller.dispose();
    repository.first.complete(
      StationSearchResult(
        stations: [sampleStation],
        generatedAt: DateTime.utc(2026),
      ),
    );
    await pending;
    expect(controller.stations, isEmpty);
  });

  test('camera viewport is sent as server-side bounds', () async {
    final repository = FakeStationRepository();
    final controller = DiscoveryController(repository);
    const bounds = GeoBounds(
      west: 120.1,
      south: 14.1,
      east: 121.1,
      north: 15.1,
    );

    await controller.loadBounds(bounds);

    expect(repository.lastBounds, same(bounds));
    expect(controller.stations, hasLength(1));
  });

  test('late viewport response cannot overwrite the latest map area', () async {
    final repository = _DelayedStationRepository();
    final controller = DiscoveryController(repository);
    final first = controller.loadBounds(
      const GeoBounds(west: 1, south: 1, east: 2, north: 2),
    );
    final second = controller.loadBounds(
      const GeoBounds(west: 3, south: 3, east: 4, north: 4),
    );

    repository.second.complete(
      StationSearchResult(
        stations: [sampleStation],
        generatedAt: DateTime.utc(2026),
      ),
    );
    await second;
    repository.first.complete(
      StationSearchResult(stations: const [], generatedAt: DateTime.utc(2025)),
    );
    await first;

    expect(controller.stations, [sampleStation]);
    expect(controller.generatedAt, DateTime.utc(2026));
  });
}

final class _RecoveringStationRepository implements StationRepository {
  bool fail = false;
  Completer<StationSearchResult>? pending;

  @override
  Future<StationSearchResult> nearby({
    required double latitude,
    required double longitude,
    required int radiusM,
    required StationFilters filters,
  }) async {
    if (fail) {
      throw const AppFailure(kind: FailureKind.server, message: 'Unavailable');
    }
    if (pending != null) return pending!.future;
    return StationSearchResult(
      stations: [Station.fromJson(stationPayload())],
      generatedAt: DateTime.utc(2026, 10, 10),
    );
  }

  @override
  Future<StationSearchResult> inBounds({
    required GeoBounds bounds,
    required StationFilters filters,
  }) =>
      nearby(latitude: 14.6, longitude: 121, radiusM: 10000, filters: filters);
}

final class _DelayedStationRepository implements StationRepository {
  final first = Completer<StationSearchResult>();
  final second = Completer<StationSearchResult>();
  var calls = 0;

  @override
  Future<StationSearchResult> inBounds({
    required GeoBounds bounds,
    required StationFilters filters,
  }) => calls++ == 0 ? first.future : second.future;

  @override
  Future<StationSearchResult> nearby({
    required double latitude,
    required double longitude,
    required int radiusM,
    required StationFilters filters,
  }) => throw UnimplementedError();
}

final class _PollingStationRepository implements StationRepository {
  int calls = 0;

  @override
  Future<StationSearchResult> nearby({
    required double latitude,
    required double longitude,
    required int radiusM,
    required StationFilters filters,
  }) async {
    calls++;
    return StationSearchResult(
      stations: calls == 1 ? [sampleStation] : [],
      generatedAt: DateTime.utc(2026),
    );
  }

  @override
  Future<StationSearchResult> inBounds({
    required GeoBounds bounds,
    required StationFilters filters,
  }) => throw UnimplementedError();
}
