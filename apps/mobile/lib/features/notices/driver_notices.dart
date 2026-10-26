import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';

final class DriverNotice {
  const DriverNotice({
    required this.id,
    required this.category,
    required this.title,
    required this.message,
    required this.showOnOpen,
    required this.startsAt,
    required this.endsAt,
  });

  factory DriverNotice.fromJson(Map<String, dynamic> json) => DriverNotice(
    id: json['id'] as String,
    category: json['category'] as String,
    title: json['title'] as String,
    message: json['message'] as String,
    showOnOpen: json['show_on_open'] as bool,
    startsAt: DateTime.parse(json['starts_at'] as String).toUtc(),
    endsAt: DateTime.parse(json['ends_at'] as String).toUtc(),
  );

  final String id;
  final String category;
  final String title;
  final String message;
  final bool showOnOpen;
  final DateTime startsAt;
  final DateTime endsAt;

  bool isActiveAt(DateTime now) =>
      !startsAt.isAfter(now.toUtc()) && endsAt.isAfter(now.toUtc());
}

abstract interface class DriverNoticeRepository {
  Future<List<DriverNotice>> list();
}

final class ApiDriverNoticeRepository implements DriverNoticeRepository {
  const ApiDriverNoticeRepository(this.client);

  final ApiClient client;

  @override
  Future<List<DriverNotice>> list() async {
    try {
      final response = await client.dio.get<Map<String, dynamic>>(
        '/api/v1/mobile/notices',
      );
      return (response.data!['data'] as List)
          .map(
            (item) =>
                DriverNotice.fromJson(Map<String, dynamic>.from(item as Map)),
          )
          .toList();
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }
}

final class DriverNoticesController extends ChangeNotifier {
  DriverNoticesController(this.repository);

  final DriverNoticeRepository repository;
  List<DriverNotice> _items = const [];
  bool _loading = false;
  bool _failed = false;
  int _generation = 0;
  final Set<String> _dismissedBannerIds = {};

  bool get loading => _loading;
  bool get failed => _failed;
  List<DriverNotice> get items => _items
      .where((item) => item.isActiveAt(DateTime.now().toUtc()))
      .toList(growable: false);

  DriverNotice? get openingBanner {
    for (final item in items) {
      if (item.showOnOpen && !_dismissedBannerIds.contains(item.id)) {
        return item;
      }
    }
    return null;
  }

  Future<void> refresh() async {
    if (_loading) return;
    final generation = _generation;
    _loading = true;
    _failed = false;
    notifyListeners();
    try {
      final result = await repository.list();
      if (generation != _generation) return;
      _items = result;
    } catch (_) {
      if (generation != _generation) return;
      _failed = true;
    } finally {
      if (generation == _generation) {
        _loading = false;
        notifyListeners();
      }
    }
  }

  void dismissBanner(String id) {
    _dismissedBannerIds.add(id);
    notifyListeners();
  }

  void clear() {
    _generation++;
    _items = const [];
    _loading = false;
    _failed = false;
    _dismissedBannerIds.clear();
    notifyListeners();
  }
}
