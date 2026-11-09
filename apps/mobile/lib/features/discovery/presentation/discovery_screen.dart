import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/maps/map_models.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/discovery/domain/station.dart';
import 'package:vtsa_mobile/features/discovery/domain/station_repository.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_map.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_widgets.dart';
import 'package:vtsa_mobile/features/wallet/wallet_repository.dart';

class _MapButton extends StatelessWidget {
  const _MapButton({
    required this.tooltip,
    required this.icon,
    required this.onPressed,
    this.loading = false,
    this.asset,
  });
  final String tooltip;
  final IconData icon;
  final VoidCallback? onPressed;
  final bool loading;
  final String? asset;

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
          : asset == null
          ? Icon(icon)
          : EvcsIcon(asset!, color: Theme.of(context).colorScheme.onSurface),
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
  bool _mapAvailable = true;
  final _mapKey = GlobalKey();
  MapPosition? _userPosition;

  @override
  void initState() {
    super.initState();
    final maps = widget.dependencies.environment.maps;
    _showMap = maps.provider.name == 'google'
        ? maps.googleReady
        : maps.tileConfigurationValid;
    _mapAvailable = _showMap;
    widget.dependencies.vehicles.addListener(_syncVehicleFilter);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _syncVehicleFilter();
      unawaited(_loadDefaultArea());
    });
  }

  @override
  void dispose() {
    widget.dependencies.vehicles.removeListener(_syncVehicleFilter);
    super.dispose();
  }

  void _syncVehicleFilter() {
    final discovery = widget.dependencies.discovery;
    final current = discovery.filters.vehicleConnectors.toSet();
    if (current.isEmpty) return;
    final selected = widget.dependencies.vehicles.selectedVehicle;
    final standards = selected?.connectorStandards ?? const <String>{};
    if (current.length == standards.length && current.containsAll(standards)) {
      return;
    }
    unawaited(
      discovery.updateFilters(
        discovery.filters.copyWith(vehicleConnectors: standards.toList()),
      ),
    );
  }

  Widget _currentVehicleCard(BuildContext context) {
    final vehicle = widget.dependencies.vehicles.selectedVehicle;
    final hasVehicles = widget.dependencies.vehicles.vehicles.isNotEmpty;
    final description = vehicle == null
        ? hasVehicles
              ? 'Choose your vehicle'
              : 'Add your vehicle'
        : [
            vehicle.manufacturer,
            vehicle.model,
            vehicle.variant ?? '',
          ].where((part) => part.trim().isNotEmpty).join(' ');
    return Semantics(
      button: true,
      child: Card(
        margin: EdgeInsets.zero,
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () {
            Navigator.pop(context);
            context.push('/vehicles');
          },
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Row(
              children: [
                Container(
                  width: 56,
                  height: 56,
                  decoration: BoxDecoration(
                    color: context.vtsaColors.brandSoft,
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Icon(
                    Icons.electric_car_outlined,
                    size: 34,
                    color: Theme.of(context).colorScheme.primary,
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'CURRENT VEHICLE',
                        style: Theme.of(context).textTheme.labelSmall,
                      ),
                      const SizedBox(height: 5),
                      Text(
                        description.isEmpty ? vehicle!.nickname : description,
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        vehicle == null
                            ? hasVehicles
                                  ? 'Choose a default vehicle'
                                  : 'Set up your charging preferences'
                            : vehicle.platePending
                            ? 'Plate pending'
                            : vehicle.plateNumber ?? vehicle.nickname,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                const Icon(Icons.chevron_right),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _mapVehicleCard(BuildContext context) {
    final vehicles = widget.dependencies.vehicles;
    final vehicle = vehicles.selectedVehicle;
    final loading = vehicles.isLoading && vehicle == null;
    final failed = vehicles.failure != null && vehicle == null;
    final description = vehicle == null
        ? loading
              ? 'Loading vehicle…'
              : failed
              ? 'Tap to retry'
              : 'Choose your vehicle'
        : [
            vehicle.manufacturer,
            vehicle.model,
            vehicle.variant ?? '',
          ].where((part) => part.trim().isNotEmpty).join(' ');
    return Material(
      color: Theme.of(context).colorScheme.surface,
      elevation: 3,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: loading
            ? null
            : failed
            ? vehicles.load
            : vehicles.vehicles.isEmpty
            ? () => context.push('/vehicles')
            : _chooseVehicle,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              Icon(
                Icons.electric_car_outlined,
                color: Theme.of(context).colorScheme.primary,
                size: 28,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      failed ? 'Vehicle unavailable' : 'Current vehicle',
                      style: const TextStyle(fontSize: 11, height: 1.3),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      description.isEmpty ? vehicle!.nickname : description,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14,
                        height: 1.3,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 6),
              if (loading)
                const SizedBox.square(
                  dimension: 16,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              else
                Icon(failed ? Icons.refresh : Icons.expand_more, size: 20),
            ],
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
      widget.dependencies.network,
      widget.dependencies.vehicles,
      if (widget.dependencies.accounts != null) widget.dependencies.accounts!,
      if (widget.dependencies.notices != null) widget.dependencies.notices!,
    ]),
    builder: (context, _) {
      final discovery = widget.dependencies.discovery;
      final station = discovery.selectedStationId == null
          ? null
          : discovery.byId(discovery.selectedStationId!);
      final insets = MediaQuery.paddingOf(context);
      final keyboard = MediaQuery.viewInsetsOf(context).bottom;
      final textScale = MediaQuery.textScalerOf(context).scale(1);
      return Theme(
        data: Theme.of(context).copyWith(
          textTheme: Theme.of(context).textTheme.apply(fontFamily: 'Inter'),
        ),
        child: Builder(
          builder: (context) => PopScope(
            canPop: _showMap || !_mapAvailable,
            onPopInvokedWithResult: (didPop, _) {
              if (!didPop && _mapAvailable) setState(() => _showMap = true);
            },
            child: Scaffold(
              resizeToAvoidBottomInset: false,
              body: Stack(
                fit: StackFit.expand,
                children: [
                  if (_mapAvailable) _map(context),
                  if (!_showMap)
                    Positioned.fill(
                      top: insets.top + 196 + (textScale - 1).clamp(0, 1) * 40,
                      child: Material(
                        color: Theme.of(context).colorScheme.surface,
                        borderRadius: const BorderRadius.vertical(
                          top: Radius.circular(24),
                        ),
                        child: ListView(
                          padding: EdgeInsets.fromLTRB(
                            20,
                            20,
                            20,
                            keyboard + 24,
                          ),
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Text(
                                    'Chargers in this area',
                                    style: Theme.of(
                                      context,
                                    ).textTheme.titleLarge,
                                  ),
                                ),
                                IconButton(
                                  tooltip: 'Nearest first',
                                  onPressed: _locating
                                      ? null
                                      : () => _locateUser(nearest: true),
                                  icon: const Icon(Icons.near_me_outlined),
                                ),
                                IconButton(
                                  tooltip: 'Favorites',
                                  onPressed: () => context.push('/favorites'),
                                  icon: const Icon(Icons.favorite_border),
                                ),
                              ],
                            ),
                            const SizedBox(height: 8),
                            _StationList(dependencies: widget.dependencies),
                          ],
                        ),
                      ),
                    ),
                  Positioned(
                    top: insets.top + 20,
                    left: 20,
                    right: 20,
                    child: Align(
                      alignment: Alignment.topLeft,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 600),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Material(
                                    elevation: 8,
                                    shadowColor: PowerSolutionsColors
                                        .textPrimary
                                        .withValues(alpha: .12),
                                    borderRadius: BorderRadius.circular(14),
                                    child: TextField(
                                      onChanged: (query) {
                                        discovery.search(query);
                                        if (query.trim().isNotEmpty) {
                                          setState(() => _showMap = false);
                                        }
                                      },
                                      decoration: InputDecoration(
                                        hintText:
                                            'Where would you like to charge?',
                                        hintStyle: const TextStyle(
                                          fontSize: 14,
                                        ),
                                        prefixIcon: const Padding(
                                          padding: EdgeInsets.all(14),
                                          child: EvcsIcon(
                                            'map-icon-search',
                                            size: 20,
                                          ),
                                        ),
                                        filled: true,
                                        fillColor: Theme.of(
                                          context,
                                        ).colorScheme.surface,
                                        contentPadding:
                                            const EdgeInsets.symmetric(
                                              horizontal: 16,
                                              vertical: 16,
                                            ),
                                        border: OutlineInputBorder(
                                          borderRadius: BorderRadius.circular(
                                            14,
                                          ),
                                          borderSide: BorderSide.none,
                                        ),
                                        enabledBorder: OutlineInputBorder(
                                          borderRadius: BorderRadius.circular(
                                            14,
                                          ),
                                          borderSide: BorderSide.none,
                                        ),
                                      ),
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 12),
                                _MapButton(
                                  tooltip: 'Updates',
                                  icon: Icons.notifications_outlined,
                                  asset: 'map-icon-bell',
                                  onPressed: () => context.push('/updates'),
                                ),
                              ],
                            ),
                            const SizedBox(height: 12),
                            SingleChildScrollView(
                              scrollDirection: Axis.horizontal,
                              child: Row(
                                children: [
                                  _quickFilter(
                                    context,
                                    'Available',
                                    discovery.filters.availability ==
                                        StationAvailability.available,
                                    () => discovery.updateFilters(
                                      discovery.filters.copyWith(
                                        availability:
                                            StationAvailability.available,
                                        clearAvailability:
                                            discovery.filters.availability ==
                                            StationAvailability.available,
                                      ),
                                    ),
                                  ),
                                  const SizedBox(width: 8),
                                  _quickFilter(
                                    context,
                                    'Fast',
                                    (discovery.filters.minimumPowerW ?? 0) >=
                                        50000,
                                    () => discovery.updateFilters(
                                      discovery.filters.copyWith(
                                        minimumPowerW: 50000,
                                        clearMinimumPower:
                                            (discovery.filters.minimumPowerW ??
                                                0) >=
                                            50000,
                                      ),
                                    ),
                                  ),
                                  const SizedBox(width: 8),
                                  _quickFilter(
                                    context,
                                    'Compatible',
                                    discovery
                                        .filters
                                        .vehicleConnectors
                                        .isNotEmpty,
                                    _chooseCompatibility,
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(height: 12),
                            Row(
                              children: [
                                if (widget.dependencies.accounts?.shared !=
                                        true ||
                                    widget.dependencies.accounts?.selected?.can(
                                          'wallet_view',
                                        ) ==
                                        true) ...[
                                  Flexible(
                                    child: _MapWallet(
                                      key: ValueKey(
                                        widget.dependencies.accounts?.accountId,
                                      ),
                                      repository:
                                          widget.dependencies.walletRepository,
                                      onTap: () => context.go('/wallet'),
                                    ),
                                  ),
                                  const SizedBox(width: 8),
                                ],
                                _MapButton(
                                  tooltip: _showMap
                                      ? 'Show charger list'
                                      : 'Show map',
                                  icon: _showMap
                                      ? Icons.list
                                      : Icons.map_outlined,
                                  asset: _showMap
                                      ? 'map-icon-list'
                                      : 'map-icon-map',
                                  onPressed: !_mapAvailable
                                      ? null
                                      : () => setState(
                                          () => _showMap = !_showMap,
                                        ),
                                ),
                                const SizedBox(width: 8),
                                _MapButton(
                                  tooltip: 'Filters',
                                  icon: Icons.tune,
                                  asset: 'map-icon-filter',
                                  onPressed: () => _showFilters(context),
                                ),
                              ],
                            ),
                            if (!widget.dependencies.network.isOnline) ...[
                              const SizedBox(height: 8),
                              const DriverNotice(
                                title: 'You’re offline',
                                message:
                                    'Showing the last loaded stations. Reconnect to check availability.',
                              ),
                            ],
                            if (discovery.isLoading) ...[
                              const SizedBox(height: 8),
                              const LinearProgressIndicator(),
                            ],
                          ],
                        ),
                      ),
                    ),
                  ),
                  if (_showMap)
                    Positioned(
                      left: 20,
                      right: 20,
                      bottom: 20,
                      child: ConstrainedBox(
                        constraints: BoxConstraints(
                          maxHeight: MediaQuery.sizeOf(context).height * .45,
                        ),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Row(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                Expanded(
                                  child: Align(
                                    alignment: Alignment.bottomLeft,
                                    child: ConstrainedBox(
                                      constraints: const BoxConstraints(
                                        maxWidth: 440,
                                      ),
                                      child: _mapVehicleCard(context),
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 12),
                                _MapButton(
                                  tooltip: 'Show my location',
                                  icon: Icons.my_location,
                                  asset: 'map-icon-target',
                                  loading: _locating,
                                  onPressed: _locating ? null : _locateUser,
                                ),
                              ],
                            ),
                            if (station != null) ...[
                              const SizedBox(height: 12),
                              Flexible(
                                child: Align(
                                  alignment: Alignment.bottomLeft,
                                  heightFactor: 1,
                                  child: ConstrainedBox(
                                    constraints: const BoxConstraints(
                                      maxWidth: 440,
                                    ),
                                    child: SingleChildScrollView(
                                      child: StationCard(
                                        station: station,
                                        selected: true,
                                        favorite: widget.dependencies.favorites
                                            .contains(station.id),
                                        onFavorite: () => widget
                                            .dependencies
                                            .favorites
                                            .toggle(station.id),
                                        onClose: () => discovery.select(null),
                                        onTap: () => context.push(
                                          '/stations/${station.id}',
                                        ),
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      );
    },
  );

  Widget _quickFilter(
    BuildContext context,
    String label,
    bool selected,
    VoidCallback onTap,
  ) => FilterChip(
    label: Text(label),
    selected: selected,
    onSelected: (_) => onTap(),
    selectedColor: PowerSolutionsColors.brandNavy,
    backgroundColor: Theme.of(context).colorScheme.surface,
    checkmarkColor: Colors.white,
    labelStyle: TextStyle(
      color: selected ? Colors.white : Theme.of(context).colorScheme.onSurface,
      fontSize: 13,
      fontWeight: FontWeight.w600,
    ),
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
    side: BorderSide(
      color: selected
          ? PowerSolutionsColors.brandNavy
          : context.vtsaColors.border,
    ),
  );

  Future<void> _chooseCompatibility() async {
    final discovery = widget.dependencies.discovery;
    if (discovery.filters.vehicleConnectors.isNotEmpty) {
      await discovery.updateFilters(
        discovery.filters.copyWith(vehicleConnectors: []),
      );
      return;
    }
    await _chooseVehicle(requireCompatibility: true);
  }

  Future<void> _chooseVehicle({bool requireCompatibility = false}) async {
    final discovery = widget.dependencies.discovery;
    final vehicles = widget.dependencies.vehicles;
    final filtering =
        requireCompatibility || discovery.filters.vehicleConnectors.isNotEmpty;
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) => AnimatedBuilder(
        animation: vehicles,
        builder: (context, _) => ListView(
          shrinkWrap: true,
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
          children: [
            Text(
              'Choose your vehicle',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 16),
            _currentVehicleCard(context),
            for (final vehicle in vehicles.vehicles)
              ListTile(
                selected: vehicle.id == vehicles.selectedVehicle?.id,
                title: Text(vehicle.nickname),
                subtitle: Text(
                  vehicle.connectorStandards.isEmpty
                      ? 'Add connector details in your garage'
                      : vehicle.connectorStandards.join(' · '),
                ),
                enabled: !filtering || vehicle.connectorStandards.isNotEmpty,
                onTap: () {
                  if (!vehicles.selectVehicle(vehicle.id)) return;
                  if (requireCompatibility) {
                    discovery.updateFilters(
                      discovery.filters.copyWith(
                        clearConnector: true,
                        vehicleConnectors: vehicle.connectorStandards.toList(),
                      ),
                    );
                  }
                  Navigator.pop(context);
                },
              ),
          ],
        ),
      ),
    );
  }

  Widget _map(BuildContext context) => StationMap(
    key: _mapKey,
    discovery: widget.dependencies.discovery,
    configuration: widget.dependencies.environment.maps,
    userPosition: _userPosition,
    openStreetMapBuilder: widget.mapBuilder,
    googleMapsBuilder: widget.mapBuilder,
    onProviderFailure: () {
      if (mounted) {
        setState(() {
          _mapAvailable = false;
          _showMap = false;
        });
      }
    },
  );

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
    final maps = widget.dependencies.environment.maps;
    await widget.dependencies.discovery.loadBounds(
      GeoBounds(
        west: maps.defaultLongitude - .15,
        south: maps.defaultLatitude - .15,
        east: maps.defaultLongitude + .15,
        north: maps.defaultLatitude + .15,
      ),
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
      await widget.dependencies.discovery.updateFilters(selected);
    }
  }
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

class _MapWallet extends StatefulWidget {
  const _MapWallet({required this.repository, required this.onTap, super.key});
  final WalletRepository? repository;
  final VoidCallback onTap;
  @override
  State<_MapWallet> createState() => _MapWalletState();
}

class _MapWalletState extends State<_MapWallet> {
  late final Future<Map<String, dynamic>>? _summary = widget.repository
      ?.summary();

  @override
  Widget build(BuildContext context) => FutureBuilder(
    future: _summary,
    builder: (context, snapshot) {
      final data = snapshot.data;
      final minor = data?['available_minor'];
      final label =
          data?['book'] == 'live' && data?['currency'] == 'PHP' && minor is int
          ? walletMoney(minor)
          : 'Wallet';
      return Material(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(999),
        child: InkWell(
          borderRadius: BorderRadius.circular(999),
          onTap: widget.onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const EvcsIcon('map-icon-wallet', size: 20),
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    },
  );
}
