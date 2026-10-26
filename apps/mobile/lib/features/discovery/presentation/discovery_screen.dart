import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/domain/station_repository.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_map.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_widgets.dart';

class _MapButton extends StatelessWidget {
  const _MapButton({
    required this.tooltip,
    required this.icon,
    required this.onPressed,
    this.loading = false,
  });
  final String tooltip;
  final IconData icon;
  final VoidCallback? onPressed;
  final bool loading;

  @override
  Widget build(BuildContext context) => Material(
    color: Theme.of(context).colorScheme.surface,
    elevation: 3,
    borderRadius: BorderRadius.circular(14),
    child: IconButton(
      tooltip: tooltip,
      onPressed: onPressed,
      constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
      icon: loading
          ? const SizedBox.square(
              dimension: 20,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : Icon(icon),
    ),
  );
}

class DiscoveryScreen extends StatefulWidget {
  const DiscoveryScreen({
    required this.dependencies,
    this.mapBuilder,
    super.key,
  });

  final AppDependencies dependencies;
  final StationMapBuilder? mapBuilder;

  @override
  State<DiscoveryScreen> createState() => _DiscoveryScreenState();
}

class _DiscoveryScreenState extends State<DiscoveryScreen> {
  bool _showMap = true;
  bool _locating = false;
  bool _mapExpanded = false;
  final _mapKey = GlobalKey();
  String? _vehicleId;
  MapPosition? _userPosition;

  @override
  void initState() {
    super.initState();
    final maps = widget.dependencies.environment.maps;
    _showMap = maps.provider.name == 'google'
        ? maps.googleReady
        : maps.tileConfigurationValid;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      unawaited(_locateUser(initial: true));
    });
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: Listenable.merge([
      widget.dependencies.discovery,
      widget.dependencies.favorites,
      widget.dependencies.network,
      widget.dependencies.vehicles,
    ]),
    builder: (context, _) {
      final discovery = widget.dependencies.discovery;
      return Theme(
        data: DriverTheme.of(context),
        child: Builder(
          builder: (context) => PopScope(
            canPop: !_mapExpanded,
            onPopInvokedWithResult: (didPop, _) {
              if (!didPop && _mapExpanded) setState(() => _mapExpanded = false);
            },
            child: Scaffold(
              body: SafeArea(
                bottom: false,
                child: _mapExpanded
                    ? _map(context)
                    : Center(
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 600),
                          child: RefreshIndicator(
                            onRefresh: discovery.refresh,
                            child: ListView(
                              padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                              children: [
                                Row(
                                  children: [
                                    Expanded(
                                      child: Align(
                                        alignment: Alignment.centerLeft,
                                        child: PowerSolutionsLogo(
                                          height: 30,
                                          inverse:
                                              Theme.of(context).brightness ==
                                              Brightness.dark,
                                        ),
                                      ),
                                    ),
                                    IconButton(
                                      tooltip: 'Scan charger code',
                                      onPressed: () => context.push('/charge'),
                                      icon: const Icon(Icons.qr_code_scanner),
                                    ),
                                    IconButton(
                                      tooltip: 'Favorites',
                                      onPressed: () =>
                                          context.push('/favorites'),
                                      icon: const Icon(Icons.favorite_border),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 12),
                                Text(
                                  'Where would you like to charge?',
                                  style: Theme.of(
                                    context,
                                  ).textTheme.headlineSmall,
                                ),
                                const SizedBox(height: 6),
                                Text(
                                  'Let’s find a spot that works for you.',
                                  style: Theme.of(context).textTheme.bodySmall,
                                ),
                                const SizedBox(height: 20),
                                if (!widget.dependencies.network.isOnline)
                                  const Padding(
                                    padding: EdgeInsets.only(bottom: 12),
                                    child: DriverNotice(
                                      title: 'You’re offline',
                                      message:
                                          'Showing the last loaded stations. Refresh before relying on availability.',
                                    ),
                                  ),
                                TextField(
                                  onChanged: discovery.search,
                                  decoration: InputDecoration(
                                    hintText: 'Address, place or station',
                                    helperText:
                                        'Search within the loaded map area',
                                    prefixIcon: const Icon(Icons.search),
                                    suffixIcon: IconButton(
                                      tooltip: 'Filters',
                                      onPressed: () => _showFilters(context),
                                      icon: Badge(
                                        isLabelVisible:
                                            _filterCount(discovery.filters) > 0,
                                        label: Text(
                                          '${_filterCount(discovery.filters)}',
                                        ),
                                        child: const Icon(Icons.tune),
                                      ),
                                    ),
                                    filled: true,
                                    fillColor: context.vtsaColors.surfaceSubtle,
                                    border: OutlineInputBorder(
                                      borderRadius: BorderRadius.circular(14),
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 16),
                                if (_showMap)
                                  ClipRRect(
                                    borderRadius: BorderRadius.circular(16),
                                    child: SizedBox(
                                      height:
                                          (MediaQuery.sizeOf(context).height *
                                                  .42)
                                              .clamp(300.0, 440.0),
                                      child: _map(context),
                                    ),
                                  ),
                                Wrap(
                                  spacing: 8,
                                  children: [
                                    TextButton.icon(
                                      onPressed: () =>
                                          setState(() => _showMap = !_showMap),
                                      icon: Icon(
                                        _showMap
                                            ? Icons.view_list_outlined
                                            : Icons.map_outlined,
                                      ),
                                      label: Text(
                                        _showMap ? 'Hide map' : 'Show map',
                                      ),
                                    ),
                                    TextButton.icon(
                                      onPressed: _locating
                                          ? null
                                          : () => _locateUser(nearest: true),
                                      icon: const Icon(Icons.near_me_outlined),
                                      label: const Text('Nearest first'),
                                    ),
                                  ],
                                ),
                                if (widget
                                    .dependencies
                                    .vehicles
                                    .vehicles
                                    .isNotEmpty)
                                  DropdownButton<String>(
                                    isExpanded: true,
                                    hint: const Text('All vehicles'),
                                    value:
                                        widget.dependencies.vehicles.vehicles
                                            .any((v) => v.id == _vehicleId)
                                        ? _vehicleId
                                        : null,
                                    items: [
                                      const DropdownMenuItem(
                                        value: null,
                                        child: Text('All connectors'),
                                      ),
                                      ...widget.dependencies.vehicles.vehicles.map(
                                        (v) => DropdownMenuItem(
                                          value: v.id,
                                          enabled:
                                              v.connectorStandards.isNotEmpty,
                                          child: Text(
                                            v.connectorStandards.isEmpty
                                                ? '${v.nickname} · add connectors'
                                                : v.nickname,
                                          ),
                                        ),
                                      ),
                                    ],
                                    onChanged: (id) {
                                      setState(() => _vehicleId = id);
                                      final vehicle = widget
                                          .dependencies
                                          .vehicles
                                          .vehicles
                                          .where((v) => v.id == id)
                                          .firstOrNull;
                                      discovery.updateFilters(
                                        discovery.filters.copyWith(
                                          clearConnector: true,
                                          vehicleConnectors:
                                              vehicle?.connectorStandards
                                                  .toList() ??
                                              [],
                                        ),
                                      );
                                    },
                                  ),
                                if (discovery.selectedStationId != null)
                                  Builder(
                                    builder: (context) {
                                      final station = discovery.byId(
                                        discovery.selectedStationId!,
                                      );
                                      return station == null
                                          ? const SizedBox.shrink()
                                          : Padding(
                                              padding: const EdgeInsets.only(
                                                bottom: 16,
                                              ),
                                              child: StationCard(
                                                station: station,
                                                selected: true,
                                                favorite: widget
                                                    .dependencies
                                                    .favorites
                                                    .contains(station.id),
                                                onFavorite: () => widget
                                                    .dependencies
                                                    .favorites
                                                    .toggle(station.id),
                                                onClose: () =>
                                                    discovery.select(null),
                                                onTap: () => context.push(
                                                  '/stations/${station.id}',
                                                ),
                                              ),
                                            );
                                    },
                                  ),
                                Row(
                                  children: [
                                    Expanded(
                                      child: Text(
                                        'Good options in this area',
                                        style: Theme.of(
                                          context,
                                        ).textTheme.titleLarge,
                                      ),
                                    ),
                                    TextButton(
                                      onPressed: () => _showFilters(context),
                                      child: const Text('Filters'),
                                    ),
                                  ],
                                ),
                                if (discovery.isLoading)
                                  const LinearProgressIndicator(),
                                _StationList(dependencies: widget.dependencies),
                              ],
                            ),
                          ),
                        ),
                      ),
              ),
            ),
          ),
        ),
      );
    },
  );

  Widget _map(BuildContext context) {
    final discovery = widget.dependencies.discovery;
    final station = discovery.selectedStationId == null
        ? null
        : discovery.byId(discovery.selectedStationId!);
    return Stack(
      fit: StackFit.expand,
      children: [
        StationMap(
          key: _mapKey,
          discovery: discovery,
          configuration: widget.dependencies.environment.maps,
          userPosition: _userPosition,
          openStreetMapBuilder: widget.mapBuilder,
          googleMapsBuilder: widget.mapBuilder,
          onProviderFailure: () {
            if (mounted) {
              setState(() {
                _showMap = false;
                _mapExpanded = false;
              });
            }
          },
        ),
        Positioned(
          top: 12,
          right: 12,
          child: Column(
            children: [
              _MapButton(
                tooltip: _mapExpanded ? 'Close expanded map' : 'Expand map',
                icon: _mapExpanded ? Icons.close : Icons.fullscreen,
                onPressed: () => setState(() => _mapExpanded = !_mapExpanded),
              ),
              const SizedBox(height: 8),
              _MapButton(
                tooltip: 'Show my location',
                icon: Icons.my_location,
                loading: _locating,
                onPressed: _locating ? null : _locateUser,
              ),
            ],
          ),
        ),
        if (_mapExpanded && station != null)
          Positioned(
            left: 16,
            right: 16,
            bottom: 44,
            child: ConstrainedBox(
              constraints: BoxConstraints(
                maxHeight: MediaQuery.sizeOf(context).height * .4,
              ),
              child: SingleChildScrollView(
                child: StationCard(
                  station: station,
                  selected: true,
                  favorite: widget.dependencies.favorites.contains(station.id),
                  onFavorite: () =>
                      widget.dependencies.favorites.toggle(station.id),
                  onClose: () => discovery.select(null),
                  onTap: () => context.push('/stations/${station.id}'),
                ),
              ),
            ),
          ),
      ],
    );
  }

  Future<void> _locateUser({bool nearest = false, bool initial = false}) async {
    if (!mounted) return;
    setState(() => _locating = true);
    try {
      final position = await widget.dependencies.location.currentPosition();
      if (!mounted) return;
      if (position == null) {
        if (initial) {
          await _loadDefaultArea();
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text(
                'Location is unavailable or permission was not granted.',
              ),
            ),
          );
        }
        return;
      }
      setState(() {
        // A fresh value also recenters when GPS returns the same coordinates.
        _userPosition = MapPosition(position.latitude, position.longitude);
        if (nearest) _showMap = false;
      });
      if (nearest || initial) {
        await widget.dependencies.discovery.loadNearby(
          latitude: position.latitude,
          longitude: position.longitude,
          radiusM: 50000,
        );
      }
    } on Exception {
      if (mounted) {
        if (initial) {
          await _loadDefaultArea();
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Your location could not be retrieved right now.'),
            ),
          );
        }
      }
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<void> _loadDefaultArea() async {
    if (widget.dependencies.discovery.stations.isNotEmpty) return;
    await widget.dependencies.discovery.loadBounds(
      const GeoBounds(west: 120.85, south: 14.45, east: 121.15, north: 14.78),
    );
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
    if (working.connector != null && !connectors.contains(working.connector)) {
      connectors.add(working.connector!);
    }
    final operators = {
      for (final station in widget.dependencies.discovery.stations)
        station.operatorId: station.operatorName,
    };
    if (working.operatorId != null) {
      operators.putIfAbsent(working.operatorId!, () => 'Selected operator');
    }
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
                        child: Text('Offline or unavailable'),
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
                          amenity: working.amenity,
                          vehicleConnectors: working.vehicleConnectors,
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
                        amenity: working.amenity,
                        vehicleConnectors: working.vehicleConnectors,
                        connector: working.connector,
                        minimumPowerW: working.minimumPowerW,
                        availability: working.availability,
                        operatorId: working.operatorId,
                        siteType: value,
                        openNow: working.openNow,
                      ),
                    ),
                  ),
                  const SizedBox(height: VtsaSpacing.md),
                  DropdownButtonFormField<String>(
                    initialValue: working.amenity,
                    decoration: const InputDecoration(labelText: 'Amenity'),
                    items: const [
                      DropdownMenuItem(value: null, child: Text('Any')),
                      DropdownMenuItem(value: 'food', child: Text('Food')),
                      DropdownMenuItem(
                        value: 'shopping',
                        child: Text('Shopping'),
                      ),
                      DropdownMenuItem(
                        value: 'restroom',
                        child: Text('Restrooms'),
                      ),
                    ],
                    onChanged: (value) => setSheetState(
                      () => working = working.copyWith(
                        amenity: value,
                        clearAmenity: value == null,
                      ),
                    ),
                  ),
                  SwitchListTile.adaptive(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Open now'),
                    subtitle: const Text(
                      'Uses published hours in the station’s local time. Stations without hours are excluded.',
                    ),
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
      if (selected.vehicleConnectors.isEmpty) setState(() => _vehicleId = null);
      await widget.dependencies.discovery.updateFilters(selected);
    }
  }

  int _filterCount(StationFilters filters) => [
    filters.connector,
    filters.amenity,
    filters.vehicleConnectors.isNotEmpty ? true : null,
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
        child: SingleChildScrollView(
          child: VtsaEmptyState(
            icon: Icons.ev_station_outlined,
            title: 'No chargers in this view',
            description:
                'Move the map or relax a filter to search another area.',
          ),
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: discovery.refresh,
      child: ListView.separated(
        padding: const EdgeInsets.only(top: 8),
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        itemCount: discovery.stations.length,
        separatorBuilder: (_, _) => const SizedBox(height: VtsaSpacing.sm),
        itemBuilder: (context, index) {
          final station = discovery.stations[index];
          return DriverStationCard(
            station: station,
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
