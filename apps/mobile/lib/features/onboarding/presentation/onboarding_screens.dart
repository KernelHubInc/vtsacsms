import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';

class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
    body: Center(
      child: Semantics(
        label: 'Opening EVCS',
        child: CircularProgressIndicator(),
      ),
    ),
  );
}

class OnboardingScreen extends StatelessWidget {
  const OnboardingScreen({required this.dependencies, super.key});
  final AppDependencies dependencies;

  @override
  Widget build(BuildContext context) => EvcsAuthScaffold(
    title: 'Make EVCS your own.',
    subtitle: 'Optional setup. You can do this anytime in Profile.',
    onBack: () => context.canPop() ? context.pop() : context.go('/explore'),
    actions: VtsaButton(
      label: 'Explore chargers',
      onPressed: () async {
        await dependencies.bootstrap.finishOnboarding();
        if (context.mounted) context.go('/explore');
      },
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ListTile(
          leading: const Icon(Icons.electric_car_outlined),
          title: const Text('Add your vehicle'),
          subtitle: const Text('Find connectors that fit your car.'),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => context.push('/vehicles'),
        ),
        if (dependencies.auth.quickUnlock?.device.supported == true) ...[
          const SizedBox(height: 12),
          ListTile(
            leading: const Icon(Icons.fingerprint),
            title: const Text('Set up quick access'),
            subtitle: const Text('Choose an app PIN and optional biometrics.'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.push('/account/security'),
          ),
        ],
        const SizedBox(height: 12),
        ListTile(
          leading: const Icon(Icons.privacy_tip_outlined),
          title: const Text('Privacy choices'),
          subtitle: const Text('Manage your preferences in Profile.'),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => context.go('/account'),
        ),
      ],
    ),
  );
}
