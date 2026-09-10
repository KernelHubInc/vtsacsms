import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/network/connectivity_service.dart';
import 'package:vtsa_mobile/core/platform/platform_services.dart';
import 'package:vtsa_mobile/features/charging/application/charging_controller.dart';
import 'package:vtsa_mobile/features/charging/data/active_session_store.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_repository.dart';

import 'support/fakes.dart';

void main() {
  late FakeChargingRepository repository;
  late MemoryActiveSessionStore store;
  late MutableConnectivity connectivity;
  late NetworkState network;
  late FakeRealtimeGateway realtime;
  late FakePushRegistrationService push;
  late ChargingController controller;

  setUp(() async {
    repository = FakeChargingRepository();
    store = MemoryActiveSessionStore();
    connectivity = MutableConnectivity();
    network = NetworkState(connectivity);
    await network.start();
    realtime = FakeRealtimeGateway();
    push = FakePushRegistrationService();
    controller = ChargingController(
      repository: repository,
      store: store,
      realtime: realtime,
      network: network,
      push: push,
      ownerId: () => 'driver-1',
      isAuthenticated: () => true,
      now: () => DateTime.utc(2026, 7, 26, 3),
      pollInterval: const Duration(hours: 1),
    );
  });

  tearDown(() async {
    controller.dispose();
    network.dispose();
    await connectivity.dispose();
    await realtime.dispose();
    await push.dispose();
  });

  test('manual or scanned code resolves a server preparation', () async {
    expect(await controller.prepare(rawCode: 'VSTA-ABC_123'), isTrue);

    expect(repository.prepareCalls, 1);
    expect(controller.phase, ChargingFlowPhase.reviewing);
    expect(controller.preparation, sampleChargePreparation);
    expect(
      controller.selectedPaymentMethod,
      sampleChargePreparation.paymentMethods.single,
    );
  });

  test('connector becoming unavailable prevents the start request', () async {
    repository.preparation = _preparationWith(
      readiness: ConnectorReadiness.occupied,
    );
    await controller.prepare(rawCode: 'VSTA-ABC_123');

    expect(await controller.start(), isFalse);
    expect(repository.startCalls, 0);
    expect(controller.failure?.code, 'connector_unavailable');
  });

  test(
    'duplicate taps produce one start request and one idempotency key',
    () async {
      repository.startCompleter = Completer<ChargingSession>();
      await controller.prepare(rawCode: 'VSTA-ABC_123');

      final first = controller.start();
      final second = await controller.start();

      expect(second, isFalse);
      expect(repository.startCalls, 1);
      expect(
        repository.lastStartRequest?.idempotencyKey,
        startsWith('mobile-start-'),
      );

      repository.startCompleter!.complete(sampleChargingSession());
      expect(await first, isTrue);
      expect(controller.phase, ChargingFlowPhase.startPending);
    },
  );

  test(
    'accepted remote start remains pending until physical evidence',
    () async {
      await _start(controller);

      expect(controller.phase, ChargingFlowPhase.startPending);

      realtime.emit(
        sampleChargingSession(
          state: ChargingSessionState.charging,
          energyWh: 140,
          durationSeconds: 9,
          currentPowerW: 52000,
          aggregateVersion: 2,
        ),
      );
      await _flush();

      expect(controller.phase, ChargingFlowPhase.live);
      expect(controller.session?.energyWh, 140);
      expect(controller.session?.currentPowerW, 52000);
    },
  );

  test(
    'pending start crosses its server deadline without assuming failure',
    () async {
      repository.startResult = sampleChargingSession(
        startDeadlineAt: DateTime.utc(2026, 7, 26, 2, 59),
      );

      await _start(controller);

      expect(controller.phase, ChargingFlowPhase.startTimedOut);
      expect(controller.session?.state, ChargingSessionState.starting);
    },
  );

  test(
    'session-already-active response recovers another-device session',
    () async {
      repository.startFailure = const AppFailure(
        kind: FailureKind.validation,
        code: 'session_already_active',
        message: 'A session is already active.',
      );
      repository.active = sampleChargingSession(
        state: ChargingSessionState.charging,
        aggregateVersion: 8,
      );
      await controller.prepare(rawCode: 'VSTA-ABC_123');

      expect(await controller.start(), isFalse);
      expect(controller.phase, ChargingFlowPhase.live);
      expect(repository.activeCalls, 1);
    },
  );

  test(
    'duplicate stop taps produce one request and accepted is not completion',
    () async {
      await _start(controller);
      realtime.emit(
        sampleChargingSession(
          state: ChargingSessionState.charging,
          aggregateVersion: 2,
        ),
      );
      await _flush();
      repository.stopCompleter = Completer<ChargingSession>();

      final first = controller.stop();
      final second = await controller.stop();

      expect(second, isFalse);
      expect(repository.stopCalls, 1);
      expect(repository.lastStopIdempotencyKey, startsWith('mobile-stop-'));

      repository.stopCompleter!.complete(
        sampleChargingSession(
          state: ChargingSessionState.stopping,
          aggregateVersion: 3,
        ),
      );
      expect(await first, isTrue);
      expect(controller.phase, ChargingFlowPhase.stopPending);
    },
  );

  test(
    'local charger stop and delayed payment follow server transitions',
    () async {
      await _start(controller);
      realtime.emit(
        sampleChargingSession(
          state: ChargingSessionState.charging,
          aggregateVersion: 2,
        ),
      );
      await _flush();

      realtime.emit(
        sampleChargingSession(
          state: ChargingSessionState.finalizing,
          energyWh: 9200,
          durationSeconds: 1100,
          aggregateVersion: 3,
        ),
      );
      await _flush();
      expect(controller.phase, ChargingFlowPhase.finalizing);

      final paymentPending = sampleChargingSession(
        state: ChargingSessionState.completed,
        paymentState: PaymentState.capturePending,
        energyWh: 9300,
        durationSeconds: 1110,
        aggregateVersion: 4,
        finalCost: const Money(minorUnits: 27900, currency: 'PHP'),
      );
      realtime.emit(paymentPending);
      await _flush();
      expect(controller.phase, ChargingFlowPhase.paymentProcessing);
      expect(store.reference?.sessionId, paymentPending.id);

      final paid = sampleChargingSession(
        state: ChargingSessionState.completed,
        paymentState: PaymentState.captured,
        energyWh: 9300,
        durationSeconds: 1110,
        aggregateVersion: 5,
        finalCost: const Money(minorUnits: 27900, currency: 'PHP'),
      );
      repository.sessions[paid.id] = paid;
      await controller.refresh();

      expect(controller.phase, ChargingFlowPhase.completed);
      expect(store.reference, isNull);
    },
  );

  test(
    'out-of-order realtime updates cannot regress aggregate state',
    () async {
      await _start(controller);
      realtime.emit(
        sampleChargingSession(
          state: ChargingSessionState.charging,
          aggregateVersion: 5,
        ),
      );
      await _flush();
      realtime.emit(
        sampleChargingSession(
          state: ChargingSessionState.starting,
          aggregateVersion: 4,
        ),
      );
      await _flush();

      expect(controller.phase, ChargingFlowPhase.live);
      expect(controller.session?.aggregateVersion, 5);
    },
  );

  test(
    'network loss disables mutations but preserves last server state',
    () async {
      await _start(controller);
      realtime.emit(
        sampleChargingSession(
          state: ChargingSessionState.charging,
          aggregateVersion: 2,
        ),
      );
      await _flush();

      connectivity.setOnline(false);
      await _flush();

      expect(controller.isOnline, isFalse);
      expect(await controller.stop(), isFalse);
      expect(repository.stopCalls, 0);
      expect(controller.phase, ChargingFlowPhase.live);
    },
  );

  test(
    'charger disconnect is an anomaly, not inferred session completion',
    () async {
      await _start(controller);
      realtime.emit(
        sampleChargingSession(
          state: ChargingSessionState.charging,
          anomalyFlags: const ['charger_disconnected'],
          aggregateVersion: 2,
        ),
      );
      await _flush();

      expect(controller.session?.chargerDisconnected, isTrue);
      expect(controller.phase, ChargingFlowPhase.live);
    },
  );

  test('realtime loss falls back to authoritative refresh', () async {
    await _start(controller);
    final live = sampleChargingSession(
      state: ChargingSessionState.charging,
      energyWh: 1000,
      aggregateVersion: 2,
    );
    realtime.emit(live);
    await _flush();
    expect(controller.realtimeConnected, isTrue);

    realtime.fail(live.id);
    await _flush();
    expect(controller.realtimeConnected, isFalse);
    expect(controller.phase, ChargingFlowPhase.live);

    repository.sessions[live.id] = sampleChargingSession(
      state: ChargingSessionState.charging,
      energyWh: 1800,
      aggregateVersion: 3,
    );
    await controller.refresh();
    expect(controller.session?.energyWh, 1800);
  });

  test('app resumption recovers the minimal local session reference', () async {
    final active = sampleChargingSession(
      state: ChargingSessionState.charging,
      aggregateVersion: 6,
    );
    repository.sessions[active.id] = active;
    store.reference = ActiveSessionReference(
      ownerId: 'driver-1',
      sessionId: active.id,
      savedAt: DateTime.utc(2026, 7, 26, 2),
    );

    await controller.onResumed();

    expect(controller.session?.id, active.id);
    expect(controller.phase, ChargingFlowPhase.live);
  });

  test(
    'terminal local reference falls through to another active session',
    () async {
      final old = sampleChargingSession(
        id: 'old-session',
        state: ChargingSessionState.completed,
        paymentState: PaymentState.captured,
        aggregateVersion: 3,
      );
      final active = sampleChargingSession(
        id: 'new-session',
        state: ChargingSessionState.charging,
        aggregateVersion: 2,
      );
      repository.sessions[old.id] = old;
      repository.active = active;
      store.reference = ActiveSessionReference(
        ownerId: 'driver-1',
        sessionId: old.id,
        savedAt: DateTime.utc(2026, 7, 26, 2),
      );

      await controller.restoreAuthoritativeSession();

      expect(controller.session?.id, active.id);
      expect(controller.phase, ChargingFlowPhase.live);
    },
  );

  test(
    'push for the current session retrieves authoritative updates',
    () async {
      final active = sampleChargingSession(
        state: ChargingSessionState.charging,
        aggregateVersion: 2,
      );
      repository.active = active;
      repository.sessions[active.id] = active;
      await controller.restoreAuthoritativeSession();
      repository.sessions[active.id] = sampleChargingSession(
        state: ChargingSessionState.completed,
        paymentState: PaymentState.captured,
        aggregateVersion: 3,
      );

      push.emit(
        PushNotificationEvent(
          type: PushNotificationType.sessionCompleted,
          sessionId: active.id,
        ),
      );
      await _flush();

      expect(controller.phase, ChargingFlowPhase.completed);
    },
  );

  test(
    'sign out clears account recovery state and synchronized session',
    () async {
      await _start(controller);
      expect(store.reference, isNotNull);

      await controller.onSignedOut();

      expect(store.reference, isNull);
      expect(controller.session, isNull);
      expect(controller.phase, ChargingFlowPhase.idle);
    },
  );
}

Future<void> _start(ChargingController controller) async {
  await controller.prepare(rawCode: 'VSTA-ABC_123');
  await controller.start();
}

Future<void> _flush() => Future<void>.delayed(Duration.zero);

ChargePreparation _preparationWith({required ConnectorReadiness readiness}) =>
    ChargePreparation(
      target: ChargeTarget(
        connectorId: sampleChargePreparation.target.connectorId,
        connectorName: sampleChargePreparation.target.connectorName,
        connectorStandard: sampleChargePreparation.target.connectorStandard,
        maximumPowerW: sampleChargePreparation.target.maximumPowerW,
        stationId: sampleChargePreparation.target.stationId,
        stationName: sampleChargePreparation.target.stationName,
        stationAddress: sampleChargePreparation.target.stationAddress,
        readiness: readiness,
        statusObservedAt: sampleChargePreparation.target.statusObservedAt,
      ),
      quote: sampleChargePreparation.quote,
      paymentMethods: sampleChargePreparation.paymentMethods,
    );

final class MutableConnectivity implements ConnectivityService {
  final _changes = StreamController<bool>.broadcast();
  bool online = true;

  @override
  Stream<bool> get changes => _changes.stream;

  @override
  Future<bool> get isOnline async => online;

  void setOnline(bool value) {
    online = value;
    _changes.add(value);
  }

  Future<void> dispose() => _changes.close();
}

final class FakeRealtimeGateway implements SessionRealtimeGateway {
  final _controllers = <String, StreamController<ChargingSession>>{};
  String? watchedSessionId;

  @override
  Stream<ChargingSession> watch(String sessionId) {
    watchedSessionId = sessionId;
    return _controllers
        .putIfAbsent(
          sessionId,
          () => StreamController<ChargingSession>.broadcast(),
        )
        .stream;
  }

  void emit(ChargingSession session) {
    _controllers[session.id]?.add(session);
  }

  void fail(String sessionId) {
    _controllers[sessionId]?.addError(StateError('Realtime unavailable'));
  }

  Future<void> dispose() async {
    for (final controller in _controllers.values) {
      await controller.close();
    }
  }
}

final class FakePushRegistrationService implements PushRegistrationService {
  final _events = StreamController<PushNotificationEvent>.broadcast();

  @override
  Stream<PushNotificationEvent> get events => _events.stream;

  void emit(PushNotificationEvent event) => _events.add(event);

  @override
  Future<void> register() async {}

  @override
  Future<void> unregister() async {}

  Future<void> dispose() => _events.close();
}
