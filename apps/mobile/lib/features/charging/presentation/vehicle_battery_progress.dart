import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

class VehicleBatteryProgress extends StatefulWidget {
  const VehicleBatteryProgress({
    required this.percentage,
    required this.reportedAt,
    required this.connected,
    required this.sessionEnded,
    this.now = DateTime.now,
    super.key,
  });

  final int? percentage;
  final DateTime? reportedAt;
  final bool connected;
  final bool sessionEnded;
  final DateTime Function() now;

  @override
  State<VehicleBatteryProgress> createState() => _VehicleBatteryProgressState();
}

class _VehicleBatteryProgressState extends State<VehicleBatteryProgress> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    // Readings must age even when network updates stop arriving.
    _timer = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = context.vtsaColors;
    final raw = widget.percentage;
    final percentage = raw != null && raw >= 0 && raw <= 100 ? raw : null;
    final reportedAt = widget.reportedAt;
    final age = reportedAt == null ? null : widget.now().difference(reportedAt);
    final fresh =
        widget.connected &&
        !widget.sessionEnded &&
        age != null &&
        !age.isNegative &&
        age <= const Duration(minutes: 5);
    final status = percentage == null
        ? 'Not reported by charger'
        : widget.sessionEnded
        ? 'Last reported battery level'
        : fresh
        ? 'Latest battery reading'
        : 'Last known battery level';
    final ring = Semantics(
      container: true,
      label: 'Vehicle battery',
      value: percentage == null
          ? 'Not reported by charger'
          : '$percentage percent. $status',
      child: ExcludeSemantics(
        child: SizedBox.square(
          dimension: 156,
          child: Stack(
            alignment: Alignment.center,
            children: [
              Positioned.fill(
                child: CircularProgressIndicator(
                  value: percentage == null ? 0 : percentage / 100,
                  strokeWidth: 10,
                  strokeCap: StrokeCap.round,
                  backgroundColor: colors.border,
                  color: fresh ? colors.accent : colors.textMuted,
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(22),
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        percentage == null
                            ? Icons.battery_unknown_outlined
                            : Icons.battery_std_outlined,
                        color: colors.textMuted,
                        size: 24,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        percentage == null ? '—' : '$percentage%',
                        style: theme.textTheme.headlineLarge?.copyWith(
                          fontSize: 38,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    final details = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(status, style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        Text(
          percentage == null
              ? 'This charger has not provided a battery percentage. Check your vehicle’s display.'
              : 'Battery level reported by the charger. Energy delivered is shown separately.',
          style: theme.textTheme.bodyMedium,
        ),
        if (percentage != null && reportedAt != null) ...[
          const SizedBox(height: 8),
          Text(
            'Reported ${DateFormat.yMMMd().add_jms().format(reportedAt.toLocal())}',
            style: theme.textTheme.bodySmall?.copyWith(color: colors.textMuted),
          ),
        ],
      ],
    );

    return VtsaCard(
      title: 'Vehicle battery',
      child: LayoutBuilder(
        builder: (context, constraints) {
          if (constraints.maxWidth >= 400 &&
              MediaQuery.textScalerOf(context).scale(14) <= 21) {
            return Row(
              children: [
                ring,
                const SizedBox(width: 24),
                Expanded(child: details),
              ],
            );
          }
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(child: ring),
              const SizedBox(height: 20),
              details,
            ],
          );
        },
      ),
    );
  }
}
