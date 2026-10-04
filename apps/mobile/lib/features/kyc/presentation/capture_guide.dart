import 'dart:math' as math;

import 'package:flutter/material.dart';

/// A positioning aid only; passing the outline does not mean evidence is valid.
class CaptureGuide extends StatelessWidget {
  const CaptureGuide({
    required this.face,
    this.color = Colors.white,
    this.scanPosition,
    super.key,
  });

  final bool face;
  final Color color;
  final double? scanPosition;

  @override
  Widget build(BuildContext context) => IgnorePointer(
    child: Semantics(
      label: face
          ? 'Face outline: position your whole face inside the oval'
          : 'Document outline: keep all four corners inside the frame',
      child: CustomPaint(
        painter: _GuidePainter(
          face: face,
          color: color,
          scanPosition: scanPosition,
        ),
      ),
    ),
  );
}

class _GuidePainter extends CustomPainter {
  const _GuidePainter({
    required this.face,
    required this.color,
    this.scanPosition,
  });
  final bool face;
  final Color color;
  final double? scanPosition;

  @override
  void paint(Canvas canvas, Size size) {
    final width = math.min(
      size.width * (face ? .66 : .86),
      size.height * (face ? .56 : 1.15),
    );
    final height = width / (face ? .76 : 1.58);
    final rect = Rect.fromCenter(
      center: size.center(Offset.zero),
      width: width,
      height: height,
    );
    final opening = Path();
    if (face) {
      opening.addOval(rect);
    } else {
      opening.addRRect(
        RRect.fromRectAndRadius(rect, const Radius.circular(14)),
      );
    }
    final mask = Path()
      ..fillType = PathFillType.evenOdd
      ..addRect(Offset.zero & size)
      ..addPath(opening, Offset.zero);
    canvas.drawPath(mask, Paint()..color = Colors.black.withValues(alpha: .58));
    canvas.drawPath(
      opening,
      Paint()
        ..color = color
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3,
    );
    if (scanPosition case final progress?) {
      canvas.save();
      canvas.clipPath(opening);
      final y = rect.top + rect.height * progress;
      canvas.drawLine(
        Offset(rect.left, y),
        Offset(rect.right, y),
        Paint()
          ..color = color.withValues(alpha: .75)
          ..strokeWidth = 2,
      );
      canvas.restore();
    }
  }

  @override
  bool shouldRepaint(_GuidePainter oldDelegate) =>
      oldDelegate.face != face ||
      oldDelegate.color != color ||
      oldDelegate.scanPosition != scanPosition;
}

class CaptureTips extends StatelessWidget {
  const CaptureTips({required this.tips, super.key});
  final List<String> tips;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      for (final (index, tip) in tips.indexed)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                radius: 12,
                backgroundColor: Theme.of(context).colorScheme.primaryContainer,
                child: Text(
                  '${index + 1}',
                  style: const TextStyle(fontSize: 12),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(child: Text(tip)),
            ],
          ),
        ),
    ],
  );
}
