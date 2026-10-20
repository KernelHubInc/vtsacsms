import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/network/connectivity_service.dart';
import 'package:vtsa_mobile/core/platform/platform_services.dart';
import 'package:vtsa_mobile/features/charging/application/charging_controller.dart';
import 'package:vtsa_mobile/features/charging/data/active_session_store.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_repository.dart';

import 'charging_controller_test.dart' show MutableConnectivity;
import 'support/fakes.dart';

void main() {
  late FakeChargingRepository repository;
  late MemoryActiveSessionStore store;
  late ChargingController controller;
  late String owner;

  setUp(() async {
    repository = FakeChargingRepository();
    store = MemoryActiveSessionStore();
    owner = 'driver-a';
    final connectivity = MutableConnectivity();
    final network = NetworkState(connectivity);
    await network.start();
    controller = ChargingController(
      repository: repository,
      store: store,
      realtime: const NoopSessionRealtimeGateway(),
      network: network,
      push: const NoopPushRegistrationService(),
      ownerId: () => owner,
      isAuthenticated: () => true,
      now: () => DateTime.utc(2026, 7, 26, 3),
      pollInterval: const Duration(hours: 1),
    );
    addTearDown(() async {
      controller.dispose();
      network.dispose();
      await connectivity.dispose();
    });
  });

  test(
    'lost Start response recovers the server session without a second start',
    () async {
      repository.startFailure = const AppFailure(
        kind: FailureKind.timeout,
        message: 'Timed out',
      );
      repository.active = sampleChargingSession(
        state: ChargingSessionState.charging,
      );
      await controller.prepare(rawCode: 'VSTA-ABC_123');
      expect(await controller.start(), isTrue);
      expect(controller.phase, ChargingFlowPhase.live);
      expect(repository.startCalls, 1);
      expect(store.reference?.sessionId, repository.active!.id);
    },
  );

  test(
    'missing saved reference immediately recovers the current session',
    () async {
      store.reference = ActiveSessionReference(
        ownerId: owner,
        sessionId: 'obsolete',
        savedAt: DateTime.utc(2026),
      );
      repository.getSessionFailure = const AppFailure(
        kind: FailureKind.notFound,
        message: 'Not found',
      );
      repository.active = sampleChargingSession(
        state: ChargingSessionState.charging,
      );
      await controller.restoreAuthoritativeSession();
      expect(controller.session?.id, repository.active!.id);
      expect(controller.phase, ChargingFlowPhase.live);
    },
  );

  test('Stop retries keep a key but the next session uses a new key', () async {
    repository.active = sampleChargingSession(
      id: 'first',
      state: ChargingSessionState.charging,
    );
    await controller.restoreAuthoritativeSession();
    repository.stopFailure = const AppFailure(
      kind: FailureKind.timeout,
      message: 'Timed out',
    );
    await controller.stop();
    final firstKey = repository.lastStopIdempotencyKey;
    await controller.stop();
    expect(repository.lastStopIdempotencyKey, firstKey);
    repository.sessions['second'] = sampleChargingSession(
      id: 'second',
      state: ChargingSessionState.charging,
    );
    await controller.recoverSession('second');
    await controller.stop();
    expect(repository.lastStopIdempotencyKey, isNot(firstKey));
  });

  test(
    'late Start response cannot appear after sign-out and another sign-in',
    () async {
      repository.startCompleter = Completer<ChargingSession>();
      await controller.prepare(rawCode: 'VSTA-ABC_123');
      final pending = controller.start();
      await controller.onSignedOut();
      owner = 'driver-b';
      repository.startCompleter!.complete(sampleChargingSession());
      expect(await pending, isFalse);
      expect(controller.session, isNull);
      expect(store.reference, isNull);
      expect(controller.mutationInFlight, isFalse);
    },
  );

  test('late history cannot expose the previous account sessions', () async {
    repository.historyCompleter = Completer<ChargingHistoryPage>();
    final pending = controller.loadHistory();
    await controller.onSignedOut();
    owner = 'driver-b';
    repository.historyCompleter!.complete(
      ChargingHistoryPage(
        sessions: [sampleChargingSession()],
        nextCursor: 'private-cursor',
      ),
    );
    await pending;
    expect(controller.history, isEmpty);
    expect(controller.hasMoreHistory, isFalse);
    expect(controller.historyLoading, isFalse);
  });

  test(
    'session expiry clears cached charging data without pressing Sign out',
    () async {
      final fixture = await buildTestDependencies(signedIn: true);
      addTearDown(fixture.dependencies.dispose);
      fixture.charging.active = sampleChargingSession(
        state: ChargingSessionState.charging,
      );
      fixture.charging.historyResult = ChargingHistoryPage(
        sessions: [sampleChargingSession()],
        nextCursor: null,
      );
      await fixture.dependencies.charging.restoreAuthoritativeSession();
      await fixture.dependencies.charging.loadHistory();
      fixture.dependencies.auth.sessionExpired();
      expect(fixture.dependencies.charging.session, isNull);
      expect(fixture.dependencies.charging.history, isEmpty);
    },
  );
}
