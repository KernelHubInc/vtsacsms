import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';

class UnlockScreen extends StatefulWidget {
  const UnlockScreen({required this.dependencies, super.key});
  final AppDependencies dependencies;
  @override
  State<UnlockScreen> createState() => _UnlockScreenState();
}

class _UnlockScreenState extends State<UnlockScreen> {
  final _pin = TextEditingController();
  @override
  void dispose() {
    _pin.dispose();
    super.dispose();
  }

  Future<void> _unlock({bool biometric = false}) async {
    final auth = widget.dependencies.auth;
    final pin = _pin.text;
    _pin.clear();
    if (await auth.unlock(pin: biometric ? null : pin)) {
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

  Future<void> _usePassword() async {
    await widget.dependencies.auth.usePassword();
    if (widget.dependencies.auth.status == AuthStatus.guest) {
      await _clearAccount();
    }
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: widget.dependencies.auth,
    builder: (context, _) {
      final auth = widget.dependencies.auth;
      return VtsaPageShell(
        eyebrow: 'WELCOME BACK',
        title: 'Unlock your drive.',
        body: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: ListView(
              shrinkWrap: true,
              children: [
                Icon(
                  Icons.lock_outline_rounded,
                  size: 52,
                  color: Theme.of(context).colorScheme.primary,
                ),
                const SizedBox(height: 24),
                const Text('Enter your six-digit app PIN to continue.'),
                const SizedBox(height: 20),
                _PinField(
                  controller: _pin,
                  label: 'App PIN',
                  enabled: !auth.isBusy,
                  onSubmitted: (_) {
                    if (_pin.text.length == 6) _unlock();
                  },
                ),
                const SizedBox(height: 16),
                if (auth.failure != null) ...[
                  Text(
                    auth.failure!.message,
                    semanticsLabel: auth.failure!.message,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
                ValueListenableBuilder(
                  valueListenable: _pin,
                  builder: (context, value, _) => VtsaButton(
                    label: 'Unlock with PIN',
                    loading: auth.isBusy,
                    onPressed: value.text.length == 6 && !auth.isBusy
                        ? () => _unlock()
                        : null,
                  ),
                ),
                if (auth.quickUnlock?.biometricsEnabled == true) ...[
                  const SizedBox(height: 12),
                  VtsaButton(
                    label: 'Use fingerprint / Face ID',
                    variant: VtsaButtonVariant.secondary,
                    onPressed: auth.isBusy
                        ? null
                        : () => _unlock(biometric: true),
                  ),
                ],
                const SizedBox(height: 24),
                TextButton(
                  onPressed: auth.isBusy ? null : _usePassword,
                  child: const Text('Forgot PIN? Sign in with password'),
                ),
                const Text(
                  'Password sign-in removes quick unlock from this phone. '
                  'You can set it up again in Account.',
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      );
    },
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
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Quick unlock is ready on this phone.')),
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
                            : 'Create a six-digit app PIN. You can also use a fingerprint or Face ID already enrolled on your phone.',
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
                if (auth.failure != null) ...[
                  const SizedBox(height: 16),
                  Text(
                    auth.failure!.message,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                ],
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
    this.onSubmitted,
  });
  final TextEditingController controller;
  final String label;
  final bool enabled;
  final String? Function(String?)? validator;
  final ValueChanged<String>? onSubmitted;
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
    onFieldSubmitted: onSubmitted,
  );
}
