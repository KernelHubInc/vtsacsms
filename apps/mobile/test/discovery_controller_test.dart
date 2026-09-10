import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/features/discovery/application/discovery_controller.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/domain/station_repository.dart';

import 'support/fakes.dart';

void main() {
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
