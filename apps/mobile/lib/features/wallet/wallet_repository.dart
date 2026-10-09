import 'package:vtsa_mobile/core/network/api_client.dart';

abstract interface class WalletRepository {
  Future<Map<String, dynamic>> summary({String? cursor});
  Future<Map<String, dynamic>> topups({String? cursor});
  Future<Map<String, dynamic>> create(int amountMinor, String key);
  Future<Map<String, dynamic>> topup(String id);
}

final class ApiWalletRepository implements WalletRepository {
  const ApiWalletRepository(this.client);
  final ApiClient client;
  Future<Map<String, dynamic>> _get(String path, {String? cursor}) async {
    try {
      final response = await client.dio.get<Map<String, dynamic>>(
        path,
        queryParameters: {'cursor': ?cursor},
      );
      return Map<String, dynamic>.from(response.data!['data'] as Map);
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<Map<String, dynamic>> summary({String? cursor}) =>
      _get('/api/v1/wallet', cursor: cursor);
  @override
  Future<Map<String, dynamic>> topups({String? cursor}) =>
      _get('/api/v1/wallet/topups', cursor: cursor);
  @override
  Future<Map<String, dynamic>> topup(String id) =>
      _get('/api/v1/wallet/topups/$id');
  @override
  Future<Map<String, dynamic>> create(int amountMinor, String key) async {
    try {
      final response = await client.dio.post<Map<String, dynamic>>(
        '/api/v1/wallet/topups',
        data: {'amount_minor': amountMinor, 'idempotency_key': key},
      );
      return Map<String, dynamic>.from(response.data!['data'] as Map);
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }
}

int? parseWalletAmount(String input) {
  final value = input.trim();
  if (!RegExp(r'^\d{1,7}(?:\.\d{1,2})?$').hasMatch(value)) return null;
  final parts = value.split('.');
  return int.parse(parts.first) * 100 +
      (parts.length == 1 ? 0 : int.parse(parts.last.padRight(2, '0')));
}

String walletMoney(int minor) =>
    'PHP ${(minor ~/ 100)}.${(minor.abs() % 100).toString().padLeft(2, '0')}';
