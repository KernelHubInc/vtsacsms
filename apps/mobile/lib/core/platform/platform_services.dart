import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

abstract interface class AppUpdateService {
  Future<void> checkForUpdate();
}

abstract interface class AnalyticsConsentService {
  Future<bool?> readConsent();
  Future<void> setConsent(bool consented);
}

abstract interface class CrashReportingService {
  Future<void> record(Object error, StackTrace stackTrace);
}

abstract interface class PushRegistrationService {
  Stream<PushNotificationEvent> get events;
  Future<void> register();
  Future<void> unregister();
}

enum PushNotificationType {
  chargingStarted,
  chargingStopped,
  chargerFault,
  paymentUpdated,
  sessionCompleted,
}

final class PushNotificationEvent {
  const PushNotificationEvent({required this.type, required this.sessionId});

  final PushNotificationType type;
  final String sessionId;
}

abstract interface class DirectionsService {
  Future<bool> open({required double latitude, required double longitude});
}

final class LocalAnalyticsConsentService implements AnalyticsConsentService {
  LocalAnalyticsConsentService(this._preferences);

  static const _key = 'analytics_consent';
  final SharedPreferences _preferences;

  @override
  Future<bool?> readConsent() async => _preferences.getBool(_key);

  @override
  Future<void> setConsent(bool consented) async {
    await _preferences.setBool(_key, consented);
  }
}

final class ExternalDirectionsService implements DirectionsService {
  @override
  Future<bool> open({required double latitude, required double longitude}) =>
      launchUrl(
        Uri.https('www.google.com', '/maps/dir/', {
          'api': '1',
          'destination': '$latitude,$longitude',
        }),
        mode: LaunchMode.externalApplication,
      );
}

final class NoopAppUpdateService implements AppUpdateService {
  const NoopAppUpdateService();

  @override
  Future<void> checkForUpdate() async {}
}

final class NoopCrashReportingService implements CrashReportingService {
  const NoopCrashReportingService();

  @override
  Future<void> record(Object error, StackTrace stackTrace) async {}
}

final class NoopPushRegistrationService implements PushRegistrationService {
  const NoopPushRegistrationService();

  @override
  Stream<PushNotificationEvent> get events =>
      const Stream<PushNotificationEvent>.empty();

  @override
  Future<void> register() async {}

  @override
  Future<void> unregister() async {}
}
