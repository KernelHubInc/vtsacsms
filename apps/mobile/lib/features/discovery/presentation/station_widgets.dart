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
    StationAvailability.offline => VtsaOperationalState.offline,
    StationAvailability.stale => VtsaOperationalState.unknown,
    StationAvailability.unknown => VtsaOperationalState.unknown,
  };
}

String availabilityLabel(Station station) {
  if (station.isStale || station.availability == StationAvailability.stale) {
    return 'Status stale';
  }
  return switch (station.availability) {
    StationAvailability.available => 'Available',
    StationAvailability.busy => 'In use',
    StationAvailability.faulted => 'Fault reported',
    StationAvailability.offline => 'Offline',
    StationAvailability.stale => 'Status stale',
    StationAvailability.unknown => 'Status unknown',
  };
}

class StationCard extends StatelessWidget {
  const StationCard({
    required this.station,
    required this.onTap,
    required this.favorite,
    required this.onFavorite,
    this.selected = false,
    super.key,
  });

  final Station station;
  final VoidCallback onTap;
  final bool favorite;
  final VoidCallback onFavorite;
  final bool selected;

  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    selected: selected,
    label:
        '${station.siteName}. ${availabilityLabel(station)}. ${station.maximumPowerLabel}.',
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
                          station.siteName,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: VtsaSpacing.xxs),
                        Text(
                          station.address ?? station.name,
                          style: Theme.of(context).textTheme.bodySmall
                              ?.copyWith(color: context.vtsaColors.textMuted),
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
                ],
              ),
              const SizedBox(height: VtsaSpacing.sm),
              Wrap(
                spacing: VtsaSpacing.xs,
                runSpacing: VtsaSpacing.xs,
                children: [
                  VtsaStatusChip(
                    label: availabilityLabel(station),
                    state: operationalStateFor(station),
                  ),
                  _FactChip(
                    icon: Icons.bolt_outlined,
                    label: 'Up to ${station.maximumPowerLabel}',
                  ),
                  _FactChip(
                    icon: station.openNow
                        ? Icons.schedule
                        : Icons.schedule_outlined,
                    label: station.openNow ? 'Open now' : 'Closed now',
                  ),
                ],
              ),
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
    final compatible = vehicle?.supports(connector.standard);
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: CircleAvatar(
        backgroundColor: context.vtsaColors.brandSoft,
        child: const Icon(Icons.ev_station_outlined),
      ),
      title: Text(connector.name),
      subtitle: Text('${connector.standard} · ${connector.powerLabel}'),
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
