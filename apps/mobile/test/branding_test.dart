import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';

void main() {
  test(
    'official logo is shared with the website and has balanced clear space',
    () async {
      final bytes = File(
        'assets/branding/power-solutions-logo-horizontal.png',
      ).readAsBytesSync();
      expect(
        bytes,
        orderedEquals(
          File(
            '../platform/public/branding/power-solutions-logo.png',
          ).readAsBytesSync(),
        ),
      );
      final codec = await ui.instantiateImageCodec(bytes);
      final frame = await codec.getNextFrame();
      final image = frame.image;
      expect(image.width, greaterThanOrEqualTo(1800));
      expect(image.width / image.height, inInclusiveRange(2.9, 3.1));
      final pixels = (await image.toByteData())!;
      var first = image.height;
      var last = 0;
      var left = image.width;
      var right = 0;
      for (var y = 0; y < image.height; y++) {
        for (var x = 0; x < image.width; x++) {
          final offset = (y * image.width + x) * 4;
          final channels = [
            pixels.getUint8(offset),
            pixels.getUint8(offset + 1),
            pixels.getUint8(offset + 2),
          ]..sort();
          if (channels.first < 200 && channels.last - channels.first > 25) {
            if (y < first) first = y;
            if (y > last) last = y;
            if (x < left) left = x;
            if (x > right) right = x;
          }
        }
      }
      expect(first, inInclusiveRange(64, 68));
      expect(image.height - last - 1, inInclusiveRange(64, 68));
      expect(left, inInclusiveRange(64, 68));
      expect(image.width - right - 1, inInclusiveRange(64, 68));
      image.dispose();
      codec.dispose();
    },
  );

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
