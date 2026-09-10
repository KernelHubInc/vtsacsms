import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/features/favorites/domain/favorites_repository.dart';

final class FavoritesController extends ChangeNotifier {
  factory FavoritesController({
    required FavoritesRepository repository,
    required String Function() ownerId,
  }) => FavoritesController._(repository, ownerId);

  FavoritesController._(this._repository, this._ownerId);

  final FavoritesRepository _repository;
  final String Function() _ownerId;
  Set<String> _ids = const {};

  Set<String> get ids => _ids;
  bool contains(String stationId) => _ids.contains(stationId);

  Future<void> load() async {
    _ids = await _repository.list(_ownerId());
    notifyListeners();
  }

  Future<void> toggle(String stationId) async {
    final updated = {..._ids};
    if (!updated.remove(stationId)) {
      updated.add(stationId);
    }
    _ids = updated;
    notifyListeners();
    await _repository.set(_ownerId(), updated);
  }
}
