import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_app_bar.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/presentation/auth_entry_scaffold.dart';
import 'package:vtsa_mobile/features/auth/presentation/driver_date_field.dart';
import 'package:vtsa_mobile/features/auth/presentation/registration_validation.dart';

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
  Widget build(BuildContext context) => AuthEntryScaffold(
    title: 'Sign in to your drive.',
    subtitle: 'Welcome back to Power Solutions.',
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
            const Center(child: Text('New to Power Solutions?')),
            const SizedBox(height: 8),
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
  Set<String> _invalid = {};
  final _fieldKeys = {
    for (final name in [
      'first_name',
      'middle_name',
      'last_name',
      'birth_date',
      'plate_number',
      'email',
      'password',
    ])
      name: GlobalKey(),
  };

  void _showFirstInvalid() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || _invalid.isEmpty) return;
      final fieldContext = _fieldKeys[_invalid.first]?.currentContext;
      if (fieldContext != null) {
        Scrollable.ensureVisible(
          fieldContext,
          alignment: .2,
          duration: const Duration(milliseconds: 200),
        );
      }
    });
  }

  AppFailure? _requestFailure;

  void _edited(String field) {
    setState(() {
      _invalid.remove(field);
      _requestFailure = null;
    });
  }

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
            key: _fieldKeys['first_name'],
            invalid: _invalid.contains('first_name'),
            onChanged: (_) => _edited('first_name'),
            enabled: !widget.dependencies.auth.isBusy,
            required: true,
            autofillHints: const [AutofillHints.givenName],
          ),
          const SizedBox(height: VtsaSpacing.md),
          VtsaTextField(
            label: 'Middle name (optional)',
            controller: _middle,
            key: _fieldKeys['middle_name'],
            invalid: _invalid.contains('middle_name'),
            onChanged: (_) => _edited('middle_name'),
            enabled: !widget.dependencies.auth.isBusy,
            autofillHints: const [AutofillHints.middleName],
          ),
          const SizedBox(height: VtsaSpacing.md),
          VtsaTextField(
            label: 'Last name',
            controller: _last,
            key: _fieldKeys['last_name'],
            invalid: _invalid.contains('last_name'),
            onChanged: (_) => _edited('last_name'),
            enabled: !widget.dependencies.auth.isBusy,
            required: true,
            autofillHints: const [AutofillHints.familyName],
          ),
          const SizedBox(height: VtsaSpacing.md),
          DriverDateField(
            label: 'Date of birth',
            value: _birthDate,
            key: _fieldKeys['birth_date'],
            invalid: _invalid.contains('birth_date'),
            onChanged: (date) {
              _birthDate = date;
              _edited('birth_date');
            },
          ),
          const SizedBox(height: VtsaSpacing.md),
          if (!_platePending)
            VtsaTextField(
              label: 'Vehicle plate number',
              controller: _plate,
              key: _fieldKeys['plate_number'],
              invalid: _invalid.contains('plate_number'),
              onChanged: (_) => _edited('plate_number'),
              enabled: !widget.dependencies.auth.isBusy,
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
            onChanged: (value) => setState(() {
              _platePending = value ?? false;
              _invalid.remove('plate_number');
              _requestFailure = null;
            }),
          ),
          const SizedBox(height: VtsaSpacing.md),
          VtsaTextField(
            label: 'Email address',
            controller: _email,
            key: _fieldKeys['email'],
            invalid: _invalid.contains('email'),
            onChanged: (_) => _edited('email'),
            enabled: !widget.dependencies.auth.isBusy,
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
            key: _fieldKeys['password'],
            invalid: _invalid.contains('password'),
            onChanged: (_) => _edited('password'),
            enabled: !widget.dependencies.auth.isBusy,
            obscureText: true,
            autofillHints: const [AutofillHints.newPassword],
            required: true,
          ),
          const SizedBox(height: VtsaSpacing.lg),
          if (_requestFailure != null) ...[
            VtsaErrorState(
              title: 'Account creation was not successful',
              description: _requestFailure!.kind == FailureKind.validation
                  ? 'We could not create this account. If you already have an account, sign in or reset your password.'
                  : _requestFailure!.message,
              correlationId: _requestFailure!.correlationId,
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
    if (widget.dependencies.auth.isBusy) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _requestFailure = null;
      _invalid = registrationInvalidFields(
        firstName: _first.text,
        middleName: _middle.text,
        lastName: _last.text,
        birthDate: _birthDate,
        today: DateTime.now().toUtc(),
        plate: _plate.text,
        platePending: _platePending,
        email: _email.text,
        password: _password.text,
      );
    });
    if (_invalid.isNotEmpty) {
      _showFirstInvalid();
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
    if (!mounted) return;
    if (!created) {
      final failure = widget.dependencies.auth.failure;
      setState(() {
        _invalid =
            failure?.fieldErrors.keys
                .where(
                  (field) => const {
                    'first_name',
                    'middle_name',
                    'last_name',
                    'birth_date',
                    'plate_number',
                    'email',
                    'password',
                  }.contains(field),
                )
                .toSet() ??
            {};
        // Account existence and configuration failures must never blame a field.
        _requestFailure = _invalid.isEmpty ? failure : null;
      });
      _showFirstInvalid();
      return;
    }
    if (created) {
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
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: widget.dependencies.auth,
    builder: (context, _) => AuthEntryScaffold(
      title: _sent ? 'Check your inbox.' : 'Reset your password.',
      subtitle: _sent
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
                if (widget.dependencies.auth.failure != null)
                  VtsaErrorState(
                    title: 'Could not send reset link',
                    description: widget.dependencies.auth.failure!.message,
                  ),
                VtsaButton(
                  label: 'Send reset link',
                  loading: widget.dependencies.auth.isBusy,
                  onPressed: widget.dependencies.auth.isBusy ? null : _submit,
                ),
              ],
            ),
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
