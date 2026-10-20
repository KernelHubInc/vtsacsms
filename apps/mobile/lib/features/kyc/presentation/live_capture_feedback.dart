import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:vtsa_mobile/features/kyc/domain/live_challenge.dart';
import 'package:vtsa_mobile/features/kyc/presentation/capture_guide.dart';

enum LiveScanState {
  ready,
  scanning,
  move,
  unclear,
  aligned,
  complete;

  static LiveScanState from(
    LiveChallenge? challenge, {
    required bool checking,
  }) {
    if (checking) return scanning;
    if (challenge == null) return ready;
    if (challenge.complete) return complete;
    return switch (challenge.feedback) {
      'hold_still' => aligned,
      'face_not_clear' => unclear,
      _ => move,
    };
  }

  Color get color => switch (this) {
    aligned || complete => const Color(0xff24d887),
    unclear => const Color(0xffffc04a),
    ready => Colors.white,
    _ => const Color(0xff65cfff),
  };

  String get label => switch (this) {
    ready => 'Position your face in the oval',
    scanning => 'Scanning your face',
    aligned => 'Position confirmed — hold still',
    unclear => 'Face not clear — adjust lighting',
    move => 'Follow the movement shown above',
    complete => 'Live check complete',
  };
}

class LiveFaceGuide extends StatefulWidget {
  const LiveFaceGuide({required this.state, super.key});
  final LiveScanState state;

  @override
  State<LiveFaceGuide> createState() => _LiveFaceGuideState();
}

class _LiveFaceGuideState extends State<LiveFaceGuide>
    with SingleTickerProviderStateMixin {
  late final AnimationController motion = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1600),
  );

  void updateMotion() {
    if (widget.state == LiveScanState.scanning &&
        !MediaQuery.disableAnimationsOf(context)) {
      if (!motion.isAnimating) motion.repeat(reverse: true);
    } else {
      motion.stop();
    }
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    updateMotion();
  }

  @override
  void didUpdateWidget(LiveFaceGuide oldWidget) {
    super.didUpdateWidget(oldWidget);
    updateMotion();
  }

  @override
  void dispose() {
    motion.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: motion,
    builder: (context, _) => CaptureGuide(
      face: true,
      color: widget.state.color,
      scanPosition:
          widget.state == LiveScanState.scanning &&
              !MediaQuery.disableAnimationsOf(context)
          ? .1 + motion.value * .8
          : null,
    ),
  );
}

class LiveScanIndicator extends StatelessWidget {
  const LiveScanIndicator({required this.state, super.key});
  final LiveScanState state;

  @override
  Widget build(BuildContext context) => Semantics(
    liveRegion: true,
    child: Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xff102b4a),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Icon(
            switch (state) {
              LiveScanState.aligned ||
              LiveScanState.complete => Icons.check_circle,
              LiveScanState.unclear => Icons.light_mode_outlined,
              LiveScanState.scanning => Icons.center_focus_strong,
              _ => Icons.face_outlined,
            },
            color: state.color,
            size: 24,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              state.label,
              style: const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

class HeadMovementCue extends StatefulWidget {
  const HeadMovementCue({required this.action, this.hold = false, super.key});
  final String action;
  final bool hold;

  @override
  State<HeadMovementCue> createState() => _HeadMovementCueState();
}

class _HeadMovementCueState extends State<HeadMovementCue>
    with SingleTickerProviderStateMixin {
  late final AnimationController motion = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1400),
  );

  void updateMotion() {
    if (!widget.hold &&
        const {'left', 'right'}.contains(widget.action) &&
        !MediaQuery.disableAnimationsOf(context)) {
      if (!motion.isAnimating) motion.repeat(reverse: true);
    } else {
      motion.stop();
    }
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    updateMotion();
  }

  @override
  void didUpdateWidget(HeadMovementCue oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.action != widget.action) motion.value = 0;
    updateMotion();
  }

  @override
  void dispose() {
    motion.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ExcludeSemantics(
    child: SizedBox(
      width: 100,
      height: 64,
      child: AnimatedBuilder(
        animation: motion,
        builder: (context, _) {
          final direction = widget.action == 'left'
              ? -1.0
              : widget.action == 'right'
              ? 1.0
              : 0.0;
          final position =
              widget.hold || MediaQuery.disableAnimationsOf(context)
              ? 1.0
              : Curves.easeInOut.transform(motion.value);
          return CustomPaint(
            painter: _HeadPainter(
              turn: direction * position,
              direction: direction,
              color: Theme.of(context).colorScheme.primary,
            ),
          );
        },
      ),
    ),
  );
}

class _HeadPainter extends CustomPainter {
  const _HeadPainter({
    required this.turn,
    required this.direction,
    required this.color,
  });
  final double turn;
  final double direction;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final center = size.center(Offset.zero);
    final stroke = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.5
      ..strokeCap = StrokeCap.round;
    final fill = Paint()..color = color;
    final width = 38 - turn.abs() * 8;
    canvas.drawOval(
      Rect.fromCenter(center: center, width: width, height: 48),
      stroke,
    );
    final shift = turn * 8;
    for (final eye in [-1, 1]) {
      canvas.drawCircle(
        center + Offset(shift + eye * (8 - turn.abs() * 2), -7),
        2,
        fill,
      );
    }
    final nose = Path()
      ..moveTo(center.dx + shift, center.dy - 3)
      ..lineTo(center.dx + shift + turn * 3, center.dy + 5)
      ..lineTo(center.dx + shift - 3, center.dy + 5);
    canvas.drawPath(nose, stroke);
    canvas.drawArc(
      Rect.fromCenter(center: center + Offset(shift, 10), width: 12, height: 6),
      0,
      math.pi,
      false,
      stroke,
    );
    if (direction != 0) {
      final tip = center + Offset(direction * 44, 0);
      final arrow = Path()
        ..moveTo(center.dx + direction * 27, center.dy)
        ..lineTo(tip.dx, tip.dy)
        ..moveTo(tip.dx - direction * 6, tip.dy - 6)
        ..lineTo(tip.dx, tip.dy)
        ..lineTo(tip.dx - direction * 6, tip.dy + 6);
      canvas.drawPath(arrow, stroke);
    }
  }

  @override
  bool shouldRepaint(_HeadPainter oldDelegate) =>
      oldDelegate.turn != turn ||
      oldDelegate.direction != direction ||
      oldDelegate.color != color;
}
