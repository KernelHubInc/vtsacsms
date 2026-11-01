import 'package:flutter/material.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/ids/ulid_generator.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/wallet/wallet_repository.dart';

class TopupAmountDialog extends StatefulWidget {
  const TopupAmountDialog({
    required this.repository,
    required this.wallet,
    super.key,
  });

  final WalletRepository repository;
  final Map<String, dynamic> wallet;

  @override
  State<TopupAmountDialog> createState() => _TopupAmountDialogState();
}

class _TopupAmountDialogState extends State<TopupAmountDialog> {
  final _amount = TextEditingController();
  String _key = UlidGenerator().next();
  late Map<String, dynamic> _wallet = widget.wallet;
  bool _busy = false;
  bool _expanded = false;
  bool _custom = false;
  int? _selected;
  int? _submitted;
  String? _fieldError;
  String? _requestError;

  int get _minimum => _wallet['minimum_minor'] as int;
  int get _maximum => _wallet['maximum_minor'] as int;
  bool get _allowCustom => _wallet['allow_custom_amount'] != false;
  List<int> get _presets =>
      (_wallet['preset_amounts_minor'] as List? ?? const [])
          .whereType<int>()
          .where((amount) => amount >= _minimum && amount <= _maximum)
          .toSet()
          .toList();
  bool get _editing => !_busy && _submitted == null;
  bool get _showCustom => _allowCustom && (_custom || _presets.isEmpty);
  int? get _chosen => _showCustom ? parseWalletAmount(_amount.text) : _selected;

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  String? _validate(int? amount) {
    if (amount == null) {
      return _showCustom
          ? 'Enter an amount with up to two decimal places.'
          : 'Choose a top-up amount.';
    }
    if (amount < _minimum) return 'Enter at least ${walletMoney(_minimum)}.';
    if (amount > _maximum) {
      return 'Enter no more than ${walletMoney(_maximum)}.';
    }
    if (!_allowCustom && !_presets.contains(amount)) {
      return 'Choose one of the available amounts.';
    }
    return null;
  }

  Future<void> _submit() async {
    final amount = _submitted ?? _chosen;
    // An ambiguous retry must retain its original amount even if policy changes.
    final error = _submitted == null ? _validate(amount) : null;
    if (error != null || amount == null) {
      setState(() => _fieldError = error);
      return;
    }
    setState(() {
      _busy = true;
      _fieldError = null;
      _requestError = null;
      _submitted = amount;
    });
    try {
      final row = await widget.repository.create(amount, _key);
      if (mounted) Navigator.pop(context, row);
    } on Object catch (error) {
      if (!mounted) return;
      if (error is AppFailure && error.code == 'INVALID_TOPUP_AMOUNT') {
        // This rejection happens before an order is persisted, so selection can safely restart.
        setState(() {
          _submitted = null;
          _key = UlidGenerator().next();
          _selected = null;
          _fieldError = error.message;
        });
        try {
          final latest = await widget.repository.summary();
          if (mounted) setState(() => _wallet = latest);
        } on Object {
          if (mounted) {
            setState(
              () => _requestError =
                  'Close this window and refresh Wallet to see the latest amounts.',
            );
          }
        }
      } else {
        setState(
          () => _requestError = error is AppFailure
              ? error.message
              : 'The request could not be confirmed. Please try again.',
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final presets = _presets;
    final selected = _chosen;
    final errorColor = Theme.of(context).colorScheme.error;
    return AlertDialog(
      title: Text(
        _wallet['mode'] == 'simulated' ? 'Test top-up' : 'Add prepaid credit',
      ),
      content: SizedBox(
        width: 400,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Minimum ${walletMoney(_minimum)}'),
              const SizedBox(height: 16),
              if (presets.isNotEmpty) ...[
                const Text('Choose an amount'),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final amount in _expanded ? presets : presets.take(6))
                      ChoiceChip(
                        label: Text(walletMoney(amount)),
                        selected: !_showCustom && _selected == amount,
                        onSelected: !_editing
                            ? null
                            : (_) => setState(() {
                                _selected = amount;
                                _custom = false;
                                _fieldError = null;
                                _requestError = null;
                              }),
                      ),
                    if (_allowCustom)
                      ChoiceChip(
                        label: const Text('Other amount'),
                        selected: _showCustom,
                        onSelected: !_editing
                            ? null
                            : (_) => setState(() {
                                _custom = true;
                                _fieldError = null;
                                _requestError = null;
                              }),
                      ),
                  ],
                ),
                if (presets.length > 6)
                  TextButton(
                    onPressed: _busy
                        ? null
                        : () => setState(() => _expanded = !_expanded),
                    child: Text(
                      _expanded
                          ? 'Fewer amounts'
                          : 'More amounts (${presets.length - 6})',
                    ),
                  ),
                const SizedBox(height: 12),
              ],
              if (_showCustom)
                TextField(
                  controller: _amount,
                  enabled: _editing,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  onChanged: (_) => setState(() {
                    if (_fieldError != null) _fieldError = _validate(_chosen);
                  }),
                  decoration: InputDecoration(
                    labelText: 'Amount in PHP',
                    helperText: 'Enter an amount within your wallet limits.',
                    helperMaxLines: 3,
                    errorText: _fieldError,
                    errorMaxLines: 3,
                    border: const OutlineInputBorder(),
                  ),
                )
              else if (_fieldError != null)
                Semantics(
                  liveRegion: true,
                  child: Text(
                    _fieldError!,
                    style: TextStyle(color: errorColor),
                  ),
                ),
              if (presets.isEmpty && !_allowCustom)
                const Text(
                  'No top-up amounts are currently available. Please refresh Wallet or contact support.',
                ),
              if (_requestError != null) ...[
                const SizedBox(height: 12),
                Semantics(
                  liveRegion: true,
                  child: Text(
                    _requestError!,
                    style: TextStyle(color: errorColor),
                  ),
                ),
                if (_submitted != null)
                  const Text(
                    'Retry keeps the same amount and request. Check Top-ups before creating another request.',
                  ),
              ],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.pop(context),
          child: const Text('Close'),
        ),
        VtsaButton(
          label: _submitted != null
              ? 'Retry same request'
              : selected == null
              ? 'Continue'
              : 'Continue with ${walletMoney(selected)}',
          onPressed: _busy || (presets.isEmpty && !_allowCustom)
              ? null
              : _submit,
          loading: _busy,
        ),
      ],
    );
  }
}
