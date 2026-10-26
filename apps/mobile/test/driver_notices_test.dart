import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/features/notices/driver_notice_banner.dart';
import 'package:vtsa_mobile/features/notices/driver_notices.dart';
import 'package:vtsa_mobile/features/notices/driver_notices_screen.dart';

void main() {
  DriverNotice notice({String id = 'notice-1', bool showOnOpen = true}) =>
      DriverNotice(
        id: id,
        category: 'maintenance',
        title: 'Charger service window',
        message: 'This location will be unavailable tonight.',
        showOnOpen: showOnOpen,
        startsAt: DateTime.now().toUtc().subtract(const Duration(hours: 1)),
        endsAt: DateTime.now().toUtc().add(const Duration(hours: 1)),
      );

  test('active notices appear on open and can be dismissed', () async {
    final controller = DriverNoticesController(_Repository([notice()]));
    await controller.refresh();
    expect(controller.openingBanner?.title, 'Charger service window');
    controller.dismissBanner('notice-1');
    expect(controller.openingBanner, isNull);
    expect(controller.items, hasLength(1));
  });

  test('expired notices are never displayed from a cached response', () async {
    final old = DriverNotice(
      id: 'old',
      category: 'promotion',
      title: 'Old promotion',
      message: 'No longer valid',
      showOnOpen: true,
      startsAt: DateTime.now().toUtc().subtract(const Duration(days: 2)),
      endsAt: DateTime.now().toUtc().subtract(const Duration(days: 1)),
    );
    final controller = DriverNoticesController(_Repository([old]));
    await controller.refresh();
    expect(controller.items, isEmpty);
    expect(controller.openingBanner, isNull);
  });

  test('sign-out discards an in-flight response', () async {
    final result = Completer<List<DriverNotice>>();
    final controller = DriverNoticesController(
      _PendingRepository(result.future),
    );
    final refresh = controller.refresh();
    controller.clear();
    result.complete([notice()]);
    await refresh;
    expect(controller.items, isEmpty);
  });

  testWidgets('updates page shows active notice and retry state', (
    tester,
  ) async {
    final controller = DriverNoticesController(_Repository([notice()]));
    await controller.refresh();
    await tester.pumpWidget(
      MaterialApp(home: DriverNoticesScreen(controller: controller)),
    );
    expect(find.text('Charger service window'), findsOneWidget);
    expect(
      find.text('This location will be unavailable tonight.'),
      findsOneWidget,
    );
  });

  testWidgets('opening banner can be dismissed without removing the update', (
    tester,
  ) async {
    final controller = DriverNoticesController(_Repository([notice()]));
    await controller.refresh();
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: DriverNoticeBanner(
            notice: controller.openingBanner!,
            onDismiss: () => controller.dismissBanner('notice-1'),
          ),
        ),
      ),
    );
    expect(find.text('Charger service window'), findsOneWidget);
    await tester.tap(find.byTooltip('Dismiss update'));
    expect(controller.openingBanner, isNull);
    expect(controller.items, hasLength(1));
  });
}

final class _Repository implements DriverNoticeRepository {
  _Repository(this.items);
  final List<DriverNotice> items;

  @override
  Future<List<DriverNotice>> list() async => items;
}

final class _PendingRepository implements DriverNoticeRepository {
  _PendingRepository(this.result);
  final Future<List<DriverNotice>> result;

  @override
  Future<List<DriverNotice>> list() => result;
}
