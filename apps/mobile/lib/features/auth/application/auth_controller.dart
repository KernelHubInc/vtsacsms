import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/data/quick_unlock_store.dart';
import 'package:vtsa_mobile/features/auth/domain/auth_repository.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';

enum AuthStatus { checking, guest, locked, authenticated }

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
  bool _verificationRequired = false;
  RegistrationResult? _registration;
  bool _passwordSignIn = false;
  QuickUnlockStore? get quickUnlock =>
      _tokens is QuickUnlockStore ? _tokens : null;
  bool get canConfigureQuickUnlock =>
      _passwordSignIn &&
      _status == AuthStatus.authenticated &&
      !needsEmailVerification;

  AuthStatus get status => _status;
  UserProfile? get user => _user;
  AppFailure? get failure => _failure;
  bool get isBusy => _busy;
  bool get needsEmailVerification =>
      _status == AuthStatus.authenticated &&
      (_verificationRequired || _user?.emailVerified != true);
  RegistrationResult? get registration => _registration;

  Future<void> restore() async {
    try {
      await quickUnlock?.initialize();
    } on Object {
      _status = AuthStatus.locked;
      _failure = const AppFailure(
        kind: FailureKind.unknown,
        message:
            'Saved sign-in could not be read. Try again or sign in with your password.',
      );
      notifyListeners();
      return;
    }
    if (quickUnlock?.locked == true) {
      _status = AuthStatus.locked;
      notifyListeners();
      return;
    }
    if (await _tokens.read() == null) {
      _user = null;
      _verificationRequired = false;
      _status = AuthStatus.guest;
      notifyListeners();
      return;
    }
    try {
      _user = await _repository.currentUser();
      _verificationRequired = !_user!.emailVerified;
      _status = AuthStatus.authenticated;
    } on AppFailure catch (failure) {
      if (failure.code == 'email_unverified') {
        _verificationRequired = true;
        _status = AuthStatus.authenticated;
      } else if (failure.kind == FailureKind.offline ||
          failure.kind == FailureKind.timeout) {
        _status = AuthStatus.authenticated;
      } else {
        await _tokens.clear();
        _user = null;
        _verificationRequired = false;
        _status = AuthStatus.guest;
      }
    }
    notifyListeners();
  }

  Future<bool> login({required String email, required String password}) async {
    if (_busy) return false;
    _setBusy(true);
    try {
      final result = await _repository.login(email: email, password: password);
      await _tokens.write(result.tokens);
      _user = result.user;
      _verificationRequired = !result.user.emailVerified;
      _status = AuthStatus.authenticated;
      _passwordSignIn = true;
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
    String? firstName,
    String? middleName,
    String? lastName,
    String? birthDate,
    String? plateNumber,
    bool platePending = false,
  }) async {
    _setBusy(true);
    _registration = null;
    try {
      _registration = await _repository.register(
        name: name,
        email: email,
        password: password,
        firstName: firstName,
        middleName: middleName,
        lastName: lastName,
        birthDate: birthDate,
        plateNumber: plateNumber,
        platePending: platePending,
      );
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
      _passwordSignIn = false;
      _verificationRequired = false;
      _status = AuthStatus.guest;
      _setBusy(false);
    }
  }

  Future<bool> refreshVerification() async {
    _setBusy(true);
    try {
      _user = await _repository.currentUser();
      _verificationRequired = !_user!.emailVerified;
      _status = AuthStatus.authenticated;
      if (_verificationRequired) {
        _failure = const AppFailure(
          kind: FailureKind.forbidden,
          code: 'email_unverified',
          message: 'Email verification is required.',
        );
      }
      return !_verificationRequired;
    } on AppFailure catch (failure) {
      if (failure.kind == FailureKind.unauthenticated) {
        await _tokens.clear();
        _user = null;
        _verificationRequired = false;
        _status = AuthStatus.guest;
      } else if (failure.code == 'email_unverified') {
        _verificationRequired = true;
      }
      _failure = failure;
      return false;
    } finally {
      _setBusy(false);
    }
  }

  void sessionExpired() {
    _passwordSignIn = false;
    _user = null;
    _verificationRequired = false;
    _status = AuthStatus.guest;
    _failure = const AppFailure(
      kind: FailureKind.unauthenticated,
      message: 'Your session has ended. Sign in again to continue.',
    );
    notifyListeners();
  }

  void lock() {
    final unlock = quickUnlock;
    if (unlock == null) return;
    unlock.lock();
    _passwordSignIn = false;
    if (!unlock.enabled || _status == AuthStatus.guest) {
      notifyListeners();
      return;
    }
    _user = null;
    _status = AuthStatus.locked;
    _failure = null;
    notifyListeners();
  }

  Future<bool> configureQuickUnlock(
    String pin, {
    required bool biometrics,
  }) async {
    if (_busy || !canConfigureQuickUnlock || quickUnlock == null) return false;
    _setBusy(true);
    try {
      await quickUnlock!.configure(pin, biometrics: biometrics);
      if (quickUnlock!.locked) lock();
      return quickUnlock!.enabled;
    } on AppFailure catch (failure) {
      _failure = failure;
      return false;
    } on Object {
      _failure = const AppFailure(
        kind: FailureKind.unknown,
        message: 'Quick unlock could not be set up. Please try again.',
      );
      return false;
    } finally {
      _setBusy(false);
    }
  }

  Future<bool> unlock({String? pin}) async {
    final unlock = quickUnlock;
    if (_busy || unlock == null || _status != AuthStatus.locked) return false;
    _setBusy(true);
    final generation = unlock.generation;
    try {
      if (!await unlock.unlock(pin: pin)) {
        _failure = const AppFailure(
          kind: FailureKind.forbidden,
          message:
              'Could not unlock. Try your app PIN or sign in with your password.',
        );
        return false;
      }
      // Local proof never overrides expiry, revocation, membership or verification.
      final user = await _repository.currentUser();
      if (generation != unlock.generation) return false;
      _user = user;
      _verificationRequired = !user.emailVerified;
      _status = AuthStatus.authenticated;
      return true;
    } on AppFailure catch (failure) {
      if (failure.code == 'email_unverified' &&
          generation == unlock.generation) {
        _verificationRequired = true;
        _status = AuthStatus.authenticated;
        return true;
      }
      if (failure.kind == FailureKind.unauthenticated ||
          failure.kind == FailureKind.forbidden ||
          !unlock.enabled) {
        await unlock.clear();
        _status = AuthStatus.guest;
      }
      _failure = failure;
      return false;
    } on Object {
      _failure = const AppFailure(
        kind: FailureKind.unknown,
        message:
            'Unlock is unavailable. Try your app PIN or sign in with your password.',
      );
      return false;
    } finally {
      if (_status != AuthStatus.authenticated) unlock.lock();
      _setBusy(false);
    }
  }

  Future<void> usePassword() async {
    if (_busy) return;
    _setBusy(true);
    try {
      await _tokens.clear();
      _user = null;
      _passwordSignIn = false;
      _verificationRequired = false;
      _status = AuthStatus.guest;
    } on Object {
      _failure = const AppFailure(
        kind: FailureKind.unknown,
        message: 'Could not clear this session. Please try again.',
      );
    } finally {
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
