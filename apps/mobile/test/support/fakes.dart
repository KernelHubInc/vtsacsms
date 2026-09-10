import 'dart:async';

import 'package:shared_preferences/shared_preferences.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/config/app_environment.dart';
import 'package:vtsa_mobile/core/config/feature_flags.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/maps/map_configuration.dart';
import 'package:vtsa_mobile/core/network/connectivity_service.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/domain/auth_repository.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_repository.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/domain/station_repository.dart';
import 'package:vtsa_mobile/features/favorites/data/local_favorites_repository.dart';
import 'package:vtsa_mobile/features/vehicles/data/local_vehicle_repository.dart';

final class FakeAuthRepository implements AuthRepository {
  FakeAuthRepository({this.signedIn = false});

  bool signedIn;
  bool passwordResetRequested = false;
  bool registrationRequested = false;
  final user = const UserProfile(
    id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0M',
    name: 'Ada Driver',
    email: 'ada@example.test',
    emailVerified: true,
  );

  @override
  Future<UserProfile> currentUser() async {
    if (!signedIn) {
      throw StateError('Not signed in');
    }
    return user;
  }

  @override
  Future<({TokenBundle tokens, UserProfile user})> login({
    required String email,
    required String password,
  }) async {
    signedIn = true;
    return (
      tokens: TokenBundle(
        accessToken: 'test-token',
        expiresAt: DateTime.utc(2030),
        tenantId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0N',
        deviceId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0P',
      ),
      user: user,
    );
  }

  @override
  Future<void> logout() async => signedIn = false;

  @override
  Future<void> register({
    required String name,
    required String email,
    required String password,
  }) async => registrationRequested = true;

  @override
  Future<void> requestPasswordReset(String email) async =>
      passwordResetRequested = true;

  @override
  Future<void> resendEmailVerification() async {}
}

final class FakeStationRepository implements StationRepository {
  FakeStationRepository({List<Station>? stations})
    : stations = stations ?? [sampleStation];

  final List<Station> stations;
  GeoBounds? lastBounds;
  StationFilters? lastFilters;

  @override
  Future<StationSearchResult> inBounds({
    required GeoBounds bounds,
    required StationFilters filters,
  }) async {
    lastBounds = bounds;
    lastFilters = filters;
    return StationSearchResult(
      stations: stations,
      generatedAt: DateTime.utc(2026, 7, 23, 4),
    );
  }

  @override
  Future<StationSearchResult> nearby({
    required double latitude,
    required double longitude,
    required int radiusM,
    required StationFilters filters,
  }) => inBounds(
    bounds: const GeoBounds(west: 120.8, south: 14.4, east: 121.2, north: 14.8),
    filters: filters,
  );
}

const sampleStation = Station(
  id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0Q',
  siteId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0R',
  name: 'Station A',
  siteName: 'Harbor Exchange',
  address: '1 Current Street',
  latitude: 14.58,
  longitude: 120.98,
  timezone: 'Asia/Manila',
  siteType: 'retail',
  operatorId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0S',
  operatorName: 'Northline Charging',
  availability: StationAvailability.available,
  isStale: true,
  statusObservedAt: null,
  maximumPowerW: 120000,
  openNow: true,
  connectors: [
    StationConnector(
      standard: 'CCS2',
      name: 'CCS connector',
      maximumPowerW: 120000,
    ),
  ],
  operatingHours: ['Daily · 06:00–23:00'],
  amenities: ['Restroom', 'Coffee'],
);

final sampleChargePreparation = ChargePreparation(
  target: ChargeTarget(
    connectorId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0T',
    connectorName: 'Bay 2 · CCS2',
    connectorStandard: 'CCS2',
    maximumPowerW: 120000,
    stationId: sampleStation.id,
    stationName: sampleStation.name,
    stationAddress: sampleStation.address,
    readiness: ConnectorReadiness.available,
    statusObservedAt: DateTime.utc(2026, 7, 26, 3),
  ),
  quote: TariffQuote(
    id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0V',
    tariffVersionId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0W',
    name: 'Standard charging',
    currency: 'PHP',
    taxInclusive: true,
    lines: const [
      TariffLine(
        dimension: TariffDimension.energy,
        priceMinor: 3000,
        unitQuantity: 1000,
        description: 'PHP 30.00 per kWh',
      ),
    ],
    estimatedPreauthorization: const Money(minorUnits: 100000, currency: 'PHP'),
    expiresAt: DateTime.utc(2030),
  ),
  paymentMethods: const [
    PaymentMethodSummary(
      id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0X',
      label: 'Visa ending 4242',
      isDefault: true,
      brand: 'Visa',
      lastFour: '4242',
    ),
  ],
);

ChargingSession sampleChargingSession({
  String id = '01K0M0JJ5X0M0JJ5X0M0JJ5X0Y',
  ChargingSessionState state = ChargingSessionState.starting,
  PaymentState paymentState = PaymentState.authorized,
  int energyWh = 0,
  int durationSeconds = 0,
  int? currentPowerW,
  int aggregateVersion = 1,
  DateTime? startDeadlineAt,
  Money? finalCost,
  List<String> anomalyFlags = const [],
  ChargingDocument? receipt,
  ChargingDocument? invoice,
  bool refundable = false,
}) => ChargingSession(
  id: id,
  state: state,
  stationId: sampleStation.id,
  stationName: sampleStation.name,
  connectorId: sampleChargePreparation.target.connectorId,
  connectorName: sampleChargePreparation.target.connectorName,
  energyWh: energyWh,
  durationSeconds: durationSeconds,
  currentPowerW: currentPowerW,
  estimatedCost: const Money(minorUnits: 2500, currency: 'PHP'),
  finalCost: finalCost,
  requestedAt: DateTime.utc(2026, 7, 26, 3),
  startedAt: state == ChargingSessionState.starting
      ? null
      : DateTime.utc(2026, 7, 26, 3, 1),
  stoppedAt: state == ChargingSessionState.completed
      ? DateTime.utc(2026, 7, 26, 3, 31)
      : null,
  startDeadlineAt: startDeadlineAt,
  aggregateVersion: aggregateVersion,
  paymentState: paymentState,
  updatedAt: DateTime.utc(2026, 7, 26, 3, aggregateVersion),
  anomalyFlags: anomalyFlags,
  receipt: receipt,
  invoice: invoice,
  refundable: refundable,
);

final class FakeChargingRepository implements ChargingRepository {
  ChargePreparation preparation = sampleChargePreparation;
  ChargingSession startResult = sampleChargingSession();
  ChargingSession? active;
  ChargingHistoryPage historyResult = const ChargingHistoryPage(
    sessions: [],
    nextCursor: null,
  );
  final Map<String, ChargingSession> sessions = {};
  AppFailure? prepareFailure;
  AppFailure? startFailure;
  AppFailure? stopFailure;
  Completer<ChargingSession>? startCompleter;
  Completer<ChargingSession>? stopCompleter;
  int prepareCalls = 0;
  int startCalls = 0;
  int stopCalls = 0;
  int activeCalls = 0;
  int historyCalls = 0;
  int refundCalls = 0;
  int issueCalls = 0;
  StartChargingRequest? lastStartRequest;
  String? lastStopIdempotencyKey;
  String? lastRefundSessionId;
  String? lastIssueSessionId;

  @override
  Future<ChargingSession?> activeSession() async {
    activeCalls++;
    return active;
  }

  @override
  Future<ChargingSession> cancelStart({
    required String sessionId,
    required String reason,
  }) async {
    final cancelled = sampleChargingSession(
      id: sessionId,
      state: ChargingSessionState.cancelled,
      aggregateVersion: 9,
    );
    sessions[sessionId] = cancelled;
    return cancelled;
  }

  @override
  Future<ChargingSession> getSession(String sessionId) async {
    final result = sessions[sessionId] ?? active;
    if (result == null) {
      throw const AppFailure(
        kind: FailureKind.notFound,
        message: 'Session not found.',
      );
    }
    return result;
  }

  @override
  Future<ChargingHistoryPage> history({String? cursor}) async {
    historyCalls++;
    return historyResult;
  }

  @override
  Future<ChargePreparation> prepare({
    required ChargerCode code,
    String? vehicleId,
  }) async {
    prepareCalls++;
    if (prepareFailure case final failure?) {
      throw failure;
    }
    return preparation;
  }

  @override
  Future<void> reportIssue({
    required String sessionId,
    required String category,
    required String description,
    required String idempotencyKey,
  }) async {
    issueCalls++;
    lastIssueSessionId = sessionId;
  }

  @override
  Future<void> requestRefund({
    required String sessionId,
    required String reason,
    required String idempotencyKey,
  }) async {
    refundCalls++;
    lastRefundSessionId = sessionId;
  }

  @override
  Future<ChargingSession> start(StartChargingRequest request) async {
    startCalls++;
    lastStartRequest = request;
    if (startFailure case final failure?) {
      throw failure;
    }
    final result = startCompleter == null
        ? startResult
        : await startCompleter!.future;
    sessions[result.id] = result;
    active = result;
    return result;
  }

  @override
  Future<ChargingSession> stop({
    required String sessionId,
    required String idempotencyKey,
  }) async {
    stopCalls++;
    lastStopIdempotencyKey = idempotencyKey;
    if (stopFailure case final failure?) {
      throw failure;
    }
    final result = stopCompleter == null
        ? sampleChargingSession(
            id: sessionId,
            state: ChargingSessionState.stopping,
            aggregateVersion: 4,
          )
        : await stopCompleter!.future;
    sessions[result.id] = result;
    active = result;
    return result;
  }
}

Future<
  ({
    AppDependencies dependencies,
    FakeAuthRepository auth,
    FakeStationRepository stations,
    FakeChargingRepository charging,
  })
>
buildTestDependencies({
  bool onboardingComplete = true,
  bool signedIn = false,
  List<Station>? stations,
  FakeChargingRepository? chargingRepository,
}) async {
  SharedPreferences.setMockInitialValues({
    'onboarding_complete_v1': onboardingComplete,
  });
  final preferences = await SharedPreferences.getInstance();
  final tokens = MemoryTokenStore();
  if (signedIn) {
    await tokens.write(
      TokenBundle(
        accessToken: 'test-token',
        expiresAt: DateTime.utc(2030),
        tenantId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0N',
        deviceId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0P',
      ),
    );
  }
  final auth = FakeAuthRepository(signedIn: signedIn);
  final stationRepository = FakeStationRepository(stations: stations);
  final charging = chargingRepository ?? FakeChargingRepository();
  final dependencies = AppDependencies.fromParts(
    environment: AppEnvironment(
      name: AppEnvironmentName.local,
      apiBaseUrl: Uri.parse('http://localhost:8000'),
      defaultTenantId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0N',
      googleMapsApiKey: '',
      maps: const MapConfiguration(
        requestedProvider: MapProviderKind.openstreetmap,
        provider: MapProviderKind.openstreetmap,
        defaultLatitude: 14.5995,
        defaultLongitude: 120.9842,
        defaultZoom: 11,
        minimumZoom: 3,
        maximumZoom: 19,
        tileUrlTemplate: '',
        tileAttribution: '© OpenStreetMap contributors',
        tileMaximumNativeZoom: 19,
        clusteringEnabled: true,
        googleReady: false,
        status: 'widget_test_list_default',
      ),
    ),
    preferences: preferences,
    tokenStore: tokens,
    connectivity: const AlwaysOnlineConnectivity(),
    authRepository: auth,
    stationRepository: stationRepository,
    vehicleRepository: LocalVehicleRepository(preferences),
    favoritesRepository: LocalFavoritesRepository(preferences),
    chargingRepository: charging,
    featureFlags: const FeatureFlags(
      ocpp: false,
      remoteCharging: false,
      realPayments: false,
      settlements: false,
      ocpi: false,
      storeLocator: true,
      inventory: true,
      maintenance: true,
      procurement: true,
      demoMode: true,
      simulatedCharging: true,
      simulatedPayments: true,
    ),
  );
  await dependencies.initialize();
  return (
    dependencies: dependencies,
    auth: auth,
    stations: stationRepository,
    charging: charging,
  );
}
