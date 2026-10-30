import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

class ChargerScannerView extends StatelessWidget {
  const ChargerScannerView({
    required this.camera,
    required this.onManualEntry,
    this.loading = false,
    this.errorMessage,
    super.key,
  });
  final Widget camera;
  final VoidCallback onManualEntry;
  final bool loading;
  final String? errorMessage;

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: PowerSolutionsColors.brandDarkNavy,
    body: Stack(
      fit: StackFit.expand,
      children: [
        camera,
        const IgnorePointer(
          child: DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  Color(0xDD0B2448),
                  Color(0x220B2448),
                  Color(0xDD0B2448),
                ],
              ),
            ),
          ),
        ),
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
            child: CustomScrollView(
              slivers: [
                SliverFillRemaining(
                  hasScrollBody: false,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Align(
                        alignment: Alignment.centerLeft,
                        child: IconButton(
                          tooltip: 'Back to manual entry',
                          onPressed: onManualEntry,
                          icon: const Icon(
                            Icons.arrow_back,
                            color: Colors.white,
                          ),
                        ),
                      ),
                      const SizedBox(height: 12),
                      const Text(
                        'Scan QR Code',
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: 28,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 12),
                      const Text(
                        'Point your camera at the charger’s QR code to get started.',
                        style: TextStyle(color: Colors.white, fontSize: 16),
                      ),
                      Expanded(
                        child: Center(
                          child: AspectRatio(
                            aspectRatio: 1,
                            child: Padding(
                              padding: const EdgeInsets.all(24),
                              child: IgnorePointer(
                                child: CustomPaint(painter: _ScanCorners()),
                              ),
                            ),
                          ),
                        ),
                      ),
                      if (loading) const LinearProgressIndicator(),
                      if (errorMessage case final message?)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Text(
                            message,
                            style: const TextStyle(color: Colors.white),
                            textAlign: TextAlign.center,
                          ),
                        ),
                      const Text(
                        'OR',
                        style: TextStyle(color: Colors.white70),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 16),
                      FilledButton.icon(
                        style: FilledButton.styleFrom(
                          backgroundColor: PowerSolutionsColors.brandTurquoise,
                          foregroundColor: PowerSolutionsColors.brandDarkNavy,
                          padding: const EdgeInsets.symmetric(
                            horizontal: 16,
                            vertical: 20,
                          ),
                        ),
                        onPressed: onManualEntry,
                        icon: const Icon(Icons.keyboard_outlined),
                        label: const Text(
                          'Enter the Charge Point ID',
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    ),
  );
}

class _ScanCorners extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = PowerSolutionsColors.brandTurquoise
      ..style = PaintingStyle.stroke
      ..strokeWidth = 3
      ..strokeCap = StrokeCap.round;
    final length = size.shortestSide * .14;
    for (final corner in [
      Offset.zero,
      Offset(size.width, 0),
      Offset(0, size.height),
      Offset(size.width, size.height),
    ]) {
      final dx = corner.dx == 0 ? length : -length;
      final dy = corner.dy == 0 ? length : -length;
      canvas.drawPath(
        Path()
          ..moveTo(corner.dx + dx, corner.dy)
          ..lineTo(corner.dx, corner.dy)
          ..lineTo(corner.dx, corner.dy + dy),
        paint,
      );
    }
  }

  @override
  bool shouldRepaint(covariant _ScanCorners oldDelegate) => false;
}
