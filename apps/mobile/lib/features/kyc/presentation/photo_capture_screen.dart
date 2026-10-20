import 'dart:async';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:vtsa_mobile/features/kyc/data/capture_cleanup.dart';
import 'package:vtsa_mobile/features/kyc/presentation/capture_guide.dart';

class PhotoCaptureScreen extends StatefulWidget {
  const PhotoCaptureScreen({
    required this.kind,
    required this.passport,
    super.key,
  });
  final String kind;
  final bool passport;

  @override
  State<PhotoCaptureScreen> createState() => _PhotoCaptureScreenState();
}

class _PhotoCaptureScreenState extends State<PhotoCaptureScreen>
    with WidgetsBindingObserver {
  CameraController? camera;
  bool opening = false;
  bool taking = false;
  String? error;
  int generation = 0;
  bool get face => widget.kind == 'selfie';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(open());
  }

  Future<void> stop() async {
    generation++;
    final previous = camera;
    camera = null;
    opening = false;
    if (mounted) setState(() {});
    await previous?.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && (camera != null || opening)) {
      error = 'Camera paused. Reopen it when you are ready.';
      unawaited(stop());
    }
  }

  Future<void> open() async {
    if (opening || camera != null || taking) return;
    final session = ++generation;
    bool active() => mounted && session == generation;
    setState(() {
      opening = true;
      error = null;
    });
    try {
      final devices = await availableCameras();
      if (!active()) return;
      final device = devices
          .where(
            (d) =>
                d.lensDirection ==
                (face ? CameraLensDirection.front : CameraLensDirection.back),
          )
          .firstOrNull;
      if (device == null) {
        throw CameraException('Unavailable', 'Camera unavailable');
      }
      final created = CameraController(
        device,
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
            'Camera access is unavailable. Allow camera permission in your device settings, then reopen the camera.';
        await stop();
      }
    }
  }

  Future<void> takePhoto() async {
    final controller = camera;
    if (controller == null || !controller.value.isInitialized || taking) return;
    final session = generation;
    setState(() {
      taking = true;
      error = null;
    });
    XFile? photo;
    try {
      photo = await controller.takePicture();
      if (!mounted || generation != session) return;
      Navigator.of(context).pop(photo);
      photo = null; // The review screen now owns temporary-file cleanup.
    } catch (_) {
      if (mounted && generation == session) {
        setState(
          () => error =
              'The photo could not be taken. Hold the phone steady and try again.',
        );
      }
    } finally {
      if (photo != null) await deleteTemporaryCapture(photo.path);
      if (mounted) setState(() => taking = false);
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
                padding: const EdgeInsets.only(bottom: 12),
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
            FilledButton.icon(
              onPressed: opening || taking
                  ? null
                  : camera?.value.isInitialized == true
                  ? takePhoto
                  : open,
              icon: Icon(
                camera?.value.isInitialized == true
                    ? Icons.camera_alt_outlined
                    : Icons.refresh,
              ),
              label: Text(
                taking
                    ? 'Taking photo…'
                    : camera?.value.isInitialized == true
                    ? 'Take photo'
                    : 'Reopen camera',
              ),
            ),
            TextButton(
              onPressed: taking ? null : () => Navigator.of(context).pop(),
              child: const Text('Cancel'),
            ),
          ],
        ),
      ),
    ),
    appBar: AppBar(
      title: Text(
        face
            ? 'Take your selfie'
            : widget.passport
            ? 'Passport photo page'
            : 'Document ${widget.kind}',
      ),
    ),
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            face
                ? 'Fit your face inside the oval'
                : 'Fit your ID inside the frame',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          Text(
            face
                ? 'Look straight at the camera. Keep your eyes and face visible.'
                : widget.passport
                ? 'Open to the page with your photo. Keep the text and the code lines at the bottom visible.'
                : 'Show the ${widget.kind} of your ID. Keep all four corners visible.',
          ),
          const SizedBox(height: 16),
          ClipRRect(
            borderRadius: BorderRadius.circular(24),
            child: camera != null && camera!.value.isInitialized
                ? SizedBox(
                    height: (MediaQuery.sizeOf(context).height * .42).clamp(
                      200,
                      380,
                    ),
                    child: ColoredBox(
                      color: const Color(0xff102b4a),
                      child: Center(
                        child: CameraPreview(
                          camera!,
                          child: CaptureGuide(face: face),
                        ),
                      ),
                    ),
                  )
                : AspectRatio(
                    aspectRatio: 3 / 4,
                    child: ColoredBox(
                      color: const Color(0xff102b4a),
                      child: Stack(
                        fit: StackFit.expand,
                        children: [
                          CaptureGuide(face: face),
                          if (opening)
                            const Center(
                              child: CircularProgressIndicator(
                                color: Colors.white,
                              ),
                            ),
                        ],
                      ),
                    ),
                  ),
          ),
          const SizedBox(height: 16),
          CaptureTips(
            tips: face
                ? const [
                    'Use light in front of you, not a bright window behind you.',
                    'Remove sunglasses and anything covering your face.',
                    'Hold still, then tap Take photo. You can review it before uploading.',
                  ]
                : const [
                    'Place the original document on a flat, plain surface.',
                    'Avoid glare, shadows and fingers covering the text.',
                    'Hold still, then tap Take photo. You can retake a blurry image.',
                  ],
          ),
        ],
      ),
    ),
  );
}
