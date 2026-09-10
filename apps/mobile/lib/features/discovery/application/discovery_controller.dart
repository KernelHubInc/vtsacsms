import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/domain/station_repository.dart';

final class DiscoveryController extends ChangeNotifier {
  DiscoveryController(this._repository);

  final StationRepository _repository;
  List<Station> _stations = const [];
  StationFilters _filters = const StationFilters();
  GeoBounds? _lastBounds;
  DateTime? _generatedAt;
  String _query = '';
  String? _selectedStationId;
  AppFailure? _failure;
  bool _loading = false;
  int _requestGeneration = 0;

  List<Station> get stations {
    if (_query.isEmpty) {
      return _stations;
    }
    final needle = _query.toLowerCase();
    return _stations
        .where(
          (station) =>
              station.name.toLowerCase().contains(needle) ||
              station.siteName.toLowerCase().contains(needle) ||
              (station.address?.toLowerCase().contains(needle) ?? false) ||
              station.operatorName.toLowerCase().contains(needle),
        )
        .toList(growable: false);
  }

  StationFilters get filters => _filters;
  DateTime? get generatedAt => _generatedAt;
  String? get selectedStationId => _selectedStationId;
  AppFailure? get failure => _failure;
  bool get isLoading => _loading;

  Station? byId(String id) =>
      _stations.where((item) => item.id == id).firstOrNull;

  Future<void> loadBounds(GeoBounds bounds) async {
    _lastBounds = bounds;
    final generation = ++_requestGeneration;
    _loading = true;
    _failure = null;
    notifyListeners();
    try {
      final result = await _repository.inBounds(
        bounds: bounds,
        filters: _filters,
      );
      if (generation != _requestGeneration) {
        return;
      }
      _stations = result.stations;
      _generatedAt = result.generatedAt;
    } on AppFailure catch (failure) {
      if (generation == _requestGeneration) {
        _failure = failure;
      }
    } finally {
      if (generation == _requestGeneration) {
        _loading = false;
        notifyListeners();
      }
    }
  }

  Future<void> loadNearby({
    required double latitude,
    required double longitude,
    int radiusM = 10000,
  }) async {
    final generation = ++_requestGeneration;
    _loading = true;
    _failure = null;
    notifyListeners();
    try {
      final result = await _repository.nearby(
        latitude: latitude,
        longitude: longitude,
        radiusM: radiusM,
        filters: _filters,
      );
      if (generation == _requestGeneration) {
        _stations = result.stations;
        _generatedAt = result.generatedAt;
      }
    } on AppFailure catch (failure) {
      if (generation == _requestGeneration) {
        _failure = failure;
      }
    } finally {
      if (generation == _requestGeneration) {
        _loading = false;
        notifyListeners();
      }
    }
  }

  Future<void> updateFilters(StationFilters filters) async {
    _filters = filters;
    notifyListeners();
    final bounds = _lastBounds;
    if (bounds != null) {
      await loadBounds(bounds);
    }
  }

  void search(String query) {
    _query = query.trim();
    notifyListeners();
  }

  void select(String? stationId) {
    _selectedStationId = stationId;
    notifyListeners();
  }

  Future<void> refresh() async {
    final bounds = _lastBounds;
    if (bounds != null) {
      await loadBounds(bounds);
    }
  }
}
