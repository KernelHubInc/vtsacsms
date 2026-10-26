import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle.dart';

VtsaOperationalState operationalStateFor(Station station) {
  if (station.isStale) {
    return VtsaOperationalState.unknown;
  }
  return switch (station.availability) {
    StationAvailability.available => VtsaOperationalState.available,
    StationAvailability.busy => VtsaOperationalState.charging,
    StationAvailability.faulted => VtsaOperationalState.faulted,
    StationAvailability.unavailable => VtsaOperationalState.unavailable,
    StationAvailability.offline => VtsaOperationalState.offline,
    StationAvailability.stale => VtsaOperationalState.unknown,
    StationAvailability.unknown => VtsaOperationalState.unknown,
  };
}

String availabilityLabel(Station station) {
  if (station.isStale || station.availability == StationAvailability.stale) {
    return 'Status stale';
  }
  if (station.availability == StationAvailability.busy) {
    final occupied = station.connectors
        .where((connector) => connector.availability == 'occupied')
        .toList();
    if (occupied.any((connector) => connector.reportedStatus == 'charging')) {
      return 'Charging';
    }
    if (occupied.length == 1) return occupied.single.statusLabel;
    if (station.connectors.isNotEmpty &&
        station.connectors.every(
          (connector) => connector.availability == 'reserved',
        )) {
      return 'Reserved';
    }
  }
  return switch (station.availability) {
    StationAvailability.available => 'Available',
    StationAvailability.busy => 'In use',
    StationAvailability.faulted => 'Fault reported',
    StationAvailability.unavailable => 'Unavailable',
    StationAvailability.offline => 'Offline',
    StationAvailability.stale => 'Status stale',
    StationAvailability.unknown => 'Status unknown',
  };
}

String? connectorAsset(String standard) {
  final value = standard.toLowerCase();
  if (value.contains('ccs')) return 'icon-ccs2';
  if (value.contains('chademo')) return 'icon-chademo';
  if (value.contains('type 2') || value.contains('type2')) return 'icon-type2';
  return null;
}

String? amenityAsset(String name) {
  final value = name.toLowerCase();
  if (value.contains('wifi') || value.contains('wi-fi')) return 'icon-wifi';
  if (value.contains('food') || value.contains('drink')) return 'icon-food';
  if (value.contains('shop')) return 'icon-shopping';
  if (value.contains('restroom') || value.contains('toilet')) {
    return 'icon-restroom';
  }
  if (value.contains('accessible')) return 'icon-accessible';
  if (value.contains('parking')) return 'icon-parking';
  return null;
}

class StationStatusBadge extends StatelessWidget {
  const StationStatusBadge({required this.station, super.key});

  final Station station;

  @override
  Widget build(BuildContext context) {
    final label = availabilityLabel(station);
    final asset = switch (label) {
      'Available' => 'available',
      'In use' => 'busy',
      'Offline' => 'offline',
      _ => null,
    };
    if (asset == null) {
      return VtsaStatusChip(label: label, state: operationalStateFor(station));
    }
    return Semantics(
      label: 'Status: $label',
      child: Image.asset(
        'assets/interface/status-$asset.png',
        width: 164,
        height: 40,
        alignment: Alignment.centerLeft,
        excludeFromSemantics: true,
      ),
    );
  }
}

class DriverStationCard extends StatelessWidget {
  const DriverStationCard({
    required this.station,
    required this.onTap,
    super.key,
  });
  final Station station;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => VtsaCard(
    padding: EdgeInsets.zero,
    child: InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 58,
              height: 58,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: context.vtsaColors.brandSoft,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Image.asset(
                'assets/interface/icon-charger.png',
                width: 24,
                height: 24,
                excludeFromSemantics: true,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    station.name,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    station.distanceM == null
                        ? station.siteName
                        : '${(station.distanceM! / 1000).toStringAsFixed(1)} km · straight-line distance',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    station.maximumPowerW > 0
                        ? 'Up to ${station.maximumPowerLabel}'
                        : 'Power not provided',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  const SizedBox(height: 6),
                  StationStatusBadge(station: station),
                ],
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class StationCard extends StatelessWidget {
  const StationCard({
    required this.station,
    required this.onTap,
    required this.favorite,
    required this.onFavorite,
    this.onClose,
    this.selected = false,
    super.key,
  });

  final Station station;
  final VoidCallback onTap;
  final bool favorite;
  final VoidCallback onFavorite;
  final VoidCallback? onClose;
  final bool selected;

  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    selected: selected,
    label:
        '${station.name}. ${station.siteName}. ${station.connectionLabel}. ${availabilityLabel(station)}.',
    child: VtsaCard(
      selected: selected,
      padding: EdgeInsets.zero,
      child: InkWell(
        borderRadius: BorderRadius.circular(VtsaRadii.lg),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(VtsaSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          station.name,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: VtsaSpacing.xxs),
                        Text(
                          station.siteName,
                          style: Theme.of(context).textTheme.bodySmall
                              ?.copyWith(color: context.vtsaColors.textMuted),
                        ),
                        if (station.address != null)
                          Text(
                            station.address!,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                      ],
                    ),
                  ),
                  IconButton(
                    tooltip: favorite
                        ? 'Remove from favorites'
                        : 'Add to favorites',
                    onPressed: onFavorite,
                    icon: Icon(
                      favorite ? Icons.favorite : Icons.favorite_border,
                      color: favorite
                          ? context.vtsaColors.danger
                          : context.vtsaColors.textMuted,
                    ),
                  ),
                  if (onClose != null)
                    IconButton(
                      tooltip: 'Close station preview',
                      onPressed: onClose,
                      icon: const Icon(Icons.close),
                    ),
                ],
              ),
              const SizedBox(height: VtsaSpacing.sm),
              Wrap(
                spacing: VtsaSpacing.xs,
                runSpacing: VtsaSpacing.xs,
                children: [
                  StationStatusBadge(station: station),
                  _FactChip(
                    icon: station.connectionStatus == 'online'
                        ? Icons.cloud_done_outlined
                        : Icons.cloud_off_outlined,
                    label: station.connectionLabel,
                  ),
                  _FactChip(
                    icon: Icons.bolt_outlined,
                    label: station.maximumPowerW > 0
                        ? 'Up to ${station.maximumPowerLabel}'
                        : 'Power not provided',
                  ),
                  _FactChip(
                    icon: station.openNow
                        ? Icons.schedule
                        : Icons.schedule_outlined,
                    label: !station.hoursKnown
                        ? 'Hours not provided'
                        : station.openNow
                        ? 'Open now'
                        : 'Closed now',
                  ),
                ],
              ),
              const SizedBox(height: VtsaSpacing.xs),
              Text(
                station.connectors.isEmpty
                    ? 'Connector details pending'
                    : '${station.connectors.where((item) => item.availability == "available").length} of ${station.connectors.length} connectors reported available',
              ),
              if (station.distanceM != null)
                Text(
                  '${(station.distanceM! / 1000).toStringAsFixed(1)} km away · straight-line distance',
                ),
              if (station.lastSeenAt != null) ...[
                const SizedBox(height: VtsaSpacing.xs),
                Text(
                  'Last heard ${DateFormat('MMM d, HH:mm:ss').format(station.lastSeenAt!.toLocal())}',
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              ],
              if (station.statusObservedAt != null) ...[
                const SizedBox(height: VtsaSpacing.sm),
                Text(
                  'Observed ${DateFormat('MMM d, HH:mm').format(station.statusObservedAt!.toLocal())}',
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                    color: context.vtsaColors.textMuted,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    ),
  );
}

class ConnectorCompatibilityTile extends StatelessWidget {
  const ConnectorCompatibilityTile({
    required this.connector,
    this.vehicle,
    super.key,
  });

  final StationConnector connector;
  final Vehicle? vehicle;

  @override
  Widget build(BuildContext context) {
    final compatible = vehicle == null || vehicle!.connectorStandards.isEmpty
        ? null
        : vehicle!.supports(connector.standard);
    final iconAsset = connectorAsset(connector.standard);
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: CircleAvatar(
        backgroundColor: context.vtsaColors.brandSoft,
        child: iconAsset == null
            ? const Icon(Icons.ev_station_outlined)
            : Image.asset(
                'assets/interface/$iconAsset.png',
                width: 24,
                height: 24,
                excludeFromSemantics: true,
              ),
      ),
      title: Text(connector.name),
      subtitle: Text(
        '${connector.standard} · ${connector.powerLabel}\n${connector.statusLabel}',
      ),
      trailing: compatible == null
          ? null
          : Tooltip(
              message: compatible
                  ? 'Compatible with ${vehicle!.nickname}'
                  : 'Not listed for ${vehicle!.nickname}',
              child: Icon(
                compatible ? Icons.check_circle : Icons.info_outline,
                color: compatible
                    ? context.vtsaColors.success
                    : context.vtsaColors.warning,
              ),
            ),
    );
  }
}

class _FactChip extends StatelessWidget {
  const _FactChip({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(
      horizontal: VtsaSpacing.sm,
      vertical: VtsaSpacing.xs,
    ),
    decoration: BoxDecoration(
      color: context.vtsaColors.surfaceSubtle,
      borderRadius: BorderRadius.circular(VtsaRadii.pill),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 14),
        const SizedBox(width: VtsaSpacing.xxs),
        Text(label, style: Theme.of(context).textTheme.labelSmall),
      ],
    ),
  );
}
