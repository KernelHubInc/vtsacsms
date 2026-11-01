import 'package:flutter/material.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/wallet/wallet_repository.dart';

class ChargingBudgetDialog extends StatefulWidget {
  const ChargingBudgetDialog({required this.quote, super.key});
  final TariffQuote quote;

  @override
  State<ChargingBudgetDialog> createState() => _ChargingBudgetDialogState();
}

class _ChargingBudgetDialogState extends State<ChargingBudgetDialog> {
  final _amount = TextEditingController();
  late int? _selected = widget.quote.estimatedPreauthorization.minorUnits;
  bool _custom = false;
  bool _expanded = false;
  String? _error;

  Map<String, dynamic> get _policy => widget.quote.budgetPolicy;
  int get _minimum => _policy['minimum_minor'] as int;
  int get _available => _policy['available_minor'] as int;
  bool get _allowCustom => _policy['allow_custom_amount'] == true;
  List<int> get _presets => (_policy['presets'] as List).cast<int>();
  bool get _customEntry => _allowCustom && (_custom || _presets.isEmpty);

  @override
  void initState() {
    super.initState();
    final selected = _selected!;
    _custom = _allowCustom && !_presets.contains(selected);
    _amount.text =
        '${selected ~/ 100}.${(selected % 100).toString().padLeft(2, '0')}';
  }

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  void _submit() {
    final amount = _customEntry ? parseWalletAmount(_amount.text) : _selected;
    final error = amount == null
        ? 'Enter an amount with up to two decimal places.'
        : amount < _minimum
        ? 'Choose at least ${walletMoney(_minimum)}.'
        : amount > _available
        ? 'Your available charging budget is ${walletMoney(_available)}.'
        : !_allowCustom && !_presets.contains(amount)
        ? 'Choose an available budget.'
        : null;
    if (error != null) {
      setState(() => _error = error);
    } else {
      Navigator.pop(context, amount);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Charging budget'),
    content: SizedBox(
      width: 420,
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Choose how much to reserve for this session, including any platform fee. Unused funds are released after settlement.',
            ),
            const SizedBox(height: 12),
            Text('Minimum ${walletMoney(_minimum)}'),
            Text('Available for charging ${walletMoney(_available)}'),
            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final amount in (_expanded ? _presets : _presets.take(6)))
                  ChoiceChip(
                    label: Text(walletMoney(amount)),
                    selected: !_customEntry && _selected == amount,
                    onSelected: amount > _available
                        ? null
                        : (_) => setState(() {
                            _selected = amount;
                            _custom = false;
                            _error = null;
                          }),
                  ),
                if (_allowCustom)
                  ChoiceChip(
                    label: const Text('Other amount'),
                    selected: _customEntry,
                    onSelected: (_) => setState(() {
                      _custom = true;
                      _error = null;
                    }),
                  ),
              ],
            ),
            if (_presets.length > 6)
              TextButton(
                onPressed: () => setState(() => _expanded = !_expanded),
                child: Text(_expanded ? 'Fewer amounts' : 'More amounts'),
              ),
            if (_customEntry) ...[
              const SizedBox(height: 16),
              TextField(
                controller: _amount,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: InputDecoration(
                  labelText: 'Budget (PHP)',
                  errorText: _error,
                ),
                onChanged: (_) {
                  if (_error != null) setState(() => _error = null);
                },
                onSubmitted: (_) => _submit(),
              ),
            ] else if (_error != null)
              Text(
                _error!,
                semanticsLabel: _error,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
          ],
        ),
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      FilledButton(onPressed: _submit, child: const Text('Review budget')),
    ],
  );
}
