import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/accounts/charging_account_screen.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_formatters.dart';

class ActivityScreen extends StatefulWidget {
  const ActivityScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<ActivityScreen> createState() => _ActivityScreenState();
}

class _ActivityScreenState extends State<ActivityScreen> {
  String _filter = 'All';
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) widget.dependencies.charging.loadHistory();
    });
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: widget.dependencies.charging,
    builder: (context, _) {
      final charging = widget.dependencies.charging;
      final active = charging.session;
      final history = charging.history
          .where(
            (session) =>
                active == null || active.isTerminal || session.id != active.id,
          )
          .where(
            (session) => switch (_filter) {
              'Completed' => session.state == ChargingSessionState.completed,
              'Pending' => !session.isTerminal,
              _ => true,
            },
          )
          .toList(growable: false);
      return DriverTabPage(
        subtitle: widget.dependencies.accounts?.selected == null
            ? 'Charging activity and receipts'
            : 'History for ${widget.dependencies.accounts!.selected!.name}',
        title: 'Activity',
        body: RefreshIndicator(
          onRefresh: () async {
            await Future.wait([charging.refresh(), charging.loadHistory()]);
          },
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              ChargingAccountSelector(dependencies: widget.dependencies),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final label in ['All', 'Completed', 'Pending'])
                    DriverFilterChip(
                      label: label,
                      selected: _filter == label,
                      onSelected: (_) => setState(() => _filter = label),
                    ),
                ],
              ),
              const SizedBox(height: 20),
              if (charging.failure != null)
                VtsaErrorState(
                  title: 'Activity could not be refreshed',
                  description: charging.failure!.message,
                  correlationId: charging.failure!.correlationId,
                ),
              if (active != null && !active.isTerminal) ...[
                if (active.attribution['payer'] case final String payer)
                  Text('Current session payer: $payer'),
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
              else if (history.isEmpty && charging.failure == null)
                const VtsaEmptyState(
                  icon: Icons.receipt_long_outlined,
                  title: 'No sessions in this view',
                  description:
                      'Session records appear after server confirmation. Filters apply to loaded history; load more to see earlier records.',
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
                  _Fact(
                    label: 'Payment',
                    value: session.paymentState.name.replaceAllMapped(
                      RegExp(r'[A-Z]'),
                      (match) => ' ${match[0]!.toLowerCase()}',
                    ),
                  ),
                ],
              ),
            ),
            if (session.attribution.isNotEmpty) ...[
              const SizedBox(height: VtsaSpacing.md),
              VtsaCard(
                title: 'Charged to',
                child: Column(
                  children: [
                    _Fact(
                      label: 'Account',
                      value:
                          session.attribution['account_name'] as String? ??
                          'Personal',
                    ),
                    _Fact(
                      label: 'Driver',
                      value:
                          session.attribution['driver_name'] as String? ??
                          session.attribution['driver_id'] as String? ??
                          'Recorded driver',
                    ),
                    if (session.attribution['vehicle']
                        case final Map<String, dynamic> vehicle)
                      _Fact(
                        label: 'Vehicle',
                        value: vehicle['nickname'] as String,
                      ),
                    _Fact(
                      label: 'Payer',
                      value:
                          session.attribution['payer'] as String? ??
                          'Personal prepaid wallet',
                    ),
                  ],
                ),
              ),
            ],
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
  bool _busy = false;
  String? _error;

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
              if (_error != null) Text(_error!),
              VtsaButton(
                label: 'Submit review request',
                loading: _busy,
                onPressed: _busy ? null : _submit,
              ),
            ],
          ),
  );

  Future<void> _submit() async {
    if (_busy) return;
    if (_reason.text.trim().length < 10) {
      showVtsaToast(context, message: 'Add at least 10 characters.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final sent = await widget.dependencies.charging.requestRefund(
      _reason.text.trim(),
      sessionId: widget.sessionId,
    );
    if (mounted) {
      setState(() {
        _busy = false;
        _submitted = sent;
        if (!sent) {
          _error =
              widget.dependencies.charging.failure?.message ??
              'Request was not sent. Check your connection and try again.';
        }
      });
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
  bool _busy = false;
  String? _error;

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
              SelectableText('Session reference: ${widget.sessionId}'),
              TextButton.icon(
                onPressed: () =>
                    Clipboard.setData(ClipboardData(text: widget.sessionId)),
                icon: const Icon(Icons.copy),
                label: const Text('Copy session reference'),
              ),
              if (_error != null) Text(_error!),
              VtsaButton(
                label: 'Submit issue',
                loading: _busy,
                onPressed: _busy ? null : _submit,
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
    if (_busy) return;
    if (_description.text.trim().length < 10) {
      showVtsaToast(context, message: 'Add at least 10 characters.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final sent = await widget.dependencies.charging.reportIssue(
      sessionId: widget.sessionId,
      category: _category,
      description: _description.text.trim(),
    );
    if (mounted) {
      final failure = widget.dependencies.charging.failure;
      setState(() {
        _busy = false;
        _submitted = sent;
        if (!sent) {
          _error = failure?.kind == FailureKind.notFound
              ? 'In-app support submission is not available. Copy your session reference and open customer support below. Your issue has not been submitted.'
              : failure?.message ??
                    'Issue was not sent. Check your connection and try again.';
        }
      });
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
    child: InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              DateFormat.yMMMd().add_jm().format(session.requestedAt.toLocal()),
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: Text(
                    session.stationName,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                Icon(active ? Icons.bolt : Icons.chevron_right, size: 20),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              '${formatEnergy(session.energyWh)} · ${formatDuration(session.durationSeconds)}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 16,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(
                  '${formatMoney(session.finalCost ?? session.estimatedCost)}${session.finalCost == null ? ' estimated' : ''}',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                VtsaStatusChip(
                  label: session.state.name.replaceAllMapped(
                    RegExp(r'[A-Z]'),
                    (m) => ' ${m[0]!.toLowerCase()}',
                  ),
                  state: session.state == ChargingSessionState.completed
                      ? VtsaOperationalState.available
                      : session.state == ChargingSessionState.charging
                      ? VtsaOperationalState.charging
                      : VtsaOperationalState.unknown,
                ),
              ],
            ),
          ],
        ),
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
