import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';

class GoogleAccessButton extends StatefulWidget {
  const GoogleAccessButton({
    required this.dependencies,
    this.onRegistration,
    super.key,
  });

  final AppDependencies dependencies;
  final VoidCallback? onRegistration;

  @override
  State<GoogleAccessButton> createState() => _GoogleAccessButtonState();
}

class _GoogleAccessButtonState extends State<GoogleAccessButton> {
  final _password = TextEditingController();
  bool _attempted = false;

  @override
  void dispose() {
    _password.dispose();
    super.dispose();
  }

  Future<void> _signIn({bool link = false}) async {
    final dependencies = widget.dependencies;
    if (link && _password.text.isEmpty) return;
    setState(() => _attempted = true);
    await dependencies.auth.signInWithGoogle(
      password: link ? _password.text : null,
    );
    if (mounted) _password.clear();
    await dependencies.loadSignedInData();
    if (!mounted) return;
    if (dependencies.auth.googleRegistration != null) {
      if (widget.onRegistration != null) {
        widget.onRegistration!();
      } else {
        context.go('/register');
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = widget.dependencies.auth;
    final dark = Theme.of(context).brightness == Brightness.dark;
    if (!auth.googleAvailable) return const SizedBox.shrink();
    if (auth.googleLinkRequired) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Connect your existing account',
            style: Theme.of(context).textTheme.titleSmall,
          ),
          const SizedBox(height: 8),
          const Text(
            'Confirm your Power Solutions password once to use Google for future sign-ins.',
          ),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('google-link-password'),
            controller: _password,
            enabled: !auth.isBusy,
            obscureText: true,
            autocorrect: false,
            enableSuggestions: false,
            decoration: const InputDecoration(labelText: 'Existing password'),
            onSubmitted: (_) => _signIn(link: true),
          ),
          const SizedBox(height: 12),
          VtsaAlertRegion(
            title: 'Could not connect your Google account',
            message: auth.failure?.code == 'google_link_required'
                ? null
                : auth.failure?.message,
            correlationId: auth.failure?.correlationId,
          ),
          VtsaButton(
            label: 'Connect Google account',
            loading: auth.isBusy,
            onPressed: () => _signIn(link: true),
          ),
          TextButton(
            onPressed: auth.isBusy
                ? null
                : () {
                    _password.clear();
                    auth.cancelGoogle();
                  },
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: auth.isBusy
                ? null
                : () {
                    auth.cancelGoogle();
                    context.push('/forgot-password');
                  },
            child: const Text('Forgot your password?'),
          ),
        ],
      );
    }
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          OutlinedButton(
            key: const ValueKey('continue-with-google'),
            style: OutlinedButton.styleFrom(
              minimumSize: const Size.fromHeight(48),
              backgroundColor: dark ? const Color(0xFF131314) : Colors.white,
              foregroundColor: dark
                  ? const Color(0xFFE3E3E3)
                  : const Color(0xFF1F1F1F),
              side: BorderSide(
                color: dark ? const Color(0xFF8E918F) : const Color(0xFF747775),
              ),
            ),
            onPressed: auth.isBusy ? null : _signIn,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Image.asset(
                  'assets/interface/google-g.png',
                  width: 20,
                  height: 20,
                  excludeFromSemantics: true,
                ),
                const SizedBox(width: 10),
                const Flexible(child: Text('Continue with Google')),
              ],
            ),
          ),
          if (_attempted && widget.onRegistration != null)
            VtsaAlertRegion(
              title: 'Could not continue with Google',
              message: auth.failure?.message,
              correlationId: auth.failure?.correlationId,
            ),
        ],
      ),
    );
  }
}
