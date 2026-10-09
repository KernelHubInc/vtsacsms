import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';

@immutable
final class StationFilters {
  const StationFilters({
    this.connector,
    this.vehicleConnectors = const [],
    this.amenity,
    this.minimumPowerW,
    this.availability,
    this.operatorId,
    this.siteType,
    this.openNow = false,
  });

  final String? connector;
  final List<String> vehicleConnectors;
  final String? amenity;
  final int? minimumPowerW;
  final StationAvailability? availability;
  final String? operatorId;
  final String? siteType;
  final bool openNow;

  Map<String, Object> toQuery() => {
    if (amenity != null && amenity!.isNotEmpty) 'amenity': amenity!,
    if (vehicleConnectors.isNotEmpty) 'connectors[]': vehicleConnectors,
    if (connector != null && connector!.isNotEmpty) 'connector': connector!,
    'min_power_w': ?minimumPowerW,
    'availability': ?availability?.name,
    if (operatorId != null && operatorId!.isNotEmpty)
      'operator_id': operatorId!,
    if (siteType != null && siteType!.isNotEmpty) 'site_type': siteType!,
    if (openNow) 'open_now': true,
  };

  StationFilters copyWith({
    String? connector,
    bool clearConnector = false,
    List<String>? vehicleConnectors,
    String? amenity,
    bool clearAmenity = false,
    int? minimumPowerW,
    bool clearMinimumPower = false,
    StationAvailability? availability,
    bool clearAvailability = false,
    String? operatorId,
    String? siteType,
    bool? openNow,
  }) => StationFilters(
    amenity: clearAmenity ? null : amenity ?? this.amenity,
    vehicleConnectors: vehicleConnectors ?? this.vehicleConnectors,
    connector: clearConnector ? null : connector ?? this.connector,
    minimumPowerW: clearMinimumPower
        ? null
        : minimumPowerW ?? this.minimumPowerW,
    availability: clearAvailability ? null : availability ?? this.availability,
    operatorId: operatorId ?? this.operatorId,
    siteType: siteType ?? this.siteType,
    openNow: openNow ?? this.openNow,
  );
}

@immutable
final class StationSearchResult {
  const StationSearchResult({
    required this.stations,
    required this.generatedAt,
  });

  final List<Station> stations;
  final DateTime generatedAt;
}

abstract interface class StationRepository {
  Future<StationSearchResult> inBounds({
    required GeoBounds bounds,
    required StationFilters filters,
  });
  Future<StationSearchResult> nearby({
    required double latitude,
    required double longitude,
    required int radiusM,
    required StationFilters filters,
  });
}
