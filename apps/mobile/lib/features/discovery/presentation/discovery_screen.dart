import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_app_bar.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/domain/station_repository.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_map.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_widgets.dart';

class DiscoveryScreen extends StatefulWidget {
  const DiscoveryScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<DiscoveryScreen> createState() => _DiscoveryScreenState();
}

class _DiscoveryScreenState extends State<DiscoveryScreen> {
  bool _showMap = true;
  bool _locating = false;
  MapPosition? _userPosition;

  @override
  void initState() {
    super.initState();
    final maps = widget.dependencies.environment.maps;
    _showMap = maps.provider.name == 'google'
        ? maps.googleReady
        : maps.tileConfigurationValid;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (widget.dependencies.discovery.stations.isEmpty) {
        widget.dependencies.discovery.loadBounds(
          const GeoBounds(
            west: 120.85,
            south: 14.45,
            east: 121.15,
            north: 14.78,
          ),
        );
      }
    });
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: Listenable.merge([
      widget.dependencies.discovery,
      widget.dependencies.favorites,
      widget.dependencies.network,
    ]),
    builder: (context, _) {
      final discovery = widget.dependencies.discovery;
      return Scaffold(
        appBar: PowerSolutionsAppBar(
          title: 'Find your next charge',
          eyebrow: 'Explore',
          actions: [
            IconButton(
              tooltip: 'Scan charger code',
              onPressed: () => context.push('/charge'),
              icon: const Icon(Icons.qr_code_scanner),
            ),
            IconButton(
              tooltip: 'Favorites',
              onPressed: () => context.push('/favorites'),
              icon: const Icon(Icons.favorite_border),
            ),
          ],
        ),
        body: Column(
          children: [
            if (widget.dependencies.environment.maps.fallbackActive)
              MaterialBanner(
                content: const Text(
                  'Google Maps is not configured. OpenStreetMap is active.',
                ),
                leading: const Icon(Icons.map_outlined),
                actions: const [SizedBox.shrink()],
              ),
            if (!widget.dependencies.network.isOnline)
              MaterialBanner(
                content: const Text(
                  'Offline. Showing the last loaded station data; availability may be stale.',
                ),
                leading: const Icon(Icons.cloud_off_outlined),
                actions: const [SizedBox.shrink()],
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(
                VtsaSpacing.md,
                VtsaSpacing.sm,
                VtsaSpacing.md,
                VtsaSpacing.sm,
              ),
              child: Row(
                children: [
                  Expanded(
                    child: SearchBar(
                      hintText: 'Search this map area',
                      leading: const Icon(Icons.search),
                      onChanged: discovery.search,
                    ),
                  ),
                  const SizedBox(width: VtsaSpacing.xs),
                  IconButton.filledTonal(
                    tooltip: 'Filters',
                    onPressed: () => _showFilters(context),
                    icon: Badge(
                      isLabelVisible: _filterCount(discovery.filters) > 0,
                      label: Text('${_filterCount(discovery.filters)}'),
                      child: const Icon(Icons.tune),
                    ),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: VtsaSpacing.md),
              child: Row(
                children: [
                  SegmentedButton<bool>(
                    segments: const [
                      ButtonSegment(
                        value: true,
                        icon: Icon(Icons.map_outlined),
                        label: Text('Map'),
                      ),
                      ButtonSegment(
                        value: false,
                        icon: Icon(Icons.view_list_outlined),
                        label: Text('List'),
                      ),
                    ],
                    selected: {_showMap},
                    onSelectionChanged: (selection) => setState(() {
                      _showMap = selection.first;
                    }),
                  ),
                  const Spacer(),
                  Text(
                    '${discovery.stations.length} nearby',
                    style: Theme.of(context).textTheme.labelMedium,
                  ),
                ],
              ),
            ),
            const SizedBox(height: VtsaSpacing.sm),
            Expanded(
              child: Stack(
                children: [
                  if (_showMap)
                    StationMap(
                      discovery: discovery,
                      configuration: widget.dependencies.environment.maps,
                      userPosition: _userPosition,
                      onProviderFailure: () {
                        if (mounted) setState(() => _showMap = false);
                      },
                    )
                  else
                    _StationList(dependencies: widget.dependencies),
                  if (_showMap)
                    Positioned(
                      top: 12,
                      right: 16,
                      child: FloatingActionButton.small(
                        heroTag: 'discovery-current-location',
                        tooltip: 'Show my location',
                        onPressed: _locating ? null : _locateUser,
                        child: _locating
                            ? const SizedBox.square(
                                dimension: 18,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : const Icon(Icons.my_location),
                      ),
                    ),
                  if (discovery.isLoading)
                    const Positioned(
                      top: 8,
                      left: 16,
                      right: 16,
                      child: LinearProgressIndicator(),
                    ),
                  if (_showMap && discovery.selectedStationId != null)
                    Positioned(
                      left: VtsaSpacing.md,
                      right: VtsaSpacing.md,
                      bottom: VtsaSpacing.md,
                      child: Builder(
                        builder: (context) {
                          final station = discovery.byId(
                            discovery.selectedStationId!,
                          );
                          return station == null
                              ? const SizedBox.shrink()
                              : StationCard(
                                  station: station,
                                  favorite: widget.dependencies.favorites
                                      .contains(station.id),
                                  onFavorite: () => widget
                                      .dependencies
                                      .favorites
                                      .toggle(station.id),
                                  onTap: () =>
                                      context.push('/stations/${station.id}'),
                                  selected: true,
                                );
                        },
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      );
    },
  );

  Future<void> _locateUser() async {
    setState(() => _locating = true);
    try {
      final position = await widget.dependencies.location.currentPosition();
      if (!mounted) return;
      if (position == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Location is unavailable or permission was not granted.',
            ),
          ),
        );
        return;
      }
      setState(() => _userPosition = position);
    } on Exception {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Your location could not be retrieved right now.'),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<void> _showFilters(BuildContext context) async {
    var working = widget.dependencies.discovery.filters;
    final connectors =
        widget.dependencies.discovery.stations
            .expand((station) => station.connectors)
            .map((connector) => connector.standard)
            .toSet()
            .toList()
          ..sort();
    final operators = {
      for (final station in widget.dependencies.discovery.stations)
        station.operatorId: station.operatorName,
    };
    final selected = await showModalBottomSheet<StationFilters>(
      context: context,
      isScrollControlled: true,
      builder: (context) => StatefulBuilder(
        builder: (context, setSheetState) => SafeArea(
          child: Padding(
            padding: EdgeInsets.fromLTRB(
              VtsaSpacing.lg,
              VtsaSpacing.lg,
              VtsaSpacing.lg,
              MediaQuery.viewInsetsOf(context).bottom + VtsaSpacing.lg,
            ),
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Refine this area',
                    style: Theme.of(context).textTheme.headlineSmall,
                  ),
                  const SizedBox(height: VtsaSpacing.lg),
                  DropdownButtonFormField<String?>(
                    initialValue: working.connector,
                    decoration: const InputDecoration(
                      labelText: 'Connector standard',
                    ),
                    items: [
                      const DropdownMenuItem(value: null, child: Text('Any')),
                      for (final connector in connectors)
                        DropdownMenuItem(
                          value: connector,
                          child: Text(connector),
                        ),
                    ],
                    onChanged: (value) => setSheetState(
                      () => working = working.copyWith(
                        connector: value,
                        clearConnector: value == null,
                      ),
                    ),
                  ),
                  const SizedBox(height: VtsaSpacing.md),
                  DropdownButtonFormField<int?>(
                    initialValue: working.minimumPowerW,
                    decoration: const InputDecoration(
                      labelText: 'Minimum power',
                    ),
                    items: const [
                      DropdownMenuItem(value: null, child: Text('Any')),
                      DropdownMenuItem(value: 7000, child: Text('7 kW+')),
                      DropdownMenuItem(value: 22000, child: Text('22 kW+')),
                      DropdownMenuItem(value: 50000, child: Text('50 kW+')),
                      DropdownMenuItem(value: 100000, child: Text('100 kW+')),
                    ],
                    onChanged: (value) => setSheetState(
                      () => working = working.copyWith(
                        minimumPowerW: value,
                        clearMinimumPower: value == null,
                      ),
                    ),
                  ),
                  const SizedBox(height: VtsaSpacing.md),
                  DropdownButtonFormField<StationAvailability?>(
                    initialValue: working.availability,
                    decoration: const InputDecoration(
                      labelText: 'Availability',
                    ),
                    items: const [
                      DropdownMenuItem(value: null, child: Text('Any')),
                      DropdownMenuItem(
                        value: StationAvailability.available,
                        child: Text('Available'),
                      ),
                      DropdownMenuItem(
                        value: StationAvailability.busy,
                        child: Text('In use'),
                      ),
                      DropdownMenuItem(
                        value: StationAvailability.offline,
                        child: Text('Offline'),
                      ),
                    ],
                    onChanged: (value) => setSheetState(
                      () => working = working.copyWith(
                        availability: value,
                        clearAvailability: value == null,
                      ),
                    ),
                  ),
                  if (operators.isNotEmpty) ...[
                    const SizedBox(height: VtsaSpacing.md),
                    DropdownButtonFormField<String?>(
                      initialValue: working.operatorId,
                      decoration: const InputDecoration(labelText: 'Operator'),
                      items: [
                        const DropdownMenuItem(value: null, child: Text('Any')),
                        for (final entry in operators.entries)
                          DropdownMenuItem(
                            value: entry.key,
                            child: Text(entry.value),
                          ),
                      ],
                      onChanged: (value) => setSheetState(
                        () => working = StationFilters(
                          connector: working.connector,
                          minimumPowerW: working.minimumPowerW,
                          availability: working.availability,
                          operatorId: value,
                          siteType: working.siteType,
                          openNow: working.openNow,
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: VtsaSpacing.md),
                  DropdownButtonFormField<String?>(
                    initialValue: working.siteType,
                    decoration: const InputDecoration(labelText: 'Site type'),
                    items: const [
                      DropdownMenuItem(value: null, child: Text('Any')),
                      DropdownMenuItem(
                        value: 'public_parking',
                        child: Text('Public parking'),
                      ),
                      DropdownMenuItem(value: 'retail', child: Text('Retail')),
                      DropdownMenuItem(
                        value: 'workplace',
                        child: Text('Workplace'),
                      ),
                      DropdownMenuItem(value: 'fleet', child: Text('Fleet')),
                      DropdownMenuItem(
                        value: 'highway',
                        child: Text('Highway'),
                      ),
                      DropdownMenuItem(
                        value: 'hospitality',
                        child: Text('Hospitality'),
                      ),
                    ],
                    onChanged: (value) => setSheetState(
                      () => working = StationFilters(
                        connector: working.connector,
                        minimumPowerW: working.minimumPowerW,
                        availability: working.availability,
                        operatorId: working.operatorId,
                        siteType: value,
                        openNow: working.openNow,
                      ),
                    ),
                  ),
                  SwitchListTile.adaptive(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Open now'),
                    value: working.openNow,
                    onChanged: (value) => setSheetState(
                      () => working = working.copyWith(openNow: value),
                    ),
                  ),
                  const SizedBox(height: VtsaSpacing.md),
                  Row(
                    children: [
                      Expanded(
                        child: VtsaButton(
                          label: 'Clear',
                          variant: VtsaButtonVariant.secondary,
                          onPressed: () =>
                              Navigator.pop(context, const StationFilters()),
                        ),
                      ),
                      const SizedBox(width: VtsaSpacing.sm),
                      Expanded(
                        child: VtsaButton(
                          label: 'Apply filters',
                          onPressed: () => Navigator.pop(context, working),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
    if (selected != null) {
      await widget.dependencies.discovery.updateFilters(selected);
    }
  }

  int _filterCount(StationFilters filters) => [
    filters.connector,
    filters.minimumPowerW,
    filters.availability,
    filters.operatorId,
    filters.siteType,
    filters.openNow ? true : null,
  ].where((value) => value != null).length;
}

class _StationList extends StatelessWidget {
  const _StationList({required this.dependencies});

  final AppDependencies dependencies;

  @override
  Widget build(BuildContext context) {
    final discovery = dependencies.discovery;
    if (discovery.failure != null && discovery.stations.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(VtsaSpacing.md),
        child: VtsaErrorState(
          title: 'Stations did not load',
          description: discovery.failure!.message,
          correlationId: discovery.failure!.correlationId,
          action: VtsaButton(label: 'Try again', onPressed: discovery.refresh),
        ),
      );
    }
    if (discovery.isLoading && discovery.stations.isEmpty) {
      return const Padding(
        padding: EdgeInsets.all(VtsaSpacing.md),
        child: VtsaSkeleton(lines: 6),
      );
    }
    if (discovery.stations.isEmpty) {
      return const Padding(
        padding: EdgeInsets.all(VtsaSpacing.md),
        child: VtsaEmptyState(
          icon: Icons.ev_station_outlined,
          title: 'No chargers in this view',
          description: 'Move the map or relax a filter to search another area.',
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: discovery.refresh,
      child: ListView.separated(
        padding: const EdgeInsets.all(VtsaSpacing.md),
        itemCount: discovery.stations.length,
        separatorBuilder: (_, _) => const SizedBox(height: VtsaSpacing.sm),
        itemBuilder: (context, index) {
          final station = discovery.stations[index];
          return StationCard(
            station: station,
            selected: discovery.selectedStationId == station.id,
            favorite: dependencies.favorites.contains(station.id),
            onFavorite: () => dependencies.favorites.toggle(station.id),
            onTap: () {
              discovery.select(station.id);
              context.push('/stations/${station.id}');
            },
          );
        },
      ),
    );
  }
}
