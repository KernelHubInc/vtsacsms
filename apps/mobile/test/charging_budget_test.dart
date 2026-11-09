import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_budget_dialog.dart';

void main() {
  TariffQuote quote({bool custom = true, List<int>? presets}) => TariffQuote(
    id: 'quote',
    tariffVersionId: 'tariff',
    name: 'Charging',
    currency: 'PHP',
    taxInclusive: true,
    lines: const [],
    estimatedPreauthorization: const Money(minorUnits: 50000, currency: 'PHP'),
    expiresAt: DateTime.utc(2030),
    budgetPolicy: {
      'minimum_minor': 10000,
      'default_minor': 50000,
      'maximum_minor': 200000,
      'available_minor': 75000,
      'presets': presets ?? [20000, 50000, 100000],
      'allow_custom_amount': custom,
    },
  );

  testWidgets('budget selection returns centavos without creating a payment', (
    tester,
  ) async {
    int? result;
    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () async {
                result = await showDialog<int>(
                  context: context,
                  builder: (_) => ChargingBudgetDialog(quote: quote()),
                );
              },
              child: const Text('Open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();
    expect(find.text('Minimum PHP 100.00'), findsOneWidget);
    await tester.tap(find.text('PHP 200.00'));
    await tester.pump();
    await tester.tap(find.text('Apply budget'));
    await tester.pumpAndSettle();
    expect(result, 20000);
  });

  testWidgets('custom budget enforces minimum and available funds inline', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: ChargingBudgetDialog(quote: quote())),
      ),
    );
    await tester.tap(find.text('Other amount'));
    await tester.pump();
    for (final entry in [
      ('99.99', 'Choose at least PHP 100.00.'),
      ('750.01', 'Your available charging budget is PHP 750.00.'),
      ('1.001', 'Enter an amount with up to two decimal places.'),
    ]) {
      await tester.enterText(find.byType(TextField), entry.$1);
      await tester.tap(find.text('Apply budget'));
      await tester.pump();
      expect(find.text(entry.$2), findsOneWidget);
    }
    final large = tester.widget<ChoiceChip>(
      find.widgetWithText(ChoiceChip, 'PHP 1000.00'),
    );
    expect(large.onSelected, isNull);
  });

  testWidgets(
    'preset-only policy hides custom entry and supports more amounts',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ChargingBudgetDialog(
              quote: quote(
                custom: false,
                presets: [10000, 20000, 30000, 40000, 50000, 60000, 70000],
              ),
            ),
          ),
        ),
      );
      expect(find.text('Other amount'), findsNothing);
      expect(find.text('PHP 700.00'), findsNothing);
      await tester.tap(find.text('More amounts'));
      await tester.pump();
      expect(find.text('PHP 700.00'), findsOneWidget);
    },
  );
}
