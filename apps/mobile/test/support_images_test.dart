import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/charging/data/api_charging_repository.dart';
import 'package:vtsa_mobile/features/charging/domain/support_image.dart';
import 'package:vtsa_mobile/features/charging/presentation/support_image_picker.dart';

final _png = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII=',
);

class _Picker extends Fake implements ImagePicker {
  List<XFile> files = [];

  @override
  Future<List<XFile>> pickMultiImage({
    double? maxWidth,
    double? maxHeight,
    int? imageQuality,
    int? limit,
    bool requestFullMetadata = true,
  }) async => files;
}

void main() {
  test(
    'ticket images use multipart array fields and retain the request key',
    () async {
      final dio = Dio();
      late RequestOptions sent;
      dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (request, handler) {
            sent = request;
            handler.resolve(
              Response<void>(requestOptions: request, statusCode: 201),
            );
          },
        ),
      );
      final repository = ApiChargingRepository(
        ApiClient(
          baseUrl: Uri.parse('https://example.test'),
          tokenStore: MemoryTokenStore(),
          dio: dio,
        ),
      );
      await repository.reportIssue(
        sessionId: 'session-fixture',
        category: 'charger',
        description: 'Images of a connector issue.',
        idempotencyKey: 'support-image-retry',
        images: [
          SupportImage(bytes: _png, name: 'one.png'),
          SupportImage(bytes: _png, name: 'two.png'),
        ],
      );
      expect(sent.path, '/api/v1/mobile/support/issues');
      expect(sent.headers['Idempotency-Key'], 'support-image-retry');
      final data = sent.data as FormData;
      expect(Map.fromEntries(data.fields)['session_id'], 'session-fixture');
      expect(data.files.length, 2);
      expect(data.files.map((file) => file.key), everyElement('images[]'));
      expect(data.files.first.value.contentType.toString(), 'image/png');
    },
  );

  testWidgets('picker supports previews, removal and the five-image limit', (
    tester,
  ) async {
    final picker = _Picker()
      ..files = [
        XFile.fromData(_png, name: 'one.png'),
        XFile.fromData(_png, name: 'two.png'),
      ];
    List<SupportImage> selected = [];
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SupportImagePicker(
            picker: picker,
            onChanged: (images) => selected = images,
          ),
        ),
      ),
    );
    await tester.tap(find.text('Add images (0/5)'));
    await tester.pumpAndSettle();
    expect(selected.length, 2);
    expect(find.byType(Image), findsNWidgets(2));
    await tester.tap(find.text('Remove 1'));
    await tester.pumpAndSettle();
    expect(selected.length, 1);
    picker.files = List.generate(
      5,
      (index) => XFile.fromData(_png, name: 'image.png'),
    );
    await tester.tap(find.text('Add images (1/5)'));
    await tester.pumpAndSettle();
    expect(selected.length, 1);
    expect(find.text('Choose up to five images per ticket.'), findsOneWidget);
  });

  testWidgets(
    'picker rejects an oversized image and retains the existing selection',
    (tester) async {
      final picker = _Picker()
        ..files = [
          XFile.fromData(Uint8List(2 * 1024 * 1024 + 1), name: 'large.png'),
        ];
      List<SupportImage> selected = [];
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SupportImagePicker(
              picker: picker,
              onChanged: (images) => selected = images,
            ),
          ),
        ),
      );
      await tester.tap(find.text('Add images (0/5)'));
      await tester.pumpAndSettle();
      expect(selected, isEmpty);
      expect(find.text('Each image must be 2 MB or smaller.'), findsOneWidget);
    },
  );
}
