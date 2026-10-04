import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/kyc/application/kyc_controller.dart';
import 'package:vtsa_mobile/features/kyc/domain/kyc_repository.dart';
import 'package:vtsa_mobile/features/kyc/domain/kyc_verification.dart';
import 'package:vtsa_mobile/features/kyc/presentation/kyc_screen.dart';

class FakeKycRepository implements KycRepository {
  String state = 'NOT_STARTED';
  final uploaded = <String>{};
  final starts = <Map<String, dynamic>>[];
  bool unavailable = false;
  bool uploadFails = false;
  int submits = 0;

  Map<String, dynamic> snapshot() => {
    'id': state == 'NOT_STARTED' ? null : '01J00000000000000000000003',
    'status': state,
    'document_type': 'passport',
    'evidence': uploaded.map((kind) => {'kind': kind}).toList(),
    'enabled': true,
    'documents': {
      'passport': {
        'label': 'Passport',
        'front': true,
        'back': false,
        'document_number': true,
        'expiration_date': true,
      },
    },
    'consent': {
      'version': 'test-v1',
      'text': 'Synthetic verification consent',
      'retention_days': 30,
    },
  };

  @override
  Future<Map<String, dynamic>> status() async {
    if (unavailable) {
      throw const AppFailure(
        kind: FailureKind.offline,
        message: 'Service unavailable. Try again.',
      );
    }
    return snapshot();
  }

  @override
  Future<Map<String, dynamic>> start(
    Map<String, dynamic> data, {
    required bool resubmit,
  }) async {
    starts.add({...data, 'resubmit': resubmit});
    if (unavailable) {
      throw const AppFailure(
        kind: FailureKind.offline,
        message: 'Service unavailable. Try again.',
      );
    }
    state = 'PENDING_UPLOAD';
    return snapshot();
  }

  @override
  Future<Map<String, dynamic>> upload(
    String id,
    String kind,
    Uint8List bytes,
  ) async {
    if (uploadFails) {
      throw const AppFailure(
        kind: FailureKind.offline,
        message: 'Upload failed. Try again.',
      );
    }
    uploaded.add(kind);
    return snapshot();
  }

  @override
  Future<Map<String, dynamic>> submit(String id) async {
    submits++;
    state = 'PROCESSING';
    return snapshot();
  }

  @override
  Future<Map<String, dynamic>> cancel(String id) async {
    state = 'CANCELLED';
    return snapshot();
  }

  @override
  Future<Map<String, dynamic>> startLive(String id) async =>
      throw UnimplementedError();

  @override
  Future<Map<String, dynamic>> liveFrame(
    String id,
    String token,
    Uint8List bytes,
  ) async => throw UnimplementedError();
}

class DeniedCamera extends ImagePicker {
  @override
  Future<XFile?> pickImage({
    required ImageSource source,
    double? maxWidth,
    double? maxHeight,
    int? imageQuality,
    CameraDevice preferredCameraDevice = CameraDevice.rear,
    bool requestFullMetadata = true,
  }) async {
    throw PlatformException(code: 'camera_access_denied');
  }
}

class SyntheticCamera extends ImagePicker {
  @override
  Future<XFile?> pickImage({
    required ImageSource source,
    double? maxWidth,
    double? maxHeight,
    int? imageQuality,
    CameraDevice preferredCameraDevice = CameraDevice.rear,
    bool requestFullMetadata = true,
  }) async => XFile.fromData(
    base64Decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAFElEQVR4nGNkYGhgwAaYsIoOWgkAaYgAkK2VydMAAAAASUVORK5CYII=',
    ),
    mimeType: 'image/png',
  );
}

void main() {
  testWidgets('consent through document and selfie preview to submission', (
    tester,
  ) async {
    final repository = FakeKycRepository();
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: KycScreen(repository: repository, picker: SyntheticCamera()),
      ),
    );
    await tester.pumpAndSettle();
    Future<void> tap(String label) async {
      final target = find.text(label).first;
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      await tester.ensureVisible(target);
      await tester.pumpAndSettle();
      if (label == 'Capture') {
        await tester.runAsync(() async {
          await tester.tap(target);
          await Future<void>.delayed(const Duration(milliseconds: 50));
        });
      } else {
        await tester.tap(target);
      }
      await tester.pumpAndSettle();
    }

    await tap('Get started');
    await tap('I have read and agree to this verification consent.');
    await tap('Continue');
    for (final entry in [
      'SYNTHETIC PERSON',
      '1990-01-01',
      'PH',
      'PH',
    ].asMap().entries) {
      await tester.enterText(find.byType(TextField).at(entry.key), entry.value);
    }
    await tap('Choose identity document');
    await tap('Passport');
    await tester.enterText(find.byType(TextField).at(0), 'TEST-123456');
    await tester.enterText(find.byType(TextField).at(1), '2035-01-01');
    await tap('Continue to photos');
    for (var image = 0; image < 2; image++) {
      await tap('Capture');
      expect(find.text('Check your photo'), findsOneWidget);
      await tap('Use photo and upload');
    }
    expect(repository.uploaded, {'front', 'selfie'});
    await tap('Review submission');
    await tap('Submit verification');
    expect(repository.submits, 1);
    expect(find.text(KycStatus.processing.label), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  test(
    'onboarding, failed upload, restart, pending and approval restore from server',
    () async {
      final repository = FakeKycRepository();
      var controller = KycController(repository);
      await controller.refresh();
      expect(controller.verification!.status, KycStatus.notStarted);
      await controller.start('passport', {'full_name': 'SYNTHETIC'});
      repository.uploadFails = true;
      expect(await controller.upload('front', Uint8List(10)), false);
      expect(controller.error, contains('Upload failed'));
      repository.uploadFails = false;
      await controller.upload('front', Uint8List(10));
      controller.dispose();
      controller = KycController(repository);
      await controller.refresh();
      expect(controller.verification!.uploaded, contains('front'));
      await controller.upload('selfie', Uint8List(10));
      await controller.submit();
      expect(controller.verification!.status, KycStatus.processing);
      repository.state = 'APPROVED';
      await controller.refresh();
      expect(controller.verification!.status, KycStatus.approved);
      controller.dispose();
    },
  );

  test(
    'service failures preserve idempotency key and resubmission is explicit',
    () async {
      final repository = FakeKycRepository()..state = 'REJECTED';
      final controller = KycController(repository);
      await controller.refresh();
      repository.unavailable = true;
      await controller.start('passport', {'full_name': 'SYNTHETIC'});
      repository.unavailable = false;
      await controller.start('passport', {'full_name': 'SYNTHETIC'});
      expect(
        repository.starts[0]['idempotency_key'],
        repository.starts[1]['idempotency_key'],
      );
      expect(repository.starts[1]['resubmit'], true);
      controller.dispose();
    },
  );

  for (final status in [
    'APPROVED',
    'NEEDS_REVIEW',
    'REJECTED',
    'ACTION_REQUIRED',
    'EXPIRED',
  ]) {
    testWidgets('overview displays $status', (tester) async {
      final repository = FakeKycRepository()..state = status;
      await tester.pumpWidget(
        MaterialApp(
          theme: VtsaTheme.light(),
          home: KycScreen(repository: repository),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text(KycStatus.parse(status).label), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
    });
  }

  testWidgets('camera permission denial provides recovery guidance', (
    tester,
  ) async {
    final repository = FakeKycRepository()..state = 'PENDING_UPLOAD';
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: KycScreen(repository: repository, picker: DeniedCamera()),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Continue with photos'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Capture').first);
    await tester.pumpAndSettle();
    expect(find.textContaining('Allow camera permission'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('consent must be accepted before collecting information', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: KycScreen(repository: FakeKycRepository()),
      ),
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Get started'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Get started'));
    await tester.pumpAndSettle();
    final button = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Continue'),
    );
    expect(button.onPressed, isNull);
    expect(find.text('Synthetic verification consent'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });
}
