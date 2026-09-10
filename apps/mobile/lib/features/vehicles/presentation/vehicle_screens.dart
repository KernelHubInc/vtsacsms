import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/vehicles/domain/vehicle.dart';

class VehicleListScreen extends StatefulWidget {
  const VehicleListScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<VehicleListScreen> createState() => _VehicleListScreenState();
}

class _VehicleListScreenState extends State<VehicleListScreen> {
  @override
  void initState() {
    super.initState();
    widget.dependencies.vehicles.load();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: widget.dependencies.vehicles,
    builder: (context, _) {
      final controller = widget.dependencies.vehicles;
      return VtsaPageShell(
        eyebrow: 'COMPATIBILITY',
        title: 'My vehicles',
        actions: [
          IconButton(
            tooltip: 'Add vehicle',
            onPressed: () => context.push('/vehicles/new'),
            icon: const Icon(Icons.add),
          ),
        ],
        body: controller.isLoading
            ? const VtsaSkeleton(lines: 5)
            : controller.vehicles.isEmpty
            ? VtsaEmptyState(
                icon: Icons.directions_car_outlined,
                title: 'No vehicles yet',
                description:
                    'Add connector standards to compare a vehicle with station connectors.',
                action: VtsaButton(
                  label: 'Add vehicle',
                  onPressed: () => context.push('/vehicles/new'),
                ),
              )
            : ListView.separated(
                itemCount: controller.vehicles.length,
                separatorBuilder: (_, _) =>
                    const SizedBox(height: VtsaSpacing.sm),
                itemBuilder: (context, index) {
                  final vehicle = controller.vehicles[index];
                  return VtsaCard(
                    padding: EdgeInsets.zero,
                    child: ListTile(
                      leading: CircleAvatar(
                        backgroundColor: context.vtsaColors.brandSoft,
                        child: const Icon(Icons.directions_car_outlined),
                      ),
                      title: Text(vehicle.nickname),
                      subtitle: Text(
                        '${vehicle.manufacturer} ${vehicle.model}\n${vehicle.connectorStandards.join(' · ')}',
                      ),
                      isThreeLine: true,
                      trailing: vehicle.isDefault
                          ? const Chip(label: Text('Default'))
                          : const Icon(Icons.chevron_right),
                      onTap: () => context.push('/vehicles/${vehicle.id}/edit'),
                    ),
                  );
                },
              ),
      );
    },
  );
}

class VehicleFormScreen extends StatefulWidget {
  const VehicleFormScreen({
    required this.dependencies,
    this.vehicleId,
    super.key,
  });

  final AppDependencies dependencies;
  final String? vehicleId;

  @override
  State<VehicleFormScreen> createState() => _VehicleFormScreenState();
}

class _VehicleFormScreenState extends State<VehicleFormScreen> {
  static const _standards = ['Type 2', 'CCS2', 'CHAdeMO', 'GB/T', 'NACS'];
  late final TextEditingController _nickname;
  late final TextEditingController _manufacturer;
  late final TextEditingController _model;
  late final TextEditingController _variant;
  final Set<String> _connectors = {};
  bool _default = false;
  Vehicle? _existing;

  @override
  void initState() {
    super.initState();
    _existing = widget.dependencies.vehicles.vehicles
        .where((vehicle) => vehicle.id == widget.vehicleId)
        .firstOrNull;
    _nickname = TextEditingController(text: _existing?.nickname);
    _manufacturer = TextEditingController(text: _existing?.manufacturer);
    _model = TextEditingController(text: _existing?.model);
    _variant = TextEditingController(text: _existing?.variant);
    _connectors.addAll(_existing?.connectorStandards ?? const {});
    _default = _existing?.isDefault ?? false;
  }

  @override
  void dispose() {
    _nickname.dispose();
    _manufacturer.dispose();
    _model.dispose();
    _variant.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => VtsaPageShell(
    eyebrow: _existing == null ? 'NEW VEHICLE' : 'EDIT VEHICLE',
    title: _existing == null ? 'Add vehicle' : _existing!.nickname,
    body: ListView(
      children: [
        VtsaTextField(
          label: 'Nickname',
          hint: 'Example: Daily driver',
          controller: _nickname,
          required: true,
        ),
        const SizedBox(height: VtsaSpacing.md),
        VtsaTextField(
          label: 'Manufacturer',
          controller: _manufacturer,
          required: true,
        ),
        const SizedBox(height: VtsaSpacing.md),
        VtsaTextField(label: 'Model', controller: _model, required: true),
        const SizedBox(height: VtsaSpacing.md),
        VtsaTextField(label: 'Variant', controller: _variant),
        const SizedBox(height: VtsaSpacing.lg),
        Text(
          'Connector compatibility',
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: VtsaSpacing.xs),
        Text(
          'Select standards listed by the vehicle manufacturer. This is guidance, not a guarantee of charging support.',
          style: Theme.of(
            context,
          ).textTheme.bodySmall?.copyWith(color: context.vtsaColors.textMuted),
        ),
        const SizedBox(height: VtsaSpacing.sm),
        Wrap(
          spacing: VtsaSpacing.xs,
          children: [
            for (final standard in _standards)
              FilterChip(
                label: Text(standard),
                selected: _connectors.contains(standard),
                onSelected: (selected) => setState(() {
                  selected
                      ? _connectors.add(standard)
                      : _connectors.remove(standard);
                }),
              ),
          ],
        ),
        SwitchListTile.adaptive(
          contentPadding: EdgeInsets.zero,
          title: const Text('Use as default vehicle'),
          value: _default,
          onChanged: (value) => setState(() => _default = value),
        ),
        const SizedBox(height: VtsaSpacing.lg),
        VtsaButton(label: 'Save vehicle', onPressed: _save),
        if (_existing != null) ...[
          const SizedBox(height: VtsaSpacing.sm),
          VtsaButton(
            label: 'Remove vehicle',
            variant: VtsaButtonVariant.danger,
            onPressed: _remove,
          ),
        ],
      ],
    ),
  );

  Future<void> _save() async {
    if (_nickname.text.trim().isEmpty ||
        _manufacturer.text.trim().isEmpty ||
        _model.text.trim().isEmpty ||
        _connectors.isEmpty) {
      showVtsaToast(
        context,
        message: 'Complete the vehicle details and select a connector.',
      );
      return;
    }
    await widget.dependencies.vehicles.save(
      id: _existing?.id,
      nickname: _nickname.text,
      manufacturer: _manufacturer.text,
      model: _model.text,
      variant: _variant.text.isEmpty ? null : _variant.text,
      connectorStandards: _connectors,
      isDefault: _default,
    );
    if (mounted) {
      context.pop();
    }
  }

  Future<void> _remove() async {
    final confirmed = await showVtsaConfirmationDialog(
      context: context,
      title: 'Remove ${_existing!.nickname}?',
      description:
          'This removes locally saved compatibility information for this account on this device.',
      confirmLabel: 'Remove',
      dangerous: true,
    );
    if (!confirmed) {
      return;
    }
    await widget.dependencies.vehicles.remove(_existing!.id);
    if (mounted) {
      context.pop();
    }
  }
}
