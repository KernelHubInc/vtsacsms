import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:vtsa_mobile/app/bootstrap_controller.dart';
import 'package:vtsa_mobile/core/config/app_environment.dart';
import 'package:vtsa_mobile/core/config/feature_flags.dart';
import 'package:vtsa_mobile/core/maps/device_location_service.dart';
import 'package:vtsa_mobile/core/maps/map_configuration_repository.dart';
import 'package:vtsa_mobile/core/network/api_client.dart';
import 'package:vtsa_mobile/core/network/connectivity_service.dart';
import 'package:vtsa_mobile/core/platform/platform_services.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/accounts/charging_accounts.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/data/api_auth_repository.dart';
import 'package:vtsa_mobile/features/auth/data/device_authenticator.dart';
import 'package:vtsa_mobile/features/auth/data/quick_unlock_store.dart';
import 'package:vtsa_mobile/features/auth/domain/auth_repository.dart';
import 'package:vtsa_mobile/features/charging/application/charging_controller.dart';
import 'package:vtsa_mobile/features/charging/data/active_session_store.dart';
import 'package:vtsa_mobile/features/charging/data/api_charging_repository.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_repository.dart';
import 'package:vtsa_mobile/features/discovery/application/discovery_controller.dart';
import 'package:vtsa_mobile/features/discovery/data/api_station_repository.dart';
import 'package:vtsa_mobile/features/discovery/domain/station_repository.dart';
import 'package:vtsa_mobile/features/favorites/application/favorites_controller.dart';
import 'package:vtsa_mobile/features/favorites/data/local_favorites_repository.dart';
import 'package:vtsa_mobile/features/favorites/domain/favorites_repository.dart';
import 'package:vtsa_mobile/features/kyc/data/api_kyc_repository.dart';
import 'package:vtsa_mobile/features/kyc/domain/kyc_repository.dart';
import 'package:vtsa_mobile/features/notices/driver_notices.dart';
import 'package:vtsa_mobile/features/notices/notice_push_registration.dart';
import 'package:vtsa_mobile/features/vehicles/application/vehicles_controller.dart';
import 'package:vtsa_mobile/features/vehicles/data/api_vehicle_repository.dart';
import 'package:vtsa_mobile/features/vehicles/data/local_vehicle_repository.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle_repository.dart';
import 'package:vtsa_mobile/features/wallet/wallet_repository.dart';

final class AppDependencies {
  AppDependencies({
    required this.environment,
    required this.bootstrap,
    required this.auth,
    required this.charging,
    required this.discovery,
    required this.favorites,
    required this.vehicles,
    required this.network,
    required this.directions,
    required this.location,
    required this.updates,
    required this.analyticsConsent,
    required this.crashReporting,
    required this.pushRegistration,
    required this.featureFlags,
    this.kycRepository,
    this.walletRepository,
    this.accounts,
    this.notices,
    this.noticePush = const NoopNoticePushRegistration(),
  });

  factory AppDependencies.fromParts({
    required AppEnvironment environment,
    required SharedPreferences preferences,
    required TokenStore tokenStore,
    required ConnectivityService connectivity,
    required AuthRepository authRepository,
    required StationRepository stationRepository,
    required VehicleRepository vehicleRepository,
    required FavoritesRepository favoritesRepository,
    required ChargingRepository chargingRepository,
    SessionRealtimeGateway realtimeGateway = const NoopSessionRealtimeGateway(),
    DirectionsService directions = const _UnavailableDirectionsService(),
    DeviceLocationService location = const UnavailableDeviceLocationService(),
    FeatureFlags featureFlags = const FeatureFlags.fromDefines(),
    KycRepository? kycRepository,
    WalletRepository? walletRepository,
    ChargingAccountsController? accounts,
    DriverNoticeRepository? noticeRepository,
    NoticePushRegistration noticePush = const NoopNoticePushRegistration(),
  }) {
    late final AuthController auth;
    String ownerId() =>
        auth.user?.id.isNotEmpty == true ? auth.user!.id : 'guest';
    auth = AuthController(repository: authRepository, tokens: tokenStore);
    final network = NetworkState(connectivity);
    final notices = noticeRepository == null
        ? null
        : DriverNoticesController(noticeRepository);
    const push = NoopPushRegistrationService();
    final charging = ChargingController(
      repository: chargingRepository,
      store: LocalActiveSessionStore(preferences),
      realtime: realtimeGateway,
      network: network,
      push: push,
      ownerId: ownerId,
      isAuthenticated: () =>
          auth.status == AuthStatus.authenticated &&
          !auth.needsEmailVerification,
    );
    String? lastOwner;
    var lastVerified = false;
    auth.addListener(() {
      if (auth.status == AuthStatus.guest) {
        unawaited(noticePush.unregister());
        accounts?.clear();
        notices?.clear();
        final previous = lastOwner;
        lastOwner = null;
        lastVerified = false;
        if (previous != null) {
          unawaited(charging.onSignedOut(ownerId: previous));
        }
      } else if (auth.status == AuthStatus.authenticated && auth.user != null) {
        final current = ownerId();
        if (lastOwner != null && lastOwner != current) {
          unawaited(charging.onSignedOut(ownerId: lastOwner));
        }
        final changed = lastOwner != current;
        if (changed) accounts?.clear();
        lastOwner = current;
        final verified = !auth.needsEmailVerification;
        if ((changed || !lastVerified) && verified && accounts != null) {
          unawaited(accounts.load());
        }
        if ((changed || !lastVerified) && verified) {
          if (notices != null) unawaited(notices.refresh());
          unawaited(noticePush.register());
        }
        lastVerified = verified;
      } else if (auth.status == AuthStatus.locked) {
        lastVerified = false;
      }
    });

    final dependencies = AppDependencies(
      environment: environment,
      bootstrap: BootstrapController(preferences),
      auth: auth,
      charging: charging,
      discovery: DiscoveryController(stationRepository),
      favorites: FavoritesController(
        repository: favoritesRepository,
        ownerId: ownerId,
      ),
      vehicles: VehiclesController(
        repository: vehicleRepository,
        ownerId: () => accounts?.shared == true
            ? '${ownerId()}:${accounts!.accountId}'
            : ownerId(),
      ),
      network: network,
      directions: directions,
      location: location,
      updates: const NoopAppUpdateService(),
      analyticsConsent: LocalAnalyticsConsentService(preferences),
      crashReporting: const NoopCrashReportingService(),
      pushRegistration: push,
      featureFlags: featureFlags,
      kycRepository: kycRepository,
      walletRepository: walletRepository,
      accounts: accounts,
      notices: notices,
      noticePush: noticePush,
    );
    String? selectedAccount;
    String? selectedPermissions;
    accounts?.addListener(() {
      final permissions =
          (accounts.selected?.permissions.toList() ?? <String>[])..sort();
      final scope = permissions.join(',');
      if (accounts.accountId == selectedAccount &&
          scope == selectedPermissions) {
        return;
      }
      final hadSelection = selectedAccount != null;
      selectedAccount = accounts.accountId;
      selectedPermissions = scope;
      // Initial personal selection must not invalidate active-session restoration.
      if (hadSelection) charging.onChargingAccountChanged();
      if (auth.status == AuthStatus.authenticated &&
          !auth.needsEmailVerification) {
        unawaited(dependencies.vehicles.load());
      }
    });
    return dependencies;
  }

  static Future<AppDependencies> create() async {
    final localEnvironment = AppEnvironment.fromDefines();
    final preferences = await SharedPreferences.getInstance();
    const secureStorage = FlutterSecureStorage(
      aOptions: AndroidOptions(storageNamespace: 'vtsa_auth'),
      iOptions: IOSOptions(
        accessibility: KeychainAccessibility.unlocked_this_device,
      ),
    );
    final tokens = QuickUnlockStore(
      tokens: SecureTokenStore(secureStorage),
      settings: SecureUnlockSettingsStore(secureStorage),
      device: NativeDeviceAuthenticator(),
    );
    final client = ApiClient(
      baseUrl: localEnvironment.apiBaseUrl,
      tokenStore: tokens,
    );
    late final AppDependencies dependencies;
    NoticePushRegistration noticePush = const NoopNoticePushRegistration();
    if (!kIsWeb &&
        defaultTargetPlatform == TargetPlatform.android &&
        localEnvironment.name == AppEnvironmentName.staging) {
      try {
        await Firebase.initializeApp();
        noticePush = FirebaseNoticePushRegistration(
          client: client,
          messaging: FirebaseMessaging.instance,
          onNotice: () async {
            await dependencies.notices?.refresh();
          },
        );
      } on Object {
        // A Firebase outage must not block sign-in, charging, or wallet access.
      }
    }
    final environment = localEnvironment.withMaps(
      await MapConfigurationRepository(client).resolve(localEnvironment.maps),
    );

    final accounts = ChargingAccountsController(
      ApiChargingAccountRepository(client),
      ownerId: () => dependencies.auth.user?.id ?? 'guest',
    );
    dependencies = AppDependencies.fromParts(
      environment: environment,
      preferences: preferences,
      tokenStore: tokens,
      connectivity: DeviceConnectivityService(Connectivity()),
      authRepository: ApiAuthRepository(
        client: client,
        tokens: tokens,
        environment: environment,
      ),
      stationRepository: ApiStationRepository(client),
      vehicleRepository: ApiVehicleRepository(
        client,
        legacy: LocalVehicleRepository(preferences),
        accountId: () => accounts.accountId,
        shared: () => accounts.shared,
      ),
      favoritesRepository: LocalFavoritesRepository(preferences),
      chargingRepository: ApiChargingRepository(
        client,
        accountId: () => accounts.accountId,
      ),
      kycRepository: ApiKycRepository(client),
      walletRepository: ApiWalletRepository(
        client,
        accountId: () => accounts.accountId,
      ),
      accounts: accounts,
      noticeRepository: ApiDriverNoticeRepository(client),
      noticePush: noticePush,
      directions: ExternalDirectionsService(),
      location: GeolocatorDeviceLocationService(),
    );
    client.onSessionExpired = dependencies.auth.sessionExpired;
    return dependencies;
  }

  final AppEnvironment environment;
  final BootstrapController bootstrap;
  final AuthController auth;
  final ChargingController charging;
  final DiscoveryController discovery;
  final FavoritesController favorites;
  final VehiclesController vehicles;
  final NetworkState network;
  final DirectionsService directions;
  final DeviceLocationService location;
  final AppUpdateService updates;
  final AnalyticsConsentService analyticsConsent;
  final CrashReportingService crashReporting;
  final PushRegistrationService pushRegistration;
  final FeatureFlags featureFlags;
  final KycRepository? kycRepository;
  final WalletRepository? walletRepository;
  final ChargingAccountsController? accounts;
  final DriverNoticesController? notices;
  final NoticePushRegistration noticePush;

  Future<void> initialize() async {
    await Future.wait([
      bootstrap.initialize(),
      network.start(),
      updates.checkForUpdate(),
    ]);
    await auth.restore();
    await favorites.load();
    if (auth.status == AuthStatus.authenticated &&
        !auth.needsEmailVerification) {
      await Future.wait([
        vehicles.load(),
        pushRegistration.register(),
        noticePush.register(),
        if (notices != null) notices!.refresh(),
        if (featureFlags.remoteCharging || featureFlags.simulatedCharging)
          charging.restoreAuthoritativeSession(),
      ]);
    }
  }

  void dispose() {
    accounts?.dispose();
    notices?.dispose();
    noticePush.dispose();
    bootstrap.dispose();
    auth.dispose();
    charging.dispose();
    discovery.dispose();
    favorites.dispose();
    vehicles.dispose();
    network.dispose();
  }
}

final class _UnavailableDirectionsService implements DirectionsService {
  const _UnavailableDirectionsService();

  @override
  Future<bool> open({
    required double latitude,
    required double longitude,
  }) async => false;
}
