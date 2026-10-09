import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_app_bar.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/presentation/driver_date_field.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _AuthShell(
    eyebrow: 'WELCOME BACK',
    title: 'Sign in to your drive.',
    intro:
        'Sign in to find charging stations, manage your vehicles, and verify your identity. New here? Create an account to get started.',
    child: AnimatedBuilder(
      animation: widget.dependencies.auth,
      builder: (context, _) {
        final auth = widget.dependencies.auth;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AutofillGroup(
              child: Column(
                children: [
                  VtsaTextField(
                    label: 'Email address',
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    textInputAction: TextInputAction.next,
                    autofillHints: const [AutofillHints.email],
                    required: true,
                  ),
                  const SizedBox(height: VtsaSpacing.md),
                  VtsaTextField(
                    label: 'Password',
                    controller: _password,
                    obscureText: true,
                    textInputAction: TextInputAction.done,
                    autofillHints: const [AutofillHints.password],
                    onSubmitted: (_) => _submit(),
                    required: true,
                  ),
                ],
              ),
            ),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                onPressed: () => context.push('/forgot-password'),
                child: const Text('Forgot password?'),
              ),
            ),
            if (auth.failure != null) ...[
              VtsaErrorState(
                title: 'Could not sign in',
                description: auth.failure!.message,
                correlationId: auth.failure!.correlationId,
              ),
              const SizedBox(height: VtsaSpacing.md),
            ],
            VtsaButton(
              label: 'Sign in',
              loading: auth.isBusy,
              onPressed: _submit,
            ),
            const SizedBox(height: VtsaSpacing.md),
            VtsaButton(
              label: 'Create an account',
              variant: VtsaButtonVariant.secondary,
              onPressed: () => context.push('/register'),
            ),
          ],
        );
      },
    ),
  );

  Future<void> _submit() async {
    if (_email.text.trim().isEmpty || _password.text.isEmpty) {
      showVtsaToast(context, message: 'Enter your email and password.');
      return;
    }
    final dependencies = widget.dependencies;
    final signedIn = await dependencies.auth.login(
      email: _email.text,
      password: _password.text,
    );
    if (!signedIn || dependencies.auth.needsEmailVerification) {
      return;
    }
    await Future.wait([
      dependencies.favorites.load(),
      dependencies.vehicles.load(),
      dependencies.pushRegistration.register(),
      if (dependencies.featureFlags.remoteCharging ||
          dependencies.featureFlags.simulatedCharging)
        dependencies.charging.restoreAuthoritativeSession(),
    ]);
  }
}

class RegistrationScreen extends StatefulWidget {
  const RegistrationScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<RegistrationScreen> createState() => _RegistrationScreenState();
}

class _RegistrationScreenState extends State<RegistrationScreen> {
  final _first = TextEditingController();
  final _middle = TextEditingController();
  final _last = TextEditingController();
  final _plate = TextEditingController();
  DateTime? _birthDate;
  bool _platePending = false;
  final _email = TextEditingController();
  final _password = TextEditingController();

  @override
  void dispose() {
    _first.dispose();
    _middle.dispose();
    _last.dispose();
    _plate.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _AuthShell(
    eyebrow: 'NEW DRIVER',
    title: 'Make Power Solutions yours.',
    intro:
        'Start with your details and vehicle plate. You must be 18 or older. You can add vehicle specifications later.',
    child: AnimatedBuilder(
      animation: widget.dependencies.auth,
      builder: (context, _) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          VtsaTextField(
            label: 'First name',
            controller: _first,
            required: true,
            autofillHints: const [AutofillHints.givenName],
          ),
          const SizedBox(height: VtsaSpacing.md),
          VtsaTextField(
            label: 'Middle name (optional)',
            controller: _middle,
            autofillHints: const [AutofillHints.middleName],
          ),
          const SizedBox(height: VtsaSpacing.md),
          VtsaTextField(
            label: 'Last name',
            controller: _last,
            required: true,
            autofillHints: const [AutofillHints.familyName],
          ),
          const SizedBox(height: VtsaSpacing.md),
          DriverDateField(
            label: 'Date of birth',
            value: _birthDate,
            onChanged: (date) => setState(() => _birthDate = date),
          ),
          const SizedBox(height: VtsaSpacing.md),
          if (!_platePending)
            VtsaTextField(
              label: 'Vehicle plate number',
              controller: _plate,
              required: true,
              hint: 'Use the plate on your vehicle. You can update it later.',
            ),
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Plate pending'),
            subtitle: const Text(
              'For a newly registered vehicle awaiting its plate.',
            ),
            value: _platePending,
            onChanged: (value) =>
                setState(() => _platePending = value ?? false),
          ),
          const SizedBox(height: VtsaSpacing.md),
          VtsaTextField(
            label: 'Email address',
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            textInputAction: TextInputAction.next,
            autofillHints: const [AutofillHints.newUsername],
            required: true,
          ),
          const SizedBox(height: VtsaSpacing.md),
          VtsaTextField(
            label: 'Password',
            hint:
                '12+ characters, with uppercase, lowercase, a number and a symbol.',
            controller: _password,
            obscureText: true,
            autofillHints: const [AutofillHints.newPassword],
            required: true,
          ),
          const SizedBox(height: VtsaSpacing.lg),
          if (widget.dependencies.auth.failure != null) ...[
            VtsaErrorState(
              title: 'Account creation was not successful',
              description:
                  'Check your details and try again. If you already have an account, sign in or reset your password.',
              correlationId: widget.dependencies.auth.failure!.correlationId,
            ),
            const SizedBox(height: VtsaSpacing.md),
          ],
          VtsaButton(
            label: 'Create account',
            loading: widget.dependencies.auth.isBusy,
            onPressed: _submit,
          ),
          TextButton(
            onPressed: () => context.pop(),
            child: const Text('Already have an account? Sign in'),
          ),
        ],
      ),
    ),
  );

  Future<void> _submit() async {
    if (_first.text.trim().isEmpty ||
        _last.text.trim().isEmpty ||
        _first.text.trim().length > 80 ||
        _middle.text.trim().length > 80 ||
        _last.text.trim().length > 80 ||
        _birthDate == null ||
        !isAdult(_birthDate!, DateTime.now().toUtc()) ||
        (!_platePending &&
            !RegExp(
              r'^[A-Z0-9][A-Z0-9 -]{0,19}$',
            ).hasMatch(_plate.text.trim().toUpperCase())) ||
        !RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(_email.text.trim()) ||
        _password.text.length < 12 ||
        !RegExp('[A-Z]').hasMatch(_password.text) ||
        !RegExp('[a-z]').hasMatch(_password.text) ||
        !RegExp('[0-9]').hasMatch(_password.text) ||
        !RegExp(r'[^a-zA-Z0-9]').hasMatch(_password.text)) {
      showVtsaToast(
        context,
        message:
            'Enter your names, an adult date of birth, a plate or Plate pending, a valid email and the required password.',
      );
      return;
    }
    final created = await widget.dependencies.auth.register(
      name: [
        _first.text.trim(),
        _middle.text.trim(),
        _last.text.trim(),
      ].where((s) => s.isNotEmpty).join(' '),
      firstName: _first.text,
      middleName: _middle.text,
      lastName: _last.text,
      birthDate: dateOnly(_birthDate!),
      plateNumber: _plate.text,
      platePending: _platePending,
      email: _email.text,
      password: _password.text,
    );
    if (mounted && created) {
      final deliveryFailed =
          widget.dependencies.auth.registration?.verificationEmailSent == false;
      context.go(
        Uri(
          path: '/verify-email',
          queryParameters: {
            'email': _email.text,
            if (deliveryFailed) 'delivery': 'failed',
          },
        ).toString(),
      );
    }
  }
}

class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _email = TextEditingController();
  bool _sent = false;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _AuthShell(
    eyebrow: 'ACCOUNT RECOVERY',
    title: _sent ? 'Check your inbox.' : 'Reset your password.',
    intro: _sent
        ? 'If an account matches that address, reset instructions are on their way.'
        : 'We will send a time-limited reset link without revealing whether an account exists.',
    child: _sent
        ? VtsaButton(
            label: 'Back to sign in',
            onPressed: () => context.go('/login'),
          )
        : Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              VtsaTextField(
                label: 'Email address',
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                required: true,
              ),
              const SizedBox(height: VtsaSpacing.lg),
              VtsaButton(
                label: 'Send reset link',
                loading: widget.dependencies.auth.isBusy,
                onPressed: _submit,
              ),
            ],
          ),
  );

  Future<void> _submit() async {
    if (!_email.text.contains('@')) {
      showVtsaToast(context, message: 'Enter a valid email address.');
      return;
    }
    final sent = await widget.dependencies.auth.requestPasswordReset(
      _email.text,
    );
    if (mounted && sent) {
      setState(() => _sent = true);
    }
  }
}

class EmailVerificationScreen extends StatelessWidget {
  const EmailVerificationScreen({
    required this.dependencies,
    this.email,
    this.deliveryFailed = false,
    super.key,
  });

  final AppDependencies dependencies;
  final String? email;
  final bool deliveryFailed;

  @override
  Widget build(BuildContext context) => _AuthShell(
    eyebrow: 'VERIFY EMAIL',
    title: deliveryFailed
        ? 'Your account is created.'
        : 'One tap from verified.',
    intro: deliveryFailed
        ? 'We could not send your verification email. Sign in with the account you just created to request another email. Email verification is still required before browsing.'
        : 'Open the signed link sent to ${email ?? dependencies.auth.user?.email ?? 'your email address'}. Return here when verification is complete.',
    child: AnimatedBuilder(
      animation: dependencies.auth,
      builder: (context, _) {
        final auth = dependencies.auth;
        final signedIn = auth.status == AuthStatus.authenticated;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (auth.failure != null) ...[
              VtsaErrorState(
                title: 'Verification needed',
                description: auth.failure!.message,
                correlationId: auth.failure!.correlationId,
              ),
              const SizedBox(height: VtsaSpacing.md),
            ],
            if (signedIn) ...[
              VtsaButton(
                label: "I've verified my email",
                loading: auth.isBusy,
                onPressed: () async {
                  final verified = await auth.refreshVerification();
                  if (!verified) return;
                  await Future.wait([
                    dependencies.favorites.load(),
                    dependencies.vehicles.load(),
                  ]);
                },
              ),
              const SizedBox(height: VtsaSpacing.sm),
              VtsaButton(
                label: 'Resend verification email',
                variant: VtsaButtonVariant.secondary,
                onPressed: auth.isBusy
                    ? null
                    : () async {
                        final sent = await auth.resendVerification();
                        if (context.mounted && sent) {
                          showVtsaToast(
                            context,
                            message: 'Verification email sent.',
                          );
                        }
                      },
              ),
              TextButton(
                onPressed: auth.isBusy ? null : auth.logout,
                child: const Text('Use a different account'),
              ),
            ] else ...[
              Text(
                deliveryFailed
                    ? 'Use the same email and password to sign in. You do not need to create another account.'
                    : 'Sign in after verifying your email. You can also sign in to request a new link.',
              ),
              const SizedBox(height: VtsaSpacing.md),
              VtsaButton(
                label: 'Sign in to continue',
                onPressed: () => context.go('/login'),
              ),
            ],
          ],
        );
      },
    ),
  );
}

class _AuthShell extends StatelessWidget {
  const _AuthShell({
    required this.eyebrow,
    required this.title,
    required this.intro,
    required this.child,
  });

  final String eyebrow;
  final String title;
  final String intro;
  final Widget child;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: PowerSolutionsAppBar(title: title, eyebrow: eyebrow),
    body: SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(VtsaSpacing.lg),
        child: Align(
          alignment: Alignment.topCenter,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const SizedBox(height: VtsaSpacing.md),
                Text(
                  intro,
                  style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                    color: context.vtsaColors.textMuted,
                  ),
                ),
                const SizedBox(height: VtsaSpacing.xxl),
                child,
              ],
            ),
          ),
        ),
      ),
    ),
  );
}
