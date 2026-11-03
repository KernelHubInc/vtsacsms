import 'package:camera/camera.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Face previews fill the guide without stretching the camera image.
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
              ? face
                    ? ClipRect(
                        child: Stack(
                          fit: StackFit.expand,
                          children: [
                            if (kIsWeb)
                              // The web camera already fits its video with cover.
                              // CameraPreview's native orientation swap can letterbox it.
                              camera!.buildPreview()
                            else
                              ValueListenableBuilder<CameraValue>(
                                valueListenable: camera!,
                                builder: (context, value, _) {
                                  final orientation =
                                      value.previewPauseOrientation ??
                                      value.lockedCaptureOrientation ??
                                      value.deviceOrientation;
                                  final landscape =
                                      orientation ==
                                          DeviceOrientation.landscapeLeft ||
                                      orientation ==
                                          DeviceOrientation.landscapeRight;
                                  final ratio = landscape
                                      ? value.aspectRatio
                                      : 1 / value.aspectRatio;
                                  return FittedBox(
                                    fit: BoxFit.cover,
                                    child: SizedBox(
                                      width: 720,
                                      height: 720 / ratio,
                                      child: CameraPreview(camera!),
                                    ),
                                  );
                                },
                              ),
                            guide,
                          ],
                        ),
                      )
                    : Center(child: CameraPreview(camera!, child: guide))
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
