import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

enum FailureKind {
  offline,
  unauthenticated,
  forbidden,
  validation,
  notFound,
  rateLimited,
  timeout,
  server,
  unknown,
}

@immutable
final class AppFailure implements Exception {
  const AppFailure({
    required this.kind,
    required this.message,
    this.code,
    this.correlationId,
    this.fieldErrors = const <String, List<String>>{},
  });

  factory AppFailure.fromDio(DioException error) {
    final response = error.response;
    final body = response?.data;
    final json = body is Map<String, dynamic> ? body : null;
    final status = response?.statusCode;
    final headers = response?.headers;
    final correlation =
        json?['correlation_id']?.toString() ??
        headers?.value('x-correlation-id');
    final message = json?['message']?.toString();

    if (error.type == DioExceptionType.connectionTimeout ||
        error.type == DioExceptionType.sendTimeout ||
        error.type == DioExceptionType.receiveTimeout) {
      return AppFailure(
        kind: FailureKind.timeout,
        message: 'The request timed out. Please try again.',
        correlationId: correlation,
      );
    }
    if (error.type == DioExceptionType.connectionError) {
      return const AppFailure(
        kind: FailureKind.offline,
        message: 'You appear to be offline.',
      );
    }

    return AppFailure(
      kind: switch (status) {
        401 => FailureKind.unauthenticated,
        403 => FailureKind.forbidden,
        404 => FailureKind.notFound,
        422 => FailureKind.validation,
        429 => FailureKind.rateLimited,
        final value when value != null && value >= 500 => FailureKind.server,
        _ => FailureKind.unknown,
      },
      message: message ?? _safeMessage(status),
      code: json?['code']?.toString(),
      correlationId: correlation,
      fieldErrors: _fieldErrors(json?['errors']),
    );
  }

  final FailureKind kind;
  final String message;
  final String? code;
  final String? correlationId;
  final Map<String, List<String>> fieldErrors;

  static String _safeMessage(int? status) => switch (status) {
    401 => 'Please sign in again.',
    403 => 'You do not have access to this action.',
    404 => 'The requested item was not found.',
    422 => 'Please check the highlighted details.',
    429 => 'Too many attempts. Please wait and try again.',
    final value when value != null && value >= 500 =>
      'The service is temporarily unavailable.',
    _ => 'Something went wrong. Please try again.',
  };

  static Map<String, List<String>> _fieldErrors(Object? value) {
    if (value is! Map<String, dynamic>) {
      return const {};
    }

    return value.map((key, messages) {
      final items = messages is List<Object?>
          ? messages.map((item) => item.toString()).toList(growable: false)
          : <String>[messages.toString()];
      return MapEntry(key, items);
    });
  }
}
