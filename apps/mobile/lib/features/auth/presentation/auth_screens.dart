import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_app_bar.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/presentation/auth_entry_scaffold.dart';
import 'package:vtsa_mobile/features/auth/presentation/driver_date_field.dart';
import 'package:vtsa_mobile/features/auth/presentation/registration_layout.dart';
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
  bool _passwordVisible = false;
  int _step = 0;
  final _scroll = ScrollController();
  static const _stepFields = [
    {'email', 'password', 'password_confirmation'},
    {'first_name', 'middle_name', 'last_name', 'birth_date'},
    {'plate_number'},
  ];
  Set<String> _invalid = {};
  final Set<String> _validated = {};
  final Map<String, String> _serverErrors = {};
  late final Map<String, FocusNode> _focusNodes;
  final _fieldKeys = {
    for (final name in [
      'email',
      'password',
      'password_confirmation',
      'first_name',
      'middle_name',
      'last_name',
      'birth_date',
      'plate_number',
    ])
      name: GlobalKey(),
  };

  void _showFirstInvalid() {
    if (_invalid.isEmpty) return;
    final first = _fieldKeys.keys.firstWhere(_invalid.contains);
    setState(
      () => _step = _stepFields.indexWhere((fields) => fields.contains(first)),
    );
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || _invalid.isEmpty) return;
      final fieldContext =
          _fieldKeys[_fieldKeys.keys.firstWhere(_invalid.contains)]
              ?.currentContext;
      if (fieldContext != null) {
        _focusNodes[_fieldKeys.keys.firstWhere(_invalid.contains)]
            ?.requestFocus();
        Scrollable.ensureVisible(
          fieldContext,
          alignment: .2,
          duration: MediaQuery.disableAnimationsOf(context)
              ? Duration.zero
              : const Duration(milliseconds: 200),
        );
      }
    });
  }

  AppFailure? _requestFailure;

  @override
  void initState() {
    super.initState();
    _focusNodes = {
      for (final field in _fieldKeys.keys.where((name) => name != 'birth_date'))
        field: FocusNode(),
    };
    for (final entry in _focusNodes.entries) {
      entry.value.addListener(() {
        if (!entry.value.hasFocus &&
            mounted &&
            !widget.dependencies.auth.isBusy) {
          setState(() {
            _validated.add(entry.key);
            _refreshValidation(entry.key);
          });
        }
      });
    }
  }

  void _refreshValidation(String field) {
    if (!_validated.contains(field)) return;
    if (_invalidFields().contains(field) || _serverErrors.containsKey(field)) {
      _invalid.add(field);
    } else {
      _invalid.remove(field);
    }
  }

  String? _fieldError(String field) => _invalid.contains(field)
      ? _serverErrors[field] ?? registrationFieldMessage(field)
      : null;

  void _edited(String field) {
    setState(() {
      _serverErrors.remove(field);
      _refreshValidation(field);
      if (field == 'password') {
        _refreshValidation('password_confirmation');
      }
      _requestFailure = null;
    });
  }

  final _email = TextEditingController();
  final _password = TextEditingController();
  final _passwordConfirmation = TextEditingController();

  @override
  void dispose() {
    for (final node in _focusNodes.values) {
      node.dispose();
    }
    _scroll.dispose();
    _first.dispose();
    _middle.dispose();
    _last.dispose();
    _plate.dispose();
    _email.dispose();
    _password.dispose();
    _passwordConfirmation.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => RegistrationLayout(
    step: _step,
    scrollController: _scroll,
    canExit: () => !widget.dependencies.auth.isBusy,
    child: AnimatedBuilder(
      animation: widget.dependencies.auth,
      builder: (context, _) {
        final busy = widget.dependencies.auth.isBusy;
        final muted = Theme.of(context).textTheme.bodySmall;
        return PopScope(
          canPop: _step == 0 && !busy,
          onPopInvokedWithResult: (didPop, _) {
            if (!didPop && !busy && _step > 0) _setStep(_step - 1);
          },
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Offstage(
                offstage: _step != 0,
                child: ExcludeFocus(
                  excluding: _step != 0,
                  child: RegistrationSection(
                    title: 'Account security',
                    description: 'Set up your email and a secure password.',
                    icon: 'shield-check',
                    requiredNotice: true,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _field(
                          'email',
                          'Email address',
                          _email,
                          'you@example.com',
                          autofill: AutofillHints.newUsername,
                          keyboardType: TextInputType.emailAddress,
                        ),
                        const SizedBox(height: 8),
                        Text('You’ll use this email to sign in.', style: muted),
                        const SizedBox(height: 22),
                        _field(
                          'password',
                          'Password',
                          _password,
                          'Create a password',
                          autofill: AutofillHints.newPassword,
                          obscureText: !_passwordVisible,
                        ),
                        const SizedBox(height: 12),
                        RegistrationPasswordGuide(password: _password.text),
                        const SizedBox(height: 20),
                        _field(
                          'password_confirmation',
                          'Confirm password',
                          _passwordConfirmation,
                          'Enter your password again',
                          autofill: AutofillHints.newPassword,
                          obscureText: !_passwordVisible,
                        ),
                        Align(
                          alignment: Alignment.centerLeft,
                          child: TextButton(
                            onPressed: busy
                                ? null
                                : () => setState(
                                    () => _passwordVisible = !_passwordVisible,
                                  ),
                            style: TextButton.styleFrom(
                              padding: EdgeInsets.zero,
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const RegistrationIcon('eye', 17),
                                const SizedBox(width: 7),
                                Flexible(
                                  child: Text(
                                    _passwordVisible
                                        ? 'Hide passwords'
                                        : 'Show passwords',
                                    style: const TextStyle(fontSize: 12),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              Offstage(
                offstage: _step != 1,
                child: ExcludeFocus(
                  excluding: _step != 1,
                  child: RegistrationSection(
                    title: 'Personal information',
                    description: 'A few details so we can get to know you.',
                    icon: 'user-round',
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _field(
                          'first_name',
                          'First name',
                          _first,
                          'Enter your first name',
                          autofill: AutofillHints.givenName,
                        ),
                        const SizedBox(height: 20),
                        _field(
                          'middle_name',
                          'Middle name · Optional',
                          _middle,
                          'Enter your middle name',
                          required: false,
                          autofill: AutofillHints.middleName,
                        ),
                        const SizedBox(height: 20),
                        _field(
                          'last_name',
                          'Last name',
                          _last,
                          'Enter your last name',
                          autofill: AutofillHints.familyName,
                        ),
                        const SizedBox(height: 20),
                        DriverDateField(
                          label: 'Date of birth',
                          value: _birthDate,
                          key: _fieldKeys['birth_date'],
                          labelAbove: true,
                          enabled: !busy,
                          invalid: _invalid.contains('birth_date'),
                          error: _fieldError('birth_date'),
                          onChanged: (date) {
                            _birthDate = date;
                            _edited('birth_date');
                          },
                        ),
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            const RegistrationIcon('info', 15),
                            const SizedBox(width: 7),
                            Expanded(
                              child: Text(
                                'You must be 18 or older to register.',
                                style: muted,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              Offstage(
                offstage: _step != 2,
                child: ExcludeFocus(
                  excluding: _step != 2,
                  child: RegistrationSection(
                    title: 'Vehicle details',
                    description: 'Connect your vehicle to your account.',
                    icon: 'car-front',
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (!_platePending) ...[
                          _field(
                            'plate_number',
                            'Vehicle plate number',
                            _plate,
                            'Enter your plate number',
                          ),
                          const SizedBox(height: 8),
                          Text(
                            'Use the plate on your vehicle. You can update it later. Not required if your plate is pending.',
                            style: muted,
                          ),
                          const SizedBox(height: 22),
                        ],
                        CheckboxListTile(
                          contentPadding: EdgeInsets.zero,
                          controlAffinity: ListTileControlAffinity.leading,
                          title: const Text(
                            'Plate pending',
                            style: TextStyle(fontSize: 14),
                          ),
                          subtitle: Text(
                            'For a newly registered vehicle awaiting its plate. Select this if you don’t have a plate yet.',
                            style: muted?.copyWith(fontSize: 13),
                          ),
                          value: _platePending,
                          onChanged: busy
                              ? null
                              : (value) => setState(() {
                                  _platePending = value ?? false;
                                  _invalid.remove('plate_number');
                                  _serverErrors.remove('plate_number');
                                  _requestFailure = null;
                                }),
                        ),
                        const SizedBox(height: 22),
                        Container(
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(
                            color: RegistrationColors.soft,
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const RegistrationIcon('circle-plus', 18),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text(
                                      'The rest can wait',
                                      style: TextStyle(
                                        fontSize: 13,
                                        fontWeight: FontWeight.w600,
                                        color: RegistrationColors.teal,
                                      ),
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      'You can add your vehicle specifications later from your account.',
                                      style: muted?.copyWith(
                                        color: RegistrationColors.muted,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              Container(
                color: Theme.of(context).colorScheme.surface,
                padding: const EdgeInsets.fromLTRB(24, 24, 24, 28),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (_step == 2) ...[
                      Text(
                        'By creating an account, you agree to our',
                        style: muted,
                      ),
                      Wrap(
                        crossAxisAlignment: WrapCrossAlignment.center,
                        spacing: 4,
                        children: [
                          TextButton(
                            onPressed: () => _openPolicy('/terms-of-service'),
                            child: const Text('Terms of service'),
                          ),
                          Text('and', style: muted),
                          TextButton(
                            onPressed: () => _openPolicy('/privacy-policy'),
                            child: const Text('Privacy policy'),
                          ),
                        ],
                      ),
                      const SizedBox(height: 20),
                    ],
                    if (_requestFailure != null) ...[
                      VtsaErrorState(
                        title: 'Account creation was not successful',
                        description:
                            _requestFailure!.kind == FailureKind.validation
                            ? 'We could not create this account. If you already have an account, sign in or reset your password.'
                            : _requestFailure!.message,
                        correlationId: _requestFailure!.correlationId,
                      ),
                      const SizedBox(height: 16),
                    ],
                    if (_invalid.any(_stepFields[_step].contains)) ...[
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          'Check the highlighted fields to continue.',
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.error,
                          ),
                        ),
                      ),
                      const SizedBox(height: 12),
                    ],
                    Row(
                      children: [
                        if (_step > 0) ...[
                          TextButton(
                            onPressed: busy ? null : () => _setStep(_step - 1),
                            child: const Text('Back'),
                          ),
                          const SizedBox(width: 16),
                        ],
                        Expanded(
                          child: VtsaButton(
                            label: _step == 2 ? 'Create account' : 'Continue',
                            loading: busy,
                            onPressed: _step == 2 ? _submit : _continue,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 20),
                    Wrap(
                      alignment: WrapAlignment.center,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      spacing: 5,
                      children: [
                        Text(
                          'Already have an account?',
                          style: muted?.copyWith(fontSize: 13),
                        ),
                        TextButton(
                          onPressed: busy ? null : () => context.go('/login'),
                          child: const Text('Sign in'),
                        ),
                      ],
                    ),
                    const SizedBox(height: 20),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const RegistrationIcon('lock-keyhole', 13),
                        const SizedBox(width: 6),
                        Flexible(
                          child: Text(
                            'Your information stays safe and secure.',
                            style: muted?.copyWith(fontSize: 11),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    ),
  );

  Widget _field(
    String name,
    String label,
    TextEditingController controller,
    String placeholder, {
    bool required = true,
    String? autofill,
    TextInputType? keyboardType,
    bool obscureText = false,
  }) => VtsaTextField(
    key: _fieldKeys[name],
    label: label,
    controller: controller,
    placeholder: placeholder,
    labelAbove: true,
    required: required,
    invalid: _invalid.contains(name),
    error: _fieldError(name),
    focusNode: _focusNodes[name],
    enabled: !widget.dependencies.auth.isBusy,
    onChanged: (_) => _edited(name),
    autofillHints: autofill == null ? null : [autofill],
    keyboardType: keyboardType,
    obscureText: obscureText,
    textInputAction: TextInputAction.next,
  );

  Future<void> _openPolicy(String path) async {
    try {
      final opened = await launchUrl(
        widget.dependencies.environment.apiBaseUrl.resolve(path),
        mode: LaunchMode.externalApplication,
      );
      if (opened || !mounted) return;
    } catch (_) {
      if (!mounted) return;
    }
    if (mounted) {
      showVtsaToast(
        context,
        message: 'Unable to open this page. Please try again.',
      );
    }
  }

  void _setStep(int step) {
    if (widget.dependencies.auth.isBusy) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _step = step;
      _passwordVisible = false;
      _requestFailure = null;
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted && _scroll.hasClients) _scroll.jumpTo(0);
    });
  }

  void _continue() {
    if (_step == 2 || widget.dependencies.auth.isBusy) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _requestFailure = null;
      _validated.addAll(_stepFields[_step]);
      _invalid = _invalidFields().where(_stepFields[_step].contains).toSet();
      _invalid.addAll(_serverErrors.keys.where(_stepFields[_step].contains));
    });
    if (_invalid.isNotEmpty) {
      _showFirstInvalid();
      return;
    }
    _setStep(_step + 1);
  }

  Set<String> _invalidFields() => registrationInvalidFields(
    firstName: _first.text,
    middleName: _middle.text,
    lastName: _last.text,
    birthDate: _birthDate,
    today: DateTime.now().toUtc(),
    plate: _plate.text,
    platePending: _platePending,
    email: _email.text,
    password: _password.text,
    passwordConfirmation: _passwordConfirmation.text,
  );

  Future<void> _submit() async {
    if (_step != 2 || widget.dependencies.auth.isBusy) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _requestFailure = null;
      _validated.addAll(_fieldKeys.keys);
      _invalid = _invalidFields();
      _invalid.addAll(_serverErrors.keys);
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
      passwordConfirmation: _passwordConfirmation.text,
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
                    'password_confirmation',
                  }.contains(field),
                )
                .toSet() ??
            {};
        // Account existence and configuration failures must never blame a field.
        _serverErrors
          ..clear()
          ..addEntries(
            _invalid.map(
              (field) => MapEntry(field, registrationServerFieldMessage(field)),
            ),
          );
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
