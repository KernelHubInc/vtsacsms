import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';

void main() {
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
