import 'package:connectivity_plus/connectivity_plus.dart';
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
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/data/api_auth_repository.dart';
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
import 'package:vtsa_mobile/features/vehicles/application/vehicles_controller.dart';
import 'package:vtsa_mobile/features/vehicles/data/local_vehicle_repository.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle_repository.dart';

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
  }) {
    late final AuthController auth;
    String ownerId() =>
        auth.user?.id.isNotEmpty == true ? auth.user!.id : 'guest';
    auth = AuthController(repository: authRepository, tokens: tokenStore);
    final network = NetworkState(connectivity);
    const push = NoopPushRegistrationService();
    final charging = ChargingController(
      repository: chargingRepository,
      store: LocalActiveSessionStore(preferences),
      realtime: realtimeGateway,
      network: network,
      push: push,
      ownerId: ownerId,
      isAuthenticated: () => auth.status == AuthStatus.authenticated,
    );

    return AppDependencies(
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
        ownerId: ownerId,
      ),
      network: network,
      directions: directions,
      location: location,
      updates: const NoopAppUpdateService(),
      analyticsConsent: LocalAnalyticsConsentService(preferences),
      crashReporting: const NoopCrashReportingService(),
      pushRegistration: push,
      featureFlags: featureFlags,
    );
  }

  static Future<AppDependencies> create() async {
    final localEnvironment = AppEnvironment.fromDefines();
    final preferences = await SharedPreferences.getInstance();
    const secureStorage = FlutterSecureStorage(
      aOptions: AndroidOptions(storageNamespace: 'vtsa_auth'),
    );
    final tokens = SecureTokenStore(secureStorage);
    final client = ApiClient(
      baseUrl: localEnvironment.apiBaseUrl,
      tokenStore: tokens,
    );
    final environment = localEnvironment.withMaps(
      await MapConfigurationRepository(client).resolve(localEnvironment.maps),
    );

    return AppDependencies.fromParts(
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
      vehicleRepository: LocalVehicleRepository(preferences),
      favoritesRepository: LocalFavoritesRepository(preferences),
      chargingRepository: ApiChargingRepository(client),
      directions: ExternalDirectionsService(),
      location: GeolocatorDeviceLocationService(),
    );
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

  Future<void> initialize() async {
    await Future.wait([
      bootstrap.initialize(),
      network.start(),
      updates.checkForUpdate(),
    ]);
    await auth.restore();
    await favorites.load();
    if (auth.status == AuthStatus.authenticated) {
      await Future.wait([
        vehicles.load(),
        pushRegistration.register(),
        charging.restoreAuthoritativeSession(),
      ]);
    }
  }

  void dispose() {
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
