import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';

void main() {
  testWidgets('Power Solutions logo exposes a text alternative', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: const Scaffold(body: PowerSolutionsLogo()),
      ),
    );

    expect(find.bySemanticsLabel('Power Solutions'), findsOneWidget);
  });

  test(
    'web and native launch assets are present and the manifest is branded',
    () {
      final manifest =
          jsonDecode(File('web/manifest.json').readAsStringSync())
              as Map<String, dynamic>;
      final icons = manifest['icons'] as List<dynamic>;
      final webStartup = File('web/index.html').readAsStringSync();

      expect(manifest['name'], 'Power Solutions');
      expect(manifest['theme_color'], '#12366B');
      expect(icons, hasLength(4));
      expect(webStartup, contains('power-solutions-logo-horizontal.png'));
      expect(webStartup.toLowerCase(), isNot(contains('debug build')));
      expect(File('web/icons/Icon-192.png').existsSync(), isTrue);
      expect(
        File(
          'android/app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml',
        ).existsSync(),
        isTrue,
      );
      expect(
        File(
          'ios/Runner/Assets.xcassets/AppIcon.appiconset/'
          'Icon-App-1024x1024@1x.png',
        ).existsSync(),
        isTrue,
      );
    },
  );
}
