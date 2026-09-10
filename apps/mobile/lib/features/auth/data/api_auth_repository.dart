import 'package:dio/dio.dart';
import 'package:vtsa_mobile/core/config/app_environment.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/domain/auth_repository.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';

final class ApiAuthRepository implements AuthRepository {
  factory ApiAuthRepository({
    required ApiClient client,
    required TokenStore tokens,
    required AppEnvironment environment,
  }) => ApiAuthRepository._(client, tokens, environment);

  ApiAuthRepository._(this._client, this._tokens, this._environment);

  final ApiClient _client;
  final TokenStore _tokens;
  final AppEnvironment _environment;

  @override
  Future<({TokenBundle tokens, UserProfile user})> login({
    required String email,
    required String password,
  }) async {
    if (!_environment.hasTenant) {
      throw StateError(
        'DEFAULT_TENANT_ID is required for the current login contract.',
      );
    }
    try {
      final response = await _client.dio.post<Map<String, dynamic>>(
        '/api/v1/auth/login',
        data: {
          'email': email.trim().toLowerCase(),
          'password': password,
          'tenant_id': _environment.defaultTenantId,
          'device_name': 'Power Solutions consumer app',
        },
        options: Options(extra: {'anonymous': true}),
      );
      final data = response.data!['data']! as Map<String, dynamic>;
      final tokens = TokenBundle(
        accessToken: data['token']! as String,
        refreshToken: data['refresh_token'] as String?,
        expiresAt: DateTime.parse(data['expires_at']! as String).toUtc(),
        tenantId: data['tenant_id']! as String,
        deviceId: data['device_id']! as String,
      );
      final userJson = data['user'] as Map<String, dynamic>?;
      await _tokens.write(tokens);
      final user = userJson == null
          ? await currentUser()
          : UserProfile.fromJson(userJson);
      return (tokens: tokens, user: user);
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<void> register({
    required String name,
    required String email,
    required String password,
  }) async {
    try {
      await _client.dio.post<void>(
        '/api/v1/auth/register',
        data: {
          'name': name.trim(),
          'email': email.trim().toLowerCase(),
          'password': password,
          'password_confirmation': password,
          if (_environment.hasTenant) 'tenant_id': _environment.defaultTenantId,
        },
        options: Options(extra: {'anonymous': true}),
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<UserProfile> currentUser() async {
    try {
      final response = await _client.dio.get<Map<String, dynamic>>(
        '/api/v1/me',
      );
      final body = response.data!;
      final data = body['data'] as Map<String, dynamic>? ?? body;
      final user = data['user'] as Map<String, dynamic>? ?? data;
      return UserProfile.fromJson(user);
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<void> logout() async {
    try {
      await _client.dio.post<void>('/api/v1/auth/logout');
    } on DioException catch (error) {
      if (error.response?.statusCode != 401) {
        ApiClient.throwFailure(error);
      }
    } finally {
      await _tokens.clear();
    }
  }

  @override
  Future<void> requestPasswordReset(String email) async {
    try {
      await _client.dio.post<void>(
        '/api/v1/auth/forgot-password',
        data: {'email': email.trim().toLowerCase()},
        options: Options(extra: {'anonymous': true}),
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<void> resendEmailVerification() async {
    try {
      await _client.dio.post<void>(
        '/api/v1/auth/email/verification-notification',
      );
    } on Object catch (error) {
      ApiClient.throwFailure(error);
    }
  }
}
