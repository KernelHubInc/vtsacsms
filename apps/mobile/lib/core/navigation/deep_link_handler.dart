import 'package:flutter/foundation.dart';

@immutable
final class DeepLinkTarget {
  const DeepLinkTarget(this.location);

  final String location;
}

final class DeepLinkHandler {
  const DeepLinkHandler();

  DeepLinkTarget? resolve(Uri uri) {
    if (uri.scheme != 'vtsa') {
      return null;
    }
    final segments = [if (uri.host.isNotEmpty) uri.host, ...uri.pathSegments];
    if (segments.length == 2 && segments.first == 'stations') {
      final id = segments.last.toUpperCase();
      if (RegExp(r'^[0-9A-HJKMNP-TV-Z]{26}$').hasMatch(id)) {
        return DeepLinkTarget('/stations/$id');
      }
    }
    if (segments.length == 1 && segments.first == 'verify-email') {
      return const DeepLinkTarget('/verify-email');
    }
    return null;
  }
}
