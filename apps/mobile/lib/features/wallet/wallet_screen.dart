import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/ids/ulid_generator.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/wallet/wallet_repository.dart';

class WalletScreen extends StatefulWidget {
  const WalletScreen({
    required this.repository,
    this.allowRealPayments = false,
    super.key,
  });
  final WalletRepository? repository;
  final bool allowRealPayments;
  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  Map<String, dynamic>? _wallet;
  List<Map<String, dynamic>> _orders = [];
  List<Map<String, dynamic>> _entries = [];
  String? _historyCursor;
  String? _topupCursor;
  String? _error;
  bool _loading = false;
  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (_loading) return;
    final repository = widget.repository;
    if (repository == null) {
      setState(() => _error = 'Wallet services are not available yet.');
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final results = await Future.wait([
        repository.summary(),
        repository.topups(),
      ]);
      if (!mounted) return;
      setState(() {
        _wallet = results[0];
        if (_wallet!['book'] == 'live' && !widget.allowRealPayments) {
          _wallet!['topups_enabled'] = false;
        }
        _orders = _items(results[1]);
        _topupCursor = results[1]['next_cursor'] as String?;
        final history = Map<String, dynamic>.from(_wallet!['history'] as Map);
        _entries = _items(history);
        _historyCursor = history['next_cursor'] as String?;
      });
    } on Object catch (error) {
      if (mounted) setState(() => _error = _message(error));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _more(bool history) async {
    if (_loading) return;
    setState(() => _loading = true);
    try {
      final result = history
          ? await widget.repository!.summary(cursor: _historyCursor)
          : await widget.repository!.topups(cursor: _topupCursor);
      final page = history
          ? Map<String, dynamic>.from(result['history'] as Map)
          : result;
      if (mounted) {
        setState(() {
          if (history) {
            _entries.addAll(_items(page));
            _historyCursor = page['next_cursor'] as String?;
          } else {
            _orders.addAll(_items(page));
            _topupCursor = page['next_cursor'] as String?;
          }
        });
      }
    } on Object catch (error) {
      if (mounted) setState(() => _error = _message(error));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _open(Map<String, dynamic> order) async {
    await Navigator.of(context).push<void>(
      MaterialPageRoute(
        builder: (_) =>
            WalletTopupScreen(repository: widget.repository!, initial: order),
      ),
    );
    if (mounted) await _load();
  }

  Future<void> _add() async {
    final row = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) =>
          _AmountDialog(repository: widget.repository!, wallet: _wallet!),
    );
    if (row != null && mounted) await _open(row);
  }

  @override
  Widget build(BuildContext context) {
    final wallet = _wallet;
    return VtsaPageShell(
      eyebrow: 'YOUR CHARGING BALANCE',
      title: 'Wallet',
      actions: [
        IconButton(
          tooltip: 'Refresh wallet',
          onPressed: _loading ? null : _load,
          icon: const Icon(Icons.refresh),
        ),
      ],
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          children: [
            if (_loading) const LinearProgressIndicator(),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 12),
                child: Text(_error!, semanticsLabel: 'Wallet error: $_error'),
              ),
            if (wallet != null) ...[
              Container(
                padding: const EdgeInsets.all(24),
                decoration: BoxDecoration(
                  color: const Color(0xFF15386A),
                  borderRadius: BorderRadius.circular(24),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      wallet['book'] == 'simulated'
                          ? 'TEST BALANCE · NO REAL MONEY'
                          : 'AVAILABLE TO CHARGE',
                      style: const TextStyle(
                        color: Colors.white70,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      walletMoney(wallet['available_minor'] as int),
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 34,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      '${walletMoney(wallet['reserved_minor'] as int)} reserved',
                      style: const TextStyle(color: Colors.white70),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),
              Text(
                wallet['mode'] == 'simulated'
                    ? 'Practice top-ups use simulated funds. They cannot pay for charging.'
                    : wallet['topups_enabled'] == true
                    ? 'Add prepaid credit with QR Ph. Your balance updates after payment confirmation.'
                    : 'Top-ups are not available yet. Your existing balance and history remain available.',
              ),
              const SizedBox(height: 16),
              VtsaButton(
                label: wallet['mode'] == 'simulated'
                    ? 'Create test top-up'
                    : 'Add money',
                onPressed: wallet['topups_enabled'] == true && !_loading
                    ? _add
                    : null,
                icon: Icons.add,
              ),
              const SizedBox(height: 28),
              Text('Top-ups', style: Theme.of(context).textTheme.titleLarge),
              if (_orders.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 16),
                  child: Text(
                    'No top-ups yet. Your pending and completed requests will appear here.',
                  ),
                ),
              for (final row in _orders)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(
                    row['status'] == 'paid'
                        ? Icons.check_circle_outline
                        : Icons.schedule,
                  ),
                  title: Text(walletMoney(row['amount_minor'] as int)),
                  subtitle: Text(_status(row['status'] as String)),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _open(row),
                ),
              if (_topupCursor != null)
                TextButton(
                  onPressed: _loading ? null : () => _more(false),
                  child: const Text('More top-ups'),
                ),
              const SizedBox(height: 24),
              Text(
                'Balance activity',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              if (_entries.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 16),
                  child: Text(
                    'Confirmed credits and charging activity will appear here.',
                  ),
                ),
              for (final entry in _entries)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(switch (entry['kind']) {
                    'topup' => 'Top-up received',
                    'reserved' => 'Reserved for charging',
                    'released' => 'Reservation released',
                    'spent' => 'Charging payment',
                    _ => 'Balance activity',
                  }),
                  subtitle: Text(_date(entry['created_at'] as String?)),
                  trailing: Text(walletMoney(entry['amount_minor'] as int)),
                ),
              if (_historyCursor != null)
                TextButton(
                  onPressed: _loading ? null : () => _more(true),
                  child: const Text('More activity'),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

class _AmountDialog extends StatefulWidget {
  const _AmountDialog({required this.repository, required this.wallet});
  final WalletRepository repository;
  final Map<String, dynamic> wallet;
  @override
  State<_AmountDialog> createState() => _AmountDialogState();
}

class _AmountDialogState extends State<_AmountDialog> {
  final _amount = TextEditingController();
  final _key = UlidGenerator().next();
  bool _busy = false;
  int? _submitted;
  String? _error;
  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final amount = _submitted ?? parseWalletAmount(_amount.text);
    if (amount == null ||
        amount < (widget.wallet['minimum_minor'] as int) ||
        amount > (widget.wallet['maximum_minor'] as int)) {
      setState(() => _error = 'Enter an amount within the displayed limits.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _submitted = amount;
    });
    try {
      final row = await widget.repository.create(amount, _key);
      if (mounted) Navigator.pop(context, row);
    } on Object catch (error) {
      if (mounted) setState(() => _error = _message(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(
      widget.wallet['mode'] == 'simulated'
          ? 'Test top-up'
          : 'Add prepaid credit',
    ),
    content: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${walletMoney(widget.wallet['minimum_minor'] as int)} – ${walletMoney(widget.wallet['maximum_minor'] as int)}',
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _amount,
            enabled: !_busy && _submitted == null,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              labelText: 'Amount in PHP',
              border: OutlineInputBorder(),
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!),
            const Text(
              'Retry keeps the same request. Check Top-ups before creating another request.',
            ),
          ],
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: _busy ? null : () => Navigator.pop(context),
        child: const Text('Close'),
      ),
      VtsaButton(
        label: _submitted == null ? 'Continue' : 'Retry same request',
        onPressed: _busy ? null : _submit,
        loading: _busy,
      ),
    ],
  );
}

class WalletTopupScreen extends StatefulWidget {
  const WalletTopupScreen({
    required this.repository,
    required this.initial,
    super.key,
  });
  final WalletRepository repository;
  final Map<String, dynamic> initial;
  @override
  State<WalletTopupScreen> createState() => _WalletTopupScreenState();
}

class _WalletTopupScreenState extends State<WalletTopupScreen>
    with WidgetsBindingObserver {
  late Map<String, dynamic> _row;
  Timer? _timer;
  bool _busy = false;
  String? _error;
  @override
  void initState() {
    super.initState();
    _row = widget.initial;
    WidgetsBinding.instance.addObserver(this);
    _start();
  }

  void _start() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 5), (_) => _refresh());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _refresh();
      _start();
    } else {
      _timer?.cancel();
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _refresh() async {
    if (_busy || _row['status'] == 'paid') return;
    setState(() => _busy = true);
    try {
      final row = await widget.repository.topup(_row['id'] as String);
      if (mounted) {
        setState(() {
          _row = row;
          _error = null;
        });
      }
    } on Object catch (error) {
      if (mounted) setState(() => _error = _message(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final expiry = DateTime.tryParse(_row['expires_at'] as String? ?? '');
    final expired = expiry != null && !expiry.isAfter(DateTime.now());
    final status = _row['status'] as String;
    final qr = _row['qr_content'] as String?;
    final simulated = _row['mode'] == 'simulated';
    return VtsaPageShell(
      eyebrow: simulated ? 'SIMULATED TOP-UP' : 'QR PH',
      title: 'Add prepaid credit',
      body: ListView(
        children: [
          Text(
            walletMoney(_row['amount_minor'] as int),
            style: Theme.of(context).textTheme.headlineLarge,
          ),
          const SizedBox(height: 12),
          Semantics(
            liveRegion: true,
            child: Text(
              _status(status == 'pending' && expired ? 'expired' : status),
              style: Theme.of(context).textTheme.titleLarge,
            ),
          ),
          const SizedBox(height: 20),
          if (status == 'paid')
            const Icon(Icons.check_circle, color: Color(0xFF16845B), size: 76)
          else if (simulated)
            const VtsaCard(
              child: Text(
                'TEST ONLY — no money will move. Ask the staging operator to confirm this test request, then check its status.',
              ),
            )
          else if (qr != null && !expired && status == 'pending') ...[
            Center(
              child: QrImageView(
                data: qr,
                size: 260,
                backgroundColor: Colors.white,
                semanticsLabel: 'QR Ph payment code',
              ),
            ),
            const SizedBox(height: 16),
            const Text(
              '1. Scan this QR using a supported bank or wallet app.\n2. Check the merchant and amount before paying.\n3. Return here and wait for confirmation.',
            ),
            const SizedBox(height: 12),
            const Text(
              'Using one phone? Take a screenshot and import it in your payment app if supported. A screenshot does not confirm payment.',
            ),
            const SizedBox(height: 8),
            Text('Expires ${_date(_row['expires_at'] as String?)}'),
          ] else if (status != 'paid')
            const Text(
              'Do not pay an expired code. If money has already been deducted, keep this reference and wait for confirmation before trying again.',
            ),
          const SizedBox(height: 20),
          if (_error != null) Text(_error!),
          if (status != 'paid')
            VtsaButton(
              label: 'Check payment status',
              onPressed: _busy ? null : _refresh,
              loading: _busy,
            ),
          const SizedBox(height: 16),
          SelectableText('Reference: ${_row['id']}'),
          TextButton.icon(
            onPressed: () =>
                Clipboard.setData(ClipboardData(text: _row['id'] as String)),
            icon: const Icon(Icons.copy),
            label: const Text('Copy reference'),
          ),
          const SizedBox(height: 12),
          const Text(
            'You can leave this screen. Your request remains in Wallet → Top-ups.',
          ),
        ],
      ),
    );
  }
}

List<Map<String, dynamic>> _items(Map<String, dynamic> value) =>
    (value['items'] as List)
        .map((item) => Map<String, dynamic>.from(item as Map))
        .toList();
String _message(Object error) => error is AppFailure
    ? error.message
    : 'Wallet could not be updated. Please try again.';
String _date(String? value) {
  final date = DateTime.tryParse(value ?? '')?.toLocal();
  return date == null ? '' : date.toString().substring(0, 16);
}

String _status(String value) => switch (value) {
  'paid' => 'Payment confirmed',
  'pending' => 'Waiting for payment',
  'creating' => 'Preparing payment',
  'expired' => 'QR expired · confirmation still checked',
  'unknown' => 'Awaiting confirmation',
  'review_required' => 'Payment is being checked',
  _ => 'Awaiting confirmation',
};
