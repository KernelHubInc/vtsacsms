import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_formatters.dart';

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
      if (widget.dependencies.charging.session?.id != widget.sessionId) {
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
        return VtsaPageShell(
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

      return VtsaPageShell(
        eyebrow: _eyebrow(charging.phase),
        title: session.stationName,
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
              const _Notice(
                icon: Icons.sync,
                title: 'Live channel unavailable',
                description:
                    'Power Solutions is polling the authoritative session. Values may update less frequently.',
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
            _Metrics(session: session),
            const SizedBox(height: VtsaSpacing.md),
            VtsaCard(
              eyebrow: 'CHARGER',
              title: session.connectorName,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(session.stationName),
                  const SizedBox(height: VtsaSpacing.xs),
                  SelectableText(
                    'Session ${session.id}',
                    style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      color: context.vtsaColors.textMuted,
                    ),
                  ),
                ],
              ),
            ),
            if (charging.failure != null) ...[
              const SizedBox(height: VtsaSpacing.md),
              VtsaErrorState(
                title: 'The latest request needs attention',
                description: charging.failure!.message,
                correlationId: charging.failure!.correlationId,
              ),
            ],
            const SizedBox(height: VtsaSpacing.lg),
            _Actions(dependencies: widget.dependencies, session: session),
          ],
        ),
      );
    },
  );

  String _eyebrow(ChargingFlowPhase phase) => switch (phase) {
    ChargingFlowPhase.startPending => 'START PENDING',
    ChargingFlowPhase.startTimedOut => 'START NEEDS ATTENTION',
    ChargingFlowPhase.live => 'LIVE CHARGING',
    ChargingFlowPhase.stopPending => 'STOP PENDING',
    ChargingFlowPhase.finalizing => 'FINALIZING',
    ChargingFlowPhase.paymentProcessing => 'PAYMENT PROCESSING',
    ChargingFlowPhase.completed => 'SESSION COMPLETE',
    ChargingFlowPhase.failed => 'SESSION ENDED',
    _ => 'CHARGING SESSION',
  };
}

class _PhasePanel extends StatelessWidget {
  const _PhasePanel({required this.dependencies, required this.session});

  final AppDependencies dependencies;
  final ChargingSession session;

  @override
  Widget build(BuildContext context) {
    final phase = dependencies.charging.phase;
    final (icon, title, description, state) = switch (phase) {
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
        'Payment confirmation pending',
        'The charging session ended. Power Solutions is waiting for verified provider status and will not create a duplicate charge.',
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
        'Session did not complete normally',
        session.failureReason ??
            'Review the session details or report an issue.',
        VtsaOperationalState.faulted,
      ),
      _ => (
        Icons.sync,
        'Updating session',
        'Retrieving the latest authoritative state.',
        VtsaOperationalState.unknown,
      ),
    };
    return VtsaCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: context.vtsaColors.forState(state), size: 30),
          const SizedBox(width: VtsaSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: VtsaSpacing.xs),
                Text(description),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Metrics extends StatelessWidget {
  const _Metrics({required this.session});

  final ChargingSession session;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      final width = constraints.maxWidth >= 600
          ? (constraints.maxWidth - VtsaSpacing.sm) / 2
          : constraints.maxWidth;
      return Wrap(
        spacing: VtsaSpacing.sm,
        runSpacing: VtsaSpacing.sm,
        children: [
          _Metric(
            width: width,
            label: 'Energy consumed',
            value: formatEnergy(session.energyWh),
            icon: Icons.battery_charging_full,
          ),
          _Metric(
            width: width,
            label: 'Elapsed time',
            value: formatDuration(session.durationSeconds),
            icon: Icons.timer_outlined,
          ),
          _Metric(
            width: width,
            label: 'Current power',
            value: formatPower(session.currentPowerW),
            icon: Icons.speed,
          ),
          _Metric(
            width: width,
            label: session.finalCost == null ? 'Estimated cost' : 'Final cost',
            value: formatMoney(session.finalCost ?? session.estimatedCost),
            icon: Icons.payments_outlined,
          ),
        ],
      );
    },
  );
}

class _Metric extends StatelessWidget {
  const _Metric({
    required this.width,
    required this.label,
    required this.value,
    required this.icon,
  });

  final double width;
  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: width,
    child: VtsaCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: Theme.of(context).colorScheme.primary),
          const SizedBox(height: VtsaSpacing.md),
          Text(value, style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: VtsaSpacing.xxs),
          Text(
            label,
            style: Theme.of(context).textTheme.labelMedium?.copyWith(
              color: context.vtsaColors.textMuted,
            ),
          ),
        ],
      ),
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
          label: 'Stop charging',
          variant: VtsaButtonVariant.danger,
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
    if (charging.phase == ChargingFlowPhase.startTimedOut) {
      actions.addAll([
        VtsaButton(label: 'Refresh status', onPressed: charging.refresh),
        VtsaButton(
          label: 'Cancel pending start',
          variant: VtsaButtonVariant.secondary,
          onPressed: charging.isOnline ? charging.cancelPendingStart : null,
        ),
      ]);
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
