import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

final class BootstrapController extends ChangeNotifier {
  BootstrapController(this._preferences);

  static const _onboardingKey = 'onboarding_complete_v1';
  final SharedPreferences _preferences;
  bool _ready = false;
  bool _onboardingComplete = false;

  bool get isReady => _ready;
  bool get onboardingComplete => _onboardingComplete;

  Future<void> initialize() async {
    _onboardingComplete = _preferences.getBool(_onboardingKey) ?? false;
    _ready = true;
    notifyListeners();
  }

  Future<void> finishOnboarding() async {
    await _preferences.setBool(_onboardingKey, true);
    _onboardingComplete = true;
    notifyListeners();
  }
}
