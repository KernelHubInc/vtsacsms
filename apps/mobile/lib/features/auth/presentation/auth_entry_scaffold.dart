import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';

class AuthEntryScaffold extends StatelessWidget {
  const AuthEntryScaffold({
    required this.title,
    required this.subtitle,
    required this.child,
    this.locked = false,
    super.key,
  });

  final String title;
  final String subtitle;
  final Widget child;
  final bool locked;

  @override
  Widget build(BuildContext context) => Theme(
    data: DriverTheme.of(context),
    child: Builder(
      builder: (context) {
        final height = MediaQuery.sizeOf(context).height;
        final compact =
            height < 700 || MediaQuery.viewInsetsOf(context).bottom > 0;
        return Scaffold(
          backgroundColor: const Color(0xFF0A2035),
          body: SafeArea(
            bottom: false,
            child: SingleChildScrollView(
              keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
              child: Column(
                children: [
                  Container(
                    constraints: BoxConstraints(
                      minHeight: compact
                          ? 128
                          : (height * .31).clamp(230.0, 320.0),
                    ),
                    width: double.infinity,
                    child: Stack(
                      children: [
                        const Positioned.fill(
                          child: CustomPaint(painter: _ChargeScene()),
                        ),
                        Center(
                          heightFactor: 1,
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 28,
                              vertical: 16,
                            ),
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                const PowerSolutionsLogo(
                                  height: 34,
                                  inverse: true,
                                ),
                                SizedBox(height: compact ? 14 : 26),
                                Text(
                                  locked
                                      ? 'Your next drive awaits.'
                                      : 'A better way to charge.',
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: compact ? 22 : 32,
                                    height: 1.08,
                                    fontWeight: FontWeight.w700,
                                    letterSpacing: -.8,
                                  ),
                                ),
                                if (!compact) ...[
                                  const SizedBox(height: 16),
                                  Container(
                                    padding: const EdgeInsets.all(12),
                                    decoration: BoxDecoration(
                                      color: const Color(
                                        0xFF82DED9,
                                      ).withValues(alpha: .13),
                                      shape: BoxShape.circle,
                                      border: Border.all(
                                        color: const Color(
                                          0xFF82DED9,
                                        ).withValues(alpha: .35),
                                      ),
                                    ),
                                    child: Icon(
                                      locked
                                          ? Icons.lock_outline_rounded
                                          : Icons.ev_station_outlined,
                                      color: const Color(0xFF82DED9),
                                      size: 30,
                                    ),
                                  ),
                                ],
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  Container(
                    width: double.infinity,
                    constraints: BoxConstraints(minHeight: height * .65),
                    padding: EdgeInsets.fromLTRB(
                      24,
                      compact ? 22 : 30,
                      24,
                      28 + MediaQuery.paddingOf(context).bottom,
                    ),
                    decoration: BoxDecoration(
                      color: Theme.of(context).colorScheme.surface,
                      borderRadius: const BorderRadius.vertical(
                        top: Radius.circular(32),
                      ),
                    ),
                    child: Center(
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 440),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Text(
                              title,
                              textAlign: TextAlign.center,
                              style: Theme.of(context).textTheme.headlineSmall
                                  ?.copyWith(
                                    fontWeight: FontWeight.w700,
                                    letterSpacing: -.6,
                                  ),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              subtitle,
                              textAlign: TextAlign.center,
                              style: Theme.of(context).textTheme.bodyMedium
                                  ?.copyWith(
                                    color: Theme.of(
                                      context,
                                    ).colorScheme.onSurfaceVariant,
                                  ),
                            ),
                            SizedBox(height: compact ? 18 : 24),
                            child,
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    ),
  );
}

class _ChargeScene extends CustomPainter {
  const _ChargeScene();

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    canvas.drawRect(
      rect,
      Paint()
        ..shader = const RadialGradient(
          center: Alignment(0.9, 0.9),
          radius: 1.4,
          colors: [Color(0xFF174F62), Color(0xFF0A2035)],
        ).createShader(rect),
    );
    final line = Paint()
      ..color = const Color(0xFF82DED9).withValues(alpha: .12)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1;
    for (final radius in [.55, .85, 1.15]) {
      canvas.drawCircle(
        Offset(size.width * .9, size.height * 1.1),
        size.width * radius,
        line,
      );
    }
    final horizon = Path()..moveTo(0, size.height * .91);
    for (var i = 0; i <= 12; i++) {
      final x = size.width * i / 12;
      final y = size.height * (i.isEven ? .86 : .96);
      horizon.lineTo(x, y);
    }
    horizon
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(
      horizon,
      Paint()..color = const Color(0xFF0A2035).withValues(alpha: .45),
    );
  }

  @override
  bool shouldRepaint(_ChargeScene oldDelegate) => false;
}
