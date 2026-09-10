import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/ids/ulid_generator.dart';
import 'package:vtsa_mobile/core/network/connectivity_service.dart';
import 'package:vtsa_mobile/core/platform/platform_services.dart';
import 'package:vtsa_mobile/features/charging/data/active_session_store.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_repository.dart';

final class ChargingController extends ChangeNotifier {
  factory ChargingController({
    required ChargingRepository repository,
    required ActiveSessionStore store,
    required SessionRealtimeGateway realtime,
    required NetworkState network,
    required PushRegistrationService push,
    required String Function() ownerId,
    required bool Function() isAuthenticated,
    UlidGenerator? ids,
    DateTime Function()? now,
    Duration pollInterval = const Duration(seconds: 5),
  }) => ChargingController._(
    repository,
    store,
    realtime,
    network,
    push,
    ownerId,
    isAuthenticated,
    ids ?? UlidGenerator(),
    now ?? DateTime.now,
    pollInterval,
  );

  ChargingController._(
    this._repository,
    this._store,
    this._realtime,
    this._network,
    this._push,
    this._ownerId,
    this._isAuthenticated,
    this._ids,
    this._now,
    this._pollInterval,
  ) {
    _network.addListener(_onNetworkChanged);
    _pushSubscription = _push.events.listen(_onPush);
  }

  final ChargingRepository _repository;
  final ActiveSessionStore _store;
  final SessionRealtimeGateway _realtime;
  final NetworkState _network;
  final PushRegistrationService _push;
  final String Function() _ownerId;
  final bool Function() _isAuthenticated;
  final UlidGenerator _ids;
  final DateTime Function() _now;
  final Duration _pollInterval;

  ChargingFlowPhase _phase = ChargingFlowPhase.idle;
  ChargePreparation? _preparation;
  PaymentMethodSummary? _selectedPaymentMethod;
  ChargingSession? _session;
  AppFailure? _failure;
  bool _mutationInFlight = false;
  bool _syncing = false;
  bool _realtimeConnected = false;
  String? _startIdempotencyKey;
  String? _stopIdempotencyKey;
  Timer? _pollTimer;
  Timer? _deadlineTimer;
  StreamSubscription<ChargingSession>? _realtimeSubscription;
  StreamSubscription<PushNotificationEvent>? _pushSubscription;
  String? _syncedSessionId;
  List<ChargingSession> _history = const [];
  String? _historyCursor;
  bool _historyLoading = false;

  ChargingFlowPhase get phase => _phase;
  ChargePreparation? get preparation => _preparation;
  PaymentMethodSummary? get selectedPaymentMethod => _selectedPaymentMethod;
  ChargingSession? get session => _session;
  AppFailure? get failure => _failure;
  bool get mutationInFlight => _mutationInFlight;
  bool get syncing => _syncing;
  bool get realtimeConnected => _realtimeConnected;
  bool get isOnline => _network.isOnline;
  List<ChargingSession> get history => _history;
  bool get historyLoading => _historyLoading;
  bool get hasMoreHistory => _historyCursor != null;

  Future<void> restoreAuthoritativeSession() async {
    if (!_isAuthenticated() || !_network.isOnline) {
      return;
    }
    final reference = await _store.read(_ownerId());
    try {
      final recovered = reference == null
          ? await _repository.activeSession()
          : await _repository.getSession(reference.sessionId);
      if (recovered == null && reference != null) {
        await _store.clear(_ownerId());
        return;
      }
      if (recovered != null) {
        await _applySession(recovered);
        if (reference != null &&
            recovered.isTerminal &&
            _phase != ChargingFlowPhase.paymentProcessing) {
          final active = await _repository.activeSession();
          if (active != null && active.id != recovered.id) {
            await _applySession(active);
          }
        }
      }
    } on AppFailure catch (failure) {
      if (failure.kind == FailureKind.notFound && reference != null) {
        await _store.clear(_ownerId());
      } else {
        _failure = failure;
        notifyListeners();
      }
    }
  }

  Future<bool> prepare({required String rawCode, String? vehicleId}) async {
    if (!_network.isOnline || _mutationInFlight) {
      return false;
    }
    ChargerCode code;
    try {
      code = ChargerCode.parse(rawCode);
    } on FormatException catch (error) {
      _failure = AppFailure(
        kind: FailureKind.validation,
        message: error.message.toString(),
      );
      notifyListeners();
      return false;
    }

    _phase = ChargingFlowPhase.resolvingCode;
    _failure = null;
    _mutationInFlight = true;
    notifyListeners();
    try {
      final result = await _repository.prepare(
        code: code,
        vehicleId: vehicleId,
      );
      _preparation = result;
      _selectedPaymentMethod = result.paymentMethods
          .where((method) => method.isDefault)
          .firstOrNull;
      _startIdempotencyKey = 'mobile-start-${_ids.next()}';
      _phase = ChargingFlowPhase.reviewing;
      return true;
    } on AppFailure catch (failure) {
      _failure = failure;
      _phase = ChargingFlowPhase.failed;
      return false;
    } finally {
      _mutationInFlight = false;
      notifyListeners();
    }
  }

  void selectPaymentMethod(PaymentMethodSummary method) {
    if (_preparation?.paymentMethods.any((item) => item.id == method.id) !=
        true) {
      return;
    }
    _selectedPaymentMethod = method;
    notifyListeners();
  }

  Future<bool> start() async {
    final preparation = _preparation;
    final method = _selectedPaymentMethod;
    if (!_network.isOnline ||
        _mutationInFlight ||
        preparation == null ||
        method == null) {
      return false;
    }
    if (!preparation.target.canStart) {
      _failure = const AppFailure(
        kind: FailureKind.validation,
        code: 'connector_unavailable',
        message: 'The connector is no longer available.',
      );
      notifyListeners();
      return false;
    }
    if (!preparation.quote.expiresAt.isAfter(_now().toUtc())) {
      _failure = const AppFailure(
        kind: FailureKind.validation,
        code: 'quote_expired',
        message: 'The tariff review expired. Scan the charger again.',
      );
      notifyListeners();
      return false;
    }

    _mutationInFlight = true;
    _phase = ChargingFlowPhase.submittingStart;
    _failure = null;
    notifyListeners();
    try {
      final authoritative = await _repository.start(
        StartChargingRequest(
          connectorId: preparation.target.connectorId,
          quoteId: preparation.quote.id,
          paymentMethodId: method.id,
          idempotencyKey: _startIdempotencyKey!,
        ),
      );
      await _applySession(authoritative);
      return true;
    } on AppFailure catch (failure) {
      _failure = failure;
      if (failure.code == 'session_already_active') {
        await restoreAuthoritativeSession();
      } else {
        _phase = ChargingFlowPhase.failed;
      }
      return false;
    } finally {
      _mutationInFlight = false;
      notifyListeners();
    }
  }

  Future<bool> stop() async {
    final current = _session;
    if (!_network.isOnline ||
        _mutationInFlight ||
        current == null ||
        !current.canRequestRemoteStop) {
      return false;
    }
    _stopIdempotencyKey ??= 'mobile-stop-${_ids.next()}';
    _mutationInFlight = true;
    _phase = ChargingFlowPhase.stopPending;
    _failure = null;
    notifyListeners();
    try {
      final authoritative = await _repository.stop(
        sessionId: current.id,
        idempotencyKey: _stopIdempotencyKey!,
      );
      await _applySession(authoritative);
      return true;
    } on AppFailure catch (failure) {
      _failure = failure;
      _phase = current.isPhysicalActive
          ? ChargingFlowPhase.live
          : _phaseFor(current);
      return false;
    } finally {
      _mutationInFlight = false;
      notifyListeners();
    }
  }

  Future<bool> cancelPendingStart() async {
    final current = _session;
    if (!_network.isOnline ||
        _mutationInFlight ||
        current == null ||
        current.isPhysicalActive) {
      return false;
    }
    _mutationInFlight = true;
    notifyListeners();
    try {
      final authoritative = await _repository.cancelStart(
        sessionId: current.id,
        reason: 'customer_cancelled_before_start',
      );
      await _applySession(authoritative);
      return true;
    } on AppFailure catch (failure) {
      _failure = failure;
      return false;
    } finally {
      _mutationInFlight = false;
      notifyListeners();
    }
  }

  Future<void> refresh() async {
    final current = _session;
    if (current == null || !_network.isOnline || _syncing) {
      return;
    }
    _syncing = true;
    notifyListeners();
    try {
      await _applySession(await _repository.getSession(current.id));
    } on AppFailure catch (failure) {
      _failure = failure;
    } finally {
      _syncing = false;
      notifyListeners();
    }
  }

  Future<void> recoverSession(String sessionId) async {
    if (!_isAuthenticated() || !_network.isOnline || _syncing) {
      return;
    }
    _syncing = true;
    notifyListeners();
    try {
      await _applySession(await _repository.getSession(sessionId));
    } on AppFailure catch (failure) {
      _failure = failure;
    } finally {
      _syncing = false;
      notifyListeners();
    }
  }

  Future<ChargingSession?> fetchSessionDetail(String sessionId) async {
    final current = _session;
    if (current?.id == sessionId) {
      await refresh();
      return _session;
    }
    if (!_isAuthenticated() || !_network.isOnline) {
      return null;
    }
    try {
      return await _repository.getSession(sessionId);
    } on AppFailure catch (failure) {
      _failure = failure;
      notifyListeners();
      return null;
    }
  }

  Future<void> loadHistory({bool nextPage = false}) async {
    if (!_isAuthenticated() || _historyLoading || !_network.isOnline) {
      return;
    }
    _historyLoading = true;
    notifyListeners();
    try {
      final page = await _repository.history(
        cursor: nextPage ? _historyCursor : null,
      );
      _history = nextPage ? [..._history, ...page.sessions] : page.sessions;
      _historyCursor = page.nextCursor;
    } on AppFailure catch (failure) {
      _failure = failure;
    } finally {
      _historyLoading = false;
      notifyListeners();
    }
  }

  Future<bool> requestRefund(String reason, {String? sessionId}) async =>
      _runSessionMutation(
        (sessionId, idempotencyKey) => _repository.requestRefund(
          sessionId: sessionId,
          reason: reason,
          idempotencyKey: idempotencyKey,
        ),
        prefix: 'mobile-refund',
        sessionId: sessionId,
      );

  Future<bool> reportIssue({
    String? sessionId,
    required String category,
    required String description,
  }) => _runSessionMutation(
    (sessionId, idempotencyKey) => _repository.reportIssue(
      sessionId: sessionId,
      category: category,
      description: description,
      idempotencyKey: idempotencyKey,
    ),
    prefix: 'mobile-issue',
    sessionId: sessionId,
  );

  Future<bool> _runSessionMutation(
    Future<void> Function(String sessionId, String key) operation, {
    required String prefix,
    String? sessionId,
  }) async {
    final targetSessionId = sessionId ?? _session?.id;
    if (targetSessionId == null || !_network.isOnline || _mutationInFlight) {
      return false;
    }
    _mutationInFlight = true;
    _failure = null;
    notifyListeners();
    try {
      await operation(targetSessionId, '$prefix-${_ids.next()}');
      return true;
    } on AppFailure catch (failure) {
      _failure = failure;
      return false;
    } finally {
      _mutationInFlight = false;
      notifyListeners();
    }
  }

  void resetStartFlow() {
    if (_session?.isPhysicalActive == true) {
      return;
    }
    _preparation = null;
    _selectedPaymentMethod = null;
    _startIdempotencyKey = null;
    _failure = null;
    if (_session == null || _session!.isTerminal) {
      _phase = ChargingFlowPhase.idle;
    }
    notifyListeners();
  }

  Future<void> onResumed() => restoreAuthoritativeSession();

  Future<void> onSignedOut() async {
    await _store.clear(_ownerId());
    _stopSync();
    _session = null;
    _preparation = null;
    _selectedPaymentMethod = null;
    _phase = ChargingFlowPhase.idle;
    _failure = null;
    notifyListeners();
  }

  Future<void> _applySession(ChargingSession authoritative) async {
    final current = _session;
    if (current != null &&
        current.id == authoritative.id &&
        authoritative.aggregateVersion < current.aggregateVersion) {
      return;
    }
    _session = authoritative;
    _phase = _phaseFor(authoritative);
    final keepForRecovery =
        !authoritative.isTerminal ||
        _phase == ChargingFlowPhase.paymentProcessing;
    if (keepForRecovery) {
      await _store.write(
        ActiveSessionReference(
          ownerId: _ownerId(),
          sessionId: authoritative.id,
          savedAt: _now().toUtc(),
        ),
      );
      _startSync(authoritative.id);
    } else {
      await _store.clear(_ownerId());
      _stopSync();
    }
    _scheduleDeadline(authoritative);
    notifyListeners();
  }

  ChargingFlowPhase _phaseFor(ChargingSession value) {
    if (value.state == ChargingSessionState.completed) {
      return switch (value.paymentState) {
        PaymentState.notRequired ||
        PaymentState.captured ||
        PaymentState.partiallyRefunded ||
        PaymentState.refunded => ChargingFlowPhase.completed,
        _ => ChargingFlowPhase.paymentProcessing,
      };
    }
    return switch (value.state) {
      ChargingSessionState.requested ||
      ChargingSessionState.authorizing ||
      ChargingSessionState.authorized ||
      ChargingSessionState.starting => ChargingFlowPhase.startPending,
      ChargingSessionState.charging ||
      ChargingSessionState.suspendedByEv ||
      ChargingSessionState.suspendedByEvse => ChargingFlowPhase.live,
      ChargingSessionState.stopping => ChargingFlowPhase.stopPending,
      ChargingSessionState.finalizing ||
      ChargingSessionState.reviewRequired => ChargingFlowPhase.finalizing,
      ChargingSessionState.failed ||
      ChargingSessionState.cancelled ||
      ChargingSessionState.expired => ChargingFlowPhase.failed,
      ChargingSessionState.completed => ChargingFlowPhase.completed,
    };
  }

  void _scheduleDeadline(ChargingSession value) {
    _deadlineTimer?.cancel();
    final deadline = value.startDeadlineAt;
    if (_phase != ChargingFlowPhase.startPending || deadline == null) {
      return;
    }
    final delay = deadline.difference(_now().toUtc());
    if (delay <= Duration.zero) {
      _markStartTimedOut();
      return;
    }
    _deadlineTimer = Timer(delay, _markStartTimedOut);
  }

  void _markStartTimedOut() {
    final current = _session;
    if (current != null &&
        const {
          ChargingSessionState.requested,
          ChargingSessionState.authorizing,
          ChargingSessionState.authorized,
          ChargingSessionState.starting,
        }.contains(current.state)) {
      _phase = ChargingFlowPhase.startTimedOut;
      notifyListeners();
    }
  }

  void _startSync(String sessionId) {
    if (_syncedSessionId != null && _syncedSessionId != sessionId) {
      unawaited(_realtimeSubscription?.cancel());
      _realtimeSubscription = null;
      _realtimeConnected = false;
    }
    if (_realtimeSubscription == null) {
      _syncedSessionId = sessionId;
      _realtimeSubscription = _realtime
          .watch(sessionId)
          .listen(
            (update) {
              _realtimeConnected = true;
              unawaited(_applySession(update));
            },
            onError: (Object _) {
              _realtimeConnected = false;
              notifyListeners();
            },
            onDone: () {
              _realtimeConnected = false;
              notifyListeners();
            },
          );
    }
    _pollTimer ??= Timer.periodic(_pollInterval, (_) => unawaited(refresh()));
  }

  void _stopSync() {
    _pollTimer?.cancel();
    _pollTimer = null;
    _deadlineTimer?.cancel();
    _deadlineTimer = null;
    unawaited(_realtimeSubscription?.cancel());
    _realtimeSubscription = null;
    _syncedSessionId = null;
    _realtimeConnected = false;
  }

  void _onNetworkChanged() {
    if (_network.isOnline) {
      unawaited(restoreAuthoritativeSession());
    }
    notifyListeners();
  }

  void _onPush(PushNotificationEvent event) {
    final current = _session;
    if (current == null) {
      unawaited(restoreAuthoritativeSession());
    } else if (current.id == event.sessionId) {
      unawaited(refresh());
    } else if (current.isTerminal) {
      unawaited(recoverSession(event.sessionId));
    }
  }

  @override
  void dispose() {
    _network.removeListener(_onNetworkChanged);
    _stopSync();
    unawaited(_pushSubscription?.cancel());
    super.dispose();
  }
}
