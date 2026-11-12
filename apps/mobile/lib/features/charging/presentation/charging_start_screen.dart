import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/accounts/charging_account_screen.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/charging/presentation/charge_review.dart';
import 'package:vtsa_mobile/features/charging/presentation/charger_scanner_view.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_budget_dialog.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_page.dart';

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
  bool _quoteRefreshFailed = false;
  String? _vehicleId;
  String? _preparedVehicleId;

  @override
  void initState() {
    super.initState();
    _manualCode.text = widget.initialCode ?? '';
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) return;
      await widget.dependencies.vehicles.load();
      if (mounted &&
          widget.initialCode != null &&
          !widget.dependencies.charging.hasUnfinishedSession) {
        await _prepare(widget.initialCode!);
      }
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
        eyebrow: 'CHARGING',
        title: 'Charging is currently unavailable',
        body: VtsaEmptyState(
          icon: Icons.electric_bolt_outlined,
          title: 'Charging is not available yet',
          description:
              'You can explore stations and save compatible vehicles. Check back later or contact your charging operator for help.',
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
        if (_preparedHere && charging.preparation != null) {
          return ChargeReview(
            key: ValueKey(charging.preparation!.quote.id),
            quoteRefreshFailed: _quoteRefreshFailed,
            onRefresh: () => _prepare(charging.preparation!.target.connectorId),
            onVehicle: (id) {
              _vehicleId = id;
              if (id != null) {
                widget.dependencies.vehicles.selectVehicle(id);
              }
              _prepare(
                charging.preparation!.target.connectorId,
                budgetMinor: charging
                    .preparation!
                    .quote
                    .estimatedPreauthorization
                    .minorUnits,
              );
            },
            dependencies: widget.dependencies,
            fallbackVehicleId: _preparedVehicleId,
            onConfirm: _confirmStart,
            onBudget: _changeBudget,
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
        if (_cameraEnabled) return _cameraView(context);
        return ChargingPage(
          title: 'Scan or enter charger code',
          subtitle: 'Match the code to the connector you’ll use.',
          step: 0,
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
              VtsaCard(
                eyebrow: 'CAMERA PERMISSION',
                title: 'Scan the charger QR code',
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Power Solutions uses the camera only to decode the charger QR code. Camera video is not stored. You can always enter the printed code manually.',
                    ),
                    const SizedBox(height: VtsaSpacing.lg),
                    VtsaButton(
                      label: 'Open camera',
                      icon: Icons.qr_code_scanner,
                      onPressed: charging.isOnline && !charging.mutationInFlight
                          ? () => setState(() => _cameraEnabled = true)
                          : null,
                    ),
                  ],
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

  Widget _cameraView(BuildContext context) => PopScope(
    canPop: false,
    onPopInvokedWithResult: (didPop, _) {
      if (!didPop) _closeCamera();
    },
    child: ChargerScannerView(
      onManualEntry: _closeCamera,
      loading: widget.dependencies.charging.mutationInFlight,
      camera: MobileScanner(
        controller: _scanner,
        onDetect: _onDetect,
        errorBuilder: (context, error) => const Center(
          child: Padding(
            padding: EdgeInsets.symmetric(horizontal: 40),
            child: Text(
              'Camera unavailable. Allow camera access in settings, or enter the printed charge point ID.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.white, fontSize: 16),
            ),
          ),
        ),
      ),
    ),
  );

  Future<void> _closeCamera() async {
    await _scanner.stop();
    if (mounted) {
      setState(() {
        _cameraEnabled = false;
        _scanHandled = false;
      });
    }
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_scanHandled ||
        !_cameraEnabled ||
        !widget.dependencies.charging.isOnline) {
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
        _cameraEnabled &&
        widget.dependencies.charging.phase != ChargingFlowPhase.reviewing) {
      _scanHandled = false;
      await _scanner.start();
    }
  }

  Future<void> _prepare(String code, {int? budgetMinor}) async {
    if (widget.dependencies.accounts?.selected == null) {
      await widget.dependencies.accounts?.load();
    }
    final vehicleId =
        _vehicleId ?? widget.dependencies.vehicles.selectedVehicle?.id;
    final prepared = await widget.dependencies.charging.prepare(
      rawCode: code,
      budgetMinor: budgetMinor,
      vehicleId: vehicleId == '' ? null : vehicleId,
    );
    if (prepared &&
        widget.dependencies.charging.selectedPaymentMethod == null) {
      final methods = widget.dependencies.charging.preparation!.paymentMethods;
      if (methods.length == 1) {
        widget.dependencies.charging.selectPaymentMethod(methods.single);
      }
    }
    if (mounted) {
      setState(() {
        _quoteRefreshFailed = !prepared;
        _preparedHere = prepared || _preparedHere;
        _preparedVehicleId = vehicleId;
      });
      final failure = widget.dependencies.charging.failure;
      if (!prepared &&
          failure != null &&
          ModalRoute.isCurrentOf(context) == true) {
        await showVtsaAlert(
          context,
          title: 'Could not validate this connector',
          message: failure.message,
          correlationId: failure.correlationId,
        );
      }
    }
  }

  Future<void> _changeBudget() async {
    final preparation = widget.dependencies.charging.preparation!;
    final amount = await showDialog<int>(
      context: context,
      builder: (_) => ChargingBudgetDialog(quote: preparation.quote),
    );
    if (amount != null && mounted) {
      await _prepare(preparation.target.connectorId, budgetMinor: amount);
    }
  }

  Future<void> _confirmStart() async {
    final charging = widget.dependencies.charging;
    final started = await charging.start();
    if (!mounted || ModalRoute.isCurrentOf(context) != true) return;
    final session = charging.session;
    if (started && session != null) {
      context.go('/charging/${session.id}');
    } else if (charging.failure case final failure?) {
      showVtsaAlert(
        context,
        message: failure.message,
        correlationId: failure.correlationId,
      );
    }
  }

  Widget _contextPicker() => AnimatedBuilder(
    animation: widget.dependencies.vehicles,
    builder: (context, _) {
      final vehicles = widget.dependencies.vehicles;
      final selected = _vehicleId ?? vehicles.selectedVehicle?.id ?? '';
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
            onChanged: (id) {
              if (id != null) vehicles.selectVehicle(id);
              setState(() => _vehicleId = id);
            },
          ),
          const SizedBox(height: 16),
        ],
      );
    },
  );
}
