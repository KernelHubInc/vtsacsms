import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_widgets.dart';

class StationDetailScreen extends StatelessWidget {
  const StationDetailScreen({
    required this.dependencies,
    required this.stationId,
    super.key,
  });

  final AppDependencies dependencies;
  final String stationId;

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: Listenable.merge([
      dependencies.discovery,
      dependencies.favorites,
      dependencies.vehicles,
    ]),
    builder: (context, _) {
      final station = dependencies.discovery.byId(stationId);
      if (station == null) {
        return VtsaPageShell(
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
      final vehicle = dependencies.vehicles.defaultVehicle;
      return VtsaPageShell(
        eyebrow: station.operatorName,
        title: station.siteName,
        actions: [
          IconButton(
            tooltip: favorite ? 'Remove from favorites' : 'Add to favorites',
            onPressed: () => dependencies.favorites.toggle(station.id),
            icon: Icon(favorite ? Icons.favorite : Icons.favorite_border),
          ),
        ],
        body: ListView(
          children: [
            Wrap(
              spacing: VtsaSpacing.xs,
              runSpacing: VtsaSpacing.xs,
              children: [
                VtsaStatusChip(
                  label: availabilityLabel(station),
                  state: operationalStateFor(station),
                ),
                Chip(label: Text(station.openNow ? 'Open now' : 'Closed now')),
                Chip(label: Text('Up to ${station.maximumPowerLabel}')),
              ],
            ),
            const SizedBox(height: VtsaSpacing.lg),
            Text(
              station.address ?? station.name,
              style: Theme.of(context).textTheme.bodyLarge,
            ),
            const SizedBox(height: VtsaSpacing.lg),
            VtsaButton(
              label: 'Directions',
              icon: Icons.directions_outlined,
              onPressed: () async {
                final opened = await dependencies.directions.open(
                  latitude: station.latitude,
                  longitude: station.longitude,
                );
                if (context.mounted && !opened) {
                  showVtsaToast(
                    context,
                    message: 'No navigation application could open directions.',
                  );
                }
              },
            ),
            const SizedBox(height: VtsaSpacing.sm),
            VtsaButton(
              label: 'Scan charger to start',
              icon: Icons.qr_code_scanner,
              variant: VtsaButtonVariant.secondary,
              onPressed: () => context.push('/charge'),
            ),
            const SizedBox(height: VtsaSpacing.xl),
            Text(
              'Connectors',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: VtsaSpacing.xs),
            Text(
              vehicle == null
                  ? 'Add a vehicle to see compatibility.'
                  : 'Compared with ${vehicle.nickname}. Confirm the physical connector before use.',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: context.vtsaColors.textMuted,
              ),
            ),
            for (final connector in station.connectors)
              ConnectorCompatibilityTile(
                connector: connector,
                vehicle: vehicle,
              ),
            const SizedBox(height: VtsaSpacing.lg),
            _DetailSection(
              title: 'Operating hours',
              emptyText:
                  'Detailed operating hours are not published for this station.',
              values: station.operatingHours,
            ),
            const SizedBox(height: VtsaSpacing.md),
            _DetailSection(
              title: 'Amenities',
              emptyText: 'No amenities are published for this station.',
              values: station.amenities,
            ),
            if (station.isStale) ...[
              const SizedBox(height: VtsaSpacing.lg),
              VtsaCard(
                eyebrow: 'DATA FRESHNESS',
                title: 'Treat availability as unknown',
                child: Text(
                  'The last status is older than the network freshness threshold. Refresh before relying on it.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ),
            ],
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
