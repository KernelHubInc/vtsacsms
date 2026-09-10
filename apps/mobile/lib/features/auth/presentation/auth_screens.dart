import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_app_bar.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

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
    intro: 'Your saved stations and vehicles stay tied to your account.',
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
            TextButton(
              onPressed: () => context.go('/explore'),
              child: const Text('Continue as guest'),
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
    final signedIn = await widget.dependencies.auth.login(
      email: _email.text,
      password: _password.text,
    );
    if (!mounted || !signedIn) {
      return;
    }
    await Future.wait([
      widget.dependencies.favorites.load(),
      widget.dependencies.vehicles.load(),
      widget.dependencies.pushRegistration.register(),
      widget.dependencies.charging.restoreAuthoritativeSession(),
    ]);
    if (mounted) {
      context.go('/account');
    }
  }
}

class RegistrationScreen extends StatefulWidget {
  const RegistrationScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<RegistrationScreen> createState() => _RegistrationScreenState();
}

class _RegistrationScreenState extends State<RegistrationScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _AuthShell(
    eyebrow: 'NEW DRIVER',
    title: 'Make Power Solutions yours.',
    intro:
        'Create an account for favorites and vehicle compatibility. Charging remains outside this release.',
    child: AnimatedBuilder(
      animation: widget.dependencies.auth,
      builder: (context, _) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          VtsaTextField(
            label: 'Full name',
            controller: _name,
            textInputAction: TextInputAction.next,
            autofillHints: const [AutofillHints.name],
            required: true,
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
            hint: 'Use at least 12 characters.',
            controller: _password,
            obscureText: true,
            autofillHints: const [AutofillHints.newPassword],
            required: true,
          ),
          const SizedBox(height: VtsaSpacing.lg),
          if (widget.dependencies.auth.failure != null) ...[
            VtsaErrorState(
              title: 'Could not create account',
              description: widget.dependencies.auth.failure!.message,
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
    if (_name.text.trim().isEmpty ||
        !_email.text.contains('@') ||
        _password.text.length < 12) {
      showVtsaToast(
        context,
        message: 'Complete all fields and use a 12-character password.',
      );
      return;
    }
    final created = await widget.dependencies.auth.register(
      name: _name.text,
      email: _email.text,
      password: _password.text,
    );
    if (mounted && created) {
      context.go('/verify-email?email=${Uri.encodeComponent(_email.text)}');
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
    super.key,
  });

  final AppDependencies dependencies;
  final String? email;

  @override
  Widget build(BuildContext context) => _AuthShell(
    eyebrow: 'VERIFY EMAIL',
    title: 'One tap from verified.',
    intro:
        'Open the signed link sent to ${email ?? dependencies.auth.user?.email ?? 'your email address'}. Return here when verification is complete.',
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (dependencies.auth.status.name == 'authenticated')
          VtsaButton(
            label: 'Resend verification email',
            onPressed: () async {
              final sent = await dependencies.auth.resendVerification();
              if (context.mounted && sent) {
                showVtsaToast(context, message: 'Verification email sent.');
              }
            },
          ),
        const SizedBox(height: VtsaSpacing.sm),
        VtsaButton(
          label: 'Continue',
          variant: VtsaButtonVariant.secondary,
          onPressed: () => context.go('/explore'),
        ),
      ],
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
