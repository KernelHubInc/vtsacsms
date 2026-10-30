import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/features/charging/presentation/charger_scanner_view.dart';

void main() {
  for (final size in [
    const Size(320, 640),
    const Size(390, 844),
    const Size(844, 390),
  ]) {
    testWidgets(
      'scanner manual fallback remains reachable at $size with large text',
      (tester) async {
        tester.view.physicalSize = size;
        tester.view.devicePixelRatio = 1;
        tester.platformDispatcher.textScaleFactorTestValue = 1.6;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
        var manual = false;
        await tester.pumpWidget(
          MaterialApp(
            home: ChargerScannerView(
              camera: const ColoredBox(color: Colors.black),
              onManualEntry: () => manual = true,
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        await tester.ensureVisible(find.text('Enter the Charge Point ID'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Enter the Charge Point ID'));
        expect(manual, isTrue);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
