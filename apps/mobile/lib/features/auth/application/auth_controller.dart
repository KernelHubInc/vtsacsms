import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/domain/auth_repository.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';

enum AuthStatus { checking, guest, authenticated }

final class AuthController extends ChangeNotifier {
  factory AuthController({
    required AuthRepository repository,
    required TokenStore tokens,
  }) => AuthController._(repository, tokens);

  AuthController._(this._repository, this._tokens);

  final AuthRepository _repository;
  final TokenStore _tokens;
  AuthStatus _status = AuthStatus.checking;
  UserProfile? _user;
  AppFailure? _failure;
  bool _busy = false;

  AuthStatus get status => _status;
  UserProfile? get user => _user;
  AppFailure? get failure => _failure;
  bool get isBusy => _busy;

  Future<void> restore() async {
    if (await _tokens.read() == null) {
      _status = AuthStatus.guest;
      notifyListeners();
      return;
    }
    try {
      _user = await _repository.currentUser();
      _status = AuthStatus.authenticated;
    } on AppFailure catch (failure) {
      if (failure.kind == FailureKind.offline ||
          failure.kind == FailureKind.timeout) {
        _status = AuthStatus.authenticated;
      } else {
        await _tokens.clear();
        _status = AuthStatus.guest;
      }
    }
    notifyListeners();
  }

  Future<bool> login({required String email, required String password}) async {
    _setBusy(true);
    try {
      final result = await _repository.login(email: email, password: password);
      await _tokens.write(result.tokens);
      _user = result.user;
      _status = AuthStatus.authenticated;
      return true;
    } on AppFailure catch (failure) {
      _failure = failure;
      return false;
    } on StateError catch (error) {
      _failure = AppFailure(kind: FailureKind.server, message: error.message);
      return false;
    } finally {
      _setBusy(false);
    }
  }

  Future<bool> register({
    required String name,
    required String email,
    required String password,
  }) async {
    _setBusy(true);
    try {
      await _repository.register(name: name, email: email, password: password);
      return true;
    } on AppFailure catch (failure) {
      _failure = failure;
      return false;
    } finally {
      _setBusy(false);
    }
  }

  Future<bool> requestPasswordReset(String email) async {
    _setBusy(true);
    try {
      await _repository.requestPasswordReset(email);
      return true;
    } on AppFailure catch (failure) {
      _failure = failure;
      return false;
    } finally {
      _setBusy(false);
    }
  }

  Future<bool> resendVerification() async {
    _setBusy(true);
    try {
      await _repository.resendEmailVerification();
      return true;
    } on AppFailure catch (failure) {
      _failure = failure;
      return false;
    } finally {
      _setBusy(false);
    }
  }

  Future<void> logout() async {
    _setBusy(true);
    try {
      await _repository.logout();
    } finally {
      await _tokens.clear();
      _user = null;
      _status = AuthStatus.guest;
      _setBusy(false);
    }
  }

  void _setBusy(bool value) {
    _busy = value;
    if (value) {
      _failure = null;
    }
    notifyListeners();
  }
}
