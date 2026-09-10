import 'package:flutter/foundation.dart';

enum ChargingSessionState {
  requested,
  authorizing,
  authorized,
  starting,
  charging,
  suspendedByEv,
  suspendedByEvse,
  stopping,
  finalizing,
  reviewRequired,
  completed,
  failed,
  cancelled,
  expired,
}

enum ChargingFlowPhase {
  idle,
  resolvingCode,
  reviewing,
  submittingStart,
  startPending,
  startTimedOut,
  live,
  stopPending,
  finalizing,
  paymentProcessing,
  completed,
  failed,
}

enum PaymentState {
  notRequired,
  requiresPaymentMethod,
  authorizationPending,
  requiresAction,
  authorized,
  capturePending,
  captured,
  failed,
  refundPending,
  partiallyRefunded,
  refunded,
  unknown,
}

enum ConnectorReadiness { available, occupied, unavailable, faulted, stale }

@immutable
final class Money {
  const Money({required this.minorUnits, required this.currency})
    : assert(minorUnits >= 0),
      assert(currency.length == 3);

  factory Money.fromJson(Map<String, dynamic> json) => Money(
    minorUnits: json['amount_minor']! as int,
    currency: (json['currency']! as String).toUpperCase(),
  );

  final int minorUnits;
  final String currency;
}

@immutable
final class ChargerCode {
  const ChargerCode._(this.value);

  factory ChargerCode.parse(String input) {
    final trimmed = input.trim();
    final uri = Uri.tryParse(trimmed);
    var candidate = trimmed;
    if (uri != null && uri.hasScheme) {
      if (uri.scheme != 'vtsa') {
        throw const FormatException(
          'This QR code is not a Power Solutions charger code.',
        );
      }
      final segments = [if (uri.host.isNotEmpty) uri.host, ...uri.pathSegments];
      if (segments.length != 2 || segments.first != 'charge') {
        throw const FormatException(
          'This Power Solutions link is not a charger code.',
        );
      }
      candidate = segments.last;
    }
    if (!RegExp(r'^[A-Za-z0-9_-]{6,120}$').hasMatch(candidate)) {
      throw const FormatException(
        'Use the 6–120 character code printed on the charger.',
      );
    }
    return ChargerCode._(candidate);
  }

  final String value;
}

@immutable
final class ChargeTarget {
  const ChargeTarget({
    required this.connectorId,
    required this.connectorName,
    required this.connectorStandard,
    required this.maximumPowerW,
    required this.stationId,
    required this.stationName,
    required this.stationAddress,
    required this.readiness,
    required this.statusObservedAt,
  });

  factory ChargeTarget.fromJson(Map<String, dynamic> json) => ChargeTarget(
    connectorId: json['connector_id']! as String,
    connectorName: json['connector_name']! as String,
    connectorStandard: json['connector_standard']! as String,
    maximumPowerW: json['maximum_power_w']! as int,
    stationId: json['station_id']! as String,
    stationName: json['station_name']! as String,
    stationAddress: json['station_address'] as String?,
    readiness: ConnectorReadiness.values.firstWhere(
      (value) => value.name == json['readiness'],
      orElse: () => ConnectorReadiness.unavailable,
    ),
    statusObservedAt: DateTime.parse(
      json['status_observed_at']! as String,
    ).toUtc(),
  );

  final String connectorId;
  final String connectorName;
  final String connectorStandard;
  final int maximumPowerW;
  final String stationId;
  final String stationName;
  final String? stationAddress;
  final ConnectorReadiness readiness;
  final DateTime statusObservedAt;

  bool get canStart => readiness == ConnectorReadiness.available;
}

enum TariffDimension { energy, time, session, parking, idle }

@immutable
final class TariffLine {
  const TariffLine({
    required this.dimension,
    required this.priceMinor,
    required this.unitQuantity,
    required this.description,
  });

  factory TariffLine.fromJson(Map<String, dynamic> json) => TariffLine(
    dimension: TariffDimension.values.byName(json['dimension']! as String),
    priceMinor: json['price_minor']! as int,
    unitQuantity: json['unit_quantity']! as int,
    description: json['description']! as String,
  );

  final TariffDimension dimension;
  final int priceMinor;
  final int unitQuantity;
  final String description;
}

@immutable
final class TariffQuote {
  const TariffQuote({
    required this.id,
    required this.tariffVersionId,
    required this.name,
    required this.currency,
    required this.taxInclusive,
    required this.lines,
    required this.estimatedPreauthorization,
    required this.expiresAt,
  });

  factory TariffQuote.fromJson(Map<String, dynamic> json) => TariffQuote(
    id: json['id']! as String,
    tariffVersionId: json['tariff_version_id']! as String,
    name: json['name']! as String,
    currency: (json['currency']! as String).toUpperCase(),
    taxInclusive: json['tax_inclusive']! as bool,
    lines: (json['lines']! as List<Object?>)
        .map((item) => TariffLine.fromJson(item! as Map<String, dynamic>))
        .toList(growable: false),
    estimatedPreauthorization: Money.fromJson(
      json['estimated_preauthorization']! as Map<String, dynamic>,
    ),
    expiresAt: DateTime.parse(json['expires_at']! as String).toUtc(),
  );

  final String id;
  final String tariffVersionId;
  final String name;
  final String currency;
  final bool taxInclusive;
  final List<TariffLine> lines;
  final Money estimatedPreauthorization;
  final DateTime expiresAt;
}

@immutable
final class PaymentMethodSummary {
  const PaymentMethodSummary({
    required this.id,
    required this.label,
    required this.isDefault,
    this.brand,
    this.lastFour,
  });

  factory PaymentMethodSummary.fromJson(Map<String, dynamic> json) =>
      PaymentMethodSummary(
        id: json['id']! as String,
        label: json['label']! as String,
        isDefault: json['is_default'] as bool? ?? false,
        brand: json['brand'] as String?,
        lastFour: json['last_four'] as String?,
      );

  final String id;
  final String label;
  final bool isDefault;
  final String? brand;
  final String? lastFour;
}

@immutable
final class ChargePreparation {
  const ChargePreparation({
    required this.target,
    required this.quote,
    required this.paymentMethods,
  });

  factory ChargePreparation.fromJson(Map<String, dynamic> json) =>
      ChargePreparation(
        target: ChargeTarget.fromJson(json['target']! as Map<String, dynamic>),
        quote: TariffQuote.fromJson(json['quote']! as Map<String, dynamic>),
        paymentMethods: (json['payment_methods']! as List<Object?>)
            .map(
              (item) =>
                  PaymentMethodSummary.fromJson(item! as Map<String, dynamic>),
            )
            .toList(growable: false),
      );

  final ChargeTarget target;
  final TariffQuote quote;
  final List<PaymentMethodSummary> paymentMethods;
}

@immutable
final class ChargingDocument {
  const ChargingDocument({
    required this.id,
    required this.reference,
    required this.downloadUrl,
  });

  factory ChargingDocument.fromJson(Map<String, dynamic> json) =>
      ChargingDocument(
        id: json['id']! as String,
        reference: json['reference']! as String,
        downloadUrl: Uri.parse(json['download_url']! as String),
      );

  final String id;
  final String reference;
  final Uri downloadUrl;
}

@immutable
final class ChargingSession {
  const ChargingSession({
    required this.id,
    required this.state,
    required this.stationId,
    required this.stationName,
    required this.connectorId,
    required this.connectorName,
    required this.energyWh,
    required this.durationSeconds,
    required this.requestedAt,
    required this.aggregateVersion,
    required this.paymentState,
    required this.updatedAt,
    this.startedAt,
    this.stoppedAt,
    this.currentPowerW,
    this.estimatedCost,
    this.finalCost,
    this.startDeadlineAt,
    this.failureReason,
    this.anomalyFlags = const [],
    this.receipt,
    this.invoice,
    this.refundable = false,
  });

  factory ChargingSession.fromJson(Map<String, dynamic> json) {
    final currency = (json['currency'] as String?)?.toUpperCase();
    Money? money(String key) => json[key] == null || currency == null
        ? null
        : Money(minorUnits: json[key]! as int, currency: currency);
    return ChargingSession(
      id: json['id']! as String,
      state: _sessionState(json['state']! as String),
      stationId: (json['site_id'] ?? json['station_id'])! as String,
      stationName: (json['station_name'] ?? 'Charging station') as String,
      connectorId: json['connector_id']! as String,
      connectorName: (json['connector_name'] ?? 'Connector') as String,
      energyWh: json['energy_wh']! as int,
      durationSeconds: json['duration_seconds']! as int,
      currentPowerW: json['current_power_w'] as int?,
      estimatedCost: money('estimated_cost_minor'),
      finalCost: money('final_cost_minor'),
      requestedAt: DateTime.parse(json['requested_at']! as String).toUtc(),
      startedAt: json['started_at'] == null
          ? null
          : DateTime.parse(json['started_at']! as String).toUtc(),
      stoppedAt: json['stopped_at'] == null
          ? null
          : DateTime.parse(json['stopped_at']! as String).toUtc(),
      startDeadlineAt: json['start_deadline_at'] == null
          ? null
          : DateTime.parse(json['start_deadline_at']! as String).toUtc(),
      aggregateVersion: json['aggregate_version']! as int,
      paymentState: _paymentState(
        json['payment_state'] as String? ?? 'unknown',
      ),
      updatedAt: DateTime.parse(
        (json['updated_at'] ?? json['requested_at'])! as String,
      ).toUtc(),
      failureReason: json['failure_reason'] as String?,
      anomalyFlags:
          (json['anomaly_flags'] as List<Object?>?)
              ?.map((item) => item.toString())
              .toList(growable: false) ??
          const [],
      receipt: json['receipt'] == null
          ? null
          : ChargingDocument.fromJson(json['receipt']! as Map<String, dynamic>),
      invoice: json['invoice'] == null
          ? null
          : ChargingDocument.fromJson(json['invoice']! as Map<String, dynamic>),
      refundable: json['refundable'] as bool? ?? false,
    );
  }

  final String id;
  final ChargingSessionState state;
  final String stationId;
  final String stationName;
  final String connectorId;
  final String connectorName;
  final int energyWh;
  final int durationSeconds;
  final int? currentPowerW;
  final Money? estimatedCost;
  final Money? finalCost;
  final DateTime requestedAt;
  final DateTime? startedAt;
  final DateTime? stoppedAt;
  final DateTime? startDeadlineAt;
  final int aggregateVersion;
  final PaymentState paymentState;
  final DateTime updatedAt;
  final String? failureReason;
  final List<String> anomalyFlags;
  final ChargingDocument? receipt;
  final ChargingDocument? invoice;
  final bool refundable;

  bool get isPhysicalActive => const {
    ChargingSessionState.charging,
    ChargingSessionState.suspendedByEv,
    ChargingSessionState.suspendedByEvse,
    ChargingSessionState.stopping,
  }.contains(state);

  bool get canRequestRemoteStop => const {
    ChargingSessionState.charging,
    ChargingSessionState.suspendedByEv,
    ChargingSessionState.suspendedByEvse,
  }.contains(state);

  bool get isTerminal => const {
    ChargingSessionState.completed,
    ChargingSessionState.failed,
    ChargingSessionState.cancelled,
    ChargingSessionState.expired,
  }.contains(state);

  bool get chargerDisconnected => anomalyFlags.contains('charger_disconnected');

  static ChargingSessionState _sessionState(String value) =>
      ChargingSessionState.values.firstWhere(
        (state) => _snakeCase(state.name) == value,
        orElse: () => throw FormatException('Unknown session state: $value'),
      );

  static PaymentState _paymentState(String value) =>
      PaymentState.values.firstWhere(
        (state) => _snakeCase(state.name) == value,
        orElse: () => PaymentState.unknown,
      );

  static String _snakeCase(String input) => input.replaceAllMapped(
    RegExp('[A-Z]'),
    (match) => '_${match.group(0)!.toLowerCase()}',
  );
}

@immutable
final class ChargingHistoryPage {
  const ChargingHistoryPage({required this.sessions, required this.nextCursor});

  final List<ChargingSession> sessions;
  final String? nextCursor;
}
