import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/features/vehicles/presentation/vehicle_screens.dart';

import 'driver_journey_design_test.dart' show preview;
import 'support/fakes.dart';
import 'support/test_fonts.dart';

void main() {
  setUpAll(loadTestFonts);
  testWidgets(
    'current vehicle card uses saved details and opens vehicle management',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final fixture = await buildTestDependencies(signedIn: true);
      await fixture.dependencies.vehicles.save(
        nickname: 'Daily drive',
        manufacturer: 'BYD',
        model: 'ATTO 3',
        connectorStandards: {'type2'},
        platePending: true,
        isDefault: true,
      );
      await tester.pumpWidget(
        RepaintBoundary(
          key: const ValueKey('driver-preview'),
          child: VtsaApp(dependencies: fixture.dependencies),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Compatible'));
      await tester.pumpAndSettle();
      final vehicleCard = find.ancestor(
        of: find.text('CURRENT VEHICLE'),
        matching: find.byType(Card),
      );
      final vehicleName = find.descendant(
        of: vehicleCard,
        matching: find.text('BYD ATTO 3'),
      );
      expect(find.text('CURRENT VEHICLE'), findsOneWidget);
      expect(vehicleName, findsOneWidget);
      expect(find.text('Plate pending'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await preview(tester, 'current-vehicle-390');
      await tester.tap(vehicleName);
      await tester.pumpAndSettle();
      expect(find.byType(VehicleListScreen), findsOneWidget);
    },
  );
}
