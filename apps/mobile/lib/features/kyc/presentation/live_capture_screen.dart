import 'dart:async';
import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/features/kyc/data/capture_cleanup.dart';
import 'package:vtsa_mobile/features/kyc/domain/kyc_repository.dart';
import 'package:vtsa_mobile/features/kyc/domain/live_challenge.dart';
import 'package:vtsa_mobile/features/kyc/presentation/capture_guide.dart';
import 'package:vtsa_mobile/features/kyc/presentation/capture_viewport.dart';
import 'package:vtsa_mobile/features/kyc/presentation/live_capture_feedback.dart';

class LiveCaptureScreen extends StatefulWidget {
  const LiveCaptureScreen({
    required this.repository,
    required this.verificationId,
    super.key,
  });
  final KycRepository repository;
  final String verificationId;

  @override
  State<LiveCaptureScreen> createState() => _LiveCaptureScreenState();
}

class _LiveCaptureScreenState extends State<LiveCaptureScreen>
    with WidgetsBindingObserver {
  CameraController? camera;
  LiveChallenge? challenge;
  String? error;
  bool running = false;
  bool opening = false;
  bool checking = false;
  int generation = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  Future<void> stop() async {
    generation++;
    running = false;
    opening = false;
    checking = false;
    challenge = null;
    final previous = camera;
    camera = null;
    if (mounted) setState(() {});
    await previous?.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed &&
        (camera != null || opening || running)) {
      unawaited(stop());
      if (mounted) {
        setState(
          () => error = 'Camera session interrupted. Start a new live check.',
        );
      }
    }
  }

  Future<void> openCamera() async {
    if (opening || camera != null) return;
    final session = ++generation;
    bool active() => mounted && generation == session;
    setState(() {
      opening = true;
      error = null;
      challenge = null;
      checking = false;
    });
    CameraController? created;
    try {
      final devices = await availableCameras();
      if (!active()) return;
      final front = devices
          .where((device) => device.lensDirection == CameraLensDirection.front)
          .firstOrNull;
      if (front == null) {
        throw CameraException('NoFrontCamera', 'Front camera unavailable');
      }
      created = CameraController(
        front,
        ResolutionPreset.high,
        enableAudio: false,
      );
      camera = created;
      await created.initialize();
      if (!active()) return;
      setState(() => opening = false);
    } catch (_) {
      if (active()) {
        error =
            'Allow front camera access in your device settings, then reopen the camera.';
        await stop();
      }
    }
  }

  Future<void> start() async {
    final created = camera;
    if (running || created == null || !created.value.isInitialized) return;
    final session = ++generation;
    bool active() => mounted && generation == session;
    setState(() {
      running = true;
      error = null;
      challenge = null;
      checking = true;
    });
    try {
      final initial = LiveChallenge.fromJson(
        await widget.repository.startLive(widget.verificationId),
      );
      if (!active()) return;
      setState(() {
        challenge = initial;
        checking = false;
      });
      while (active() && !challenge!.complete) {
        await Future<void>.delayed(const Duration(milliseconds: 750));
        if (!active()) return;
        if (DateTime.now().isAfter(challenge!.expiresAt)) {
          throw const FormatException('Challenge expired');
        }
        XFile? capture;
        Uint8List? bytes;
        try {
          setState(() => checking = true);
          capture = await created.takePicture();
          bytes = await capture.readAsBytes();
          if (!active()) return;
          if (bytes.length > 2 * 1024 * 1024) {
            throw const FormatException('Frame too large');
          }
          final response = await widget.repository.liveFrame(
            widget.verificationId,
            challenge!.token,
            bytes,
          );
          if (!active()) return;
          setState(() {
            challenge = LiveChallenge.fromJson(response);
            checking = false;
          });
        } finally {
          bytes?.fillRange(0, bytes.length, 0);
          if (capture != null) await deleteTemporaryCapture(capture.path);
        }
      }
      if (active()) {
        await stop();
        if (mounted) Navigator.of(context).pop(true);
      }
    } on CameraException {
      if (active()) {
        setState(
          () => error =
              'Allow front camera access in your device settings, then try again.',
        );
      }
    } on AppFailure catch (failure) {
      if (active()) setState(() => error = liveFailureMessage(failure));
    } catch (_) {
      if (active()) {
        setState(
          () => error =
              'The live check could not be completed. Use even lighting and start again.',
        );
      }
    } finally {
      if (active()) await stop();
    }
  }

  @override
  void dispose() {
    generation++;
    WidgetsBinding.instance.removeObserver(this);
    unawaited(camera?.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    bottomNavigationBar: SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (error != null)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 16),
                child: Semantics(
                  liveRegion: true,
                  child: Text(
                    error!,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                ),
              ),
            if (!running)
              FilledButton(
                onPressed: opening
                    ? null
                    : camera?.value.isInitialized == true
                    ? start
                    : openCamera,
                child: Text(
                  opening
                      ? 'Opening camera…'
                      : camera?.value.isInitialized == true
                      ? 'I’m ready — start live check'
                      : error == null
                      ? 'Open camera'
                      : 'Reopen camera',
                ),
              ),
            if (running)
              TextButton(onPressed: stop, child: const Text('Stop camera')),
          ],
        ),
      ),
    ),
    appBar: AppBar(title: const Text('Live identity check')),
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.symmetric(vertical: 16),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
            child: challenge != null
                ? LivePrompt(challenge: challenge!, checking: checking)
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        running
                            ? 'Preparing your live check…'
                            : 'Position your face',
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                      const SizedBox(height: 8),
                      const Text(
                        'Hold your phone at eye level, with your whole face in the oval. No audio is recorded.',
                      ),
                    ],
                  ),
          ),
          CaptureViewport(
            camera: camera,
            opening: opening,
            guide: camera?.value.isInitialized == true
                ? LiveFaceGuide(
                    state: LiveScanState.from(
                      challenge,
                      checking: running && checking,
                    ),
                  )
                : const CaptureGuide(face: true),
          ),
          const SizedBox(height: 20),
          if (challenge == null && running) ...[
            const LinearProgressIndicator(),
            const SizedBox(height: 12),
            const Text('Preparing your live check…'),
          ] else if (!running) ...[
            const ExpansionTile(
              title: Text('Tips for a clear face check'),
              childrenPadding: EdgeInsets.symmetric(horizontal: 20),
              children: [
                CaptureTips(
                  tips: [
                    'Face a light source and remove sunglasses or face coverings.',
                    'Position your face in the oval. Start when you are ready.',
                    'Turn your head slowly in the requested direction, then hold still. Keep the phone in place.',
                    'Photos are taken automatically. Follow one prompt at a time.',
                  ],
                ),
              ],
            ),
            const SizedBox(height: 16),
          ],
        ],
      ),
    ),
  );
}

class LivePrompt extends StatelessWidget {
  const LivePrompt({required this.challenge, this.checking = false, super.key});
  final LiveChallenge challenge;
  final bool checking;

  @override
  Widget build(BuildContext context) => Semantics(
    liveRegion: true,
    child: Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.primaryContainer,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              HeadMovementCue(
                action: challenge.action,
                hold: challenge.feedback == 'hold_still' || challenge.complete,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  challenge.instruction,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'Movement ${(challenge.step + 1).clamp(1, 9)} of 9 · Automatic capture',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 8),
          LiveScanIndicator(
            state: LiveScanState.from(challenge, checking: checking),
          ),
          if (challenge.feedback == 'face_not_clear' && !checking) ...[
            const SizedBox(height: 8),
            const Text(
              'We cannot see your face clearly. Move into the oval and use even lighting.',
            ),
          ],
        ],
      ),
    ),
  );
}

String liveFailureMessage(AppFailure failure) {
  final message = switch (failure.code) {
    'LIVE_CHALLENGE_EXPIRED' =>
      'The movement took too long. Restart and follow each prompt as it appears.',
    'LIVE_CHALLENGE_CONFLICT' || 'LIVE_FRAME_REPLAY' =>
      'This camera session is no longer current. Restart the live check.',
    'LIVE_CHECK_FAILED' =>
      'We could not confirm the live check. Use even lighting, keep your face visible and try again.',
    'DOCUMENT_IMAGE_TOO_BLURRY' =>
      'The camera image is blurry. Clean the lens, hold the phone steady and try again.',
    'IMAGE_RESOLUTION_TOO_LOW' =>
      'The camera image is too small. Try a device with a higher-resolution front camera.',
    'FILE_TOO_LARGE' =>
      'The camera image is too large. Restart the live check and try again.',
    'RATE_LIMITED' || 'LIVE_CAPTURE_TOO_FAST' =>
      'Too many attempts. Wait a minute before restarting the live check.',
    _ =>
      failure.kind == FailureKind.offline || failure.kind == FailureKind.timeout
          ? failure.message
          : 'The live check could not finish. Restart it, or try again later if this continues.',
  };
  final code = failure.code;
  final reference = failure.correlationId;
  return [
    message,
    if (code != null && RegExp(r'^[A-Z_]{1,64}$').hasMatch(code)) 'Code: $code',
    if (reference != null &&
        RegExp(r'^[0-9A-HJKMNP-TV-Z]{26}$').hasMatch(reference))
      'Reference: $reference',
  ].join('\n');
}
