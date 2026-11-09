import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/charging/presentation/swipe_to_start.dart';

void main() {
  testWidgets(
    'tap, partial swipe and cancelled swipe do not authorize; full swipe submits once',
    (tester) async {
      final pending = Completer<void>();
      var starts = 0;
      await tester.pumpWidget(
        MaterialApp(
          theme: VtsaTheme.light(),
          home: Scaffold(
            body: Center(
              child: SizedBox(
                width: 353,
                child: SwipeToStart(
                  onStart: () async {
                    starts++;
                    await pending.future;
                  },
                ),
              ),
            ),
          ),
        ),
      );
      final track = find.byKey(const ValueKey('charging-start-swipe'));
      await tester.tap(track);
      expect(starts, 0);
      Future<void> drag(double distance, {bool cancel = false}) async {
        final gesture = await tester.startGesture(
          tester.getTopLeft(track) + const Offset(26, 32),
        );
        await gesture.moveBy(Offset(distance, 0));
        if (cancel) {
          await gesture.cancel();
        } else {
          await gesture.up();
        }
        await tester.pump();
      }

      await drag(100);
      expect(starts, 0);
      await drag(330, cancel: true);
      expect(starts, 0);
      await drag(330);
      expect(starts, 1);
      await drag(330);
      expect(starts, 1);
      pending.complete();
      await tester.pumpAndSettle();
    },
  );

  testWidgets(
    'disabling during a gesture cancels authorization and the button alternative uses the same gate',
    (tester) async {
      final enabled = ValueNotifier(true);
      addTearDown(enabled.dispose);
      var starts = 0;
      await tester.pumpWidget(
        MaterialApp(
          theme: VtsaTheme.light(),
          home: Scaffold(
            body: Center(
              child: SizedBox(
                width: 320,
                child: ValueListenableBuilder(
                  valueListenable: enabled,
                  builder: (_, value, _) => SwipeToStart(
                    enabled: value,
                    onStart: () async {
                      starts++;
                    },
                  ),
                ),
              ),
            ),
          ),
        ),
      );
      final track = find.byKey(const ValueKey('charging-start-swipe'));
      final gesture = await tester.startGesture(
        tester.getTopLeft(track) + const Offset(26, 32),
      );
      await gesture.moveBy(const Offset(280, 0));
      enabled.value = false;
      await tester.pump();
      await gesture.up();
      expect(starts, 0);
      enabled.value = true;
      await tester.pump();
      await tester.tap(find.text('Can’t swipe? Start with a button'));
      await tester.pumpAndSettle();
      expect(starts, 0);
      await tester.tap(find.text('Start charging'));
      await tester.pumpAndSettle();
      expect(starts, 1);
    },
  );
}
