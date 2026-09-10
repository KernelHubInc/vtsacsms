import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/app_bootstrap.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_backdrop.dart';

void main() {
  testWidgets('shows a startup state before dependencies are ready', (
    tester,
  ) async {
    final pending = Completer<Never>();

    await tester.pumpWidget(
      AppBootstrap(loadDependencies: () => pending.future),
    );

    expect(find.text('Starting Power Solutions'), findsOneWidget);
    expect(find.byType(LinearProgressIndicator), findsOneWidget);
    expect(find.byType(PowerSolutionsBackdrop), findsOneWidget);
    expect(find.bySemanticsLabel('Power Solutions'), findsOneWidget);
  });

  testWidgets('shows a recoverable startup error and retries', (tester) async {
    var attempts = 0;
    final retryPending = Completer<Never>();

    await tester.pumpWidget(
      AppBootstrap(
        loadDependencies: () async {
          attempts++;
          if (attempts == 1) {
            throw StateError('Unavailable test dependency');
          }

          return retryPending.future;
        },
      ),
    );
    await tester.pump();

    expect(find.text('Power Solutions could not start'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);

    await tester.tap(find.text('Try again'));
    await tester.pump();

    expect(attempts, 2);
  });
}
