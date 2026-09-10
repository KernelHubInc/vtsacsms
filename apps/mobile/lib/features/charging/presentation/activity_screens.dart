import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_formatters.dart';

class ActivityScreen extends StatefulWidget {
  const ActivityScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<ActivityScreen> createState() => _ActivityScreenState();
}

class _ActivityScreenState extends State<ActivityScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback(
      (_) => widget.dependencies.charging.loadHistory(),
    );
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: widget.dependencies.charging,
    builder: (context, _) {
      final charging = widget.dependencies.charging;
      final active = charging.session;
      final history = charging.history
          .where((session) => session.id != active?.id)
          .toList(growable: false);
      return VtsaPageShell(
        eyebrow: 'YOUR CHARGING',
        title: 'Activity',
        body: RefreshIndicator(
          onRefresh: () async {
            await Future.wait([charging.refresh(), charging.loadHistory()]);
          },
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              if (active != null && !active.isTerminal) ...[
                Text(
                  'CURRENT SESSION',
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                    color: Theme.of(context).colorScheme.primary,
                    letterSpacing: 1.2,
                  ),
                ),
                const SizedBox(height: VtsaSpacing.sm),
                _SessionRow(
                  session: active,
                  active: true,
                  onTap: () => context.push('/charging/${active.id}'),
                ),
                const SizedBox(height: VtsaSpacing.xl),
              ],
              Text(
                'HISTORY',
                style: Theme.of(
                  context,
                ).textTheme.labelSmall?.copyWith(letterSpacing: 1.2),
              ),
              const SizedBox(height: VtsaSpacing.sm),
              if (charging.historyLoading && history.isEmpty)
                const VtsaSkeleton(lines: 6)
              else if (history.isEmpty)
                const VtsaEmptyState(
                  icon: Icons.receipt_long_outlined,
                  title: 'No completed sessions yet',
                  description:
                      'Finalized charging sessions will appear here after the server confirms them.',
                )
              else
                for (var index = 0; index < history.length; index++) ...[
                  _SessionRow(
                    session: history[index],
                    onTap: () => context.push('/activity/${history[index].id}'),
                  ),
                  if (index < history.length - 1)
                    const SizedBox(height: VtsaSpacing.sm),
                ],
              if (charging.hasMoreHistory) ...[
                const SizedBox(height: VtsaSpacing.lg),
                VtsaButton(
                  label: 'Load more',
                  variant: VtsaButtonVariant.secondary,
                  loading: charging.historyLoading,
                  onPressed: () => charging.loadHistory(nextPage: true),
                ),
              ],
            ],
          ),
        ),
      );
    },
  );
}

class SessionDetailScreen extends StatefulWidget {
  const SessionDetailScreen({
    required this.dependencies,
    required this.sessionId,
    super.key,
  });

  final AppDependencies dependencies;
  final String sessionId;

  @override
  State<SessionDetailScreen> createState() => _SessionDetailScreenState();
}

class _SessionDetailScreenState extends State<SessionDetailScreen> {
  late Future<ChargingSession?> _session;

  @override
  void initState() {
    super.initState();
    _session = widget.dependencies.charging.fetchSessionDetail(
      widget.sessionId,
    );
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<ChargingSession?>(
    future: _session,
    builder: (context, snapshot) {
      final session = snapshot.data;
      if (snapshot.connectionState != ConnectionState.done) {
        return const VtsaPageShell(
          title: 'Session detail',
          body: VtsaSkeleton(lines: 8),
        );
      }
      if (session == null) {
        return VtsaPageShell(
          title: 'Session detail',
          body: VtsaErrorState(
            title: 'Session unavailable',
            description:
                'Reconnect and try again. The server remains authoritative for session history.',
            action: VtsaButton(
              label: 'Try again',
              onPressed: () => setState(() {
                _session = widget.dependencies.charging.fetchSessionDetail(
                  widget.sessionId,
                );
              }),
            ),
          ),
        );
      }
      return VtsaPageShell(
        eyebrow: session.state.name.toUpperCase(),
        title: session.stationName,
        body: ListView(
          children: [
            _SessionRow(session: session, onTap: () {}),
            const SizedBox(height: VtsaSpacing.md),
            VtsaCard(
              title: 'Charge summary',
              child: Column(
                children: [
                  _Fact(label: 'Energy', value: formatEnergy(session.energyWh)),
                  _Fact(
                    label: 'Duration',
                    value: formatDuration(session.durationSeconds),
                  ),
                  _Fact(
                    label: 'Final cost',
                    value: formatMoney(session.finalCost),
                  ),
                  _Fact(label: 'Payment', value: session.paymentState.name),
                ],
              ),
            ),
            const SizedBox(height: VtsaSpacing.md),
            VtsaCard(
              title: 'Evidence',
              child: Column(
                children: [
                  _Fact(
                    label: 'Requested',
                    value: DateFormat.yMMMd().add_Hm().format(
                      session.requestedAt.toLocal(),
                    ),
                  ),
                  _Fact(
                    label: 'Started',
                    value: session.startedAt == null
                        ? 'Not established'
                        : DateFormat.yMMMd().add_Hm().format(
                            session.startedAt!.toLocal(),
                          ),
                  ),
                  _Fact(
                    label: 'Stopped',
                    value: session.stoppedAt == null
                        ? 'Not finalized'
                        : DateFormat.yMMMd().add_Hm().format(
                            session.stoppedAt!.toLocal(),
                          ),
                  ),
                  _Fact(
                    label: 'Session ID',
                    value: session.id,
                    selectable: true,
                  ),
                ],
              ),
            ),
            if (session.receipt != null) ...[
              const SizedBox(height: VtsaSpacing.md),
              VtsaButton(
                label: 'Open receipt ${session.receipt!.reference}',
                onPressed: () => launchUrl(
                  session.receipt!.downloadUrl,
                  mode: LaunchMode.externalApplication,
                ),
              ),
            ],
            if (session.invoice != null) ...[
              const SizedBox(height: VtsaSpacing.sm),
              VtsaButton(
                label: 'Download invoice ${session.invoice!.reference}',
                variant: VtsaButtonVariant.secondary,
                onPressed: () => launchUrl(
                  session.invoice!.downloadUrl,
                  mode: LaunchMode.externalApplication,
                ),
              ),
            ],
            const SizedBox(height: VtsaSpacing.sm),
            if (session.refundable)
              VtsaButton(
                label: 'Request a refund review',
                variant: VtsaButtonVariant.secondary,
                onPressed: () => context.push('/activity/${session.id}/refund'),
              ),
            const SizedBox(height: VtsaSpacing.sm),
            VtsaButton(
              label: 'Report an issue',
              variant: VtsaButtonVariant.quiet,
              onPressed: () => context.push('/activity/${session.id}/issue'),
            ),
          ],
        ),
      );
    },
  );
}

class RefundRequestScreen extends StatefulWidget {
  const RefundRequestScreen({
    required this.dependencies,
    required this.sessionId,
    super.key,
  });

  final AppDependencies dependencies;
  final String sessionId;

  @override
  State<RefundRequestScreen> createState() => _RefundRequestScreenState();
}

class _RefundRequestScreenState extends State<RefundRequestScreen> {
  final _reason = TextEditingController();
  bool _submitted = false;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => VtsaPageShell(
    eyebrow: 'REVIEW REQUEST',
    title: 'Request a refund review',
    body: _submitted
        ? VtsaEmptyState(
            icon: Icons.mark_email_read_outlined,
            title: 'Request received',
            description:
                'A refund is not automatic. Power Solutions will review the session and verified payment evidence.',
            action: VtsaButton(
              label: 'Back to session',
              onPressed: () => context.go('/activity/${widget.sessionId}'),
            ),
          )
        : ListView(
            children: [
              const VtsaCard(
                child: Text(
                  'Submitting this form creates a review request. It does not immediately reverse or duplicate a payment.',
                ),
              ),
              const SizedBox(height: VtsaSpacing.md),
              VtsaTextField(
                label: 'Reason for review',
                hint: 'Describe what appears incorrect.',
                controller: _reason,
                required: true,
              ),
              const SizedBox(height: VtsaSpacing.lg),
              VtsaButton(
                label: 'Submit review request',
                loading: widget.dependencies.charging.mutationInFlight,
                onPressed: _submit,
              ),
            ],
          ),
  );

  Future<void> _submit() async {
    if (_reason.text.trim().length < 10) {
      showVtsaToast(context, message: 'Add at least 10 characters.');
      return;
    }
    final sent = await widget.dependencies.charging.requestRefund(
      _reason.text.trim(),
      sessionId: widget.sessionId,
    );
    if (mounted && sent) {
      setState(() => _submitted = true);
    }
  }
}

class IssueReportScreen extends StatefulWidget {
  const IssueReportScreen({
    required this.dependencies,
    required this.sessionId,
    super.key,
  });

  final AppDependencies dependencies;
  final String sessionId;

  @override
  State<IssueReportScreen> createState() => _IssueReportScreenState();
}

class _IssueReportScreenState extends State<IssueReportScreen> {
  final _description = TextEditingController();
  String _category = 'charger';
  bool _submitted = false;

  @override
  void dispose() {
    _description.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => VtsaPageShell(
    eyebrow: 'CUSTOMER SUPPORT',
    title: 'Report an issue',
    body: _submitted
        ? VtsaEmptyState(
            icon: Icons.support_agent,
            title: 'Issue submitted',
            description:
                'Support received the public session reference and your description.',
            action: VtsaButton(
              label: 'Back to session',
              onPressed: () => context.go('/activity/${widget.sessionId}'),
            ),
          )
        : ListView(
            children: [
              DropdownButtonFormField<String>(
                initialValue: _category,
                decoration: const InputDecoration(labelText: 'Issue category'),
                items: const [
                  DropdownMenuItem(value: 'charger', child: Text('Charger')),
                  DropdownMenuItem(value: 'session', child: Text('Session')),
                  DropdownMenuItem(value: 'payment', child: Text('Payment')),
                  DropdownMenuItem(value: 'receipt', child: Text('Receipt')),
                  DropdownMenuItem(value: 'other', child: Text('Other')),
                ],
                onChanged: (value) => setState(() => _category = value!),
              ),
              const SizedBox(height: VtsaSpacing.md),
              VtsaTextField(
                label: 'What happened?',
                hint: 'Do not include card numbers or passwords.',
                controller: _description,
                required: true,
              ),
              const SizedBox(height: VtsaSpacing.lg),
              VtsaButton(
                label: 'Submit issue',
                loading: widget.dependencies.charging.mutationInFlight,
                onPressed: _submit,
              ),
              const SizedBox(height: VtsaSpacing.sm),
              VtsaButton(
                label: 'Open customer support',
                variant: VtsaButtonVariant.secondary,
                onPressed: () => launchUrl(
                  widget.dependencies.environment.apiBaseUrl.resolve(
                    '/support',
                  ),
                  mode: LaunchMode.externalApplication,
                ),
              ),
            ],
          ),
  );

  Future<void> _submit() async {
    if (_description.text.trim().length < 10) {
      showVtsaToast(context, message: 'Add at least 10 characters.');
      return;
    }
    final sent = await widget.dependencies.charging.reportIssue(
      sessionId: widget.sessionId,
      category: _category,
      description: _description.text.trim(),
    );
    if (mounted && sent) {
      setState(() => _submitted = true);
    }
  }
}

class _SessionRow extends StatelessWidget {
  const _SessionRow({
    required this.session,
    required this.onTap,
    this.active = false,
  });

  final ChargingSession session;
  final VoidCallback onTap;
  final bool active;

  @override
  Widget build(BuildContext context) => VtsaCard(
    padding: EdgeInsets.zero,
    child: ListTile(
      onTap: onTap,
      leading: CircleAvatar(
        backgroundColor: active
            ? context.vtsaColors.brandSoft
            : context.vtsaColors.surfaceSubtle,
        child: Icon(active ? Icons.bolt : Icons.ev_station_outlined),
      ),
      title: Text(session.stationName),
      subtitle: Text(
        '${DateFormat.yMMMd().format(session.requestedAt.toLocal())} · ${formatEnergy(session.energyWh)}',
      ),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(
            formatMoney(session.finalCost ?? session.estimatedCost),
            style: Theme.of(context).textTheme.labelLarge,
          ),
          Text(
            session.state.name,
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
              color: context.vtsaColors.textMuted,
            ),
          ),
        ],
      ),
    ),
  );
}

class _Fact extends StatelessWidget {
  const _Fact({
    required this.label,
    required this.value,
    this.selectable = false,
  });

  final String label;
  final String value;
  final bool selectable;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: VtsaSpacing.xs),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Text(
            label,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              color: context.vtsaColors.textMuted,
            ),
          ),
        ),
        const SizedBox(width: VtsaSpacing.md),
        Flexible(
          child: selectable
              ? SelectableText(value, textAlign: TextAlign.end)
              : Text(value, textAlign: TextAlign.end),
        ),
      ],
    ),
  );
}
