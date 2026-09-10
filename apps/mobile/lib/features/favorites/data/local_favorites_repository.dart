import 'package:shared_preferences/shared_preferences.dart';
import 'package:vtsa_mobile/features/favorites/domain/favorites_repository.dart';

final class LocalFavoritesRepository implements FavoritesRepository {
  LocalFavoritesRepository(this._preferences);

  final SharedPreferences _preferences;
  String _key(String ownerId) => 'favorites.v1.$ownerId';

  @override
  Future<Set<String>> list(String ownerId) async =>
      (_preferences.getStringList(_key(ownerId)) ?? const []).toSet();

  @override
  Future<void> set(String ownerId, Set<String> stationIds) async {
    final sorted = stationIds.toList()..sort();
    await _preferences.setStringList(_key(ownerId), sorted);
  }
}
