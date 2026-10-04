import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/config/app_environment.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/data/api_auth_repository.dart';

void main() {
  for (final sent in [true, false, null]) {
    test('registration handles verification email result: $sent', () async {
      final tokens = MemoryTokenStore();
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: tokens,
      );
      client.dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (request, handler) {
            expect(request.path, '/api/v1/auth/register');
            expect(request.extra['anonymous'], isTrue);
            handler.resolve(
              Response(
                requestOptions: request,
                statusCode: 201,
                data: {
                  'data': {'verification_email_sent': ?sent},
                },
              ),
            );
          },
        ),
      );
      final repository = ApiAuthRepository(
        client: client,
        tokens: tokens,
        environment: AppEnvironment.fromDefines(),
      );
      final result = await repository.register(
        name: 'Test driver',
        email: 'driver@example.test',
        password: 'TestOnlyPassword!2026',
      );
      expect(result.verificationEmailSent, sent ?? true);
      expect(await tokens.read(), isNull);
    });
  }
}
