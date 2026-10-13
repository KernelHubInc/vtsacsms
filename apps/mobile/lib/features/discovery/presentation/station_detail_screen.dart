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
        title: 'A good place to charge',
        body: ListView(
          children: [
            DriverNotice(
              title: station.name,
              message:
                  '${station.address ?? station.siteName}\n${!station.hoursKnown
                      ? 'Hours not provided'
                      : station.openNow
                      ? 'Open now'
                      : 'Closed now'} · ${station.connectionLabel}',
            ),
            const SizedBox(height: 24),
            Text(
              'Choose a plug',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 4),
            const Text('We’ll show you the price before you begin.'),
            const SizedBox(height: 16),
            for (final connector in station.connectors)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Semantics(
                  selected: selected?.id == connector.id && selected != null,
                  button: true,
                  child: VtsaCard(
                    padding: EdgeInsets.zero,
                    selected: selected?.id == connector.id && selected != null,
                    child: InkWell(
                      borderRadius: BorderRadius.circular(14),
                      onTap: _canSelect(station, connector)
                          ? () => setState(() => _selectedId = connector.id)
                          : null,
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Wrap(
                              spacing: 12,
                              runSpacing: 8,
                              crossAxisAlignment: WrapCrossAlignment.center,
                              children: [
                                Text(
                                  '${connector.standard} · ${connector.name}',
                                  style: Theme.of(
                                    context,
                                  ).textTheme.titleMedium,
                                ),
                                VtsaStatusChip(
                                  label: station.isStale
                                      ? 'Status stale'
                                      : connector.statusLabel,
                                  state: _canSelect(station, connector)
                                      ? VtsaOperationalState.available
                                      : VtsaOperationalState.unavailable,
                                ),
                              ],
                            ),
                            const SizedBox(height: 6),
                            Text(
                              'Up to ${(connector.maximumPowerW / 1000).toStringAsFixed(1)} kW',
                            ),
                            const SizedBox(height: 6),
                            Text(
                              vehicle == null
                                  ? 'Price confirmed in the next step'
                                  : vehicle.supports(connector.standard)
                                  ? 'Compatible with ${vehicle.nickname}'
                                  : 'Check compatibility with ${vehicle.nickname}',
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
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
            const DriverNotice(
              title: 'No surprises',
              message:
                  'Review the tariff and reserved wallet budget before charging.',
              positive: true,
            ),
            const SizedBox(height: 16),
            VtsaButton(
              label: selected == null
                  ? 'Choose a plug to continue'
                  : 'Continue with ${selected.standard}',
              onPressed: selected == null
                  ? null
                  : () => context.push(
                      Uri(
                        path: '/charge',
                        queryParameters: {'code': selected.id!},
                      ).toString(),
                    ),
            ),
            const SizedBox(height: 8),
            Text(
              'Availability can change while you travel.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 16),
            ExpansionTile(
              tilePadding: EdgeInsets.zero,
              title: const Text('Station details & directions'),
              children: [
                Wrap(
                  children: [
                    TextButton.icon(
                      onPressed: dependencies.discovery.refresh,
                      icon: const Icon(Icons.refresh),
                      label: const Text('Refresh station status'),
                    ),
                    TextButton.icon(
                      onPressed: () =>
                          dependencies.favorites.toggle(station.id),
                      icon: Icon(
                        favorite ? Icons.favorite : Icons.favorite_border,
                      ),
                      label: Text(
                        favorite ? 'Remove from favorites' : 'Add to favorites',
                      ),
                    ),
                  ],
                ),
                if (station.lastSeenAt != null)
                  Text(
                    'Last heard ${DateFormat('MMM d, HH:mm:ss').format(station.lastSeenAt!.toLocal())}',
                  ),
                VtsaButton(
                  label: 'Directions',
                  icon: Icons.directions_outlined,
                  variant: VtsaButtonVariant.secondary,
                  onPressed: () async {
                    final opened = await dependencies.directions.open(
                      latitude: station.latitude,
                      longitude: station.longitude,
                    );
                    if (context.mounted && !opened) {
                      showVtsaToast(
                        context,
                        message:
                            'No navigation application could open directions.',
                      );
                    }
                  },
                ),
                VtsaButton(
                  label: 'Scan charger to start',
                  icon: Icons.qr_code_scanner,
                  variant: VtsaButtonVariant.quiet,
                  onPressed: () => context.push('/charge'),
                ),
                _DetailSection(
                  title: 'Operating hours',
                  emptyText: 'Hours have not been published.',
                  values: station.operatingHours,
                ),
                const SizedBox(height: 12),
                _DetailSection(
                  title: 'Amenities',
                  emptyText: 'No amenities are published.',
                  values: station.amenities,
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
    required this.title,
    required this.emptyText,
    required this.values,
  });

  final String title;
  final String emptyText;
  final List<String> values;

  @override
  Widget build(BuildContext context) => VtsaCard(
    title: title,
    child: values.isEmpty
        ? Text(
            emptyText,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              color: context.vtsaColors.textMuted,
            ),
          )
        : Wrap(
            spacing: VtsaSpacing.xs,
            runSpacing: VtsaSpacing.xs,
            children: [for (final value in values) Chip(label: Text(value))],
          ),
  );
}
