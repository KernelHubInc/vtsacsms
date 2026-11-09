import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';

class WelcomeScreen extends StatelessWidget {
  const WelcomeScreen({super.key});

  @override
  Widget build(BuildContext context) => EvcsWelcomeLayout(
    title: 'Power your next drive.',
    subtitle: 'Find a charger. Plug in. Get going.',
    actions: EvcsAccessCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          VtsaButton(label: 'Sign in', onPressed: () => context.push('/login')),
          const SizedBox(height: 12),
          VtsaButton(
            label: 'Create account',
            variant: VtsaButtonVariant.secondary,
            onPressed: () => context.push('/register'),
          ),
        ],
      ),
    ),
    footer: Padding(
      padding: const EdgeInsets.only(top: 24),
      child: EvcsFooterLink(
        label: 'Help center',
        onPressed: () => showEvcsAccessHelp(
          context,
          onUsePassword: () => context.push('/forgot-password'),
        ),
      ),
    ),
  );
}
