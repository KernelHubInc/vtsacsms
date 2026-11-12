import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_formatters.dart';

class ChargingBudgetProgress extends StatelessWidget {
  const ChargingBudgetProgress({required this.session, super.key});
  final ChargingSession session;
  @override
  Widget build(BuildContext context) {
    final budget = session.reservedBudget!;
    final cost = session.estimatedCost;
    final ratio =
        cost != null &&
            cost.currency == budget.currency &&
            budget.minorUnits > 0
        ? (cost.minorUnits / budget.minorUnits).clamp(0.0, 1.0)
        : null;
    final content = Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          ratio == null ? 'Awaiting readings' : '${(ratio * 100).round()}%',
          textAlign: TextAlign.center,
          style: TextStyle(
            fontSize: ratio == null ? 18 : 32,
            fontWeight: FontWeight.w600,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          'of ${formatMoney(budget)} budget',
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 12),
        ),
        const SizedBox(height: 4),
        const Text('Estimated budget usage', style: TextStyle(fontSize: 12)),
      ],
    );
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 16),
      child: Center(
        child: MediaQuery.textScalerOf(context).scale(1) > 1.3
            ? Padding(padding: const EdgeInsets.all(16), child: content)
            : SizedBox.square(
                dimension: 224,
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    ExcludeSemantics(
                      child: SizedBox.square(
                        dimension: 208,
                        child: CircularProgressIndicator(
                          value: ratio ?? 0,
                          color: Theme.of(context).colorScheme.primary,
                          strokeWidth: 7,
                          backgroundColor: context.vtsaColors.surfaceSubtle,
                        ),
                      ),
                    ),
                    Padding(padding: const EdgeInsets.all(20), child: content),
                  ],
                ),
              ),
      ),
    );
  }
}

class ChargingReceiptSummary extends StatelessWidget {
  const ChargingReceiptSummary({required this.session, super.key});
  final ChargingSession session;
  @override
  Widget build(BuildContext context) => EvcsAccessCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const EvcsLogo(width: 147),
        const SizedBox(height: 12),
        Text(switch (session.paymentState) {
          PaymentState.captured => 'Paid',
          PaymentState.notRequired => 'No payment due',
          PaymentState.refunded => 'Refunded',
          PaymentState.partiallyRefunded => 'Partially refunded',
          PaymentState.refundPending => 'Refund pending',
          _ => 'Payment pending',
        }, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
        const SizedBox(height: 12),
        Text(
          session.finalCost == null
              ? 'Final amount pending'
              : formatMoney(session.finalCost),
          style: const TextStyle(fontSize: 32, fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 8),
        SelectableText(session.id, style: const TextStyle(fontSize: 12)),
        const Divider(height: 28),
        Text(session.stationName),
        Text(session.connectorName),
        if (session.attribution['payer'] case final String payer)
          Text('Paid by $payer'),
      ],
    ),
  );
}
