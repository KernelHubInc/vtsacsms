import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/features/accounts/charging_account_screen.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_formatters.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_page.dart';
import 'package:vtsa_mobile/features/charging/presentation/swipe_to_start.dart';

class ChargeReview extends StatefulWidget {
  const ChargeReview({
    required this.dependencies,
    required this.onConfirm,
    required this.onBudget,
    required this.onVehicle,
    required this.onBack,
    required this.onRefresh,
    this.fallbackVehicleId,
    this.quoteRefreshFailed = false,
    super.key,
  });
  final AppDependencies dependencies;
  final Future<void> Function() onConfirm;
  final VoidCallback onBudget, onBack, onRefresh;
  final ValueChanged<String?> onVehicle;
  final String? fallbackVehicleId;
  final bool quoteRefreshFailed;
  @override
  State<ChargeReview> createState() => _ChargeReviewState();
}

class _ChargeReviewState extends State<ChargeReview> {
  bool _compatibilityAccepted = false;

  @override
  Widget build(BuildContext context) {
    final charging = widget.dependencies.charging;
    final preparation = charging.preparation!;
    final target = preparation.target;
    final quote = preparation.quote;
    final vehicles = widget.dependencies.vehicles.vehicles;
    final vehicleId =
        (preparation.attribution['vehicle'] as Map?)?['id'] as String? ??
        widget.fallbackVehicleId;
    final vehicle = vehicles.where((v) => v.id == vehicleId).firstOrNull;
    final incompatible =
        vehicle != null && !vehicle.supports(target.connectorStandard);
    final expired = !quote.expiresAt.isAfter(DateTime.now().toUtc());
    final ready =
        charging.isOnline &&
        !charging.mutationInFlight &&
        target.canStart &&
        !expired &&
        !widget.quoteRefreshFailed &&
        charging.selectedPaymentMethod != null &&
        (!incompatible || _compatibilityAccepted);
    final inlineActions =
        MediaQuery.textScalerOf(context).scale(1) > 1.3 ||
        MediaQuery.sizeOf(context).height < 740;
    final startActions = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Swiping reserves ${formatMoney(quote.estimatedPreauthorization)} from ${charging.selectedPaymentMethod?.label ?? 'the selected wallet'}. '
          'Final cost uses confirmed readings; unused funds are released.',
          style: const TextStyle(fontSize: 12, height: 16 / 12),
        ),
        const SizedBox(height: 12),
        SwipeToStart(enabled: ready, onStart: widget.onConfirm),
      ],
    );
    return ChargingPage(
      title: 'Ready to charge',
      subtitle: 'Connect the cable. Then swipe to start.',
      step: 1,
      onBack: charging.mutationInFlight ? () {} : widget.onBack,
      bottomActions: inlineActions ? null : startActions,
      body: ListView(
        padding: const EdgeInsets.only(bottom: 16),
        children: [
          Text(
            target.stationName,
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 8),
          Text(
            '${target.connectorName} · ${target.connectorStandard} · Up to ${(target.maximumPowerW / 1000).toStringAsFixed(0)} kW',
          ),
          const SizedBox(height: 8),
          Image.asset(
            'assets/evcs/charging-car.png',
            height: 128,
            fit: BoxFit.contain,
            semanticLabel:
                'EV concept illustration; not a verified vehicle model',
          ),
          const SizedBox(height: 8),
          EvcsAccessCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(quote.name, style: const TextStyle(fontSize: 12)),
                const SizedBox(height: 8),
                DropdownButtonFormField<String>(
                  key: ValueKey(
                    '$vehicleId:${vehicles.map((v) => v.id).join(',')}',
                  ),
                  initialValue: vehicle?.id ?? '',
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Vehicle',
                    contentPadding: EdgeInsets.symmetric(
                      horizontal: 12,
                      vertical: 8,
                    ),
                  ),
                  items: [
                    const DropdownMenuItem(
                      value: '',
                      child: Text('No vehicle selected'),
                    ),
                    for (final item in vehicles)
                      DropdownMenuItem(
                        value: item.id,
                        child: Text(item.nickname),
                      ),
                  ],
                  onChanged: charging.mutationInFlight
                      ? null
                      : widget.onVehicle,
                ),
                const SizedBox(height: 12),
                for (final line in quote.lines)
                  _row(
                    line.description,
                    formatMoney(
                      Money(
                        minorUnits: line.priceMinor,
                        currency: quote.currency,
                      ),
                    ),
                  ),
                _row(
                  'Tax',
                  quote.taxInclusive
                      ? 'Included'
                      : '${(quote.taxRateBasisPoints / 100).toStringAsFixed(2)}% additional',
                ),
                if (quote.platformFee['enabled'] == true) ...[
                  _row(
                    'Platform fee · tax included',
                    formatMoney(
                      Money(
                        minorUnits: quote.platformFee['amount_minor'] as int,
                        currency: quote.currency,
                      ),
                    ),
                  ),
                  Text(
                    'Included in the budget. Waived below ${formatMoney(Money(minorUnits: quote.platformFee['waiver_threshold_minor'] as int, currency: quote.currency))} charging usage.',
                    style: const TextStyle(fontSize: 12),
                  ),
                ],
                Row(
                  children: [
                    Expanded(
                      child: _row(
                        'Budget limit',
                        formatMoney(quote.estimatedPreauthorization),
                      ),
                    ),
                    if (quote.budgetPolicy.isNotEmpty)
                      IconButton(
                        tooltip: 'Change budget',
                        onPressed: charging.mutationInFlight
                            ? null
                            : widget.onBudget,
                        icon: const Icon(Icons.edit_outlined, size: 20),
                      ),
                  ],
                ),
                if (preparation.paymentMethods.length <= 1)
                  _row(
                    'Pay with',
                    charging.selectedPaymentMethod?.label ??
                        'No payment method available',
                  )
                else
                  RadioGroup<String>(
                    groupValue: charging.selectedPaymentMethod?.id,
                    onChanged: (id) {
                      if (charging.mutationInFlight) return;
                      final method = preparation.paymentMethods
                          .where((m) => m.id == id)
                          .firstOrNull;
                      if (method != null) charging.selectPaymentMethod(method);
                    },
                    child: Column(
                      children: [
                        for (final method in preparation.paymentMethods)
                          RadioListTile<String>(
                            enabled: !charging.mutationInFlight,
                            value: method.id,
                            title: Text(method.label),
                            subtitle: method.lastFour == null
                                ? null
                                : Text(
                                    '${method.brand ?? 'Card'} •••• ${method.lastFour}',
                                  ),
                          ),
                      ],
                    ),
                  ),
                if (preparation.attribution['payer'] case final String payer)
                  _row('Paid by', payer),
                if (preparation.attribution['driver_name']
                    case final String driver)
                  _row('Driver', driver),
              ],
            ),
          ),
          if (charging.mutationInFlight)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: LinearProgressIndicator(),
            ),
          const SizedBox(height: 8),
          EvcsAccessCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Connect the cable',
                  style: TextStyle(fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 6),
                Text(
                  'Use ${target.connectorName}. Keep the cable connected while starting. A request is not proof that charging has begun.',
                ),
                const SizedBox(height: 6),
                const Text(
                  'A network interruption can delay stopping at the budget limit. Unresolved charges require review.',
                  style: TextStyle(fontSize: 12),
                ),
              ],
            ),
          ),
          if (incompatible)
            CheckboxListTile(
              value: _compatibilityAccepted,
              onChanged: charging.mutationInFlight
                  ? null
                  : (value) =>
                        setState(() => _compatibilityAccepted = value == true),
              title: Text(
                '${vehicle.nickname} does not list ${target.connectorStandard}',
              ),
              subtitle: const Text(
                'I have checked the physical inlet and manufacturer guidance. Compatibility data is advisory.',
              ),
              controlAffinity: ListTileControlAffinity.leading,
            ),
          if (!target.canStart)
            Text(
              'This connector is ${target.readiness.name}. Choose an available connector.',
            ),
          if (!charging.isOnline)
            const Text('You’re offline. Reconnect to request a start.'),
          if (preparation.paymentMethods.isEmpty)
            const Text(
              'Add money or an available payment method before starting.',
            ),
          if (preparation.paymentMethods.isEmpty)
            TextButton(
              onPressed: () => context.push('/wallet'),
              child: const Text('Open wallet'),
            ),
          if (expired || widget.quoteRefreshFailed)
            TextButton(
              onPressed: charging.isOnline && !charging.mutationInFlight
                  ? widget.onRefresh
                  : null,
              child: const Text('Refresh charging quote'),
            ),
          ChargingAccountSelector(dependencies: widget.dependencies),
          if (inlineActions) ...[const SizedBox(height: 16), startActions],
        ],
      ),
    );
  }

  Widget _row(String label, String value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: Text(label, style: const TextStyle(fontSize: 14))),
        const SizedBox(width: 12),
        Flexible(
          child: Text(
            value,
            textAlign: TextAlign.right,
            style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500),
          ),
        ),
      ],
    ),
  );
}
