import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/data/quick_unlock_store.dart';
import 'support/quick_unlock_fakes.dart';

void main() {
  test(
    'locked background requests never reach the server or expire the session',
    () async {
      final store = QuickUnlockStore(
        tokens: MemoryTokenStore(),
        settings: MemoryUnlockSettings(),
        device: FakeDeviceAuthenticator(),
      );
      final adapter = _ScriptedAdapter();
      var expired = false;
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: store,
        dio: Dio()..httpClientAdapter = adapter,
      )..onSessionExpired = () => expired = true;
      await expectLater(
        client.dio.get<void>('/api/v1/me'),
        throwsA(
          isA<DioException>().having(
            (error) => error.type,
            'type',
            DioExceptionType.cancel,
          ),
        ),
      );
      expect(adapter.requests, isEmpty);
      expect(expired, isFalse);
    },
  );
  test('retries remove credentials retained before a local lock', () async {
    final tokens = MemoryTokenStore()
      ..value = TokenBundle(
        accessToken: 'saved-token',
        expiresAt: DateTime.utc(2030),
        tenantId: 'tenant-a',
        deviceId: 'device-a',
      );
    final adapter = _ScriptedAdapter()
      ..responses.addAll([const _Reply(503, '{}'), const _Reply(200, '{}')]);
    final client = ApiClient(
      baseUrl: Uri.parse('https://api.example.test'),
      tokenStore: tokens,
      dio: Dio()..httpClientAdapter = adapter,
      retryDelay: (_) => tokens.clear(),
    );
    await client.dio.get<void>('/api/v1/me');
    expect(adapter.requests.last.headers.containsKey('Authorization'), isFalse);
    expect(adapter.requests.last.headers.containsKey('X-Tenant-ID'), isFalse);
  });

  test(
    'expired session without refresh token clears storage and notifies auth',
    () async {
      final tokens = MemoryTokenStore();
      await tokens.write(
        TokenBundle(
          accessToken: 'rejected-token',
          expiresAt: DateTime.utc(2026),
          tenantId: 'test-tenant',
          deviceId: 'test-device',
        ),
      );
      final adapter = _ScriptedAdapter()
        ..responses.add(const _Reply(401, '{}'));
      var expired = false;
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: tokens,
        dio: Dio()..httpClientAdapter = adapter,
      )..onSessionExpired = () => expired = true;
      await expectLater(
        client.dio.get<void>('/api/v1/me'),
        throwsA(isA<DioException>()),
      );
      expect(await tokens.read(), isNull);
      expect(expired, isTrue);
    },
  );

  test('anonymous login failure does not expire another session', () async {
    final adapter = _ScriptedAdapter()..responses.add(const _Reply(401, '{}'));
    var expired = false;
    final client = ApiClient(
      baseUrl: Uri.parse('https://api.example.test'),
      tokenStore: MemoryTokenStore(),
      dio: Dio()..httpClientAdapter = adapter,
    )..onSessionExpired = () => expired = true;
    await expectLater(
      client.dio.post<void>(
        '/api/v1/auth/login',
        options: Options(extra: {'anonymous': true}),
      ),
      throwsA(isA<DioException>()),
    );
    expect(expired, isFalse);
  });

  test('safe GET retries transient failure and mutation does not', () async {
    final adapter = _ScriptedAdapter();
    adapter.responses.addAll([
      const _Reply(503, '{}'),
      const _Reply(200, '{"ok":true}'),
      const _Reply(503, '{}'),
    ]);
    final dio = Dio()..httpClientAdapter = adapter;
    final client = ApiClient(
      baseUrl: Uri.parse('https://api.example.test'),
      tokenStore: MemoryTokenStore(),
      dio: dio,
      retryDelay: (_) async {},
    );

    final response = await client.dio.get<Map<String, dynamic>>('/safe');
    expect(response.data?['ok'], isTrue);
    await expectLater(
      client.dio.post<void>('/mutation'),
      throwsA(isA<DioException>()),
    );
    expect(
      adapter.requests.where((request) => request.path == '/safe'),
      hasLength(2),
    );
    expect(
      adapter.requests.where((request) => request.path == '/mutation'),
      hasLength(1),
    );
  });

  test(
    '401 uses single refresh and retries with rotated access token',
    () async {
      final tokens = MemoryTokenStore();
      await tokens.write(
        TokenBundle(
          accessToken: 'expired',
          refreshToken: 'refresh-reference',
          expiresAt: DateTime.utc(2026),
          tenantId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0N',
          deviceId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0P',
        ),
      );
      final adapter = _RefreshAdapter();
      final dio = Dio()..httpClientAdapter = adapter;
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: tokens,
        dio: dio,
        retryDelay: (_) async {},
      );

      final response = await client.dio.get<Map<String, dynamic>>('/protected');

      expect(response.data?['ok'], isTrue);
      expect(adapter.refreshCalls, 1);
      expect(adapter.protectedCalls, 2);
      expect((await tokens.read())?.accessToken, 'rotated');
    },
  );
}

final class _Reply {
  const _Reply(this.status, this.body);

  final int status;
  final String body;
}

final class _ScriptedAdapter implements HttpClientAdapter {
  final responses = <_Reply>[];
  final requests = <RequestOptions>[];

  @override
  void close({bool force = false}) {}

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    final reply = responses.removeAt(0);
    return ResponseBody.fromString(
      reply.body,
      reply.status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }
}

final class _RefreshAdapter implements HttpClientAdapter {
  var refreshCalls = 0;
  var protectedCalls = 0;

  @override
  void close({bool force = false}) {}

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    if (options.path == '/api/v1/auth/refresh') {
      refreshCalls++;
      return _json(200, {
        'data': {
          'token': 'rotated',
          'refresh_token': 'next-refresh-reference',
          'expires_at': '2030-01-01T00:00:00.000Z',
        },
      });
    }
    protectedCalls++;
    if (options.headers['Authorization'] == 'Bearer rotated') {
      return _json(200, {'ok': true});
    }
    return _json(401, {'message': 'Unauthenticated.'});
  }

  ResponseBody _json(int status, Map<String, Object?> body) =>
      ResponseBody.fromString(
        jsonEncode(body),
        status,
        headers: {
          Headers.contentTypeHeader: [Headers.jsonContentType],
        },
      );
}
