import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/app_router.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/l10n/app_localizations.dart';

import 'support/fakes.dart';

void main() {
  testWidgets('cold notification waits for sign-in and opens Updates once', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    final router = createAppRouter(
      fixture.dependencies,
      initialUri: Uri.parse(
        'https://staging.evcspowersolutions.com/mobile/#/updates',
      ),
    );
    addTearDown(router.dispose);
    addTearDown(fixture.dependencies.dispose);
    await tester.pumpWidget(
      MaterialApp.router(
        routerConfig: router,
        theme: VtsaTheme.light(),
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        supportedLocales: AppLocalizations.supportedLocales,
      ),
    );
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/login');
    expect(find.text('Updates are unavailable.'), findsNothing);

    await fixture.dependencies.auth.login(
      email: 'ada@example.test',
      password: 'test-only',
    );
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/updates');

    router.go('/account');
    await tester.pumpAndSettle();
    expect(router.routeInformationProvider.value.uri.path, '/account');
    expect(tester.takeException(), isNull);
  });
}
