import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_start_screen.dart';

import 'driver_journey_design_test.dart' show availableStation, preview;
import 'support/fakes.dart';

void main() {
  setUpAll(() async {
    final root = Platform.environment['FLUTTER_ROOT']!;
    for (final (family, file) in [
      ('Roboto', 'roboto-regular.ttf'),
      ('MaterialIcons', 'materialicons-regular.otf'),
    ]) {
      final loader = FontLoader(family)
        ..addFont(
          Future.value(
            ByteData.sublistView(
              File(
                '$root/bin/cache/artifacts/material_fonts/$file',
              ).readAsBytesSync(),
            ),
          ),
        );
      await loader.load();
    }
  });

  for (final (width, scale, empty) in [
    (360.0, 1.0, true),
    (390.0, 1.0, false),
    (320.0, 1.6, false),
  ]) {
    testWidgets('station details stay aligned and usable at $width / $scale', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 844);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.textScaleFactorTestValue = scale;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      final station = empty ? availableStation : sampleStation;
      final fixture = await buildTestDependencies(
        signedIn: true,
        stations: [station],
      );
      await tester.pumpWidget(
        RepaintBoundary(
          key: const ValueKey('driver-preview'),
          child: VtsaApp(dependencies: fixture.dependencies),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Harbor Exchange').first);
      await tester.pumpAndSettle();
      final details = find.text('Station details & directions');
      await tester.scrollUntilVisible(
        details,
        150,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(details);
      await tester.pumpAndSettle();
      await Scrollable.ensureVisible(tester.element(details), alignment: 0);
      await tester.pumpAndSettle();

      for (final title in ['Operating hours', 'Amenities']) {
        final card = find.widgetWithText(VtsaCard, title);
        expect(
          tester.getSize(card).width,
          closeTo(width - 40, 1),
          reason: '$title should use the same full content width.',
        );
        expect(tester.getTopLeft(card).dx, closeTo(20, 1));
      }
      final directions = find.widgetWithText(OutlinedButton, 'Directions');
      final scan = find.widgetWithText(OutlinedButton, 'Scan charger to start');
      expect(tester.getSize(directions).width, closeTo(width - 40, 1));
      expect(tester.getSize(scan).width, closeTo(width - 40, 1));
      expect(tester.takeException(), isNull);
      await preview(tester, 'station-details-${width.toInt()}-$scale');

      final favorite = find.text('Add to favorites');
      await tester.ensureVisible(favorite);
      await tester.pumpAndSettle();
      await tester.tap(favorite);
      await tester.pumpAndSettle();
      expect(fixture.dependencies.favorites.contains(station.id), isTrue);
      expect(find.text('Remove from favorites'), findsOneWidget);
      await tester.tap(find.text('Remove from favorites'));
      await tester.pumpAndSettle();
      expect(fixture.dependencies.favorites.contains(station.id), isFalse);

      fixture.stations.lastBounds = null;
      await tester.ensureVisible(find.byTooltip('Refresh station status'));
      await tester.pumpAndSettle();
      await tester.tap(find.byTooltip('Refresh station status'));
      await tester.pumpAndSettle();
      expect(fixture.stations.lastBounds, isNotNull);
      await tester.ensureVisible(directions);
      await tester.pumpAndSettle();
      await tester.tap(directions);
      await tester.pumpAndSettle();
      expect(
        find.text('No navigation application could open directions.'),
        findsOneWidget,
      );
      await tester.ensureVisible(scan);
      await tester.pumpAndSettle();
      await tester.tap(scan);
      await tester.pumpAndSettle();
      expect(find.byType(ChargingStartScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    });
  }
}
