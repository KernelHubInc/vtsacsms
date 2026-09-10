abstract interface class FavoritesRepository {
  Future<Set<String>> list(String ownerId);
  Future<void> set(String ownerId, Set<String> stationIds);
}
