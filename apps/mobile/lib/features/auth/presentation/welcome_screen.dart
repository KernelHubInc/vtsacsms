import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/auth/presentation/google_access_button.dart';

class WelcomeScreen extends StatelessWidget {
  const WelcomeScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  void _openAuth(BuildContext context, String route) {
    dependencies.auth.cancelGoogle();
    context.push(route);
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: dependencies.auth,
    builder: (context, _) => EvcsWelcomeLayout(
      title: 'Power your next drive.',
      subtitle: 'Find a charger. Plug in. Get going.',
      actions: EvcsAccessCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            VtsaButton(
              label: 'Sign in',
              onPressed: dependencies.auth.isBusy
                  ? null
                  : () => _openAuth(context, '/login'),
            ),
            const SizedBox(height: 12),
            VtsaButton(
              label: 'Create account',
              variant: VtsaButtonVariant.secondary,
              onPressed: dependencies.auth.isBusy
                  ? null
                  : () => _openAuth(context, '/register'),
            ),
            GoogleAccessButton(
              dependencies: dependencies,
              onRegistration: () => context.push('/register'),
            ),
          ],
        ),
      ),
      footer: Padding(
        padding: const EdgeInsets.only(top: 24),
        child: EvcsFooterLink(
          label: 'Help center',
          onPressed: dependencies.auth.isBusy
              ? null
              : () => showEvcsAccessHelp(
                  context,
                  onUsePassword: () => _openAuth(context, '/forgot-password'),
                ),
        ),
      ),
    ),
  );
}
