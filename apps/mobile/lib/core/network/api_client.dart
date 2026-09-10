import 'dart:async';

import 'package:dio/dio.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/ids/ulid_generator.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';

typedef RetryDelay = Future<void> Function(Duration duration);

final class ApiClient {
  ApiClient({
    required Uri baseUrl,
    required TokenStore tokenStore,
    UlidGenerator? ids,
    Dio? dio,
    RetryDelay? retryDelay,
  }) : _tokens = tokenStore,
       _ids = ids ?? UlidGenerator(),
       _dio = dio ?? Dio(),
       _retryDelay = retryDelay ?? Future<void>.delayed {
    _dio.options = BaseOptions(
      baseUrl: baseUrl.toString(),
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 20),
      sendTimeout: const Duration(seconds: 20),
      headers: const {'Accept': 'application/json'},
    );
    _dio.interceptors.add(
      QueuedInterceptorsWrapper(onRequest: _onRequest, onError: _onError),
    );
  }

  final Dio _dio;
  final TokenStore _tokens;
  final UlidGenerator _ids;
  final RetryDelay _retryDelay;
  Future<TokenBundle?>? _refreshing;

  Dio get dio => _dio;

  Future<void> _onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final tokens = await _tokens.read();
    options.headers['X-Request-ID'] = _ids.next();
    options.headers['X-Correlation-ID'] =
        options.extra['correlation_id'] ?? _ids.next();
    if (tokens != null && options.extra['anonymous'] != true) {
      options.headers['Authorization'] = 'Bearer ${tokens.accessToken}';
      options.headers['X-Tenant-ID'] = tokens.tenantId;
    }
    handler.next(options);
  }

  Future<void> _onError(
    DioException error,
    ErrorInterceptorHandler handler,
  ) async {
    final request = error.requestOptions;
    if (_canRefresh(error) && request.extra['auth_retried'] != true) {
      final refreshed = await _refreshOnce();
      if (refreshed != null) {
        request.extra['auth_retried'] = true;
        request.headers['Authorization'] = 'Bearer ${refreshed.accessToken}';
        return handler.resolve(await _dio.fetch<Object?>(request));
      }
      await _tokens.clear();
    }

    final retries = (request.extra['retry_count'] as int?) ?? 0;
    if (_isSafe(request.method) && retries < 2 && _isTransient(error)) {
      request.extra['retry_count'] = retries + 1;
      await _retryDelay(Duration(milliseconds: 250 * (1 << retries)));
      try {
        return handler.resolve(await _dio.fetch<Object?>(request));
      } on DioException catch (retryError) {
        return handler.next(retryError);
      }
    }

    handler.next(error);
  }

  bool _canRefresh(DioException error) {
    final path = error.requestOptions.path;
    return error.response?.statusCode == 401 &&
        !path.contains('/auth/login') &&
        !path.contains('/auth/refresh');
  }

  Future<TokenBundle?> _refreshOnce() {
    final active = _refreshing;
    if (active != null) {
      return active;
    }
    final operation = _refresh();
    _refreshing = operation;
    return operation.whenComplete(() => _refreshing = null);
  }

  Future<TokenBundle?> _refresh() async {
    final current = await _tokens.read();
    if (current?.refreshToken == null) {
      return null;
    }
    try {
      final refreshDio = Dio(_dio.options);
      refreshDio.httpClientAdapter = _dio.httpClientAdapter;
      final response = await refreshDio.post<Map<String, dynamic>>(
        '/api/v1/auth/refresh',
        data: {'refresh_token': current!.refreshToken},
        options: Options(
          headers: {
            'X-Request-ID': _ids.next(),
            'X-Correlation-ID': _ids.next(),
          },
        ),
      );
      final data = response.data?['data'] as Map<String, dynamic>?;
      if (data == null) {
        return null;
      }
      final refreshed = TokenBundle(
        accessToken: data['token']! as String,
        refreshToken: data['refresh_token'] as String? ?? current.refreshToken,
        expiresAt: DateTime.parse(data['expires_at']! as String).toUtc(),
        tenantId: data['tenant_id'] as String? ?? current.tenantId,
        deviceId: data['device_id'] as String? ?? current.deviceId,
      );
      await _tokens.write(refreshed);
      return refreshed;
    } on DioException {
      return null;
    }
  }

  static bool _isSafe(String method) =>
      const {'GET', 'HEAD', 'OPTIONS'}.contains(method.toUpperCase());

  static bool _isTransient(DioException error) =>
      error.type == DioExceptionType.connectionError ||
      error.type == DioExceptionType.connectionTimeout ||
      error.type == DioExceptionType.receiveTimeout ||
      (error.response?.statusCode ?? 0) >= 500;

  static Never throwFailure(Object error) {
    if (error is AppFailure) {
      throw error;
    }
    if (error is DioException) {
      throw AppFailure.fromDio(error);
    }
    throw const AppFailure(
      kind: FailureKind.unknown,
      message: 'Something went wrong. Please try again.',
    );
  }
}
