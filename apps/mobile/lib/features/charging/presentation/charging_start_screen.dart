import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/config/feature_flags.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_formatters.dart';

class ChargingStartScreen extends StatefulWidget {
  const ChargingStartScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<ChargingStartScreen> createState() => _ChargingStartScreenState();
}

class _ChargingStartScreenState extends State<ChargingStartScreen> {
  final _manualCode = TextEditingController();
  late final MobileScannerController _scanner;
  bool _cameraEnabled = false;
  bool _scanHandled = false;

  @override
  void initState() {
    super.initState();
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
          title: FeatureFlags.milestoneTwoMessage,
          description:
              'Milestone 1 supports station discovery, vehicles, compatibility, and favorites. No charger or payment request was sent.',
        ),
      );
    }

    return AnimatedBuilder(
      animation: widget.dependencies.charging,
      builder: (context, _) {
        final charging = widget.dependencies.charging;
        if (charging.phase == ChargingFlowPhase.reviewing &&
            charging.preparation != null) {
          return _ChargeReview(
            dependencies: widget.dependencies,
            onConfirm: _confirmStart,
            onBack: () {
              charging.resetStartFlow();
              setState(() {
                _cameraEnabled = false;
                _scanHandled = false;
              });
            },
          );
        }
        return VtsaPageShell(
          eyebrow: 'START CHARGING',
          title: 'Identify the connector',
          body: ListView(
            children: [
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
    final vehicle = widget.dependencies.vehicles.defaultVehicle;
    await widget.dependencies.charging.prepare(
      rawCode: code,
      vehicleId: vehicle?.id,
    );
  }

  Future<void> _confirmStart() async {
    final charging = widget.dependencies.charging;
    final preparation = charging.preparation!;
    final vehicle = widget.dependencies.vehicles.defaultVehicle;
    final incompatible =
        vehicle != null &&
        !vehicle.supports(preparation.target.connectorStandard);
    final confirmed = await showVtsaConfirmationDialog(
      context: context,
      title: incompatible
          ? 'Start despite compatibility warning?'
          : 'Start charging?',
      description:
          '${preparation.target.stationName}, ${preparation.target.connectorName}. An estimated ${formatMoney(preparation.quote.estimatedPreauthorization)} authorization may be placed. Final cost depends on the completed session.',
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
}

class _ChargeReview extends StatelessWidget {
  const _ChargeReview({
    required this.dependencies,
    required this.onConfirm,
    required this.onBack,
  });

  final AppDependencies dependencies;
  final VoidCallback onConfirm;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    final charging = dependencies.charging;
    final preparation = charging.preparation!;
    final target = preparation.target;
    final quote = preparation.quote;
    final vehicle = dependencies.vehicles.defaultVehicle;
    final compatible = vehicle?.supports(target.connectorStandard);
    return VtsaPageShell(
      eyebrow: 'REVIEW BEFORE START',
      title: target.stationName,
      actions: [
        IconButton(
          tooltip: 'Use another charger code',
          onPressed: onBack,
          icon: const Icon(Icons.close),
        ),
      ],
      body: ListView(
        children: [
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
            eyebrow: quote.taxInclusive ? 'TAX INCLUDED' : 'TAX EXCLUDED',
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
                  title: const Text('Estimated preauthorization'),
                  subtitle: const Text(
                    'A temporary authorization is not the final charge.',
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
            eyebrow: 'TOKENIZED PAYMENT METHOD',
            title: 'How you will pay',
            child: preparation.paymentMethods.isEmpty
                ? const VtsaEmptyState(
                    title: 'No payment method available',
                    description:
                        'Add a provider-tokenized payment method before starting.',
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
