import 'dart:async';

import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:google_sign_in_web/web_only.dart' as google_web;
import 'package:vtsa_mobile/features/auth/data/browser_google_identity.dart';
import 'package:vtsa_mobile/features/auth/domain/google_auth.dart';

Widget browserGoogleButton({
  required GoogleIdentityProvider provider,
  required bool busy,
  required ValueChanged<String> onCredential,
}) => _BrowserGoogleButton(
  provider: provider as BrowserGoogleIdentity,
  busy: busy,
  onCredential: onCredential,
);

class _BrowserGoogleButton extends StatefulWidget {
  const _BrowserGoogleButton({
    required this.provider,
    required this.busy,
    required this.onCredential,
  });

  final BrowserGoogleIdentity provider;
  final bool busy;
  final ValueChanged<String> onCredential;

  @override
  State<_BrowserGoogleButton> createState() => _BrowserGoogleButtonState();
}

class _BrowserGoogleButtonState extends State<_BrowserGoogleButton> {
  StreamSubscription<GoogleSignInAuthenticationEvent>? _subscription;
  bool _ready = false;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _subscription = GoogleSignIn.instance.authenticationEvents.listen(
      (event) {
        // Covered routes can remain mounted in the router's navigation stack.
        if (!mounted ||
            widget.busy ||
            ModalRoute.of(context)?.isCurrent != true) {
          return;
        }
        if (event is GoogleSignInAuthenticationEventSignIn) {
          final token = event.user.authentication.idToken;
          if (token == null || token.isEmpty) {
            setState(() => _failed = true);
          } else {
            widget.onCredential(token);
          }
        }
      },
      onError: (Object error) {
        if (!mounted || ModalRoute.of(context)?.isCurrent != true) return;
        if (error is GoogleSignInException &&
            error.code == GoogleSignInExceptionCode.canceled) {
          return;
        }
        setState(() => _failed = true);
      },
    );
    unawaited(_initialize());
  }

  Future<void> _initialize() async {
    setState(() => _failed = false);
    try {
      await widget.provider.initialize();
      if (mounted) setState(() => _ready = true);
    } on Object {
      if (mounted) setState(() => _failed = true);
    }
  }

  @override
  void dispose() {
    unawaited(_subscription?.cancel());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) {
      return Column(
        children: [
          const Text(
            'Google sign-in could not load. Retry or sign in with your email.',
          ),
          TextButton(
            onPressed: widget.busy ? null : _initialize,
            child: const Text('Retry Google sign-in'),
          ),
        ],
      );
    }
    if (!_ready) {
      return const SizedBox(
        height: 48,
        child: Center(
          child: SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
      );
    }
    return IgnorePointer(
      ignoring: widget.busy,
      child: LayoutBuilder(
        builder: (context, constraints) => Center(
          child: google_web.renderButton(
            configuration: google_web.GSIButtonConfiguration(
              size: google_web.GSIButtonSize.large,
              text: google_web.GSIButtonText.continueWith,
              minimumWidth: constraints.maxWidth.clamp(120, 400).toDouble(),
            ),
          ),
        ),
      ),
    );
  }
}
