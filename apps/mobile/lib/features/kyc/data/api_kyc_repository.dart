import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/features/kyc/domain/kyc_repository.dart';

final class ApiKycRepository implements KycRepository {
  const ApiKycRepository(this.client);
  final ApiClient client;

  Future<Map<String, dynamic>> _read(
    Future<Response<Map<String, dynamic>>> request,
  ) async {
    try {
      final response = await request;
      return Map<String, dynamic>.from(response.data!['data'] as Map);
    } catch (error) {
      ApiClient.throwFailure(error);
    }
  }

  @override
  Future<Map<String, dynamic>> status() =>
      _read(client.dio.get('/api/v1/kyc/status'));

  @override
  Future<Map<String, dynamic>> start(
    Map<String, dynamic> data, {
    required bool resubmit,
  }) => _read(
    client.dio.post(
      '/api/v1/kyc/${resubmit ? 'resubmit' : 'start'}',
      data: data,
    ),
  );

  @override
  Future<Map<String, dynamic>> upload(
    String id,
    String kind,
    Uint8List bytes,
  ) => _read(
    client.dio.post(
      '/api/v1/kyc/verification/$id/${kind == 'selfie' ? 'selfie' : 'document'}',
      data: FormData.fromMap({
        'kind': kind,
        'image': MultipartFile.fromBytes(bytes, filename: 'capture.jpg'),
      }),
      options: Options(
        sendTimeout: const Duration(seconds: 60),
        receiveTimeout: const Duration(seconds: 45),
      ),
    ),
  );

  @override
  Future<Map<String, dynamic>> submit(String id) =>
      _read(client.dio.post('/api/v1/kyc/verification/$id/submit'));

  @override
  Future<Map<String, dynamic>> cancel(String id) =>
      _read(client.dio.post('/api/v1/kyc/verification/$id/cancel'));

  @override
  Future<Map<String, dynamic>> startLive(String id) =>
      _read(client.dio.post('/api/v1/kyc/verification/$id/live/start'));

  @override
  Future<Map<String, dynamic>> liveFrame(
    String id,
    String token,
    Uint8List bytes,
  ) => _read(
    client.dio.post(
      '/api/v1/kyc/verification/$id/live/frame',
      data: FormData.fromMap({
        'token': token,
        'image': MultipartFile.fromBytes(bytes, filename: 'live.jpg'),
      }),
      options: Options(
        sendTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 30),
      ),
    ),
  );
}
