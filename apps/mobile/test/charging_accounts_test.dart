import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/features/accounts/charging_account_screen.dart';
import 'package:vtsa_mobile/features/accounts/charging_accounts.dart';
import 'package:vtsa_mobile/features/vehicles/data/api_vehicle_repository.dart';
import 'package:vtsa_mobile/features/wallet/wallet_repository.dart';

import 'driver_journey_design_test.dart' show preview;
import 'support/fakes.dart';

const personal = ChargingAccount(
  id: 'personal',
  name: 'Personal',
  type: 'personal',
  role: 'owner',
  permissions: {'organizations.charging_accounts.view'},
);
const fleet = ChargingAccount(
  id: 'fleet',
  name: 'Lumina fleet',
  type: 'fleet',
  role: 'driver',
  permissions: {
    'organizations.charging_accounts.view',
    'organizations.charging_accounts.charge',
  },
);

class AccountRepositoryFake implements ChargingAccountRepository {
  List<ChargingAccount> accounts = [personal, fleet];
  Completer<List<ChargingAccount>>? pending;
  AppFailure? failure;
  AppFailure? inviteFailure;
  AppFailure? deleteFailure;
  final requests = <String>[];
  final posted = <Map<String, dynamic>>[];
  List<Map<String, dynamic>> invitations = [];
  Completer<Object?>? incomingRequest;
  final responses = <String, Object?>{};
  int listCalls = 0;
  @override
  Future<List<ChargingAccount>> list() async {
    listCalls++;
    if (failure != null) throw failure!;
    return pending?.future ?? accounts;
  }

  @override
  Future<Object?> request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? data,
  }) async {
    requests.add('$method $path');
    if (data != null) posted.add(data);
    if (path.endsWith('/invitations') &&
        method == 'POST' &&
        inviteFailure != null) {
      throw inviteFailure!;
    }
    if (method == 'DELETE' && deleteFailure != null) throw deleteFailure!;
    if (method == 'PATCH' &&
        data != null &&
        path.startsWith('/') &&
        path.split('/').length == 2) {
      final id = path.substring(1);
      final current = accounts.where((account) => account.id == id).first;
      final result = <String, dynamic>{
        'id': id,
        'name': data['name'],
        'type': data['type'],
        'role': current.role,
        'permissions': current.permissions.toList(),
      };
      accounts = [
        for (final account in accounts)
          if (account.id == id) ChargingAccount.fromJson(result) else account,
      ];
      return result;
    }
    if (method == 'DELETE' &&
        path.startsWith('/') &&
        path.split('/').length == 2) {
      accounts = accounts
          .where((account) => account.id != path.substring(1))
          .toList();
      return {'deleted': true};
    }
    if (responses.containsKey(path)) return responses[path];
    if (path.isEmpty && method == 'POST') {
      final result = <String, dynamic>{
        'id': 'created-${data!['type']}',
        'name': data['name'],
        'type': data['type'],
        'role': 'owner',
        'permissions': [
          'organizations.charging_accounts.view',
          'organizations.charging_accounts.members',
          'organizations.charging_accounts.vehicles',
          'organizations.charging_accounts.wallet_view',
          'organizations.charging_accounts.charge',
        ],
      };
      accounts = [...accounts, ChargingAccount.fromJson(result)];
      return result;
    }
    if (path == '/invitations') return incomingRequest?.future ?? invitations;
    if (path == '/invitations/invite-1/accept') {
      accounts = [...accounts, fleet];
      invitations = [];
      return {'accepted': true};
    }
    return <Map<String, dynamic>>[];
  }
}

void main() {
  setUpAll(() async {
    final root = Platform.environment['FLUTTER_ROOT']!;
    for (final entry in {
      'Roboto': 'roboto-regular.ttf',
      'MaterialIcons': 'materialicons-regular.otf',
    }.entries) {
      await (FontLoader(entry.key)..addFont(
            Future.value(
              ByteData.sublistView(
                File(
                  '$root/bin/cache/artifacts/material_fonts/${entry.value}',
                ).readAsBytesSync(),
              ),
            ),
          ))
          .load();
    }
  });

  testWidgets('Personal opens account management through the app router', (
    tester,
  ) async {
    final repo = AccountRepositoryFake();
    final accounts = ChargingAccountsController(repo, ownerId: () => 'driver');
    final setup = await buildTestDependencies(
      signedIn: true,
      accounts: accounts,
    );
    await tester.pumpWidget(VtsaApp(dependencies: setup.dependencies));
    await tester.pumpAndSettle();
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
    router.go('/account');
    await tester.pumpAndSettle();
    final before = repo.listCalls;
    await tester.tap(find.text('Your private vehicles and wallet'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.text('Charging accounts'), findsOneWidget);
    expect(repo.listCalls, before + 1);
    await tester.tap(find.text('Lumina fleet').first);
    await tester.pumpAndSettle();
    expect(accounts.accountId, 'fleet');
    await tester.tap(find.text('Personal').first);
    await tester.pumpAndSettle();
    expect(accounts.accountId, 'personal');
    router.pop();
    await tester.pumpAndSettle();
    expect(find.text('Your private vehicles and wallet'), findsOneWidget);
    await tester.tap(find.text('Your private vehicles and wallet'));
    await tester.pumpAndSettle();
    expect(find.text('Charging accounts'), findsOneWidget);
    expect(repo.listCalls, before + 2);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('Personal remains navigable when refreshing accounts fails', (
    tester,
  ) async {
    final repo = AccountRepositoryFake();
    final accounts = ChargingAccountsController(repo, ownerId: () => 'driver');
    final setup = await buildTestDependencies(
      signedIn: true,
      accounts: accounts,
    );
    await tester.pumpWidget(VtsaApp(dependencies: setup.dependencies));
    await tester.pumpAndSettle();
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
    router.go('/account');
    await tester.pumpAndSettle();
    repo.failure = const AppFailure(
      kind: FailureKind.offline,
      message: 'You appear to be offline.',
    );
    await tester.tap(find.text('Your private vehicles and wallet'));
    await tester.pumpAndSettle();
    expect(find.text('You appear to be offline.'), findsOneWidget);
    repo.failure = null;
    await tester.ensureVisible(find.text('Refresh accounts'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Refresh accounts'));
    await tester.pumpAndSettle();
    expect(find.text('You appear to be offline.'), findsNothing);
    await tester.tap(find.byTooltip('Back'));
    await tester.pumpAndSettle();
    expect(find.text('Your private vehicles and wallet'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('Personal can close while its account request is pending', (
    tester,
  ) async {
    final repo = AccountRepositoryFake();
    final accounts = ChargingAccountsController(repo, ownerId: () => 'driver');
    final setup = await buildTestDependencies(
      signedIn: true,
      accounts: accounts,
    );
    await tester.pumpWidget(VtsaApp(dependencies: setup.dependencies));
    await tester.pumpAndSettle();
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
    router.go('/account');
    await tester.pumpAndSettle();
    repo.pending = Completer<List<ChargingAccount>>();
    await tester.tap(find.text('Your private vehicles and wallet'));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    expect(find.byType(LinearProgressIndicator), findsOneWidget);
    await tester.tap(find.byTooltip('Back'));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    repo.pending!.complete([personal, fleet]);
    await tester.pumpAndSettle();
    expect(find.text('Your private vehicles and wallet'), findsOneWidget);
    expect(find.byType(ChargingAccountsScreen), findsNothing);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets(
    'radio switches browsing account without changing the current charge',
    (tester) async {
      final repo = AccountRepositoryFake();
      final accounts = ChargingAccountsController(
        repo,
        ownerId: () => 'driver',
      );
      final charging = FakeChargingRepository()
        ..active = sampleChargingSession();
      final setup = await buildTestDependencies(
        signedIn: true,
        accounts: accounts,
        chargingRepository: charging,
      );
      await tester.pumpWidget(VtsaApp(dependencies: setup.dependencies));
      await tester.pumpAndSettle();
      final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
      router.go('/account');
      await tester.pumpAndSettle();
      await tester.tap(find.text('Your private vehicles and wallet'));
      await tester.pumpAndSettle();
      expect(find.text('Charging accounts'), findsOneWidget);
      expect(
        find.text('Your current charge keeps its original wallet'),
        findsOneWidget,
      );
      final radio = find.descendant(
        of: find.byKey(const ValueKey('charging-account-fleet')),
        matching: find.byType(Radio<String>),
      );
      await tester.ensureVisible(radio);
      await tester.tap(radio);
      await tester.pumpAndSettle();
      expect(accounts.accountId, 'fleet');
      expect(setup.dependencies.charging.session?.id, charging.active!.id);
      expect(charging.startCalls, 0);
      expect(charging.stopCalls, 0);
      router.go('/charge');
      await tester.pumpAndSettle();
      expect(
        find.text('Finish or resolve the current request first'),
        findsOneWidget,
      );
      expect(find.text('Review charging estimate'), findsNothing);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  for (final type in ['business', 'fleet']) {
    testWidgets('creating $type validates a name and selects the new account', (
      tester,
    ) async {
      final repo = AccountRepositoryFake();
      final accounts = ChargingAccountsController(repo, ownerId: () => 'owner');
      final setup = await buildTestDependencies(
        signedIn: true,
        accounts: accounts,
      );
      await tester.pumpWidget(
        MaterialApp(
          theme: VtsaTheme.light(),
          home: ChargingAccountsScreen(dependencies: setup.dependencies),
        ),
      );
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Create business or fleet'),
        180,
      );
      await tester.tap(find.text('Create business or fleet'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Create'));
      await tester.pumpAndSettle();
      expect(find.text('Use at least two characters.'), findsOneWidget);
      await tester.enterText(find.byType(TextFormField), 'New team');
      if (type == 'fleet') {
        await tester.tap(find.byType(DropdownButtonFormField<String>));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Fleet').last);
        await tester.pumpAndSettle();
      }
      await tester.tap(find.text('Create'));
      await tester.pumpAndSettle();
      expect(accounts.accountId, 'created-$type');
      expect(accounts.selected!.role, 'owner');
      expect(repo.posted.single, {'name': 'New team', 'type': type});
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
      setup.dependencies.dispose();
    });
  }

  testWidgets('owner can rename, retype, and delete only an unused account', (
    tester,
  ) async {
    final repo = AccountRepositoryFake()
      ..accounts = [
        personal,
        const ChargingAccount(
          id: 'owned',
          name: 'Old team',
          type: 'business',
          role: 'owner',
          permissions: {'organizations.charging_accounts.view'},
        ),
      ];
    final accounts = ChargingAccountsController(repo, ownerId: () => 'owner');
    final setup = await buildTestDependencies(
      signedIn: true,
      accounts: accounts,
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: ChargingAccountsScreen(dependencies: setup.dependencies),
      ),
    );
    await tester.pumpAndSettle();
    final accountRadio = find.descendant(
      of: find.byKey(const ValueKey('charging-account-owned')),
      matching: find.byType(Radio<String>),
    );
    await tester.ensureVisible(accountRadio);
    await tester.tap(accountRadio);
    await tester.pumpAndSettle();
    expect(accounts.accountId, 'owned');
    await tester.scrollUntilVisible(find.text('Edit account'), 180);
    await tester.tap(find.text('Edit account'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField), 'A');
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();
    expect(find.text('Use at least two characters.'), findsOneWidget);
    await tester.enterText(find.byType(TextFormField), '  New   fleet  ');
    await tester.tap(find.byType(DropdownButtonFormField<String>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Fleet').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();
    expect(accounts.selected?.name, 'New fleet');
    expect(accounts.selected?.type, 'fleet');
    expect(repo.requests, contains('PATCH /owned'));
    expect(repo.posted.last, {'name': 'New fleet', 'type': 'fleet'});

    repo.deleteFailure = const AppFailure(
      kind: FailureKind.server,
      message: 'This account has charging history.',
    );
    await tester.scrollUntilVisible(find.text('Delete unused account'), 180);
    await tester.tap(find.text('Delete unused account'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Delete unused account').last);
    await tester.pumpAndSettle();
    expect(accounts.accountId, 'owned');
    expect(find.text('This account has charging history.'), findsOneWidget);

    repo.deleteFailure = null;
    await tester.scrollUntilVisible(find.text('Delete unused account'), 180);
    await tester.tap(find.text('Delete unused account').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Delete unused account').last);
    await tester.pumpAndSettle();
    expect(accounts.accountId, 'personal');
    expect(repo.accounts.map((item) => item.id), ['personal']);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    setup.dependencies.dispose();
  });

  testWidgets('accepting an invitation opens the joined account', (
    tester,
  ) async {
    final repo = AccountRepositoryFake()
      ..accounts = [personal]
      ..invitations = [
        {
          'id': 'invite-1',
          'account_id': 'fleet',
          'account_name': 'Lumina fleet',
          'role': 'driver',
          'expires_at': '2030-01-01',
        },
      ];
    final accounts = ChargingAccountsController(repo, ownerId: () => 'driver');
    final setup = await buildTestDependencies(
      signedIn: true,
      accounts: accounts,
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: ChargingAccountsScreen(dependencies: setup.dependencies),
      ),
    );
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Accept'), 180);
    await tester.tap(find.text('Accept'));
    await tester.pumpAndSettle();
    expect(accounts.accountId, 'fleet');
    expect(repo.requests, contains('POST /invitations/invite-1/accept'));
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    setup.dependencies.dispose();
  });

  testWidgets('slow account details do not disable the account radio', (
    tester,
  ) async {
    final repo = AccountRepositoryFake()
      ..incomingRequest = Completer<Object?>();
    final accounts = ChargingAccountsController(repo, ownerId: () => 'driver');
    final setup = await buildTestDependencies(
      signedIn: true,
      accounts: accounts,
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: ChargingAccountsScreen(dependencies: setup.dependencies),
      ),
    );
    await tester.pump();
    final radio = find.descendant(
      of: find.byKey(const ValueKey('charging-account-fleet')),
      matching: find.byType(Radio<String>),
    );
    await tester.tap(radio);
    await tester.pump();
    expect(accounts.accountId, 'fleet');
    repo.incomingRequest!.complete(<Map<String, dynamic>>[]);
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    setup.dependencies.dispose();
  });

  testWidgets('owner can send an invitation with the selected account role', (
    tester,
  ) async {
    const owner = ChargingAccount(
      id: 'fleet',
      name: 'Lumina fleet',
      type: 'fleet',
      role: 'owner',
      permissions: {
        'organizations.charging_accounts.view',
        'organizations.charging_accounts.members',
        'organizations.charging_accounts.vehicles',
        'organizations.charging_accounts.wallet_view',
        'organizations.charging_accounts.limits',
        'organizations.charging_accounts.history',
      },
    );
    final repo = AccountRepositoryFake()..accounts = [personal, owner];
    final accounts = ChargingAccountsController(repo, ownerId: () => 'owner');
    final setup = await buildTestDependencies(
      signedIn: true,
      accounts: accounts,
    );
    await accounts.load();
    accounts.select('fleet');
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: ChargingAccountsScreen(dependencies: setup.dependencies),
      ),
    );
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Invite member'), 200);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Invite member'));
    await tester.pumpAndSettle();
    repo.inviteFailure = const AppFailure(
      kind: FailureKind.validation,
      message: 'Use a registered email.',
      fieldErrors: {
        'email': ['Ask this person to register first.'],
      },
    );
    await tester.enterText(find.byType(TextField), 'unknown@example.test');
    await tester.tap(find.text('Send invitation'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsOneWidget);
    expect(find.text('Ask this person to register first.'), findsOneWidget);
    repo.inviteFailure = null;
    repo.posted.clear();
    await tester.enterText(find.byType(TextField), 'driver@example.test');
    await tester.tap(find.text('Send invitation'));
    await tester.pumpAndSettle();
    expect(repo.requests, contains('POST /fleet/invitations'));
    expect(repo.posted.single, {
      'email': 'driver@example.test',
      'role': 'driver',
    });
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    setup.dependencies.dispose();
  });

  testWidgets(
    'wallet reloads the selected account and hides shared balance from a driver',
    (tester) async {
      const business = ChargingAccount(
        id: 'business',
        name: 'Office team',
        type: 'business',
        role: 'owner',
        permissions: {
          'organizations.charging_accounts.view',
          'organizations.charging_accounts.wallet_view',
        },
      );
      final repo = AccountRepositoryFake()
        ..accounts = [personal, business, fleet];
      final accounts = ChargingAccountsController(
        repo,
        ownerId: () => 'driver',
      );
      final adapter = ScopeAdapter();
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: MemoryTokenStore(),
        dio: Dio()..httpClientAdapter = adapter,
      );
      final setup = await buildTestDependencies(
        signedIn: true,
        accounts: accounts,
        walletRepository: ApiWalletRepository(
          client,
          accountId: () => accounts.accountId,
        ),
      );
      await tester.pumpWidget(VtsaApp(dependencies: setup.dependencies));
      await tester.pumpAndSettle();
      final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
      router.go('/wallet');
      await tester.pumpAndSettle();
      expect(find.text('PHP 1.11'), findsOneWidget);
      await tester.tap(find.text('Your private vehicles and wallet'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('charging-account-business')));
      await tester.pumpAndSettle();
      router.pop();
      await tester.pumpAndSettle();
      expect(find.text('Office team · prepaid balance'), findsOneWidget);
      expect(find.text('PHP 2.22'), findsOneWidget);
      expect(find.text('PHP 1.11'), findsNothing);
      expect(
        adapter.calls
            .where((c) => c.path == '/api/v1/wallet')
            .last
            .headers['X-Charging-Account-ID'],
        'business',
      );
      await tester.tap(find.text('Office team').first);
      await tester.pumpAndSettle();
      final fleetChoice = find.byKey(const ValueKey('charging-account-fleet'));
      await tester.ensureVisible(fleetChoice);
      await tester.tap(fleetChoice);
      await tester.pumpAndSettle();
      router.pop();
      await tester.pumpAndSettle();
      expect(
        find.textContaining('cannot view the shared balance'),
        findsOneWidget,
      );
      expect(find.text('PHP 2.22'), findsNothing);
      expect(
        adapter.calls.where(
          (c) =>
              c.path == '/api/v1/wallet' &&
              c.headers['X-Charging-Account-ID'] == 'fleet',
        ),
        isEmpty,
      );
      await tester.tap(find.text('Choose another account'));
      await tester.pumpAndSettle();
      expect(find.byType(ChargingAccountsScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets('owner sees the current cap and can explicitly set zero', (
    tester,
  ) async {
    const owner = ChargingAccount(
      id: 'fleet',
      name: 'Owner fleet',
      type: 'fleet',
      role: 'owner',
      permissions: {
        'organizations.charging_accounts.view',
        'organizations.charging_accounts.members',
        'organizations.charging_accounts.vehicles',
        'organizations.charging_accounts.limits',
      },
    );
    final repo = AccountRepositoryFake()..accounts = [personal, owner];
    repo.responses['/fleet/members'] = [
      {'subject_id': 'owner', 'name': 'Owner', 'role': 'owner'},
    ];
    repo.responses['/fleet/limits'] = [
      {'target_type': 'driver', 'target_id': 'owner', 'amount_minor': 100},
    ];
    final accounts = ChargingAccountsController(repo, ownerId: () => 'owner');
    final setup = await buildTestDependencies(
      signedIn: true,
      accounts: accounts,
    );
    await accounts.load();
    accounts.select('fleet');
    await tester.pumpWidget(
      MaterialApp(
        theme: VtsaTheme.light(),
        home: ChargingAccountsScreen(dependencies: setup.dependencies),
      ),
    );
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Set or remove a cap'), 240);
    await tester.ensureVisible(find.text('Set or remove a cap'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Set or remove a cap'));
    await tester.pumpAndSettle();
    expect(find.text('1.00'), findsOneWidget);
    await tester.enterText(find.byType(TextFormField), '0');
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();
    expect(repo.posted.single, {
      'target_type': 'driver',
      'target_id': 'owner',
      'amount_minor': 0,
    });
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    setup.dependencies.dispose();
  });

  test(
    'revoked shared account never silently becomes personal payer',
    () async {
      final repository = AccountRepositoryFake();
      final accounts = ChargingAccountsController(
        repository,
        ownerId: () => 'driver',
      );
      await accounts.load();
      expect(accounts.accountId, 'personal');
      accounts.select('fleet');
      repository.accounts = [personal];
      await accounts.load();
      expect(accounts.accountId, 'fleet');
      expect(accounts.shared, isTrue);
      expect(accounts.selected!.permissions, isEmpty);
      accounts.select('personal');
      expect(accounts.accountId, 'personal');
      accounts.dispose();
    },
  );

  test(
    'responses from a previous signed-in person cannot restore their account',
    () async {
      final repository = AccountRepositoryFake()
        ..pending = Completer<List<ChargingAccount>>();
      var owner = 'first';
      final accounts = ChargingAccountsController(
        repository,
        ownerId: () => owner,
      );
      final loading = accounts.load();
      accounts.clear();
      owner = 'second';
      repository.pending!.complete([fleet]);
      await loading;
      expect(accounts.accounts, isEmpty);
      expect(accounts.selected, isNull);
      accounts.dispose();
    },
  );

  test(
    'a completed request cannot notify a disposed account controller',
    () async {
      final repository = AccountRepositoryFake()
        ..pending = Completer<List<ChargingAccount>>();
      final accounts = ChargingAccountsController(
        repository,
        ownerId: () => 'driver',
      );
      final request = accounts.load();
      accounts.dispose();
      repository.pending!.complete([fleet]);
      await request;
      expect(accounts.selected, isNull);
    },
  );

  test(
    'wallet and garage requests pin account selection at request creation',
    () async {
      final adapter = ScopeAdapter();
      var account = 'fleet-a';
      final client = ApiClient(
        baseUrl: Uri.parse('https://api.example.test'),
        tokenStore: MemoryTokenStore(),
        dio: Dio()..httpClientAdapter = adapter,
      );
      final wallet = ApiWalletRepository(client, accountId: () => account);
      final request = wallet.create(100, 'test-idempotency');
      account = 'fleet-b';
      await request;
      expect(adapter.calls.single.headers['X-Charging-Account-ID'], 'fleet-a');
      final vehicles = ApiVehicleRepository(
        client,
        accountId: () => account,
        shared: () => true,
      );
      await vehicles.list('driver');
      expect(adapter.calls.last.headers['X-Charging-Account-ID'], 'fleet-b');
    },
  );

  for (final width in [390.0, 320.0]) {
    testWidgets(
      'driver can select payer without exposing member or wallet controls at $width',
      (tester) async {
        tester.view.physicalSize = Size(width, 844);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final repo = AccountRepositoryFake();
        final accounts = ChargingAccountsController(
          repo,
          ownerId: () => 'driver',
        );
        final setup = await buildTestDependencies(
          signedIn: true,
          accounts: accounts,
        );
        await tester.pumpWidget(
          MaterialApp(
            theme: VtsaTheme.light(),
            home: RepaintBoundary(
              key: const ValueKey('driver-preview'),
              child: ChargingAccountsScreen(dependencies: setup.dependencies),
            ),
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text('Lumina fleet').first);
        await tester.pumpAndSettle();
        expect(accounts.accountId, 'fleet');
        expect(find.text('Invite member'), findsNothing);
        expect(find.text('Account wallet'), findsNothing);
        expect(find.text('Set or remove a cap'), findsNothing);
        expect(tester.takeException(), isNull);
        await preview(tester, 'charging-accounts-${width.toInt()}');
        await tester.pumpWidget(const SizedBox.shrink());
        setup.dependencies.dispose();
      },
    );
  }
}

class ScopeAdapter implements HttpClientAdapter {
  final calls = <RequestOptions>[];
  @override
  void close({bool force = false}) {}
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    calls.add(options);
    return ResponseBody.fromString(
      jsonEncode({
        'data': options.path == '/api/v1/vehicles'
            ? <Map<String, dynamic>>[]
            : options.path == '/api/v1/wallet'
            ? <String, dynamic>{
                'book': 'simulated',
                'topups_enabled': false,
                'available_minor':
                    options.headers['X-Charging-Account-ID'] == 'business'
                    ? 222
                    : 111,
                'reserved_minor': 0,
                'minimum_minor': 100,
                'maximum_minor': 100000,
                'history': {
                  'items': <Map<String, dynamic>>[],
                  'next_cursor': null,
                },
              }
            : options.path == '/api/v1/wallet/topups' && options.method == 'GET'
            ? <String, dynamic>{
                'items': <Map<String, dynamic>>[],
                'next_cursor': null,
              }
            : <String, dynamic>{},
      }),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }
}
