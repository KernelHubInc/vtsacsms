import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';

final class ChargingAccount {
  const ChargingAccount({
    required this.id,
    required this.name,
    required this.type,
    required this.role,
    required this.permissions,
  });
  factory ChargingAccount.fromJson(Map<String, dynamic> json) =>
      ChargingAccount(
        id: json['id'] as String,
        name: json['name'] as String,
        type: json['type'] as String,
        role: json['role'] as String,
        permissions: (json['permissions'] as List).cast<String>().toSet(),
      );
  final String id;
  final String name;
  final String type;
  final String role;
  final Set<String> permissions;
  bool get personal => type == 'personal';
  String get typeLabel => switch (type) {
    'business' => 'Business',
    'fleet' => 'Fleet',
    _ => 'Personal',
  };
  String get description => switch (type) {
    'business' => 'Shared company vehicles and a separate company wallet.',
    'fleet' =>
      'Shared fleet vehicles, driver assignments and a separate fleet wallet.',
    _ => 'Only you can use your private vehicles and personal wallet.',
  };
  String get roleLabel => chargingRoleLabel(role);
  bool can(String action) =>
      permissions.contains('organizations.charging_accounts.$action');
}

abstract interface class ChargingAccountRepository {
  Future<List<ChargingAccount>> list();
  Future<Object?> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? data,
  });
}

final class ApiChargingAccountRepository implements ChargingAccountRepository {
  const ApiChargingAccountRepository(this.client);
  final ApiClient client;
  @override
  Future<Object?> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? data,
  }) async {
    try {
      final result = await client.dio.request<Map<String, dynamic>>(
        '/api/v1/charging-accounts$path',
        data: data,
        options: Options(method: method),
      );
      return result.data!['data'];
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<List<ChargingAccount>> list() async => (await request('') as List)
      .map(
        (item) =>
            ChargingAccount.fromJson(Map<String, dynamic>.from(item as Map)),
      )
      .toList();
}

final class ChargingAccountsController extends ChangeNotifier {
  ChargingAccountsController(this.repository, {required this.ownerId});
  final ChargingAccountRepository repository;
  final String Function() ownerId;
  List<ChargingAccount> accounts = const [];
  ChargingAccount? selected;
  AppFailure? failure;
  bool loading = false;
  int _generation = 0;

  String? get accountId => selected?.id;
  bool get shared => selected != null && !selected!.personal;
  Options get options =>
      Options(headers: {'X-Charging-Account-ID': ?accountId});

  Future<void> load() async {
    final generation = ++_generation;
    final owner = ownerId();
    loading = true;
    failure = null;
    notifyListeners();
    try {
      final result = await repository.list();
      if (generation != _generation || owner != ownerId()) return;
      accounts = result;
      final previous = selected;
      // Never silently fall back to a personal payer after shared access is revoked.
      selected = previous == null
          ? result.where((a) => a.personal).firstOrNull
          : result.where((a) => a.id == previous.id).firstOrNull ??
                ChargingAccount(
                  id: previous.id,
                  name: previous.name,
                  type: previous.type,
                  role: 'revoked',
                  permissions: const {},
                );
    } on AppFailure catch (error) {
      if (generation == _generation && owner == ownerId()) failure = error;
    } finally {
      if (generation == _generation) {
        loading = false;
        notifyListeners();
      }
    }
  }

  void select(String id) {
    if (selected?.id == id) return;
    selected = accounts.firstWhere((a) => a.id == id);
    failure = null;
    notifyListeners();
  }

  void addAndSelect(ChargingAccount account) {
    _generation++;
    loading = false;
    accounts = [...accounts.where((item) => item.id != account.id), account];
    selected = account;
    failure = null;
    notifyListeners();
  }

  void clear() {
    _generation++;
    accounts = const [];
    selected = null;
    failure = null;
    loading = false;
    notifyListeners();
  }

  @override
  void dispose() {
    _generation++;
    super.dispose();
  }
}

String chargingRoleLabel(String role) => switch (role) {
  'owner' => 'Owner',
  'admin' => 'Admin',
  'billing_manager' => 'Billing manager',
  'fleet_manager' => 'Fleet manager',
  'driver' => 'Driver',
  'revoked' => 'Access removed',
  _ => role,
};

String chargingRoleDescription(String role) => switch (role) {
  'owner' =>
    'Manage members, vehicles, funding, spending caps and all account charging.',
  'admin' =>
    'Manage vehicles, invite drivers or fleet managers, and manage charging. No wallet access.',
  'billing_manager' =>
    'View and top up the shared wallet and view account charging history. Cannot start charging.',
  'fleet_manager' =>
    'Manage vehicles and driver assignments, view history and manage charging. No wallet access.',
  'driver' =>
    'Charge assigned vehicles using the shared wallet and view your own sessions. Cannot view or top up its balance.',
  _ =>
    'Ask the account owner to restore your access, or select another account.',
};
