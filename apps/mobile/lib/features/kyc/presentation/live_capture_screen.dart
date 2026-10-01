import 'dart:async';
import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/features/kyc/data/capture_cleanup.dart';
import 'package:vtsa_mobile/features/kyc/domain/kyc_repository.dart';
import 'package:vtsa_mobile/features/kyc/domain/live_challenge.dart';

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
  int generation = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  Future<void> stop() async {
    generation++;
    running = false;
    final previous = camera;
    camera = null;
    if (mounted) setState(() {});
    await previous?.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      unawaited(stop());
      if (mounted) {
        setState(
          () => error = 'Camera session interrupted. Start a new live check.',
        );
      }
    }
  }

  Future<void> start() async {
    if (running) return;
    final session = ++generation;
    bool active() => mounted && generation == session;
    setState(() {
      running = true;
      error = null;
      challenge = null;
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
      challenge = LiveChallenge.fromJson(
        await widget.repository.startLive(widget.verificationId),
      );
      if (!active()) return;
      setState(() {});
      while (active() && !challenge!.complete) {
        await Future<void>.delayed(const Duration(milliseconds: 750));
        if (!active()) return;
        if (DateTime.now().isAfter(challenge!.expiresAt)) {
          throw const FormatException('Challenge expired');
        }
        XFile? capture;
        Uint8List? bytes;
        try {
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
          setState(() => challenge = LiveChallenge.fromJson(response));
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
      if (active()) setState(() => error = failure.message);
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
    appBar: AppBar(title: const Text('Live identity check')),
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text(
            challenge?.instruction ?? 'Keep your face in view',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 12),
          const Text(
            'Use even lighting, remove face coverings and follow the head-turn prompts. Keep the phone steady. No audio is recorded.',
          ),
          const SizedBox(height: 24),
          if (camera case final controller? when controller.value.isInitialized)
            ClipRRect(
              borderRadius: BorderRadius.circular(24),
              child: AspectRatio(
                aspectRatio: 3 / 4,
                child: CameraPreview(controller),
              ),
            )
          else
            Container(
              height: 260,
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(24),
              ),
              child: const Icon(Icons.face_outlined, size: 96),
            ),
          const SizedBox(height: 20),
          if (challenge case final prompt?) ...[
            LinearProgressIndicator(value: prompt.step / 9),
            const SizedBox(height: 12),
            Semantics(
              liveRegion: true,
              child: Text(
                prompt.feedback == 'hold_still'
                    ? 'Hold this position…'
                    : prompt.feedback == 'face_not_clear'
                    ? 'Keep one face fully visible in even lighting.'
                    : prompt.instruction,
              ),
            ),
          ],
          if (error != null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 16),
              child: Text(
                error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          if (!running)
            FilledButton(
              onPressed: start,
              child: Text(error == null ? 'Start live check' : 'Try again'),
            ),
          if (running)
            TextButton(onPressed: stop, child: const Text('Stop camera')),
        ],
      ),
    ),
  );
}
