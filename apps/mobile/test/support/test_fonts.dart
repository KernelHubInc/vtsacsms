import 'dart:io';

import 'package:flutter/services.dart';

Future<void> loadTestFonts() async {
  final inter = await File('assets/fonts/Inter.ttf').readAsBytes();
  await (FontLoader(
    'Inter',
  )..addFont(Future.value(ByteData.sublistView(inter)))).load();
  for (final (family, filename) in [
    ('Roboto', 'roboto-regular.ttf'),
    ('MaterialIcons', 'materialicons-regular.otf'),
  ]) {
    final bytes = await File('test/assets/fonts/$filename').readAsBytes();
    await (FontLoader(
      family,
    )..addFont(Future.value(ByteData.sublistView(bytes)))).load();
  }
}
