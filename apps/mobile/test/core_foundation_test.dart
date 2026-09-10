import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/config/app_environment.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/ids/ulid_generator.dart';
import 'package:vtsa_mobile/core/navigation/deep_link_handler.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';

void main() {
  test(
    'web defaults to the host-loopback API while native uses Android host',
    () {
      expect(
        AppEnvironment.resolveApiBaseUrl('', isWeb: true),
        'http://localhost:8000',
      );
      expect(
        AppEnvironment.resolveApiBaseUrl('', isWeb: false),
        'http://10.0.2.2:8000',
      );
      expect(
        AppEnvironment.resolveApiBaseUrl(
          'https://api.example.test',
          isWeb: true,
        ),
        'https://api.example.test',
      );
    },
  );

  test('ULID generator produces sortable public identifier shape', () {
    final ids = UlidGenerator(now: () => DateTime.utc(2026, 7, 23));
    final value = ids.next();

    expect(value, hasLength(26));
    expect(value, matches(RegExp(r'^[0-9A-HJKMNP-TV-Z]{26}$')));
  });

  test('deep links allow only approved station ULIDs and hosts', () {
    const handler = DeepLinkHandler();
    final valid = handler.resolve(
      Uri.parse('vtsa:///stations/01K0M0JJ5X0M0JJ5X0M0JJ5X0M'),
    );
    final foreign = handler.resolve(
      Uri.parse('https://attacker.test/stations/01K0M0JJ5X0M0JJ5X0M0JJ5X0M'),
    );

    expect(valid?.location, '/stations/01K0M0JJ5X0M0JJ5X0M0JJ5X0M');
    expect(foreign, isNull);
  });

  test('API errors retain safe code, fields, and correlation ID', () {
    final failure = AppFailure.fromDio(
      DioException.badResponse(
        statusCode: 422,
        requestOptions: RequestOptions(path: '/example'),
        response: Response<Map<String, Object?>>(
          requestOptions: RequestOptions(path: '/example'),
          statusCode: 422,
          data: {
            'code': 'validation_failed',
            'message': 'Check the supplied details.',
            'correlation_id': '01K0M0JJ5X0M0JJ5X0M0JJ5X0M',
            'errors': {
              'email': ['Email is required.'],
            },
          },
        ),
      ),
    );

    expect(failure.kind, FailureKind.validation);
    expect(failure.code, 'validation_failed');
    expect(failure.fieldErrors['email'], ['Email is required.']);
    expect(failure.correlationId, '01K0M0JJ5X0M0JJ5X0M0JJ5X0M');
  });

  test('memory token store supports revocation', () async {
    final store = MemoryTokenStore();
    await store.write(
      TokenBundle(
        accessToken: 'access',
        expiresAt: DateTime.utc(2030),
        tenantId: 'tenant',
        deviceId: 'device',
      ),
    );

    expect((await store.read())?.accessToken, 'access');
    await store.clear();
    expect(await store.read(), isNull);
  });
}
