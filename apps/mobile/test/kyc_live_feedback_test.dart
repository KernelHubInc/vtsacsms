import 'dart:async';
import 'dart:typed_data';

import 'package:camera_platform_interface/camera_platform_interface.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/features/kyc/domain/live_challenge.dart';
import 'package:vtsa_mobile/features/kyc/presentation/capture_guide.dart';
import 'package:vtsa_mobile/features/kyc/presentation/live_capture_feedback.dart';
import 'package:vtsa_mobile/features/kyc/presentation/live_capture_screen.dart';

import 'kyc_capture_guidance_test.dart'
    show GuidedCamera, LiveRepository, app, prompt;

class PendingFrameRepository extends LiveRepository {
  final response = Completer<Map<String, dynamic>>();
  int frames = 0;
  @override
  Future<Map<String, dynamic>> startLive(String id) async =>
      prompt(feedback: 'follow_prompt');
  @override
  Future<Map<String, dynamic>> liveFrame(
    String id,
    String token,
    Uint8List bytes,
  ) {
    frames++;
    return response.future;
  }
}

void main() {
  test(
    'green requires an accepted server position and resets while checking',
    () {
      final accepted = LiveChallenge.fromJson(prompt());
      expect(LiveScanState.from(null, checking: false), LiveScanState.ready);
      expect(
        LiveScanState.from(accepted, checking: false),
        LiveScanState.aligned,
      );
      expect(
        LiveScanState.from(accepted, checking: true),
        LiveScanState.scanning,
      );
      expect(
        LiveScanState.from(
          LiveChallenge.fromJson(prompt(feedback: 'face_not_clear')),
          checking: false,
        ),
        LiveScanState.unclear,
      );
      expect(
        LiveScanState.from(
          LiveChallenge.fromJson(prompt(feedback: 'follow_prompt')),
          checking: false,
        ),
        LiveScanState.move,
      );
      expect(
        () => LiveChallenge.fromJson(prompt(feedback: 'untrusted_status')),
        throwsFormatException,
      );
    },
  );

  test(
    'live failures give recovery guidance and only safe support metadata',
    () {
      final message = liveFailureMessage(
        const AppFailure(
          kind: FailureKind.validation,
          message: 'private raw error',
          code: 'LIVE_CHALLENGE_EXPIRED',
          correlationId: '01J00000000000000000000004',
        ),
      );
      expect(message, contains('movement took too long'));
      expect(message, contains('LIVE_CHALLENGE_EXPIRED'));
      expect(message, contains('01J00000000000000000000004'));
      expect(message, isNot(contains('private raw error')));
      final unsafe = liveFailureMessage(
        const AppFailure(
          kind: FailureKind.unknown,
          message: 'private raw error',
          code: 'person@example.com',
          correlationId: 'private token',
        ),
      );
      expect(unsafe, isNot(contains('person@')));
      expect(unsafe, isNot(contains('private')));
    },
  );

  testWidgets(
    'scanning remains active until the frame response and clears on stop',
    (tester) async {
      final previous = CameraPlatform.instance;
      final camera = GuidedCamera()..failPhoto = false;
      CameraPlatform.instance = camera;
      addTearDown(() => CameraPlatform.instance = previous);
      final repository = PendingFrameRepository();
      await tester.pumpWidget(
        app(LiveCaptureScreen(repository: repository, verificationId: 'test')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Open camera'));
      await tester.pumpAndSettle();
      expect(
        tester.widget<LiveFaceGuide>(find.byType(LiveFaceGuide)).state,
        LiveScanState.ready,
      );
      await tester.tap(find.text('I’m ready — start live check'));
      await tester.pump();
      await tester.runAsync(() async {
        await tester.pump(const Duration(milliseconds: 800));
        await Future<void>.delayed(const Duration(milliseconds: 50));
      });
      await tester.pump(const Duration(milliseconds: 100));
      expect(repository.frames, 1);
      expect(find.text('Scanning your face'), findsOneWidget);
      final guide = tester.widget<CaptureGuide>(find.byType(CaptureGuide));
      expect(guide.color, LiveScanState.scanning.color);
      final before = guide.scanPosition;
      await tester.pump(const Duration(milliseconds: 300));
      expect(
        tester.widget<CaptureGuide>(find.byType(CaptureGuide)).scanPosition,
        isNot(before),
      );
      repository.response.complete(prompt());
      await tester.runAsync(() async {
        await Future<void>.delayed(const Duration(milliseconds: 50));
      });
      await tester.pump();
      expect(find.text('Position confirmed — hold still'), findsOneWidget);
      expect(
        tester.widget<CaptureGuide>(find.byType(CaptureGuide)).color,
        LiveScanState.aligned.color,
      );
      await tester.tap(find.text('Stop camera'));
      await tester.pump(const Duration(seconds: 1));
      expect(find.text('Position confirmed — hold still'), findsNothing);
      expect(find.byType(LiveFaceGuide), findsNothing);
      expect(camera.closed, 1);
      await tester.pumpWidget(const SizedBox());
    },
  );

  testWidgets('movement animates and respects hold and reduced motion', (
    tester,
  ) async {
    await tester.pumpWidget(
      app(const Scaffold(body: HeadMovementCue(action: 'left'))),
    );
    await tester.pump(const Duration(milliseconds: 200));
    expect(tester.binding.hasScheduledFrame, true);
    await tester.pumpWidget(
      app(const Scaffold(body: HeadMovementCue(action: 'left', hold: true))),
    );
    await tester.pumpAndSettle();
    expect(tester.binding.hasScheduledFrame, false);
    await tester.pumpWidget(
      app(
        const Scaffold(
          body: MediaQuery(
            data: MediaQueryData(disableAnimations: true),
            child: Column(
              children: [
                HeadMovementCue(action: 'right'),
                SizedBox(
                  width: 200,
                  height: 260,
                  child: LiveFaceGuide(state: LiveScanState.scanning),
                ),
              ],
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(tester.binding.hasScheduledFrame, false);
    expect(
      tester.widget<CaptureGuide>(find.byType(CaptureGuide)).scanPosition,
      isNull,
    );
  });
}
