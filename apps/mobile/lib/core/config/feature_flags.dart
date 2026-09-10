import 'package:flutter/foundation.dart';

@immutable
final class FeatureFlags {
  const FeatureFlags({
    required this.ocpp,
    required this.remoteCharging,
    required this.realPayments,
    required this.settlements,
    required this.ocpi,
    required this.storeLocator,
    required this.inventory,
    required this.maintenance,
    required this.procurement,
    required this.demoMode,
    required this.simulatedCharging,
    required this.simulatedPayments,
  });

  const FeatureFlags.fromDefines()
    : ocpp = const bool.fromEnvironment('FEATURE_OCPP'),
      remoteCharging = const bool.fromEnvironment('FEATURE_REMOTE_CHARGING'),
      realPayments = const bool.fromEnvironment('FEATURE_REAL_PAYMENTS'),
      settlements = const bool.fromEnvironment('FEATURE_SETTLEMENTS'),
      ocpi = const bool.fromEnvironment('FEATURE_OCPI'),
      storeLocator = const bool.fromEnvironment(
        'FEATURE_STORE_LOCATOR',
        defaultValue: true,
      ),
      inventory = const bool.fromEnvironment(
        'FEATURE_INVENTORY',
        defaultValue: true,
      ),
      maintenance = const bool.fromEnvironment(
        'FEATURE_MAINTENANCE',
        defaultValue: true,
      ),
      procurement = const bool.fromEnvironment(
        'FEATURE_PROCUREMENT',
        defaultValue: true,
      ),
      demoMode = const bool.fromEnvironment(
        'FEATURE_DEMO_MODE',
        defaultValue: true,
      ),
      simulatedCharging = const bool.fromEnvironment(
        'FEATURE_SIMULATED_CHARGING',
      ),
      simulatedPayments = const bool.fromEnvironment(
        'FEATURE_SIMULATED_PAYMENTS',
      );

  static const milestoneTwoMessage =
      'This feature will be available in Milestone 2.';

  final bool ocpp;
  final bool remoteCharging;
  final bool realPayments;
  final bool settlements;
  final bool ocpi;
  final bool storeLocator;
  final bool inventory;
  final bool maintenance;
  final bool procurement;
  final bool demoMode;
  final bool simulatedCharging;
  final bool simulatedPayments;
}
