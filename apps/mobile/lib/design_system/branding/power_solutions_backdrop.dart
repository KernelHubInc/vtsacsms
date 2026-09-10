import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

class PowerSolutionsBackdrop extends StatelessWidget {
  const PowerSolutionsBackdrop({
    required this.child,
    this.intensity = 1,
    super.key,
  });

  final Widget child;
  final double intensity;

  @override
  Widget build(BuildContext context) => Stack(
    fit: StackFit.expand,
    children: [
      ExcludeSemantics(
        child: CustomPaint(
          painter: _PowerSolutionsBackdropPainter(
            brightness: Theme.of(context).brightness,
            intensity: intensity,
          ),
        ),
      ),
      child,
    ],
  );
}

class PowerSolutionsFeatureArt extends StatelessWidget {
  const PowerSolutionsFeatureArt({required this.icon, super.key});

  final IconData icon;

  @override
  Widget build(BuildContext context) => SizedBox(
    height: 184,
    width: double.infinity,
    child: ExcludeSemantics(
      child: CustomPaint(
        painter: _PowerSolutionsFeaturePainter(
          brightness: Theme.of(context).brightness,
        ),
        child: Center(
          child: Container(
            width: 74,
            height: 74,
            decoration: BoxDecoration(
              color: PowerSolutionsColors.brandNavy,
              borderRadius: BorderRadius.circular(24),
              boxShadow: const [
                BoxShadow(
                  color: Color(0x2912366B),
                  blurRadius: 24,
                  offset: Offset(0, 10),
                ),
              ],
            ),
            child: Icon(
              icon,
              size: 38,
              color: PowerSolutionsColors.brandTurquoise,
            ),
          ),
        ),
      ),
    ),
  );
}

class _PowerSolutionsBackdropPainter extends CustomPainter {
  const _PowerSolutionsBackdropPainter({
    required this.brightness,
    required this.intensity,
  });

  final Brightness brightness;
  final double intensity;

  @override
  void paint(Canvas canvas, Size size) {
    final dark = brightness == Brightness.dark;
    final wash = Paint()
      ..shader = LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: dark
            ? const [Color(0x332589BE), Color(0x1657DDD2)]
            : const [Color(0x3857DDD2), Color(0x222589BE)],
      ).createShader(Offset.zero & size);
    canvas.drawOval(
      Rect.fromCenter(
        center: Offset(size.width * 0.88, size.height * 0.12),
        width: size.width * 0.86,
        height: size.width * 0.86,
      ),
      wash,
    );

    final path = Path()
      ..moveTo(-size.width * 0.08, size.height * 0.78)
      ..cubicTo(
        size.width * 0.22,
        size.height * 0.55,
        size.width * 0.48,
        size.height * 0.98,
        size.width * 1.08,
        size.height * 0.66,
      );
    canvas.drawPath(
      path,
      Paint()
        ..color = PowerSolutionsColors.brandBlue.withValues(
          alpha: 0.14 * intensity,
        )
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round
        ..strokeWidth = 42,
    );
    canvas.drawPath(
      path,
      Paint()
        ..color = PowerSolutionsColors.brandTurquoise.withValues(
          alpha: 0.34 * intensity,
        )
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round
        ..strokeWidth = 8,
    );

    _drawTiles(canvas, size, dark);
  }

  void _drawTiles(Canvas canvas, Size size, bool dark) {
    final tileColors = [
      PowerSolutionsColors.brandNavy,
      PowerSolutionsColors.brandBlue,
      PowerSolutionsColors.brandTurquoise,
    ];
    final origins = [
      Offset(size.width * 0.08, size.height * 0.14),
      Offset(size.width * 0.84, size.height * 0.8),
    ];

    for (final origin in origins) {
      for (var index = 0; index < tileColors.length; index++) {
        final dimension = 18.0 + (index * 4);
        final rect = RRect.fromRectAndRadius(
          Rect.fromLTWH(
            origin.dx + (index * 16),
            origin.dy - (index * 14),
            dimension,
            dimension,
          ),
          const Radius.circular(6),
        );
        canvas.drawRRect(
          rect,
          Paint()
            ..color = tileColors[index].withValues(
              alpha: (dark ? 0.34 : 0.22) * intensity,
            ),
        );
      }
    }
  }

  @override
  bool shouldRepaint(covariant _PowerSolutionsBackdropPainter oldDelegate) =>
      oldDelegate.brightness != brightness ||
      oldDelegate.intensity != intensity;
}

class _PowerSolutionsFeaturePainter extends CustomPainter {
  const _PowerSolutionsFeaturePainter({required this.brightness});

  final Brightness brightness;

  @override
  void paint(Canvas canvas, Size size) {
    final dark = brightness == Brightness.dark;
    final rect = RRect.fromRectAndRadius(
      Rect.fromLTWH(0, 4, size.width, size.height - 8),
      const Radius.circular(32),
    );
    canvas.drawRRect(
      rect,
      Paint()
        ..shader = LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: dark
              ? const [Color(0xFF12366B), Color(0xFF0D465D)]
              : const [Color(0xFFE7F9F7), Color(0xFFDCEFFC)],
        ).createShader(rect.outerRect),
    );

    final route = Path()
      ..moveTo(-20, size.height * 0.72)
      ..cubicTo(
        size.width * 0.24,
        size.height * 0.2,
        size.width * 0.64,
        size.height * 1.04,
        size.width + 20,
        size.height * 0.28,
      );
    canvas.drawPath(
      route,
      Paint()
        ..shader = const LinearGradient(
          colors: [
            PowerSolutionsColors.brandBlue,
            PowerSolutionsColors.brandTurquoise,
          ],
        ).createShader(Offset.zero & size)
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round
        ..strokeWidth = 9,
    );

    for (final point in [
      Offset(size.width * 0.14, size.height * 0.51),
      Offset(size.width * 0.82, size.height * 0.47),
    ]) {
      canvas.drawCircle(
        point,
        9,
        Paint()..color = dark ? const Color(0xFF0B2448) : Colors.white,
      );
      canvas.drawCircle(
        point,
        5,
        Paint()..color = PowerSolutionsColors.brandTurquoise,
      );
    }
  }

  @override
  bool shouldRepaint(covariant _PowerSolutionsFeaturePainter oldDelegate) =>
      oldDelegate.brightness != brightness;
}
