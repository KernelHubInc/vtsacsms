import 'package:flutter/foundation.dart';

@immutable
final class Vehicle {
  const Vehicle({
    required this.id,
    required this.nickname,
    required this.manufacturer,
    required this.model,
    required this.connectorStandards,
    this.variant,
    this.isDefault = false,
  });

  factory Vehicle.fromJson(Map<String, dynamic> json) => Vehicle(
    id: json['id']! as String,
    nickname: json['nickname']! as String,
    manufacturer: json['manufacturer']! as String,
    model: json['model']! as String,
    variant: json['variant'] as String?,
    connectorStandards: (json['connector_standards']! as List<Object?>)
        .map((item) => item! as String)
        .toSet(),
    isDefault: json['is_default'] as bool? ?? false,
  );

  final String id;
  final String nickname;
  final String manufacturer;
  final String model;
  final String? variant;
  final Set<String> connectorStandards;
  final bool isDefault;

  bool supports(String connectorStandard) => connectorStandards
      .map((item) => item.toLowerCase())
      .contains(connectorStandard.toLowerCase());

  Map<String, Object?> toJson() => {
    'id': id,
    'nickname': nickname,
    'manufacturer': manufacturer,
    'model': model,
    'variant': variant,
    'connector_standards': connectorStandards.toList()..sort(),
    'is_default': isDefault,
  };

  Vehicle copyWith({
    String? nickname,
    String? manufacturer,
    String? model,
    String? variant,
    Set<String>? connectorStandards,
    bool? isDefault,
  }) => Vehicle(
    id: id,
    nickname: nickname ?? this.nickname,
    manufacturer: manufacturer ?? this.manufacturer,
    model: model ?? this.model,
    variant: variant ?? this.variant,
    connectorStandards: connectorStandards ?? this.connectorStandards,
    isDefault: isDefault ?? this.isDefault,
  );
}
