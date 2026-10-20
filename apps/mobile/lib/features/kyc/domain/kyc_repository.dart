import 'dart:typed_data';

abstract interface class KycRepository {
  Future<Map<String, dynamic>> status();
  Future<Map<String, dynamic>> start(
    Map<String, dynamic> data, {
    required bool resubmit,
  });
  Future<Map<String, dynamic>> upload(String id, String kind, Uint8List bytes);
  Future<Map<String, dynamic>> submit(String id);
  Future<Map<String, dynamic>> cancel(String id);
  Future<Map<String, dynamic>> startLive(String id);
  Future<Map<String, dynamic>> liveFrame(
    String id,
    String token,
    Uint8List bytes,
  );
}
