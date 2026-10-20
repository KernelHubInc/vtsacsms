import 'dart:io';

Future<void> deleteTemporaryCapture(String path) async {
  final file = File(path);
  if (await file.exists()) await file.delete();
}
