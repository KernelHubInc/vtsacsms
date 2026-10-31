import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';

void main() {
  for (final platform in [
    TargetPlatform.android,
    TargetPlatform.iOS,
    TargetPlatform.windows,
  ]) {
    for (final dark in [false, true]) {
      testWidgets('$platform dark=$dark slides in and reverses on back', (
        tester,
      ) async {
        final router = _router();
        addTearDown(router.dispose);
        await tester.pumpWidget(_app(router, platform: platform, dark: dark));
        await tester.pumpAndSettle();
        router.push('/child');
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 100));
        final child = find.byKey(const ValueKey('child'));
        final entering = tester.getTopLeft(child).dx;
        expect(entering, greaterThan(0));
        expect(entering, lessThan(800));
        await tester.pumpAndSettle();
        expect(tester.getTopLeft(child).dx, 0);
        router.pop();
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 100));
        expect(tester.getTopLeft(child).dx, greaterThan(0));
        await tester.pumpAndSettle();
        expect(child, findsNothing);
        expect(find.text('Home'), findsOneWidget);
      });
    }
  }

  testWidgets('Reduce Motion keeps routed and directly pushed pages still', (
    tester,
  ) async {
    final router = _router();
    addTearDown(router.dispose);
    await tester.pumpWidget(_app(router, reduceMotion: true));
    await tester.pumpAndSettle();
    router.push('/child');
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.getTopLeft(find.byKey(const ValueKey('child'))).dx, 0);
    await tester.pumpAndSettle();
    router.pop();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.getTopLeft(find.byKey(const ValueKey('child'))).dx, 0);
    await tester.pumpAndSettle();
    final context = tester.element(find.text('Home'));
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => const Scaffold(key: ValueKey('direct')),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.getTopLeft(find.byKey(const ValueKey('direct'))).dx, 0);
    await tester.pumpAndSettle();
  });

  testWidgets('direct Material pages slide and main tabs stay instant', (
    tester,
  ) async {
    final router = _router();
    addTearDown(router.dispose);
    await tester.pumpWidget(_app(router));
    await tester.pumpAndSettle();
    Navigator.of(tester.element(find.text('Home'))).push(
      MaterialPageRoute<void>(
        builder: (_) => const Scaffold(key: ValueKey('direct')),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
    expect(
      tester.getTopLeft(find.byKey(const ValueKey('direct'))).dx,
      greaterThan(0),
    );
    await tester.pumpAndSettle();
    Navigator.of(tester.element(find.byKey(const ValueKey('direct')))).pop();
    await tester.pumpAndSettle();
    router.go('/tab');
    await tester.pump();
    expect(tester.getTopLeft(find.byKey(const ValueKey('tab'))).dx, 0);
    await tester.pumpAndSettle();
  });
}

GoRouter _router() => GoRouter(
  routes: [
    GoRoute(
      path: '/',
      pageBuilder: (_, state) => NoTransitionPage<void>(
        key: state.pageKey,
        child: const Scaffold(body: Text('Home')),
      ),
    ),
    GoRoute(
      path: '/child',
      builder: (_, _) => const Scaffold(key: ValueKey('child')),
    ),
    GoRoute(
      path: '/tab',
      pageBuilder: (_, state) => NoTransitionPage<void>(
        key: state.pageKey,
        child: const Scaffold(key: ValueKey('tab')),
      ),
    ),
  ],
);

Widget _app(
  GoRouter router, {
  TargetPlatform platform = TargetPlatform.android,
  bool dark = false,
  bool reduceMotion = false,
}) => MaterialApp.router(
  theme: (dark ? VtsaTheme.dark() : VtsaTheme.light()).copyWith(
    platform: platform,
  ),
  routerConfig: router,
  builder: (context, child) => MediaQuery(
    data: MediaQuery.of(context).copyWith(disableAnimations: reduceMotion),
    child: child!,
  ),
);
