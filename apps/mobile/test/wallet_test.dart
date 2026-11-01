import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/wallet/topup_amount_dialog.dart';
import 'package:vtsa_mobile/features/wallet/wallet_repository.dart';
import 'package:vtsa_mobile/features/wallet/wallet_screen.dart';

import 'driver_journey_design_test.dart' show preview;
import 'support/test_fonts.dart';

final class TestWallet implements WalletRepository {
  bool enabled = true;
  bool paid = false;
  bool failCreate = false;
  int minimumMinor = 100;
  int reads = 0;
  List<int>? presets;
  bool allowCustom = true;
  AppFailure? createFailure;
  Map<String, dynamic>? response;
  String book = 'simulated';
  final keys = <String>[];
  final amounts = <int>[];
  Map<String, dynamic> get order => {
    'id': '01K00000000000000000000001',
    'mode': 'simulated',
    'status': paid ? 'paid' : 'pending',
    'amount_minor': 12345,
    'currency': 'PHP',
    'qr_content': null,
    'expires_at': null,
  };
  @override
  Future<Map<String, dynamic>> summary({String? cursor}) async => {
    'mode': enabled ? book : 'disabled',
    'book': book,
    'topups_enabled': enabled,
    'available_minor': paid ? 12345 : 0,
    'reserved_minor': 0,
    'minimum_minor': minimumMinor,
    'maximum_minor': 100000,
    if (presets != null) 'preset_amounts_minor': presets,
    'allow_custom_amount': allowCustom,
    'history': {'items': <Map<String, dynamic>>[], 'next_cursor': null},
  };
  @override
  Future<Map<String, dynamic>> topups({String? cursor}) async => {
    'items': <Map<String, dynamic>>[],
    'next_cursor': null,
  };
  @override
  Future<Map<String, dynamic>> topup(String id) async {
    reads++;
    return response ?? order;
  }

  @override
  Future<Map<String, dynamic>> create(int amountMinor, String key) async {
    keys.add(key);
    amounts.add(amountMinor);
    if (createFailure != null) throw createFailure!;
    if (failCreate) {
      throw const AppFailure(
        kind: FailureKind.unknown,
        message: 'Connection interrupted',
      );
    }
    return order;
  }
}

void main() {
  setUpAll(loadTestFonts);

  for (final (width, height, scale, dark) in [
    (390.0, 844.0, 1.0, false),
    (320.0, 640.0, 1.6, false),
    (390.0, 844.0, 1.0, true),
    (1024.0, 900.0, 1.0, false),
  ]) {
    testWidgets('top-up selector fits $width at $scale; dark=$dark', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, height);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.textScaleFactorTestValue = scale;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      final repository = TestWallet()
        ..book = 'live'
        ..minimumMinor = 20000
        ..presets = [20000, 30000, 40000, 50000, 60000, 70000, 80000];
      final wallet = await repository.summary();
      await tester.pumpWidget(
        RepaintBoundary(
          key: const ValueKey('driver-preview'),
          child: MaterialApp(
            theme: dark ? VtsaTheme.dark() : VtsaTheme.light(),
            home: Scaffold(
              body: Builder(
                builder: (context) => TextButton(
                  onPressed: () => showDialog<void>(
                    context: context,
                    builder: (_) => TopupAmountDialog(
                      repository: repository,
                      wallet: wallet,
                    ),
                  ),
                  child: const Text('Open top-up'),
                ),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Open top-up'));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      await preview(tester, 'topup-$width-$scale-$dark');
      await tester.ensureVisible(find.text('Other amount'));
      await tester.tap(find.text('Other amount'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), '1000000.00');
      await tester.pumpAndSettle();
      await tester.tap(find.text('Continue with PHP 1000000.00'));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(find.text('Enter no more than PHP 1000.00.'), findsOneWidget);
      await preview(tester, 'topup-error-$width-$scale-$dark');
      expect(repository.amounts, isEmpty);
      await tester.pumpWidget(const SizedBox.shrink());
    });
  }

  Future<void> openAmounts(WidgetTester tester, TestWallet repository) async {
    await tester.pumpWidget(
      MaterialApp(
        home: WalletScreen(repository: repository, allowRealPayments: true),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add money'));
    await tester.pumpAndSettle();
  }

  testWidgets('presets expand beyond six and submit the chosen amount', (
    tester,
  ) async {
    final repository = TestWallet()
      ..book = 'live'
      ..presets = [20000, 30000, 40000, 50000, 60000, 70000, 80000, 90000];
    await openAmounts(tester, repository);
    expect(find.text('Other amount'), findsOneWidget);
    expect(find.text('PHP 800.00'), findsNothing);
    await tester.tap(find.text('More amounts (2)'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('PHP 800.00'));
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<ChoiceChip>(
            find.ancestor(
              of: find.text('PHP 800.00'),
              matching: find.byType(ChoiceChip),
            ),
          )
          .selected,
      isTrue,
    );
    await tester.tap(find.text('Continue with PHP 800.00'));
    await tester.pumpAndSettle();
    expect(repository.amounts, [80000]);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets(
    'custom entry has inline minimum validation and updates Continue',
    (tester) async {
      final repository = TestWallet()
        ..book = 'live'
        ..presets = [20000, 50000]
        ..minimumMinor = 20000;
      await openAmounts(tester, repository);
      await tester.tap(find.text('Other amount'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), '100');
      await tester.pumpAndSettle();
      await tester.tap(find.text('Continue with PHP 100.00'));
      await tester.pumpAndSettle();
      expect(find.text('Enter at least PHP 200.00.'), findsOneWidget);
      expect(find.textContaining('Retry keeps'), findsNothing);
      expect(repository.amounts, isEmpty);
      await tester.enterText(find.byType(TextField), '250.50');
      await tester.pumpAndSettle();
      expect(find.text('Enter at least PHP 200.00.'), findsNothing);
      await tester.tap(find.text('Continue with PHP 250.50'));
      await tester.pumpAndSettle();
      expect(repository.amounts, [25050]);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets('preset-only policy hides custom entry and requires a choice', (
    tester,
  ) async {
    final repository = TestWallet()
      ..book = 'live'
      ..presets = [50000]
      ..allowCustom = false;
    await openAmounts(tester, repository);
    expect(find.text('Other amount'), findsNothing);
    expect(find.byType(TextField), findsNothing);
    await tester.tap(find.text('Continue'));
    await tester.pumpAndSettle();
    expect(find.text('Choose a top-up amount.'), findsOneWidget);
    expect(repository.amounts, isEmpty);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('opening Add money refreshes admin settings', (tester) async {
    final repository = TestWallet()
      ..book = 'live'
      ..presets = [20000];
    await tester.pumpWidget(
      MaterialApp(
        home: WalletScreen(repository: repository, allowRealPayments: true),
      ),
    );
    await tester.pumpAndSettle();
    repository.presets = [50000];
    repository.allowCustom = false;
    await tester.tap(find.text('Add money'));
    await tester.pumpAndSettle();
    expect(find.text('PHP 500.00'), findsOneWidget);
    expect(find.text('PHP 200.00'), findsNothing);
    expect(find.text('Other amount'), findsNothing);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('a policy rejection reloads options and allows a new selection', (
    tester,
  ) async {
    final repository = TestWallet()
      ..book = 'live'
      ..presets = [20000]
      ..createFailure = const AppFailure(
        kind: FailureKind.validation,
        code: 'INVALID_TOPUP_AMOUNT',
        message: 'Refresh Wallet to see the latest options.',
      );
    await openAmounts(tester, repository);
    await tester.tap(find.text('PHP 200.00'));
    await tester.pumpAndSettle();
    repository.presets = [50000];
    await tester.tap(find.text('Continue with PHP 200.00'));
    await tester.pumpAndSettle();
    expect(find.text('PHP 500.00'), findsOneWidget);
    expect(find.text('Retry same request'), findsNothing);
    repository.createFailure = null;
    await tester.tap(find.text('PHP 500.00'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Continue with PHP 500.00'));
    await tester.pumpAndSettle();
    expect(repository.amounts, [20000, 50000]);
    expect(repository.keys[0], isNot(repository.keys[1]));
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('live pending QR renders a payment code', (tester) async {
    final repository = TestWallet();
    final row = <String, dynamic>{
      ...repository.order,
      'mode': 'live',
      'qr_content': 'synthetic-qr-content',
      'expires_at': DateTime.now()
          .add(const Duration(minutes: 10))
          .toIso8601String(),
    };
    repository.response = row;
    await tester.pumpWidget(
      MaterialApp(
        home: WalletTopupScreen(repository: repository, initial: row),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(QrImageView), findsOneWidget);
    expect(find.text('QR Ph'), findsOneWidget);
    expect(find.text('Scan to pay'), findsOneWidget);
    final code = tester.widget<QrImageView>(find.byType(QrImageView));
    expect(code.embeddedImage, isNull);
    expect(code.padding, const EdgeInsets.all(16));
    expect(
      tester.getBottomLeft(find.text('QR Ph')).dy,
      lessThan(tester.getTopLeft(find.byType(QrImageView)).dy),
    );
    expect(find.text('Waiting for payment'), findsOneWidget);
    expect(find.text('Payment confirmed'), findsNothing);
    await tester.scrollUntilVisible(find.text('Save QR to Photos'), 200);
    expect(find.text('Save QR to Photos'), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
  });
  testWidgets('saving a live QR exports a PNG without confirming payment', (
    tester,
  ) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    addTearDown(() => debugDefaultTargetPlatformOverride = null);
    const channel = MethodChannel('com.vtsa.vtsa_mobile/payment_apps');
    String? openedApp;
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
          openedApp = (call.arguments as Map)['package'] as String;
          return true;
        });
    addTearDown(
      () => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(channel, null),
    );
    final repository = TestWallet();
    final row = <String, dynamic>{
      ...repository.order,
      'mode': 'live',
      'qr_content': 'synthetic-qr-content',
      'expires_at': DateTime.now()
          .add(const Duration(minutes: 10))
          .toIso8601String(),
    };
    Uint8List? saved;
    String? savedName;
    await tester.pumpWidget(
      MaterialApp(
        home: WalletTopupScreen(
          repository: repository,
          initial: row,
          renderQrImage: (content, amountMinor, expiresAt) async {
            expect(content, 'synthetic-qr-content');
            expect(amountMinor, 12345);
            expect(expiresAt, row['expires_at']);
            return Uint8List.fromList([137, 80, 78, 71, 13, 10, 26, 10]);
          },
          saveQrImage: (bytes, name) async {
            saved = bytes;
            savedName = name;
          },
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Save QR to Photos'), 200);
    await tester.tap(find.text('Save QR to Photos'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Could not save the QR'), findsNothing);
    expect(saved, isNotNull);
    expect(saved!.take(8), [137, 80, 78, 71, 13, 10, 26, 10]);
    expect(savedName, 'Power-Solutions-QRPh-${row['id']}');
    await tester.tap(find.text('Open GCash'));
    await tester.pumpAndSettle();
    expect(openedApp, 'com.globe.gcash.android');
    await tester.tap(find.text('Open Maya'));
    await tester.pumpAndSettle();
    expect(openedApp, 'com.paymaya');
    expect(repository.paid, isFalse);
    expect(find.text('Payment confirmed'), findsNothing);
    expect(repository.keys, isEmpty);
    debugDefaultTargetPlatformOverride = null;
    await tester.pumpWidget(const SizedBox.shrink());
  });
  for (final status in ['unknown', 'review_required']) {
    testWidgets(
      '$status without QR explains recovery and stops automatic polling',
      (tester) async {
        final repository = TestWallet();
        final row = <String, dynamic>{
          ...repository.order,
          'mode': 'live',
          'status': status,
        };
        repository.response = row;
        await tester.pumpWidget(
          MaterialApp(
            home: WalletTopupScreen(repository: repository, initial: row),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.text('Payment QR unavailable'), findsOneWidget);
        expect(find.byType(QrImageView), findsNothing);
        expect(find.textContaining('Do not pay an expired code'), findsNothing);
        await tester.pump(const Duration(seconds: 20));
        expect(repository.reads, 0);
        await tester.ensureVisible(find.text('Refresh request'));
        await tester.tap(find.text('Refresh request'));
        await tester.pumpAndSettle();
        expect(repository.reads, 1);
        expect(repository.keys, isEmpty);
        await tester.pumpWidget(const SizedBox.shrink());
      },
    );
  }
  testWidgets('expired live code cannot be displayed', (tester) async {
    final repository = TestWallet();
    final row = <String, dynamic>{
      ...repository.order,
      'mode': 'live',
      'status': 'expired',
      'qr_content': 'old-qr',
      'expires_at': DateTime.now()
          .subtract(const Duration(minutes: 1))
          .toIso8601String(),
    };
    await tester.pumpWidget(
      MaterialApp(
        home: WalletTopupScreen(repository: repository, initial: row),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(QrImageView), findsNothing);
    expect(find.text('Save QR to Photos'), findsNothing);
    expect(
      find.text('QR expired · confirmation still checked'),
      findsOneWidget,
    );
    await tester.pumpWidget(const SizedBox.shrink());
  });
  for (final serverEnabled in [false, true]) {
    for (final clientEnabled in [false, true]) {
      testWidgets(
        'live top-ups require server=$serverEnabled and client=$clientEnabled',
        (tester) async {
          final repository = TestWallet()
            ..book = 'live'
            ..enabled = serverEnabled;
          await tester.pumpWidget(
            MaterialApp(
              home: WalletScreen(
                repository: repository,
                allowRealPayments: clientEnabled,
              ),
            ),
          );
          await tester.pumpAndSettle();
          expect(find.text('AVAILABLE BALANCE'), findsOneWidget);
          final button = tester.widget<FilledButton>(find.byType(FilledButton));
          expect(button.onPressed != null, serverEnabled && clientEnabled);
          expect(repository.keys, isEmpty);
        },
      );
    }
  }
  test('amount parsing uses exact minor units and rejects ambiguous input', () {
    expect(parseWalletAmount('123.45'), 12345);
    expect(parseWalletAmount('0.10'), 10);
    expect(parseWalletAmount('100'), 10000);
    for (final invalid in ['1.005', '-1', '1e3', '1,000', 'NaN', '']) {
      expect(parseWalletAmount(invalid), isNull);
    }
  });
  testWidgets('disabled collection cannot create a top-up', (tester) async {
    final repository = TestWallet()
      ..book = 'live'
      ..enabled = false;
    await tester.pumpWidget(
      MaterialApp(home: WalletScreen(repository: repository)),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('Top-ups are not available'), findsOneWidget);
    final button = tester.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNull);
    expect(repository.keys, isEmpty);
  });
  testWidgets('test wallet cannot create top-ups in a driver build', (
    tester,
  ) async {
    final repository = TestWallet();
    await tester.pumpWidget(
      MaterialApp(home: WalletScreen(repository: repository)),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('test mode'), findsOneWidget);
    expect(find.text('Create test top-up'), findsNothing);
    expect(repository.keys, isEmpty);
  });
  testWidgets('top-up form uses the minimum returned by the backend', (
    tester,
  ) async {
    final repository = TestWallet()
      ..book = 'live'
      ..minimumMinor = 10000;
    await tester.pumpWidget(
      MaterialApp(
        home: WalletScreen(repository: repository, allowRealPayments: true),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add money'));
    await tester.pumpAndSettle();
    expect(find.text('Minimum PHP 100.00'), findsOneWidget);
    expect(find.textContaining('Maximum'), findsNothing);
    await tester.enterText(find.byType(TextField), '1.00');
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('Continue with'));
    await tester.pumpAndSettle();
    expect(find.text('Enter at least PHP 100.00.'), findsOneWidget);
    expect(repository.keys, isEmpty);
    await tester.enterText(find.byType(TextField), '1000.01');
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('Continue with'));
    await tester.pumpAndSettle();
    expect(find.text('Enter no more than PHP 1000.00.'), findsOneWidget);
    expect(repository.keys, isEmpty);
    await tester.pumpWidget(const SizedBox.shrink());
  });
  testWidgets(
    'retry retains amount and idempotency key after an interrupted request',
    (tester) async {
      final repository = TestWallet()..failCreate = true;
      await tester.pumpWidget(
        MaterialApp(
          home: WalletScreen(
            repository: repository,
            allowSimulatedPayments: true,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create test top-up'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), '123.45');
      await tester.pumpAndSettle();
      await tester.tap(find.textContaining('Continue with'));
      await tester.pumpAndSettle();
      expect(find.text('Connection interrupted'), findsOneWidget);
      expect(tester.widget<TextField>(find.byType(TextField)).enabled, isFalse);
      repository.failCreate = false;
      await tester.tap(find.text('Retry same request'));
      await tester.pumpAndSettle();
      expect(repository.keys.length, 2);
      expect(repository.keys[0], repository.keys[1]);
      expect(repository.amounts, [12345, 12345]);
      expect(find.textContaining('TEST ONLY'), findsOneWidget);
      expect(find.text('Payment confirmed'), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );
  testWidgets('only a server confirmation changes pending to paid', (
    tester,
  ) async {
    final repository = TestWallet();
    await tester.pumpWidget(
      MaterialApp(
        home: WalletTopupScreen(
          repository: repository,
          initial: repository.order,
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Waiting for payment'), findsOneWidget);
    repository.paid = true;
    await tester.tap(find.text('Check payment status'));
    await tester.pumpAndSettle();
    expect(find.text('Payment confirmed'), findsOneWidget);
    expect(find.text('Check payment status'), findsNothing);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
