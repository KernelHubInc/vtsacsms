import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/accounts/charging_account_screen.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_formatters.dart';

class ChargingStartScreen extends StatefulWidget {
  const ChargingStartScreen({
    required this.dependencies,
    this.initialCode,
    super.key,
  });

  final String? initialCode;

  final AppDependencies dependencies;

  @override
  State<ChargingStartScreen> createState() => _ChargingStartScreenState();
}

class _ChargingStartScreenState extends State<ChargingStartScreen> {
  final _manualCode = TextEditingController();
  late final MobileScannerController _scanner;
  bool _cameraEnabled = false;
  bool _scanHandled = false;
  bool _preparedHere = false;
  String? _vehicleId;
  String? _preparedVehicleId;

  @override
  void initState() {
    super.initState();
    _manualCode.text = widget.initialCode ?? '';
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) widget.dependencies.vehicles.load();
    });
    _scanner = MobileScannerController(
      formats: const [BarcodeFormat.qrCode],
      detectionSpeed: DetectionSpeed.noDuplicates,
    );
  }

  @override
  void dispose() {
    _manualCode.dispose();
    _scanner.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final features = widget.dependencies.featureFlags;
    if (!features.remoteCharging && !features.simulatedCharging) {
      return const VtsaPageShell(
        eyebrow: 'MILESTONE 2',
        title: 'Charging controls are intentionally disabled',
        body: VtsaEmptyState(
          icon: Icons.electric_bolt_outlined,
          title: 'Charging is not available yet',
          description:
              'You can explore stations and save compatible vehicles. Starting a charge will become available when charging and payment services are enabled.',
        ),
      );
    }

    return AnimatedBuilder(
      animation: widget.dependencies.charging,
      builder: (context, _) {
        final charging = widget.dependencies.charging;
        if (charging.hasUnfinishedSession) {
          return DriverPage(
            title: 'Your current charging request',
            body: ListView(
              children: [
                ChargingAccountSelector(dependencies: widget.dependencies),
                const SizedBox(height: 16),
                const DriverNotice(
                  title: 'Finish or resolve the current request first',
                  message:
                      'You can browse another account, but a new charging session cannot start yet. The current request keeps its original account, tariff and wallet.',
                ),
                const SizedBox(height: 16),
                VtsaButton(
                  label: 'View current charging request',
                  onPressed: () =>
                      context.push('/charging/${charging.session!.id}'),
                ),
              ],
            ),
          );
        }
        if (_preparedHere &&
            charging.phase == ChargingFlowPhase.reviewing &&
            charging.preparation != null) {
          return _ChargeReview(
            dependencies: widget.dependencies,
            fallbackVehicleId: _preparedVehicleId,
            onConfirm: _confirmStart,
            onBack: () {
              charging.resetStartFlow();
              setState(() {
                _cameraEnabled = false;
                _scanHandled = false;
                _preparedHere = false;
              });
            },
          );
        }
        if (widget.initialCode != null) {
          return DriverPage(
            title: 'You’re nearly ready',
            body: ListView(
              children: [
                _contextPicker(),
                const Text('A quick check, then we’ll take it from here.'),
                const SizedBox(height: 16),
                const LinearProgressIndicator(
                  value: 1 / 3,
                  semanticsLabel: 'Step 1 of 3: confirm the physical connector',
                ),
                const SizedBox(height: 24),
                const DriverNotice(
                  title: '1  Plug in your vehicle',
                  message:
                      'Connect the cable securely. Follow the instructions on your vehicle and charger.',
                  positive: true,
                ),
                const SizedBox(height: 12),
                const VtsaCard(
                  padding: EdgeInsets.all(14),
                  title: '2  Confirm this charger',
                  child: Text(
                    'Make sure the selected plug matches the one connected to your vehicle.',
                  ),
                ),
                const SizedBox(height: 12),
                const VtsaCard(
                  padding: EdgeInsets.all(14),
                  title: '3  Review before you start',
                  child: Text(
                    'Check the live price and wallet reservation. Charging starts only after confirmation from the charger.',
                  ),
                ),
                const SizedBox(height: 24),
                Text(
                  'How would you like to start?',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 12),
                const VtsaCard(
                  padding: EdgeInsets.all(14),
                  selected: true,
                  title: 'Start in the app',
                  child: Text(
                    'Authorize with your account and prepaid wallet.',
                  ),
                ),
                const SizedBox(height: 8),
                const Text('RFID start is not available through this app.'),
                if (charging.failure != null)
                  VtsaErrorState(
                    title: 'Could not validate this plug',
                    description: charging.failure!.message,
                    correlationId: charging.failure!.correlationId,
                  ),
                if (!charging.isOnline)
                  const Text('Connect to the internet to review live pricing.'),
                const SizedBox(height: 16),
                VtsaButton(
                  label: 'Review charging estimate',
                  loading: charging.mutationInFlight,
                  onPressed: charging.isOnline
                      ? () => _prepare(widget.initialCode!)
                      : null,
                ),
                TextButton(
                  onPressed: () => context.push('/charge'),
                  child: const Text('Scan a different charger'),
                ),
              ],
            ),
          );
        }
        return VtsaPageShell(
          eyebrow: 'START CHARGING',
          title: 'Identify the connector',
          body: ListView(
            children: [
              _contextPicker(),
              if (!charging.isOnline) ...[
                const VtsaErrorState(
                  title: 'Network connection required',
                  description:
                      'Starting a session needs current server confirmation. Power Solutions will not queue a charging or payment request while offline.',
                ),
                const SizedBox(height: VtsaSpacing.md),
              ],
              if (!_cameraEnabled)
                VtsaCard(
                  eyebrow: 'CAMERA PERMISSION',
                  title: 'Scan only when you choose',
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Power Solutions uses the camera only to decode the charger QR code. Camera video is not stored. You can always enter the printed code manually.',
                      ),
                      const SizedBox(height: VtsaSpacing.lg),
                      VtsaButton(
                        label: 'Continue to camera',
                        icon: Icons.qr_code_scanner,
                        onPressed: charging.isOnline
                            ? () => setState(() => _cameraEnabled = true)
                            : null,
                      ),
                    ],
                  ),
                )
              else
                SizedBox(
                  height: 320,
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(VtsaRadii.lg),
                    child: Stack(
                      fit: StackFit.expand,
                      children: [
                        MobileScanner(
                          controller: _scanner,
                          onDetect: _onDetect,
                          errorBuilder: (context, error) => VtsaErrorState(
                            title: 'Camera unavailable',
                            description:
                                'Camera permission was denied or the camera could not start. Enter the charger code below instead.',
                            action: VtsaButton(
                              label: 'Use manual entry',
                              variant: VtsaButtonVariant.secondary,
                              onPressed: () =>
                                  setState(() => _cameraEnabled = false),
                            ),
                          ),
                        ),
                        IgnorePointer(
                          child: Center(
                            child: Container(
                              width: 220,
                              height: 220,
                              decoration: BoxDecoration(
                                border: Border.all(
                                  color: Colors.white,
                                  width: 3,
                                ),
                                borderRadius: BorderRadius.circular(
                                  VtsaRadii.lg,
                                ),
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              const SizedBox(height: VtsaSpacing.lg),
              Row(
                children: [
                  Expanded(child: Divider(color: context.vtsaColors.border)),
                  Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: VtsaSpacing.sm,
                    ),
                    child: Text(
                      'OR ENTER THE CODE',
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                  ),
                  Expanded(child: Divider(color: context.vtsaColors.border)),
                ],
              ),
              const SizedBox(height: VtsaSpacing.lg),
              VtsaTextField(
                label: 'Charger code',
                hint: 'Printed beside the QR code',
                controller: _manualCode,
                textInputAction: TextInputAction.done,
                onSubmitted: (_) => _prepare(_manualCode.text),
                required: true,
              ),
              const SizedBox(height: VtsaSpacing.md),
              if (charging.failure != null) ...[
                VtsaErrorState(
                  title: 'Could not validate this connector',
                  description: charging.failure!.message,
                  correlationId: charging.failure!.correlationId,
                ),
                const SizedBox(height: VtsaSpacing.md),
              ],
              VtsaButton(
                label: 'Validate connector',
                loading:
                    charging.phase == ChargingFlowPhase.resolvingCode ||
                    charging.mutationInFlight,
                onPressed: charging.isOnline
                    ? () => _prepare(_manualCode.text)
                    : null,
              ),
            ],
          ),
        );
      },
    );
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_scanHandled) {
      return;
    }
    final value = capture.barcodes.firstOrNull?.rawValue;
    if (value == null) {
      return;
    }
    _scanHandled = true;
    await _scanner.stop();
    await _prepare(value);
    if (mounted &&
        widget.dependencies.charging.phase != ChargingFlowPhase.reviewing) {
      _scanHandled = false;
      await _scanner.start();
    }
  }

  Future<void> _prepare(String code) async {
    if (widget.dependencies.accounts?.selected == null) {
      await widget.dependencies.accounts?.load();
    }
    final vehicleId =
        _vehicleId ?? widget.dependencies.vehicles.defaultVehicle?.id;
    final prepared = await widget.dependencies.charging.prepare(
      rawCode: code,
      vehicleId: vehicleId == '' ? null : vehicleId,
    );
    if (mounted) {
      setState(() {
        _preparedHere = prepared;
        _preparedVehicleId = vehicleId;
      });
    }
  }

  Future<void> _confirmStart() async {
    final charging = widget.dependencies.charging;
    final preparation = charging.preparation!;
    final vehicle = widget.dependencies.vehicles.vehicles
        .where(
          (v) =>
              v.id ==
              (preparation.attribution.isEmpty
                  ? _preparedVehicleId
                  : (preparation.attribution['vehicle'] as Map?)?['id']),
        )
        .firstOrNull;
    final incompatible =
        vehicle != null &&
        !vehicle.supports(preparation.target.connectorStandard);
    final confirmed = await showVtsaConfirmationDialog(
      context: context,
      title: incompatible
          ? 'Start despite compatibility warning?'
          : 'Start charging?',
      description:
          '${preparation.target.stationName}, ${preparation.target.connectorName}. Reserve ${formatMoney(preparation.quote.estimatedPreauthorization)} from ${preparation.attribution['payer'] ?? 'your prepaid wallet'}. We request a stop before this limit; the charger must confirm it. Final cost uses charger readings and unused funds return to the same wallet.',
      confirmLabel: 'Request start',
    );
    if (!confirmed) {
      return;
    }
    final started = await charging.start();
    final session = charging.session;
    if (mounted && started && session != null) {
      context.go('/charging/${session.id}');
    }
  }

  Widget _contextPicker() => AnimatedBuilder(
    animation: widget.dependencies.vehicles,
    builder: (context, _) {
      final vehicles = widget.dependencies.vehicles;
      final selected = _vehicleId ?? vehicles.defaultVehicle?.id ?? '';
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ChargingAccountSelector(dependencies: widget.dependencies),
          if (vehicles.isLoading) const LinearProgressIndicator(),
          if (vehicles.failure != null) Text(vehicles.failure!.message),
          DropdownButtonFormField<String>(
            key: ValueKey(
              '$selected:${vehicles.vehicles.map((v) => v.id).join(',')}',
            ),
            initialValue: vehicles.vehicles.any((v) => v.id == selected)
                ? selected
                : '',
            isExpanded: true,
            decoration: const InputDecoration(
              labelText: 'Vehicle for this session',
            ),
            items: [
              const DropdownMenuItem(value: '', child: Text('Select vehicle')),
              for (final vehicle in vehicles.vehicles)
                DropdownMenuItem(
                  value: vehicle.id,
                  child: Text(vehicle.nickname),
                ),
            ],
            onChanged: (id) => setState(() => _vehicleId = id),
          ),
          const SizedBox(height: 16),
        ],
      );
    },
  );
}

class _ChargeReview extends StatelessWidget {
  const _ChargeReview({
    required this.dependencies,
    required this.onConfirm,
    required this.onBack,
    this.fallbackVehicleId,
  });

  final AppDependencies dependencies;
  final VoidCallback onConfirm;
  final VoidCallback onBack;
  final String? fallbackVehicleId;

  @override
  Widget build(BuildContext context) {
    final charging = dependencies.charging;
    final preparation = charging.preparation!;
    final target = preparation.target;
    final quote = preparation.quote;
    final vehicle = dependencies.vehicles.vehicles
        .where(
          (v) =>
              v.id ==
              (preparation.attribution.isEmpty
                  ? fallbackVehicleId
                  : (preparation.attribution['vehicle'] as Map?)?['id']),
        )
        .firstOrNull;
    final compatible = vehicle?.supports(target.connectorStandard);
    return DriverPage(
      title: 'Review your charge',
      actions: [
        IconButton(
          tooltip: 'Use another charger code',
          onPressed: onBack,
          icon: const Icon(Icons.close),
        ),
      ],
      body: ListView(
        children: [
          if (preparation.attribution.isNotEmpty) ...[
            VtsaCard(
              eyebrow: 'DRIVER · VEHICLE · PAYER',
              title:
                  preparation.attribution['account_name'] as String? ??
                  'Personal',
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Driver: ${preparation.attribution['driver_name'] ?? ''}',
                  ),
                  Text(
                    'Vehicle: ${(preparation.attribution['vehicle'] as Map?)?['nickname'] ?? 'Not selected'}',
                  ),
                  Text('Paid by: ${preparation.attribution['payer']}'),
                  const Text('This session uses only the selected wallet.'),
                ],
              ),
            ),
            const SizedBox(height: 16),
          ],
          Text(
            'REVIEW BEFORE START',
            style: Theme.of(context).textTheme.labelSmall,
          ),
          const SizedBox(height: 12),
          Text(
            target.stationName,
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 12),
          VtsaCard(
            eyebrow: 'CONNECTOR',
            title: target.connectorName,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${target.connectorStandard} · ${(target.maximumPowerW / 1000).toStringAsFixed(0)} kW maximum',
                ),
                if (target.stationAddress != null) Text(target.stationAddress!),
                const SizedBox(height: VtsaSpacing.sm),
                VtsaStatusChip(
                  label: target.canStart ? 'Available' : target.readiness.name,
                  state: target.canStart
                      ? VtsaOperationalState.available
                      : VtsaOperationalState.unavailable,
                ),
              ],
            ),
          ),
          if (compatible == false) ...[
            const SizedBox(height: VtsaSpacing.md),
            VtsaCard(
              eyebrow: 'COMPATIBILITY WARNING',
              title:
                  '${vehicle!.nickname} does not list ${target.connectorStandard}',
              child: const Text(
                'Check the vehicle inlet and manufacturer guidance before continuing. Power Solutions compatibility data is advisory.',
              ),
            ),
          ],
          const SizedBox(height: VtsaSpacing.md),
          VtsaCard(
            eyebrow: quote.taxInclusive
                ? 'TAX INCLUDED'
                : 'PLUS ${(quote.taxRateBasisPoints / 100).toStringAsFixed(2)}% TAX',
            title: quote.name,
            child: Column(
              children: [
                for (final line in quote.lines)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(line.description),
                    trailing: Text(
                      formatMoney(
                        Money(
                          minorUnits: line.priceMinor,
                          currency: quote.currency,
                        ),
                      ),
                    ),
                  ),
                Divider(color: context.vtsaColors.border),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Reserved charging budget'),
                  subtitle: const Text(
                    'Held in your wallet until final readings are checked. Unused funds are released. A network interruption can delay the charger stopping; unresolved charges require review.',
                  ),
                  trailing: Text(
                    formatMoney(quote.estimatedPreauthorization),
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: VtsaSpacing.md),
          VtsaCard(
            eyebrow: 'PREPAID WALLET',
            title: 'How you will pay',
            child: preparation.paymentMethods.isEmpty
                ? const VtsaEmptyState(
                    title: 'No payment method available',
                    description:
                        'Add money to your wallet using QR Ph before starting.',
                  )
                : RadioGroup<String>(
                    groupValue: charging.selectedPaymentMethod?.id,
                    onChanged: (id) {
                      final method = preparation.paymentMethods
                          .where((item) => item.id == id)
                          .firstOrNull;
                      if (method != null) {
                        charging.selectPaymentMethod(method);
                      }
                    },
                    child: Column(
                      children: [
                        for (final method in preparation.paymentMethods)
                          RadioListTile<String>(
                            value: method.id,
                            title: Text(method.label),
                            subtitle: method.lastFour == null
                                ? null
                                : Text(
                                    '${method.brand ?? 'Card'} •••• ${method.lastFour}',
                                  ),
                          ),
                      ],
                    ),
                  ),
          ),
          if (charging.failure != null) ...[
            const SizedBox(height: VtsaSpacing.md),
            VtsaErrorState(
              title: 'Start request could not continue',
              description: charging.failure!.message,
              correlationId: charging.failure!.correlationId,
            ),
          ],
          const SizedBox(height: VtsaSpacing.lg),
          VtsaButton(
            label: 'Review and request start',
            loading: charging.mutationInFlight,
            onPressed:
                target.canStart &&
                    charging.selectedPaymentMethod != null &&
                    charging.isOnline
                ? onConfirm
                : null,
          ),
        ],
      ),
    );
  }
}
