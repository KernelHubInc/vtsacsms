import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_widgets.dart';

class StationDetailScreen extends StatefulWidget {
  const StationDetailScreen({
    required this.dependencies,
    required this.stationId,
    super.key,
  });
  final AppDependencies dependencies;
  final String stationId;

  @override
  State<StationDetailScreen> createState() => _StationDetailScreenState();
}

class _StationDetailScreenState extends State<StationDetailScreen> {
  String? _selectedId;

  bool _canSelect(Station station, StationConnector connector) =>
      !station.isStale &&
      station.connectionStatus == 'online' &&
      connector.id != null &&
      (connector.availability == 'available' ||
          (connector.availability == 'occupied' &&
              connector.reportedStatus == 'preparing'));

  Future<void> _directions(Station station) async {
    final opened = await widget.dependencies.directions.open(
      latitude: station.latitude,
      longitude: station.longitude,
    );
    if (mounted && !opened) {
      showVtsaToast(
        context,
        message: 'No navigation application could open directions.',
      );
    }
  }

  Widget _summary(
    BuildContext context,
    IconData icon,
    String label,
    String value,
  ) => Container(
    padding: const EdgeInsets.symmetric(vertical: 16),
    decoration: BoxDecoration(
      border: Border(bottom: BorderSide(color: context.vtsaColors.border)),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, color: Theme.of(context).colorScheme.primary),
        const SizedBox(width: 16),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: Theme.of(context).textTheme.labelSmall),
              const SizedBox(height: 5),
              Text(value),
            ],
          ),
        ),
      ],
    ),
  );

  Widget _connectorCard(
    BuildContext context,
    Station station,
    StationConnector connector,
    bool selected,
    bool? compatible,
  ) {
    final enabled = _canSelect(station, connector);
    final status = station.isStale
        ? 'Status stale'
        : station.connectionStatus == 'offline'
        ? 'Offline'
        : station.connectionStatus != 'online'
        ? 'Connection unconfirmed'
        : connector.statusLabel;
    final colors = context.vtsaColors;
    final color = enabled
        ? colors.success
        : connector.availability == 'faulted'
        ? colors.danger
        : colors.textMuted;
    return Semantics(
      button: true,
      selected: selected,
      enabled: enabled,
      child: Material(
        color: selected ? colors.brandSoft : color.withValues(alpha: 0.06),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: BorderSide(
            color: selected
                ? Theme.of(context).colorScheme.primary
                : color.withValues(alpha: 0.45),
            width: selected ? 2 : 1,
          ),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: enabled
              ? () => setState(() => _selectedId = connector.id)
              : null,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  status,
                  style: Theme.of(
                    context,
                  ).textTheme.labelLarge?.copyWith(color: color),
                ),
                const SizedBox(height: 12),
                Text(
                  '${connector.standard} · ${connector.name}',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 18),
                Text('OUTPUT', style: Theme.of(context).textTheme.labelSmall),
                Text(
                  connector.powerLabel,
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 12),
                Text('PRICE', style: Theme.of(context).textTheme.labelSmall),
                const Text('Shown at review'),
                const SizedBox(height: 14),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    if (connectorAsset(connector.standard) case final asset?)
                      Image.asset(
                        'assets/interface/$asset.png',
                        width: 24,
                        height: 24,
                        excludeFromSemantics: true,
                      ),
                    Text(connector.standard),
                    if (selected)
                      Icon(
                        Icons.check_circle,
                        size: 20,
                        color: Theme.of(context).colorScheme.primary,
                      ),
                  ],
                ),
                const SizedBox(height: 10),
                Text(
                  compatible == null
                      ? 'Check your vehicle connector'
                      : compatible
                      ? 'Compatible with your vehicle'
                      : 'Check vehicle compatibility',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: Listenable.merge([
      widget.dependencies.discovery,
      widget.dependencies.favorites,
      widget.dependencies.vehicles,
    ]),
    builder: (context, _) {
      final dependencies = widget.dependencies;
      final station = dependencies.discovery.byId(widget.stationId);
      if (station == null) {
        return DriverPage(
          title: 'Station',
          body: VtsaErrorState(
            title: 'Station is no longer in this search',
            description: 'Return to Explore and load the station area again.',
            action: VtsaButton(
              label: 'Back to Explore',
              onPressed: () => context.go('/explore'),
            ),
          ),
        );
      }
      final favorite = dependencies.favorites.contains(station.id);
      final selected = station.connectors
          .where((c) => c.id == _selectedId && _canSelect(station, c))
          .firstOrNull;
      final vehicle = dependencies.vehicles.defaultVehicle;
      return DriverPage(
        title: 'Charging station',
        bottomActions: LayoutBuilder(
          builder: (context, constraints) {
            final directions = VtsaButton(
              label: 'Directions',
              variant: VtsaButtonVariant.secondary,
              icon: Icons.directions_outlined,
              onPressed: () => _directions(station),
            );
            final charge = VtsaButton(
              label: selected == null
                  ? 'Choose a plug to continue'
                  : 'Continue with ${selected.standard}',
              onPressed: selected == null
                  ? null
                  : () => context.push('/charge?code=${selected.id}'),
            );
            if (constraints.maxWidth < 400 ||
                MediaQuery.textScalerOf(context).scale(1) > 1.3) {
              return Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [directions, const SizedBox(height: 8), charge],
              );
            }
            return Row(
              children: [
                Expanded(child: directions),
                const SizedBox(width: 12),
                Expanded(child: charge),
              ],
            );
          },
        ),
        body: ListView(
          children: [
            Text(
              station.name,
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            Text(station.address ?? station.siteName),
            if (station.distanceM != null)
              Text('${(station.distanceM! / 1000).toStringAsFixed(1)} km away'),
            const SizedBox(height: 20),
            _summary(
              context,
              Icons.bolt_outlined,
              'POWER OUTPUT',
              'Up to ${(station.maximumPowerW / 1000).toStringAsFixed(1)} kW',
            ),
            _summary(
              context,
              Icons.ev_station_outlined,
              'CHARGE POINTS',
              '${station.connectors.where((c) => _canSelect(station, c) && c.availability == 'available').length} of ${station.connectors.length} available · ${station.connectionLabel}',
            ),
            _summary(
              context,
              Icons.schedule_outlined,
              'OPERATING HOURS',
              !station.hoursKnown
                  ? 'Hours not provided'
                  : station.openNow
                  ? 'Open now'
                  : 'Closed now',
            ),
            const SizedBox(height: 20),
            Text(
              'Choose a plug',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 6),
            const Text(
              'Live pricing and your wallet reservation are shown before you start.',
            ),
            const SizedBox(height: 16),
            LayoutBuilder(
              builder: (context, constraints) {
                final twoColumns =
                    constraints.maxWidth >= 340 &&
                    MediaQuery.textScalerOf(context).scale(1) <= 1.2;
                final width = twoColumns
                    ? (constraints.maxWidth - 12) / 2
                    : constraints.maxWidth;
                return Wrap(
                  spacing: 12,
                  runSpacing: 12,
                  children: [
                    for (final connector in station.connectors)
                      SizedBox(
                        width: width,
                        child: _connectorCard(
                          context,
                          station,
                          connector,
                          selected?.id == connector.id && selected != null,
                          vehicle == null || vehicle.connectorStandards.isEmpty
                              ? null
                              : vehicle.supports(connector.standard),
                        ),
                      ),
                  ],
                );
              },
            ),
            const SizedBox(height: 16),
            if (station.connectors.isEmpty)
              const DriverNotice(
                title: 'Plug details pending',
                message:
                    'Charging availability is unconfirmed until the station publishes its connectors.',
              ),
            if (station.isStale)
              const DriverNotice(
                title: 'Availability needs a refresh',
                message:
                    'These are the last loaded details. Refresh before choosing a plug.',
              ),
            const SizedBox(height: 8),
            ExpansionTile(
              tilePadding: EdgeInsets.zero,
              expandedCrossAxisAlignment: CrossAxisAlignment.stretch,
              childrenPadding: const EdgeInsets.only(top: 8, bottom: 24),
              shape: const Border(),
              collapsedShape: const Border(),
              title: const Text('Station details & directions'),
              children: [
                VtsaCard(
                  key: const ValueKey('station-status-details'),
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Padding(
                            padding: EdgeInsets.only(top: 3),
                            child: Icon(Icons.sensors, size: 22),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  station.isStale
                                      ? 'Status needs a refresh'
                                      : station.connectionLabel,
                                  style: Theme.of(
                                    context,
                                  ).textTheme.titleMedium,
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  station.lastSeenAt == null
                                      ? 'No recent contact reported'
                                      : 'Last contact · ${DateFormat('MMM d, h:mm a').format(station.lastSeenAt!.toLocal())}',
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                              ],
                            ),
                          ),
                          IconButton(
                            tooltip: 'Refresh station status',
                            onPressed: dependencies.discovery.isLoading
                                ? null
                                : dependencies.discovery.refresh,
                            icon: const Icon(Icons.refresh),
                          ),
                        ],
                      ),
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 8),
                        child: Divider(height: 1),
                      ),
                      Semantics(
                        toggled: favorite,
                        child: InkWell(
                          borderRadius: BorderRadius.circular(8),
                          onTap: () =>
                              dependencies.favorites.toggle(station.id),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            child: Row(
                              children: [
                                Icon(
                                  favorite
                                      ? Icons.favorite
                                      : Icons.favorite_border,
                                  size: 22,
                                  color: Theme.of(context).colorScheme.primary,
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Text(
                                    favorite
                                        ? 'Remove from favorites'
                                        : 'Add to favorites',
                                    style: Theme.of(
                                      context,
                                    ).textTheme.titleMedium,
                                  ),
                                ),
                                if (favorite) const Icon(Icons.check, size: 20),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                const SizedBox(height: 8),
                VtsaButton(
                  label: 'Scan charger to start',
                  icon: Icons.qr_code_scanner,
                  variant: VtsaButtonVariant.secondary,
                  onPressed: () => context.push('/charge'),
                ),
                const SizedBox(height: 24),
                _DetailSection(
                  key: const ValueKey('station-hours-details'),
                  icon: Icons.schedule_outlined,
                  title: 'Operating hours',
                  emptyText:
                      'Hours not provided. Check with the site before visiting.',
                  values: station.operatingHours,
                ),
                const SizedBox(height: 12),
                _DetailSection(
                  key: const ValueKey('station-amenities-details'),
                  icon: Icons.local_cafe_outlined,
                  title: 'Amenities',
                  emptyText: 'No amenities listed yet.',
                  values: station.amenities,
                  chips: true,
                ),
              ],
            ),
          ],
        ),
      );
    },
  );
}

class FavoritesScreen extends StatelessWidget {
  const FavoritesScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: Listenable.merge([
      dependencies.discovery,
      dependencies.favorites,
    ]),
    builder: (context, _) {
      final stations = dependencies.discovery.stations
          .where((station) => dependencies.favorites.contains(station.id))
          .toList();
      return VtsaPageShell(
        eyebrow: 'SAVED PLACES',
        title: 'Favorite stations',
        body: stations.isEmpty
            ? VtsaEmptyState(
                icon: Icons.favorite_border,
                title: 'No favorites in the loaded area',
                description:
                    'Heart a station while exploring. Saved identifiers remain available, while live detail reload depends on the station being in the current bounded search.',
                action: VtsaButton(
                  label: 'Explore stations',
                  onPressed: () => context.go('/explore'),
                ),
              )
            : ListView.separated(
                itemCount: stations.length,
                separatorBuilder: (_, _) =>
                    const SizedBox(height: VtsaSpacing.sm),
                itemBuilder: (context, index) {
                  final station = stations[index];
                  return StationCard(
                    station: station,
                    favorite: true,
                    onFavorite: () => dependencies.favorites.toggle(station.id),
                    onTap: () => context.push('/stations/${station.id}'),
                  );
                },
              ),
      );
    },
  );
}

class _DetailSection extends StatelessWidget {
  const _DetailSection({
    required this.icon,
    required this.title,
    required this.emptyText,
    required this.values,
    this.chips = false,
    super.key,
  });

  final IconData icon;
  final String title;
  final String emptyText;
  final List<String> values;
  final bool chips;

  @override
  Widget build(BuildContext context) => VtsaCard(
    padding: const EdgeInsets.all(16),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(icon, size: 22, color: context.vtsaColors.textMuted),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                title,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (values.isEmpty)
          Text(
            emptyText,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              color: context.vtsaColors.textMuted,
            ),
          )
        else if (chips)
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final value in values)
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 6,
                  ),
                  decoration: BoxDecoration(
                    color: context.vtsaColors.surfaceSubtle,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (amenityAsset(value) case final asset?) ...[
                        Image.asset(
                          'assets/interface/$asset.png',
                          width: 24,
                          height: 24,
                          excludeFromSemantics: true,
                        ),
                        const SizedBox(width: 6),
                      ],
                      Text(value),
                    ],
                  ),
                ),
            ],
          )
        else
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (var index = 0; index < values.length; index++)
                Padding(
                  padding: EdgeInsets.only(top: index == 0 ? 0 : 8),
                  child: Text(values[index]),
                ),
            ],
          ),
      ],
    ),
  );
}
