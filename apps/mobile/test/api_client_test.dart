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
  test('concurrent unauthorized requests share one refresh', () async {
    final tokens = MemoryTokenStore()
      ..value = _session('old', refresh: 'old-refresh');
    final adapter = _RefreshAdapter()
      ..waitForRefresh = Completer<void>()
      ..refreshStarted = Completer<void>();
    final client = ApiClient(
      baseUrl: Uri.parse('https://api.example.test'),
      tokenStore: tokens,
      dio: Dio()..httpClientAdapter = adapter,
    );
    final requests = Future.wait([
      client.dio.get<void>('/first'),
      client.dio.get<void>('/second'),
    ]);
    await adapter.refreshStarted!.future;
    await Future<void>.delayed(Duration.zero);
    adapter.waitForRefresh!.complete();
    await requests;
    expect(adapter.refreshCalls, 1);
    expect(adapter.protectedCalls, 4);
    expect((await tokens.read())?.accessToken, 'rotated');
  });

  test(
    'rejected rotated token expires the current session without hanging',
    () async {
      final tokens = MemoryTokenStore()
        ..value = _session('old', refresh: 'old-refresh');
      final adapter = _RefreshAdapter()..rejectRotated = true;
      var expirations = 0;
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: tokens,
        dio: Dio()..httpClientAdapter = adapter,
      )..onSessionExpired = () => expirations++;
      await expectLater(
        client.dio.get<void>('/protected'),
        throwsA(isA<DioException>()),
      );
      expect(await tokens.read(), isNull);
      expect(expirations, 1);
      expect(adapter.refreshCalls, 1);
      expect(adapter.protectedCalls, 2);
    },
  );

  test(
    'account switch before refresh retry cancels the original mutation',
    () async {
      final tokens = MemoryTokenStore()
        ..value = _session('old', refresh: 'old-refresh');
      final adapter = _RefreshAdapter();
      final dio = Dio()..httpClientAdapter = adapter;
      dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (request, handler) async {
            if (request.extra['auth_retried'] == true) {
              await tokens.write(_session('new', tenant: 'tenant-b'));
            }
            handler.next(request);
          },
        ),
      );
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: tokens,
        dio: dio,
      );
      var expired = false;
      client.onSessionExpired = () => expired = true;
      await expectLater(
        client.dio.post<void>('/api/v1/mobile/support/issues'),
        throwsA(
          isA<DioException>().having(
            (error) => error.type,
            'type',
            DioExceptionType.cancel,
          ),
        ),
      );
      expect(adapter.protectedCalls, 1);
      expect((await tokens.read())?.accessToken, 'new');
      expect(expired, isFalse);
    },
  );

  test(
    'guest protected-request failure does not report session expiry',
    () async {
      final adapter = _ScriptedAdapter()
        ..responses.add(const _Reply(401, '{}'));
      var expired = false;
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: MemoryTokenStore(),
        dio: Dio()..httpClientAdapter = adapter,
      )..onSessionExpired = () => expired = true;
      await expectLater(
        client.dio.get<void>('/api/v1/mobile/notices'),
        throwsA(isA<DioException>()),
      );
      expect(expired, isFalse);
      expect(adapter.requests, hasLength(1));
    },
  );

  test(
    'old-account rejection neither clears nor replays under a new account',
    () async {
      final tokens = MemoryTokenStore()
        ..value = _session('old', tenant: 'tenant-a');
      final adapter = _DelayedAuthAdapter();
      var expired = false;
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: tokens,
        dio: Dio()..httpClientAdapter = adapter,
      )..onSessionExpired = () => expired = true;
      final checked = expectLater(
        client.dio.post<void>('/api/v1/mobile/support/issues'),
        throwsA(isA<DioException>()),
      );
      await adapter.protectedStarted.future;
      await tokens.write(_session('new', tenant: 'tenant-b'));
      adapter.rejected.complete();
      await checked;
      expect((await tokens.read())?.accessToken, 'new');
      expect((await tokens.read())?.tenantId, 'tenant-b');
      expect(adapter.protectedCalls, 1);
      expect(adapter.refreshCalls, 0);
      expect(expired, isFalse);
    },
  );

  for (final refreshStatus in [200, 401]) {
    test(
      'late $refreshStatus refresh cannot overwrite or clear a newer login',
      () async {
        final tokens = MemoryTokenStore()
          ..value = _session('old', refresh: 'old-refresh');
        final adapter = _DelayedAuthAdapter(refreshStatus: refreshStatus);
        var expired = false;
        final client = ApiClient(
          baseUrl: Uri.parse('https://api.example.test'),
          tokenStore: tokens,
          dio: Dio()..httpClientAdapter = adapter,
        )..onSessionExpired = () => expired = true;
        final checked = expectLater(
          client.dio.get<void>('/api/v1/me'),
          throwsA(isA<DioException>()),
        );
        await adapter.protectedStarted.future;
        adapter.rejected.complete();
        await adapter.refreshStarted.future;
        await tokens.write(_session('new', tenant: 'tenant-b'));
        adapter.refreshed.complete();
        await checked;
        expect((await tokens.read())?.accessToken, 'new');
        expect(adapter.protectedCalls, 1);
        expect(expired, isFalse);
      },
    );
  }

  test('late successful refresh cannot restore a logged-out session', () async {
    final tokens = MemoryTokenStore()
      ..value = _session('old', refresh: 'old-refresh');
    final adapter = _DelayedAuthAdapter(refreshStatus: 200);
    var expired = false;
    final client = ApiClient(
      baseUrl: Uri.parse('https://api.example.test'),
      tokenStore: tokens,
      dio: Dio()..httpClientAdapter = adapter,
    )..onSessionExpired = () => expired = true;
    final checked = expectLater(
      client.dio.get<void>('/api/v1/me'),
      throwsA(isA<DioException>()),
    );
    await adapter.protectedStarted.future;
    adapter.rejected.complete();
    await adapter.refreshStarted.future;
    await tokens.clear();
    adapter.refreshed.complete();
    await checked;
    expect(await tokens.read(), isNull);
    expect(adapter.protectedCalls, 1);
    expect(expired, isFalse);
  });

  test(
    'multipart ticket upload can be retried after token refresh with the same intent key',
    () async {
      final tokens = MemoryTokenStore()
        ..value = TokenBundle(
          accessToken: 'expired',
          refreshToken: 'refresh-reference',
          expiresAt: DateTime.utc(2026),
          tenantId: 'tenant-a',
          deviceId: 'device-a',
        );
      final adapter = _RefreshAdapter();
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: tokens,
        dio: Dio()..httpClientAdapter = adapter,
      );
      final body = FormData.fromMap({
        'description': 'A support image test.',
        'images[]': MultipartFile.fromBytes([1, 2, 3], filename: 'fixture.png'),
      });
      final response = await client.dio.post<Map<String, dynamic>>(
        '/api/v1/mobile/support/issues',
        data: body,
        options: Options(headers: {'Idempotency-Key': 'ticket-refresh-once'}),
      );
      expect(response.data?['ok'], isTrue);
      expect(body.isFinalized, isTrue);
      expect(adapter.protectedCalls, 2);
      expect(adapter.intentKeys, [
        'ticket-refresh-once',
        'ticket-refresh-once',
      ]);
    },
  );
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

TokenBundle _session(
  String token, {
  String tenant = 'tenant-a',
  String? refresh,
}) => TokenBundle(
  accessToken: token,
  refreshToken: refresh,
  expiresAt: DateTime.utc(2030),
  tenantId: tenant,
  deviceId: 'device-a',
);

final class _DelayedAuthAdapter implements HttpClientAdapter {
  _DelayedAuthAdapter({this.refreshStatus = 401});
  final int refreshStatus;
  final protectedStarted = Completer<void>();
  final refreshStarted = Completer<void>();
  final rejected = Completer<void>();
  final refreshed = Completer<void>();
  int protectedCalls = 0;
  int refreshCalls = 0;

  @override
  void close({bool force = false}) {}

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? body,
    Future<void>? cancel,
  ) async {
    final refresh = options.path == '/api/v1/auth/refresh';
    if (refresh) {
      refreshCalls++;
      refreshStarted.complete();
      await refreshed.future;
    } else {
      protectedCalls++;
      protectedStarted.complete();
      await rejected.future;
    }
    return ResponseBody.fromString(
      refresh
          ? '{"data":{"token":"rotated-old","expires_at":"2030-01-01T00:00:00Z"}}'
          : '{}',
      refresh ? refreshStatus : 401,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }
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
  final intentKeys = <String?>[];
  var refreshCalls = 0;
  var protectedCalls = 0;
  var rejectRotated = false;
  Completer<void>? waitForRefresh;
  Completer<void>? refreshStarted;

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
      refreshStarted?.complete();
      if (waitForRefresh != null) await waitForRefresh!.future;
      return _json(200, {
        'data': {
          'token': 'rotated',
          'refresh_token': 'next-refresh-reference',
          'expires_at': '2030-01-01T00:00:00.000Z',
        },
      });
    }
    protectedCalls++;
    intentKeys.add(options.headers['Idempotency-Key'] as String?);
    if (!rejectRotated &&
        options.headers['Authorization'] == 'Bearer rotated') {
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
