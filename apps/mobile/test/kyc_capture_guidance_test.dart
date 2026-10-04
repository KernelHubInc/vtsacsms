import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:camera_platform_interface/camera_platform_interface.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/kyc/domain/live_challenge.dart';
import 'package:vtsa_mobile/features/kyc/presentation/capture_guide.dart';
import 'package:vtsa_mobile/features/kyc/presentation/kyc_screen.dart';
import 'package:vtsa_mobile/features/kyc/presentation/live_capture_feedback.dart';
import 'package:vtsa_mobile/features/kyc/presentation/live_capture_screen.dart';
import 'package:vtsa_mobile/features/kyc/presentation/photo_capture_screen.dart';

import 'kyc_test.dart' show FakeKycRepository;

class GuidedCamera extends CameraPlatform {
  final errors = StreamController<CameraErrorEvent>.broadcast();
  bool denied = false;
  bool failPhoto = true;
  int photos = 0;
  int closed = 0;
  CameraLensDirection? lens;
  bool? audio;

  @override
  Future<List<CameraDescription>> availableCameras() async {
    if (denied) throw CameraException('CameraAccessDenied', 'Denied');
    return [
      for (final direction in [
        CameraLensDirection.front,
        CameraLensDirection.back,
      ])
        CameraDescription(
          name: direction.name,
          lensDirection: direction,
          sensorOrientation: 0,
        ),
    ];
  }

  @override
  Future<int> createCamera(
    CameraDescription cameraDescription,
    ResolutionPreset? resolutionPreset, {
    bool enableAudio = false,
  }) async {
    lens = cameraDescription.lensDirection;
    audio = enableAudio;
    return 1;
  }

  @override
  Stream<DeviceOrientationChangedEvent> onDeviceOrientationChanged() =>
      const Stream.empty();
  @override
  Stream<CameraErrorEvent> onCameraError(int cameraId) => errors.stream;
  @override
  Stream<CameraInitializedEvent> onCameraInitialized(int cameraId) =>
      Stream.value(
        CameraInitializedEvent(
          cameraId,
          640,
          480,
          ExposureMode.auto,
          true,
          FocusMode.auto,
          true,
        ),
      );
  @override
  Future<void> initializeCamera(
    int cameraId, {
    ImageFormatGroup imageFormatGroup = ImageFormatGroup.unknown,
  }) async {}
  @override
  Widget buildPreview(int cameraId) =>
      const ColoredBox(color: Color(0xff637788));
  @override
  Future<void> dispose(int cameraId) async {
    closed++;
  }

  @override
  Future<XFile> takePicture(int cameraId) async {
    photos++;
    if (!failPhoto) {
      return XFile.fromData(
        base64Decode(
          'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAFElEQVR4nGNkYGhgwAaYsIoOWgkAaYgAkK2VydMAAAAASUVORK5CYII=',
        ),
        mimeType: 'image/png',
      );
    }
    throw CameraException(
      'SyntheticFailure',
      'No personal image captured in tests',
    );
  }
}

Map<String, dynamic> prompt({
  String action = 'center',
  String feedback = 'hold_still',
}) => {
  'token': 'a' * 64,
  'action': action,
  'step': 0,
  'total_steps': 9,
  'complete': false,
  'feedback': feedback,
  'expires_at': '2099-01-01T00:00:00Z',
};

class LiveRepository extends FakeKycRepository {
  int liveStarts = 0;
  @override
  Future<Map<String, dynamic>> startLive(String id) async {
    liveStarts++;
    return prompt();
  }
}

Future<void> preview(WidgetTester tester, String name) async {
  if (!const bool.fromEnvironment('KYC_PREVIEWS')) return;
  final boundary = tester.renderObject<RenderRepaintBoundary>(
    find.byKey(const ValueKey('preview')),
  );
  await tester.runAsync(() async {
    final image = await boundary.toImage();
    final data = await image.toByteData(format: ui.ImageByteFormat.png);
    final folder = Directory('../../.cache/mobile-staging-kyc/ui-preview')
      ..createSync(recursive: true);
    File(
      '${folder.path}/$name.png',
    ).writeAsBytesSync(data!.buffer.asUint8List());
    image.dispose();
  });
}

Widget app(Widget screen, {double scale = 1}) => RepaintBoundary(
  key: const ValueKey('preview'),
  child: MaterialApp(
    theme: VtsaTheme.light(),
    builder: (context, child) => MediaQuery(
      data: MediaQuery.of(
        context,
      ).copyWith(textScaler: TextScaler.linear(scale)),
      child: child!,
    ),
    home: screen,
  ),
);

void main() {
  setUpAll(() async {
    const font = String.fromEnvironment('KYC_FONT');
    if (font.isNotEmpty) {
      final loader = FontLoader('Roboto')
        ..addFont(
          File(font).readAsBytes().then((bytes) => ByteData.sublistView(bytes)),
        );
      await loader.load();
      final icons = FontLoader('MaterialIcons')
        ..addFont(
          File(
            '${File(font).parent.path}/materialicons-regular.otf',
          ).readAsBytes().then((bytes) => ByteData.sublistView(bytes)),
        );
      await icons.load();
    }
  });
  late GuidedCamera camera;
  late CameraPlatform previous;
  setUp(() {
    previous = CameraPlatform.instance;
    camera = GuidedCamera();
    CameraPlatform.instance = camera;
  });
  tearDown(() {
    CameraPlatform.instance = previous;
  });

  testWidgets('live camera waits for readiness and stops on interruption', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final repository = LiveRepository();
    await tester.pumpWidget(
      app(LiveCaptureScreen(repository: repository, verificationId: 'test')),
    );
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Open camera'), 160);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Open camera'));
    await tester.pumpAndSettle();
    expect(find.byType(CaptureGuide), findsOneWidget);
    expect(repository.liveStarts, 0);
    expect(camera.photos, 0);
    expect(camera.lens, CameraLensDirection.front);
    expect(camera.audio, false);
    await tester.scrollUntilVisible(
      find.text('I’m ready — start live check'),
      160,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('I’m ready — start live check'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
    expect(repository.liveStarts, 1);
    expect(find.text('Look straight at the camera'), findsOneWidget);
    await tester.drag(find.byType(ListView), const Offset(0, 1600));
    await tester.pump(const Duration(milliseconds: 100));
    await preview(tester, 'live-check');
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump(const Duration(seconds: 1));
    expect(camera.closed, 1);
    expect(camera.photos, 0);
    expect(find.textContaining('Camera session interrupted'), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('guided document capture can be reviewed, retaken and uploaded', (
    tester,
  ) async {
    camera.failPhoto = false;
    final repository = FakeKycRepository()..state = 'PENDING_UPLOAD';
    await tester.pumpWidget(app(KycScreen(repository: repository)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Continue with photos'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Capture').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Capture').first);
    await tester.pumpAndSettle();
    expect(find.byType(PhotoCaptureScreen), findsOneWidget);
    expect(find.text('Passport photo page'), findsOneWidget);
    for (var attempt = 0; attempt < 2; attempt++) {
      await tester.runAsync(() async {
        await tester.tap(find.text('Take photo'));
        await tester.pump(const Duration(milliseconds: 400));
        await Future<void>.delayed(const Duration(milliseconds: 50));
      });
      await tester.pumpAndSettle();
      expect(find.text('Check your photo'), findsOneWidget);
      expect(repository.uploaded, isEmpty);
      final action = find.text(
        attempt == 0 ? 'Retake photo' : 'Use photo and upload',
      );
      await tester.ensureVisible(action);
      await tester.pumpAndSettle();
      await tester.tap(action);
      await tester.pumpAndSettle();
    }
    expect(camera.photos, 2);
    expect(repository.uploaded, {'front'});
    expect(find.text('1 of 2 checks ready'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  for (final kind in ['front', 'selfie']) {
    testWidgets('$kind guide and retake recovery fit a small phone', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(
        app(PhotoCaptureScreen(kind: kind, passport: false), scale: 1.3),
      );
      await tester.pumpAndSettle();
      expect(
        camera.lens,
        kind == 'selfie' ? CameraLensDirection.front : CameraLensDirection.back,
      );
      expect(
        tester.widget<CaptureGuide>(find.byType(CaptureGuide)).face,
        kind == 'selfie',
      );
      expect(camera.photos, 0);
      expect(tester.takeException(), isNull);
      await preview(tester, '$kind-guide');
      await tester.scrollUntilVisible(find.text('Take photo'), 160);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Take photo'));
      await tester.pumpAndSettle();
      expect(camera.photos, 1);
      expect(
        find.textContaining('The photo could not be taken'),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox());
    });
  }

  testWidgets('permission denial offers reopen without taking a photo', (
    tester,
  ) async {
    camera.denied = true;
    await tester.pumpWidget(
      app(const PhotoCaptureScreen(kind: 'front', passport: true)),
    );
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Reopen camera'), 160);
    await tester.pumpAndSettle();
    expect(find.textContaining('Allow camera permission'), findsOneWidget);
    expect(find.text('Reopen camera'), findsOneWidget);
    expect(camera.photos, 0);
    await tester.pumpWidget(const SizedBox());
  });

  for (final action in ['left', 'right']) {
    testWidgets('$action direction and unclear-face feedback are explicit', (
      tester,
    ) async {
      await tester.pumpWidget(
        app(
          Scaffold(
            body: LivePrompt(
              challenge: LiveChallenge.fromJson(
                prompt(action: action, feedback: 'face_not_clear'),
              ),
            ),
          ),
        ),
      );
      expect(
        find.text('Slowly turn your head to your $action'),
        findsOneWidget,
      );
      expect(
        find.textContaining('We cannot see your face clearly'),
        findsOneWidget,
      );
      expect(
        tester.widget<HeadMovementCue>(find.byType(HeadMovementCue)).action,
        action,
      );
    });
  }
}
