import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/data/device_authenticator.dart';
import 'package:vtsa_mobile/features/auth/presentation/evcs_pin_entry.dart';

class UnlockScreen extends StatefulWidget {
  const UnlockScreen({required this.dependencies, super.key});
  final AppDependencies dependencies;
  @override
  State<UnlockScreen> createState() => _UnlockScreenState();
}

class _UnlockScreenState extends State<UnlockScreen> {
  String _pin = '';
  bool _pinEntry = false;
  DeviceBiometricKind _biometricKind = DeviceBiometricKind.generic;

  @override
  void initState() {
    super.initState();
    _loadBiometricKind();
  }

  Future<void> _loadBiometricKind() async {
    try {
      final kind = await widget.dependencies.auth.quickUnlock?.device
          .biometricKind();
      if (mounted && kind != null) setState(() => _biometricKind = kind);
    } on Object {
      // An unavailable device capability must leave password recovery usable.
    }
  }

  String get _biometricLabel => switch (_biometricKind) {
    DeviceBiometricKind.face => 'Face ID',
    DeviceBiometricKind.fingerprint => 'Fingerprint',
    DeviceBiometricKind.generic => 'Biometrics',
  };

  Future<void> _digit(String digit) async {
    if (widget.dependencies.auth.isBusy || _pin.length >= 6) return;
    setState(() => _pin += digit);
    if (_pin.length == 6) await _unlock();
  }

  Future<void> _unlock({bool biometric = false}) async {
    final auth = widget.dependencies.auth;
    if (auth.isBusy || (!biometric && _pin.length != 6)) return;
    final pin = _pin;
    setState(() => _pin = '');
    final accepted = await auth.unlock(pin: biometric ? null : pin);
    if (accepted) {
      await Future.wait([
        widget.dependencies.favorites.load(),
        widget.dependencies.vehicles.load(),
        widget.dependencies.pushRegistration.register(),
      ]);
      if (!auth.needsEmailVerification &&
          (widget.dependencies.featureFlags.remoteCharging ||
              widget.dependencies.featureFlags.simulatedCharging)) {
        await widget.dependencies.charging.restoreAuthoritativeSession();
      }
    } else if (auth.status == AuthStatus.guest) {
      await _clearAccount();
    } else if (mounted) {
      setState(() => _pinEntry = true);
    }
  }

  Future<void> _clearAccount() async {
    await widget.dependencies.charging.onSignedOut();
    await widget.dependencies.pushRegistration.unregister();
    await Future.wait([
      widget.dependencies.favorites.load(),
      widget.dependencies.vehicles.load(),
    ]);
  }

  Future<void> _usePassword({bool switchAccount = false}) async {
    final confirmed = await showVtsaConfirmationDialog(
      context: context,
      title: switchAccount ? 'Switch account?' : 'Use your password',
      description:
          'This removes the saved sign-in, app PIN and biometric access '
          'from this phone. Set up quick access again after signing in. '
          'Any ongoing charge will continue.',
      confirmLabel: switchAccount ? 'Switch account' : 'Continue with password',
    );
    if (!confirmed) return;
    await widget.dependencies.auth.usePassword();
    if (widget.dependencies.auth.status == AuthStatus.guest) {
      await _clearAccount();
      if (mounted) context.go('/login');
    }
  }

  void _showPin() => setState(() {
    _pin = '';
    _pinEntry = true;
  });

  void _help() => showEvcsAccessHelp(
    context,
    onUsePin: _showPin,
    onUsePassword: _usePassword,
  );

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: widget.dependencies.auth,
    builder: (context, _) {
      final auth = widget.dependencies.auth;
      final biometrics = auth.quickUnlock?.biometricsEnabled == true;
      if (_pinEntry) {
        return EvcsPinEntry(
          length: _pin.length,
          busy: auth.isBusy,
          failure: auth.failure?.message,
          onDigit: _digit,
          onDelete: () {
            if (_pin.isNotEmpty && !auth.isBusy) {
              setState(() => _pin = _pin.substring(0, _pin.length - 1));
            }
          },
          onClose: () => setState(() {
            _pin = '';
            _pinEntry = false;
          }),
          onPassword: _usePassword,
          onHelp: _help,
          biometricLabel: _biometricLabel,
          onBiometric: biometrics ? () => _unlock(biometric: true) : null,
        );
      }
      return EvcsWelcomeLayout(
        title: 'Welcome back.',
        subtitle: 'A simpler start to every drive.',
        actions: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Material(
              color: PowerSolutionsColors.brandNavy,
              borderRadius: BorderRadius.circular(999),
              clipBehavior: Clip.antiAlias,
              child: InkWell(
                onTap: auth.isBusy
                    ? null
                    : () => _usePassword(switchAccount: true),
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 20,
                    vertical: 10,
                  ),
                  child: Row(
                    children: [
                      const EvcsIcon('access-icon-user', size: 22),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              auth.quickUnlock?.savedFirstName ??
                                  'Your saved account',
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 13,
                                height: 18 / 13,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            const Text(
                              'Switch account',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 12,
                                height: 16 / 12,
                              ),
                            ),
                          ],
                        ),
                      ),
                      const EvcsIcon('access-icon-arrow', size: 22),
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(height: 24),
            EvcsAccessCard(
              padding: 0,
              child: IntrinsicHeight(
                child: Row(
                  children: [
                    _method(
                      label: _biometricLabel,
                      detail: biometrics ? 'Quick unlock' : 'Not enabled',
                      asset: _biometricKind == DeviceBiometricKind.face
                          ? 'access-face-id'
                          : 'access-fingerprint',
                      onTap: biometrics && !auth.isBusy
                          ? () => _unlock(biometric: true)
                          : null,
                    ),
                    const VerticalDivider(
                      width: 1,
                      indent: 20,
                      endIndent: 20,
                      color: PowerSolutionsColors.borderDefault,
                    ),
                    _method(
                      label: 'App PIN',
                      detail: 'Six-digit code',
                      asset: 'access-access-keypad-icon',
                      onTap: auth.isBusy || auth.quickUnlock?.enabled != true
                          ? null
                          : _showPin,
                    ),
                  ],
                ),
              ),
            ),
            if (auth.isBusy) ...[
              const SizedBox(height: 12),
              Semantics(
                liveRegion: true,
                child: Text(
                  'Checking your saved session…',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.white),
                ),
              ),
            ],
          ],
        ),
        footer: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: EvcsFooterLink(
                    label: 'Help center',
                    onPressed: auth.isBusy ? null : _help,
                  ),
                ),
                Expanded(
                  child: EvcsFooterLink(
                    label: 'Forgot app PIN?',
                    onPressed: auth.isBusy ? null : _usePassword,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 4),
            EvcsFooterLink(
              label: 'Use your password',
              onPressed: auth.isBusy ? null : _usePassword,
            ),
          ],
        ),
      );
    },
  );

  Widget _method({
    required String label,
    required String detail,
    required String asset,
    required VoidCallback? onTap,
  }) => Expanded(
    child: Semantics(
      button: true,
      enabled: onTap != null,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(10, 24, 10, 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Opacity(
                opacity: onTap == null ? .35 : 1,
                child: EvcsIcon(asset, size: 36),
              ),
              const SizedBox(height: 16),
              Text(
                label,
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 13,
                  height: 18 / 13,
                  fontWeight: FontWeight.w600,
                  color: onTap == null
                      ? PowerSolutionsColors.textSecondary
                      : PowerSolutionsColors.brandNavy,
                ),
              ),
              const SizedBox(height: 5),
              Text(
                detail,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 12,
                  height: 16 / 12,
                  color: PowerSolutionsColors.textPrimary,
                ),
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

class QuickUnlockSettingsScreen extends StatefulWidget {
  const QuickUnlockSettingsScreen({required this.dependencies, super.key});
  final AppDependencies dependencies;
  @override
  State<QuickUnlockSettingsScreen> createState() =>
      _QuickUnlockSettingsScreenState();
}

class _QuickUnlockSettingsScreenState extends State<QuickUnlockSettingsScreen> {
  final _form = GlobalKey<FormState>();
  final _pin = TextEditingController();
  final _confirmation = TextEditingController();
  bool _biometrics = false;

  @override
  void dispose() {
    _pin.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    final pin = _pin.text;
    _pin.clear();
    _confirmation.clear();
    if (await widget.dependencies.auth.configureQuickUnlock(
          pin,
          biometrics: _biometrics,
        ) &&
        mounted) {
      showVtsaAlert(
        context,
        message: 'Quick unlock is ready on this phone.',
        tone: VtsaAlertTone.success,
      );
    }
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: widget.dependencies.auth,
    builder: (context, _) {
      final auth = widget.dependencies.auth;
      final enabled = auth.quickUnlock?.enabled == true;
      return VtsaPageShell(
        eyebrow: 'ACCOUNT SECURITY',
        title: 'PIN & fingerprint',
        body: Form(
          key: _form,
          child: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () => context.go('/account'),
                    icon: const Icon(Icons.arrow_back),
                    label: const Text('Account'),
                  ),
                ),
                VtsaCard(
                  eyebrow: enabled ? 'QUICK UNLOCK ON' : 'THIS PHONE ONLY',
                  title: enabled
                      ? 'Ready for your next drive'
                      : 'Make sign-in simpler',
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        enabled
                            ? 'Your app PIN is enabled${auth.quickUnlock!.biometricsEnabled ? ' with fingerprint / Face ID' : ''}. The app locks when you leave it.'
                            : 'Create a six-digit app PIN. Your first name will appear on this phone’s locked welcome screen. You can also use a fingerprint or Face ID already enrolled on your phone.',
                      ),
                      const SizedBox(height: 12),
                      const Text(
                        'Your password is still needed when your session expires, '
                        'after sign-out, or after five incorrect PIN attempts.',
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 24),
                if (enabled || !auth.canConfigureQuickUnlock) ...[
                  Text(
                    enabled
                        ? 'To change or remove quick unlock, sign out below. Then sign in with your password to choose a new PIN.'
                        : 'Sign in with your password again before setting up quick unlock.',
                  ),
                  const SizedBox(height: 16),
                  VtsaButton(
                    label: 'Sign out and use password',
                    loading: auth.isBusy,
                    variant: VtsaButtonVariant.secondary,
                    onPressed: auth.isBusy
                        ? null
                        : () async {
                            await widget.dependencies.pushRegistration
                                .unregister();
                            await widget.dependencies.charging.onSignedOut();
                            await auth.logout();
                          },
                  ),
                ] else if (auth.quickUnlock?.device.supported == true) ...[
                  _PinField(
                    controller: _pin,
                    label: 'New app PIN',
                    enabled: !auth.isBusy,
                    validator: (value) =>
                        RegExp(r'^\d{6}$').hasMatch(value ?? '')
                        ? null
                        : 'Enter exactly six digits.',
                  ),
                  const SizedBox(height: 16),
                  _PinField(
                    controller: _confirmation,
                    label: 'Confirm app PIN',
                    enabled: !auth.isBusy,
                    validator: (value) =>
                        value == _pin.text ? null : 'PINs must match.',
                  ),
                  const SizedBox(height: 16),
                  SwitchListTile.adaptive(
                    contentPadding: EdgeInsets.zero,
                    title: const Text('Fingerprint / Face ID'),
                    subtitle: const Text(
                      'Your phone will ask you to confirm. Biometric data stays on your device.',
                    ),
                    value: _biometrics,
                    onChanged: auth.isBusy
                        ? null
                        : (value) => setState(() => _biometrics = value),
                  ),
                  const SizedBox(height: 24),
                  VtsaButton(
                    label: 'Enable quick unlock',
                    loading: auth.isBusy,
                    onPressed: auth.isBusy ? null : _save,
                  ),
                ] else
                  const Text(
                    'Quick unlock is available in the Android and iOS app.',
                  ),
                VtsaAlertRegion(
                  title: 'Could not set up quick unlock',
                  message: auth.failure?.message,
                  correlationId: auth.failure?.correlationId,
                ),
              ],
            ),
          ),
        ),
      );
    },
  );
}

class _PinField extends StatelessWidget {
  const _PinField({
    required this.controller,
    required this.label,
    required this.enabled,
    this.validator,
  });
  final TextEditingController controller;
  final String label;
  final bool enabled;
  final String? Function(String?)? validator;
  @override
  Widget build(BuildContext context) => TextFormField(
    controller: controller,
    enabled: enabled,
    obscureText: true,
    autocorrect: false,
    enableSuggestions: false,
    enableIMEPersonalizedLearning: false,
    keyboardType: TextInputType.number,
    maxLength: 6,
    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
    decoration: InputDecoration(
      labelText: label,
      counterText: '',
      prefixIcon: const Icon(Icons.pin_outlined),
    ),
    validator: validator,
  );
}
