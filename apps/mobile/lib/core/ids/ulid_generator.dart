import 'dart:math';

final class UlidGenerator {
  UlidGenerator({Random? random, DateTime Function()? now})
    : _random = random ?? Random.secure(),
      _now = now ?? DateTime.now;

  static const _alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  final Random _random;
  final DateTime Function() _now;

  String next() {
    var time = _now().toUtc().millisecondsSinceEpoch;
    final chars = List<String>.filled(26, '0');
    for (var index = 9; index >= 0; index--) {
      chars[index] = _alphabet[time & 31];
      time ~/= 32;
    }
    for (var index = 10; index < 26; index++) {
      chars[index] = _alphabet[_random.nextInt(32)];
    }
    return chars.join();
  }
}
