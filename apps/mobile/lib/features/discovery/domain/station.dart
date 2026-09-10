import 'package:flutter/foundation.dart';

enum StationAvailability { available, busy, faulted, offline, stale, unknown }

@immutable
final class GeoBounds {
  const GeoBounds({
    required this.west,
    required this.south,
    required this.east,
    required this.north,
  }) : assert(west >= -180 && west <= 180),
       assert(east >= -180 && east <= 180),
       assert(south >= -90 && south <= 90),
       assert(north >= -90 && north <= 90),
       assert(south <= north);

  final double west;
  final double south;
  final double east;
  final double north;

  Map<String, Object> toQuery() => {
    'west': west,
    'south': south,
    'east': east,
    'north': north,
  };
}

@immutable
final class StationConnector {
  const StationConnector({
    required this.standard,
    required this.name,
    required this.maximumPowerW,
  });

  factory StationConnector.fromJson(Map<String, dynamic> json) =>
      StationConnector(
        standard: json['standard']! as String,
        name: json['name']! as String,
        maximumPowerW: json['maximum_power_w']! as int,
      );

  final String standard;
  final String name;
  final int maximumPowerW;

  String get powerLabel => maximumPowerW >= 1000
      ? '${(maximumPowerW / 1000).toStringAsFixed(maximumPowerW % 1000 == 0 ? 0 : 1)} kW'
      : '$maximumPowerW W';
}

@immutable
final class Station {
  const Station({
    required this.id,
    required this.siteId,
    required this.name,
    required this.siteName,
    required this.latitude,
    required this.longitude,
    required this.timezone,
    required this.siteType,
    required this.operatorId,
    required this.operatorName,
    required this.availability,
    required this.isStale,
    required this.maximumPowerW,
    required this.openNow,
    required this.connectors,
    this.address,
    this.statusObservedAt,
    this.distanceM,
    this.operatingHours = const [],
    this.amenities = const [],
  });

  factory Station.fromJson(Map<String, dynamic> json) => Station(
    id: json['id']! as String,
    siteId: json['site_id']! as String,
    name: json['name']! as String,
    siteName: json['site_name']! as String,
    address: json['address_line_1'] as String?,
    latitude: _coordinate(json['latitude']),
    longitude: _coordinate(json['longitude']),
    timezone: json['timezone']! as String,
    siteType: json['site_type']! as String,
    operatorId: json['operator_id']! as String,
    operatorName: json['operator_name']! as String,
    availability: StationAvailability.values.firstWhere(
      (value) => value.name == json['availability'],
      orElse: () => StationAvailability.unknown,
    ),
    isStale: json['is_stale']! as bool,
    statusObservedAt: json['status_observed_at'] == null
        ? null
        : DateTime.parse(json['status_observed_at']! as String).toUtc(),
    maximumPowerW: json['maximum_power_w']! as int,
    openNow: json['open_now']! as bool,
    connectors: (json['connectors']! as List<Object?>)
        .map((item) => StationConnector.fromJson(item! as Map<String, dynamic>))
        .toList(growable: false),
    distanceM: (json['distance_m'] as num?)?.toDouble(),
    operatingHours:
        (json['operating_hours'] as List<Object?>?)
            ?.map((item) => item.toString())
            .toList(growable: false) ??
        const [],
    amenities:
        (json['amenities'] as List<Object?>?)
            ?.map((item) => item.toString())
            .toList(growable: false) ??
        const [],
  );

  final String id;
  final String siteId;
  final String name;
  final String siteName;
  final String? address;
  final double latitude;
  final double longitude;
  final String timezone;
  final String siteType;
  final String operatorId;
  final String operatorName;
  final StationAvailability availability;
  final bool isStale;
  final DateTime? statusObservedAt;
  final int maximumPowerW;
  final bool openNow;
  final List<StationConnector> connectors;
  final double? distanceM;
  final List<String> operatingHours;
  final List<String> amenities;

  String get maximumPowerLabel => maximumPowerW >= 1000
      ? '${(maximumPowerW / 1000).toStringAsFixed(maximumPowerW % 1000 == 0 ? 0 : 1)} kW'
      : '$maximumPowerW W';
}

double _coordinate(Object? value) {
  if (value is num) {
    return value.toDouble();
  }
  if (value is String) {
    final parsed = double.tryParse(value);
    if (parsed != null) {
      return parsed;
    }
  }

  throw const FormatException('Station coordinates must be numeric.');
}
