import 'dart:typed_data';

final class SupportImage {
  const SupportImage({required this.bytes, required this.name});

  final Uint8List bytes;
  final String name;
}
