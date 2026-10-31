import 'package:camera/camera.dart';
import 'package:flutter/material.dart';

/// Keep the guide on the camera image so rotation and letterboxing cannot
/// position it over pixels that are absent from the captured photo.
class CaptureViewport extends StatelessWidget {
  const CaptureViewport({
    required this.guide,
    this.camera,
    this.opening = false,
    this.face = true,
    super.key,
  });

  final Widget guide;
  final CameraController? camera;
  final bool opening;
  final bool face;

  @override
  Widget build(BuildContext context) => Center(
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 720),
      child: SizedBox(
        width: double.infinity,
        height: (MediaQuery.sizeOf(context).height * .62).clamp(320, 640),
        child: ColoredBox(
          color: const Color(0xff102b4a),
          child: camera?.value.isInitialized == true
              ? Center(child: CameraPreview(camera!, child: guide))
              : Stack(
                  fit: StackFit.expand,
                  children: [
                    guide,
                    Center(
                      child: opening
                          ? const CircularProgressIndicator(
                              color: Colors.white,
                              semanticsLabel: 'Opening camera',
                            )
                          : Icon(
                              face ? Icons.face_outlined : Icons.badge_outlined,
                              size: 64,
                              color: Colors.white,
                            ),
                    ),
                  ],
                ),
        ),
      ),
    ),
  );
}
