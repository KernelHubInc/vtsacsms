import 'package:flutter/material.dart';
import 'package:flutter/painting.dart' as painting;
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/app/consumer_shell.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_start_screen.dart';
import 'package:vtsa_mobile/features/discovery/presentation/discovery_screen.dart';
import 'package:vtsa_mobile/features/vehicles/presentation/vehicle_screens.dart';
import 'package:vtsa_mobile/l10n/app_localizations.dart';

import 'driver_journey_design_test.dart' show preview;
import 'map_configuration_test.dart' show configuration;
import 'support/fakes.dart';
import 'support/test_fonts.dart';

void main() {
  setUpAll(loadTestFonts);
  for (final (width, scale) in [(390.0, 1.0), (320.0, 1.6), (1440.0, 1.0)]) {
    testWidgets(
      'selected vehicle stays visible on the map at $width / $scale',
      (tester) => _withShadows(() async {
        tester.view.physicalSize = Size(width, 844);
        tester.view.devicePixelRatio = 1;
        tester.platformDispatcher.textScaleFactorTestValue = scale;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
        final fixture = await buildTestDependencies(
          signedIn: true,
          maps: configuration(),
        );
        await _saveVehicles(fixture.dependencies);
        final router = _router(fixture.dependencies);
        addTearDown(router.dispose);
        addTearDown(fixture.dependencies.dispose);
        await _pump(tester, router);
        expect(find.text('BYD ATTO 3').hitTestable(), findsOneWidget);
        expect(
          find.byTooltip('Show my location').hitTestable(),
          findsOneWidget,
        );
        await preview(tester, 'explore-vehicle-${width.toInt()}-$scale');

        await tester.tap(find.text('BYD ATTO 3'));
        await tester.pumpAndSettle();
        await tester.ensureVisible(find.text('Weekend drive'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Weekend drive'));
        await tester.pumpAndSettle();
        expect(find.text('Kia EV6').hitTestable(), findsOneWidget);
        expect(
          fixture.dependencies.vehicles.defaultVehicle?.manufacturer,
          'BYD',
        );
        await tester.tap(find.byTooltip('Show charger list'));
        await tester.pumpAndSettle();
        await tester.tap(find.byTooltip('Show map'));
        await tester.pumpAndSettle();
        expect(find.text('Kia EV6').hitTestable(), findsOneWidget);

        fixture.dependencies.discovery.select(sampleStation.id);
        await tester.pumpAndSettle();
        expect(find.text('Kia EV6').hitTestable(), findsOneWidget);
        expect(find.text('Harbor Exchange').hitTestable(), findsOneWidget);
        expect(
          tester.getBottomLeft(find.text('Kia EV6')).dy,
          lessThan(tester.getTopLeft(find.text('Harbor Exchange')).dy),
        );
        expect(
          find.byTooltip('Show my location').hitTestable(),
          findsOneWidget,
        );
        expect(tester.takeException(), isNull);
        await preview(
          tester,
          'explore-vehicle-station-${width.toInt()}-$scale',
        );
        await tester.pumpWidget(const SizedBox.shrink());
      }),
    );
  }

  testWidgets(
    'vehicle choice survives tab changes and matches compatibility and charging',
    (tester) => _withShadows(() async {
      final fixture = await buildTestDependencies(
        signedIn: true,
        maps: configuration(),
      );
      await _saveVehicles(fixture.dependencies);
      final router = _router(fixture.dependencies);
      addTearDown(router.dispose);
      addTearDown(fixture.dependencies.dispose);
      await _pump(tester, router);
      await tester.tap(find.text('Compatible'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Weekend drive'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Weekend drive'));
      await tester.pumpAndSettle();
      expect(fixture.dependencies.discovery.filters.vehicleConnectors, [
        'CCS2',
      ]);
      expect(find.text('Kia EV6').hitTestable(), findsOneWidget);
      router.go('/account');
      await tester.pumpAndSettle();
      router.go('/explore');
      await tester.pumpAndSettle();
      expect(find.text('Kia EV6').hitTestable(), findsOneWidget);
      router.push<void>('/charge?code=VSTA-ABC_123');
      await tester.pumpAndSettle();
      expect(find.text('Ready to charge'), findsOneWidget);
      expect(
        fixture.charging.lastPreparedVehicleId,
        fixture.dependencies.vehicles.selectedVehicle?.id,
      );
      router.pop();
      await tester.pumpAndSettle();
      fixture.dependencies.vehicles.selectVehicle(
        fixture.dependencies.vehicles.defaultVehicle!.id,
      );
      await tester.pumpAndSettle();
      expect(find.text('BYD ATTO 3').hitTestable(), findsOneWidget);
      expect(fixture.dependencies.discovery.filters.vehicleConnectors, [
        'Type 2',
      ]);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    }),
  );
}

Future<void> _withShadows(Future<void> Function() run) async {
  final original = painting.debugDisableShadows;
  painting.debugDisableShadows = false;
  try {
    await run();
  } finally {
    painting.debugDisableShadows = original;
  }
}

Future<void> _saveVehicles(AppDependencies dependencies) async {
  await dependencies.vehicles.save(
    nickname: 'Daily drive',
    manufacturer: 'BYD',
    model: 'ATTO 3',
    connectorStandards: {'Type 2'},
    isDefault: true,
  );
  await dependencies.vehicles.save(
    nickname: 'Weekend drive',
    manufacturer: 'Kia',
    model: 'EV6',
    connectorStandards: {'CCS2'},
    isDefault: false,
  );
}

GoRouter _router(AppDependencies dependencies) => GoRouter(
  initialLocation: '/explore',
  routes: [
    ShellRoute(
      builder: (context, state, child) => ConsumerShell(
        dependencies: dependencies,
        location: state.uri.path,
        child: child,
      ),
      routes: [
        GoRoute(
          path: '/explore',
          builder: (context, state) => DiscoveryScreen(
            dependencies: dependencies,
            mapBuilder: (_, _, _, _) => const ColoredBox(
              color: Color(0xFFE6EEF3),
              child: Center(child: Text('Map preview')),
            ),
          ),
        ),
        GoRoute(
          path: '/account',
          builder: (context, state) =>
              const Scaffold(body: Text('Profile page')),
        ),
      ],
    ),
    GoRoute(
      path: '/charge',
      builder: (context, state) => ChargingStartScreen(
        dependencies: dependencies,
        initialCode: state.uri.queryParameters['code'],
      ),
    ),
    GoRoute(
      path: '/vehicles',
      builder: (context, state) =>
          VehicleListScreen(dependencies: dependencies),
    ),
  ],
);

Future<void> _pump(WidgetTester tester, GoRouter router) => tester
    .pumpWidget(
      RepaintBoundary(
        key: const ValueKey('driver-preview'),
        child: MaterialApp.router(
          debugShowCheckedModeBanner: false,
          theme: VtsaTheme.light(),
          routerConfig: router,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: AppLocalizations.supportedLocales,
        ),
      ),
    )
    .then((_) => tester.pumpAndSettle());
