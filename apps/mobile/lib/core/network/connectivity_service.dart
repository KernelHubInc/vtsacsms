import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';

abstract interface class ConnectivityService {
  Stream<bool> get changes;
  Future<bool> get isOnline;
}

final class DeviceConnectivityService implements ConnectivityService {
  DeviceConnectivityService(this._connectivity);

  final Connectivity _connectivity;

  @override
  Stream<bool> get changes => _connectivity.onConnectivityChanged.map(
    (results) => !results.contains(ConnectivityResult.none),
  );

  @override
  Future<bool> get isOnline async => !(await _connectivity.checkConnectivity())
      .contains(ConnectivityResult.none);
}

final class NetworkState extends ChangeNotifier {
  NetworkState(this._service);

  final ConnectivityService _service;
  StreamSubscription<bool>? _subscription;
  bool _online = true;

  bool get isOnline => _online;

  Future<void> start() async {
    _online = await _service.isOnline;
    _subscription = _service.changes.distinct().listen((online) {
      _online = online;
      notifyListeners();
    });
  }

  @override
  void dispose() {
    _subscription?.cancel();
    super.dispose();
  }
}

final class AlwaysOnlineConnectivity implements ConnectivityService {
  const AlwaysOnlineConnectivity();

  @override
  Stream<bool> get changes => const Stream<bool>.empty();

  @override
  Future<bool> get isOnline async => true;
}
