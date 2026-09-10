import 'dart:convert';

import 'package:flutter/foundation.dart';

typedef LogSink = void Function(String message);
typedef UtcNow = DateTime Function();

final class JsonLogger {
  JsonLogger({LogSink? sink, UtcNow? now})
    : _sink = sink ?? debugPrint,
      _now = now ?? (() => DateTime.now().toUtc());

  final LogSink _sink;
  final UtcNow _now;

  void info(String event, {Map<String, Object?> context = const {}}) {
    _sink(
      jsonEncode({
        'timestamp': _now().toIso8601String(),
        'level': 'info',
        'event': event,
        ...context,
      }),
    );
  }
}
