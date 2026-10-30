import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_formatters.dart';
import 'package:vtsa_mobile/features/charging/presentation/vehicle_battery_progress.dart';

class LiveChargingScreen extends StatefulWidget {
  const LiveChargingScreen({
    required this.dependencies,
    required this.sessionId,
    super.key,
  });

  final AppDependencies dependencies;
  final String sessionId;

  @override
  State<LiveChargingScreen> createState() => _LiveChargingScreenState();
}

class _LiveChargingScreenState extends State<LiveChargingScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted &&
          widget.dependencies.charging.session?.id != widget.sessionId) {
        widget.dependencies.charging.recoverSession(widget.sessionId);
      }
    });
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: Listenable.merge([
      widget.dependencies.charging,
      widget.dependencies.network,
    ]),
    builder: (context, _) {
      final charging = widget.dependencies.charging;
      final session = charging.session;
      if (session == null || session.id != widget.sessionId) {
        return DriverPage(
          title: 'Charging session',
          body: charging.failure == null
              ? const VtsaSkeleton(lines: 7)
              : VtsaErrorState(
                  title: 'Session could not be recovered',
                  description: charging.failure!.message,
                  correlationId: charging.failure!.correlationId,
                  action: VtsaButton(
                    label: 'Try again',
                    onPressed: () => charging.recoverSession(widget.sessionId),
                  ),
                ),
        );
      }

      return DriverPage(
        selectedIndex: 1,
        title:
            charging.phase == ChargingFlowPhase.live &&
                session.state == ChargingSessionState.charging &&
                !session.chargerDisconnected &&
                charging.isOnline
            ? 'You’re charging'
            : 'Your charging session',
        actions: [
          IconButton(
            tooltip: 'Refresh session',
            onPressed: charging.isOnline ? charging.refresh : null,
            icon: charging.syncing
                ? const SizedBox.square(
                    dimension: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.refresh),
          ),
        ],
        body: ListView(
          children: [
            if (!charging.isOnline)
              const _Notice(
                icon: Icons.cloud_off_outlined,
                title: 'Connection lost',
                description:
                    'Values are the last confirmed server state. Start and stop requests are disabled until the connection returns.',
              )
            else if (!charging.realtimeConnected && !session.isTerminal)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Text(
                  'Updates refresh periodically. Last confirmed readings are shown.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            if (session.chargerDisconnected)
              const _Notice(
                icon: Icons.power_off_outlined,
                title: 'Charger connection interrupted',
                description:
                    'The physical session is not assumed stopped. Power Solutions will wait for authoritative charger evidence.',
              ),
            _PhasePanel(dependencies: widget.dependencies, session: session),
            const SizedBox(height: VtsaSpacing.md),
            _Actions(dependencies: widget.dependencies, session: session),
            const SizedBox(height: VtsaSpacing.md),
            _Metrics(session: session),
            const SizedBox(height: VtsaSpacing.md),
            if (session.batteryPercentage case final int percentage
                when percentage >= 0 && percentage <= 100) ...[
              VehicleBatteryProgress(
                percentage: percentage,
                reportedAt: session.batteryReportedAt,
                connected: charging.isOnline && !session.chargerDisconnected,
                sessionEnded: session.isTerminal,
              ),
              const SizedBox(height: VtsaSpacing.md),
            ],
            _ChargerReadings(session: session),
            const SizedBox(height: VtsaSpacing.md),
            if (charging.phase == ChargingFlowPhase.live &&
                charging.isOnline &&
                !session.chargerDisconnected)
              const DriverNotice(
                title: 'Your session stays with you',
                message:
                    'Check progress here or return through Activity. Final cost is confirmed after the charger stops.',
              ),
            const SizedBox(height: 12),
            if (charging.failure != null) ...[
              const SizedBox(height: VtsaSpacing.md),
              VtsaErrorState(
                title: 'The latest request needs attention',
                description: charging.failure!.message,
                correlationId: charging.failure!.correlationId,
              ),
            ],
            const SizedBox(height: VtsaSpacing.lg),
            VtsaButton(
              label: 'Need help? We’re here',
              variant: VtsaButtonVariant.secondary,
              onPressed: () => showModalBottomSheet<void>(
                context: context,
                isScrollControlled: true,
                builder: (context) => SafeArea(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Your charging session',
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        const SizedBox(height: 12),
                        const Text(
                          'If charging has not started, check the cable and the vehicle’s charging schedule. Do not submit another start while this request is pending.',
                        ),
                        const SizedBox(height: 12),
                        const Text(
                          'To finish, request a stop and wait for charger confirmation before disconnecting. If a payment needs review, keep this session reference for your operator.',
                        ),
                        const SizedBox(height: 12),
                        SelectableText(session.id),
                        TextButton(
                          onPressed: () => Navigator.pop(context),
                          child: const Text('Close'),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 12),
            if (session.canRequestRemoteStop)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  'We’ll ask you to confirm before ending.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            SelectableText(
              'Session ${session.id}',
              style: Theme.of(context).textTheme.labelSmall,
            ),
          ],
        ),
      );
    },
  );
}

class _PhasePanel extends StatelessWidget {
  const _PhasePanel({required this.dependencies, required this.session});

  final AppDependencies dependencies;
  final ChargingSession session;

  @override
  Widget build(BuildContext context) {
    final phase = dependencies.charging.phase;
    final (icon, title, description, state) =
        session.cancellationRequested &&
            !session.isTerminal &&
            session.stoppedAt == null
        ? (
            Icons.pending_actions_outlined,
            'Cancellation requested',
            session.state == ChargingSessionState.stopping
                ? 'A stop has been requested. Wait for the charger to confirm it has stopped before unplugging.'
                : 'Your request is saved. If the charger reports a transaction, we will request a stop automatically. Reserved funds stay held until the outcome is confirmed. If this remains pending, contact your operator with the session reference below.',
            VtsaOperationalState.finishing,
          )
        : switch (phase) {
            ChargingFlowPhase.startPending => (
              Icons.hourglass_top,
              'Waiting for physical start',
              'The request was accepted, but charging is not active until the charger reports a transaction.',
              VtsaOperationalState.preparing,
            ),
            ChargingFlowPhase.startTimedOut => (
              Icons.schedule_outlined,
              'The transaction did not begin in time',
              'Power Solutions has not received start evidence. Refresh or cancel the pending request; do not submit another start.',
              VtsaOperationalState.suspended,
            ),
            ChargingFlowPhase.live => (
              Icons.bolt,
              session.state == ChargingSessionState.charging
                  ? 'Charging in progress'
                  : 'Charging session suspended',
              'Live values remain subject to final meter validation.',
              session.state == ChargingSessionState.charging
                  ? VtsaOperationalState.charging
                  : VtsaOperationalState.suspended,
            ),
            ChargingFlowPhase.stopPending => (
              Icons.stop_circle_outlined,
              'Stop requested',
              'A command acknowledgment does not prove the charger stopped. Waiting for terminal transaction evidence.',
              VtsaOperationalState.finishing,
            ),
            ChargingFlowPhase.finalizing => (
              Icons.receipt_long_outlined,
              session.state == ChargingSessionState.reviewRequired
                  ? 'Session requires review'
                  : 'Finalizing measurements',
              'Energy, duration, tariff, and payment are being finalized. Totals are not final yet.',
              VtsaOperationalState.finishing,
            ),
            ChargingFlowPhase.paymentProcessing => (
              Icons.payments_outlined,
              session.paymentState == PaymentState.requiresAction
                  ? 'Charging payment requires review'
                  : 'Finalizing wallet payment',
              session.paymentState == PaymentState.requiresAction
                  ? 'Your reserved funds are held while the operator checks the charger evidence. Do not start or pay again for this session.'
                  : 'The session ended. We are checking the final readings, deducting the charge once, and releasing unused reserved funds.',
              VtsaOperationalState.preparing,
            ),
            ChargingFlowPhase.completed => (
              Icons.check_circle_outline,
              'Session complete',
              'Final server evidence is available below.',
              VtsaOperationalState.available,
            ),
            ChargingFlowPhase.failed => (
              Icons.info_outline,
              session.state == ChargingSessionState.cancelled
                  ? 'Start request cancelled'
                  : 'Charging did not start or complete',
              session.state == ChargingSessionState.cancelled
                  ? 'The start request is cancelled. No charging fee was taken and the reserved funds are available again.'
                  : 'Check the charger and cable before trying again. Your session details and any payment review remain available in Activity.',
              VtsaOperationalState.faulted,
            ),
            _ => (
              Icons.sync,
              'Updating session',
              'Retrieving the latest authoritative state.',
              VtsaOperationalState.unknown,
            ),
          };
    final healthy =
        phase == ChargingFlowPhase.live &&
        session.state == ChargingSessionState.charging &&
        dependencies.charging.isOnline &&
        !session.chargerDisconnected;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: healthy
            ? context.vtsaColors.successSurface
            : context.vtsaColors.surfaceSubtle,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                icon,
                color: healthy
                    ? context.vtsaColors.success
                    : context.vtsaColors.forState(state),
                size: 18,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  title,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            '${session.stationName} · ${session.connectorName}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          Text(
            'Last server update ${DateFormat('MMM d, HH:mm:ss').format(session.updatedAt.toLocal())}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          if (!healthy) ...[const SizedBox(height: 8), Text(description)],
        ],
      ),
    );
  }
}

class _Metrics extends StatelessWidget {
  const _Metrics({required this.session});
  final ChargingSession session;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      const SizedBox(height: 8),
      Text('ENERGY DELIVERED', style: Theme.of(context).textTheme.labelSmall),
      const SizedBox(height: 8),
      Text(
        formatEnergy(session.energyWh),
        style: Theme.of(context).textTheme.headlineLarge?.copyWith(
          fontSize: 34,
          fontWeight: FontWeight.w600,
        ),
      ),
      const SizedBox(height: 12),
      Container(
        height: 5,
        decoration: BoxDecoration(
          color: context.vtsaColors.border,
          borderRadius: BorderRadius.circular(4),
        ),
      ),
      const SizedBox(height: 8),
      Text(
        'Energy so far · last reported value',
        style: Theme.of(context).textTheme.bodySmall,
      ),
      const SizedBox(height: 24),
      LayoutBuilder(
        builder: (context, constraints) {
          final wideText = MediaQuery.textScalerOf(context).scale(14) > 20;
          final width = wideText
              ? constraints.maxWidth
              : (constraints.maxWidth - 12) / 2;
          return Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              _Metric(
                width: width,
                label: 'SESSION TIME',
                value: formatDuration(session.durationSeconds),
              ),
              _Metric(
                width: width,
                label: session.finalCost == null
                    ? 'ESTIMATED COST'
                    : 'FINAL COST',
                value: formatMoney(session.finalCost ?? session.estimatedCost),
              ),
            ],
          );
        },
      ),
      const SizedBox(height: 8),
      Text(
        'Time and cost use the last server update until the session is finalized.',
        style: Theme.of(context).textTheme.bodySmall,
      ),
    ],
  );
}

class _ChargerReadings extends StatelessWidget {
  const _ChargerReadings({required this.session});

  final ChargingSession session;

  @override
  Widget build(BuildContext context) {
    String time(DateTime? value, String fallback) => value == null
        ? fallback
        : DateFormat.yMMMd().add_jms().format(value.toLocal());

    return VtsaCard(
      title: 'Charging timeline & vehicle',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _Reading(
            label: 'Started',
            value: time(session.startedAt, 'Waiting for charger'),
          ),
          _Reading(label: 'Ended', value: time(session.stoppedAt, 'Not ended')),
          _Reading(
            label: 'Current power',
            value: formatPower(session.currentPowerW),
          ),
          const SizedBox(height: 8),
          _Reading(
            label: 'Energy-flow time',
            value: formatDuration(session.energyFlowSeconds),
          ),
          const SizedBox(height: 4),
          Text(
            'Start and end are confirmed from charger transaction evidence. Times use your phone\'s local timezone.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

class _Reading extends StatelessWidget {
  const _Reading({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.labelMedium),
        const SizedBox(height: 3),
        Text(value, style: Theme.of(context).textTheme.bodyLarge),
      ],
    ),
  );
}

class _Metric extends StatelessWidget {
  const _Metric({
    required this.width,
    required this.label,
    required this.value,
  });
  final double width;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Container(
    width: width,
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: context.vtsaColors.surfaceSubtle,
      borderRadius: BorderRadius.circular(12),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: Theme.of(context).textTheme.labelSmall?.copyWith(fontSize: 10),
        ),
        const SizedBox(height: 10),
        Text(
          value,
          style: Theme.of(context).textTheme.titleLarge?.copyWith(fontSize: 19),
        ),
      ],
    ),
  );
}

class _Actions extends StatelessWidget {
  const _Actions({required this.dependencies, required this.session});

  final AppDependencies dependencies;
  final ChargingSession session;

  @override
  Widget build(BuildContext context) {
    final charging = dependencies.charging;
    final actions = <Widget>[];
    if (session.canRequestRemoteStop) {
      actions.add(
        VtsaButton(
          label: 'End charging',
          variant: VtsaButtonVariant.secondary,
          loading: charging.mutationInFlight,
          onPressed: charging.isOnline
              ? () async {
                  final confirmed = await showVtsaConfirmationDialog(
                    context: context,
                    title: 'Stop charging?',
                    description:
                        'Power Solutions will request a stop for ${session.connectorName}. Keep the vehicle connected until the charger confirms the transaction ended.',
                    confirmLabel: 'Request stop',
                    dangerous: true,
                  );
                  if (confirmed) {
                    await charging.stop();
                  }
                }
              : null,
        ),
      );
    }
    if (session.canCancelStart) {
      actions.addAll([
        VtsaButton(
          label: 'Cancel pending start',
          variant: VtsaButtonVariant.secondary,
          loading: charging.mutationInFlight,
          onPressed: charging.isOnline
              ? () async {
                  final confirmed = await showVtsaConfirmationDialog(
                    context: context,
                    title: 'Cancel this start request?',
                    description:
                        'If Start has not been sent, we will cancel it and release your reserved funds. Otherwise, we will save the cancellation and request a stop when the charger reports its transaction. Funds remain held until the outcome is confirmed.',
                    confirmLabel: 'Cancel start request',
                  );
                  if (confirmed) await charging.cancelPendingStart();
                }
              : null,
        ),
      ]);
    }
    if (session.cancellationRequested && !session.isTerminal) {
      actions.add(
        VtsaButton(
          label: 'Refresh status',
          loading: charging.syncing,
          onPressed: charging.isOnline ? charging.refresh : null,
        ),
      );
    }
    if (charging.phase == ChargingFlowPhase.completed) {
      if (session.receipt != null) {
        actions.add(
          VtsaButton(
            label: 'Open receipt',
            icon: Icons.receipt_long_outlined,
            onPressed: () => launchUrl(
              session.receipt!.downloadUrl,
              mode: LaunchMode.externalApplication,
            ),
          ),
        );
      }
      if (session.invoice != null) {
        actions.add(
          VtsaButton(
            label: 'Download invoice',
            variant: VtsaButtonVariant.secondary,
            onPressed: () => launchUrl(
              session.invoice!.downloadUrl,
              mode: LaunchMode.externalApplication,
            ),
          ),
        );
      }
      if (session.refundable) {
        actions.add(
          VtsaButton(
            label: 'Request a refund review',
            variant: VtsaButtonVariant.secondary,
            onPressed: () => context.push('/activity/${session.id}/refund'),
          ),
        );
      }
    }
    actions.add(
      VtsaButton(
        label: 'Report an issue',
        variant: VtsaButtonVariant.quiet,
        onPressed: () => context.push('/activity/${session.id}/issue'),
      ),
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var index = 0; index < actions.length; index++) ...[
          actions[index],
          if (index < actions.length - 1)
            const SizedBox(height: VtsaSpacing.sm),
        ],
      ],
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({
    required this.icon,
    required this.title,
    required this.description,
  });

  final IconData icon;
  final String title;
  final String description;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: VtsaSpacing.md),
    child: VtsaCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: context.vtsaColors.warning),
          const SizedBox(width: VtsaSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: VtsaSpacing.xxs),
                Text(description),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}
