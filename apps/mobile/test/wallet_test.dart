import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/features/wallet/wallet_repository.dart';
import 'package:vtsa_mobile/features/wallet/wallet_screen.dart';

final class TestWallet implements WalletRepository {
  bool enabled = true;
  bool paid = false;
  bool failCreate = false;
  int reads = 0;
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
    'minimum_minor': 100,
    'maximum_minor': 100000,
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
    final repository = TestWallet()..enabled = false;
    await tester.pumpWidget(
      MaterialApp(home: WalletScreen(repository: repository)),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('Top-ups are not available'), findsOneWidget);
    final button = tester.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNull);
    expect(repository.keys, isEmpty);
  });
  testWidgets(
    'retry retains amount and idempotency key after an interrupted request',
    (tester) async {
      final repository = TestWallet()..failCreate = true;
      await tester.pumpWidget(
        MaterialApp(home: WalletScreen(repository: repository)),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create test top-up'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), '123.45');
      await tester.tap(find.text('Continue'));
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
