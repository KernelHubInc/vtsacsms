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
      final pixels = (await image.toByteData(
        format: ui.ImageByteFormat.rawStraightRgba,
      ))!;
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
      expect(pixels.getUint8(3), 0);
      expect(pixels.getUint8((image.width * image.height - 1) * 4 + 3), 0);

      final monoImages = <ui.Image>[];
      for (final name in [
        'power-solutions-logo-horizontal-dark.png',
        'power-solutions-logo-black.png',
      ]) {
        final monoCodec = await ui.instantiateImageCodec(
          File('assets/branding/$name').readAsBytesSync(),
        );
        monoImages.add((await monoCodec.getNextFrame()).image);
        monoCodec.dispose();
      }
      final white = (await monoImages[0].toByteData(
        format: ui.ImageByteFormat.rawStraightRgba,
      ))!;
      final black = (await monoImages[1].toByteData(
        format: ui.ImageByteFormat.rawStraightRgba,
      ))!;
      var visible = 0;
      var alphaMatches = true;
      var solidInks = true;
      for (var offset = 0; offset < pixels.lengthInBytes; offset += 4) {
        final alpha = pixels.getUint8(offset + 3);
        alphaMatches =
            alphaMatches &&
            white.getUint8(offset + 3) == alpha &&
            black.getUint8(offset + 3) == alpha;
        if (alpha == 0) continue;
        visible++;
        for (var channel = 0; channel < 3; channel++) {
          solidInks =
              solidInks &&
              white.getUint8(offset + channel) == 255 &&
              black.getUint8(offset + channel) == 0;
        }
      }
      expect(visible, greaterThan(100000));
      expect(alphaMatches, isTrue);
      expect(solidInks, isTrue);
      for (final mono in monoImages) {
        mono.dispose();
      }
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

  testWidgets('system appearance changes the logo between color and white', (
    tester,
  ) async {
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.light;
    addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        darkTheme: VtsaTheme.dark(),
        themeMode: ThemeMode.system,
        home: const Scaffold(body: PowerSolutionsLogo()),
      ),
    );
    String imageAsset() =>
        (tester.widget<Image>(find.byType(Image)).image as AssetImage)
            .assetName;
    expect(imageAsset(), 'assets/branding/power-solutions-logo-horizontal.png');
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
    await tester.pumpAndSettle();
    expect(
      imageAsset(),
      'assets/branding/power-solutions-logo-horizontal-dark.png',
    );
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.light;
    await tester.pumpAndSettle();
    expect(imageAsset(), 'assets/branding/power-solutions-logo-horizontal.png');
  });

  testWidgets('a navy surface keeps the white logo in light mode', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: const Scaffold(body: PowerSolutionsLogo(inverse: true)),
      ),
    );
    expect(
      (tester.widget<Image>(find.byType(Image)).image as AssetImage).assetName,
      'assets/branding/power-solutions-logo-horizontal-dark.png',
    );
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
