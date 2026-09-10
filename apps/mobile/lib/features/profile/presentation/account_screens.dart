import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/platform/platform_services.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: dependencies.auth,
    builder: (context, _) {
      final user = dependencies.auth.user;
      return VtsaPageShell(
        eyebrow: 'Driver',
        title: 'Account',
        body: ListView(
          children: [
            if (dependencies.auth.status == AuthStatus.guest) ...[
              VtsaCard(
                eyebrow: 'GUEST MODE',
                title: 'Keep discovery personal',
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Sign in to keep vehicles and favorites separated for your account.',
                    ),
                    const SizedBox(height: VtsaSpacing.lg),
                    VtsaButton(
                      label: 'Sign in',
                      onPressed: () => context.push('/login'),
                    ),
                  ],
                ),
              ),
            ] else ...[
              VtsaCard(
                eyebrow: user?.emailVerified == true
                    ? 'VERIFIED DRIVER'
                    : 'VERIFICATION NEEDED',
                title: user?.name ?? 'Power Solutions driver',
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(user?.email ?? 'Profile temporarily unavailable'),
                    if (user?.mobileNumber != null) Text(user!.mobileNumber!),
                    const SizedBox(height: VtsaSpacing.md),
                    Wrap(
                      spacing: VtsaSpacing.sm,
                      children: [
                        VtsaButton(
                          label: 'View profile',
                          variant: VtsaButtonVariant.secondary,
                          onPressed: () => context.push('/profile'),
                        ),
                        if (user?.emailVerified == false)
                          VtsaButton(
                            label: 'Verify email',
                            variant: VtsaButtonVariant.quiet,
                            onPressed: () => context.push('/verify-email'),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: VtsaSpacing.md),
              _AccountTile(
                icon: Icons.directions_car_outlined,
                title: 'My vehicles',
                subtitle: 'Connector standards and compatibility',
                onTap: () => context.push('/vehicles'),
              ),
            ],
            const SizedBox(height: VtsaSpacing.md),
            VtsaCard(
              title: 'Privacy choices',
              child: _AnalyticsConsent(service: dependencies.analyticsConsent),
            ),
            const SizedBox(height: VtsaSpacing.md),
            if (dependencies.auth.status == AuthStatus.authenticated)
              VtsaButton(
                label: 'Sign out on this device',
                variant: VtsaButtonVariant.secondary,
                loading: dependencies.auth.isBusy,
                onPressed: () async {
                  final confirmed = await showVtsaConfirmationDialog(
                    context: context,
                    title: 'Sign out on this device?',
                    description:
                        'The current access token will be revoked. Local non-sensitive preferences remain on this device.',
                    confirmLabel: 'Sign out',
                  );
                  if (!confirmed) {
                    return;
                  }
                  await dependencies.pushRegistration.unregister();
                  await dependencies.charging.onSignedOut();
                  await dependencies.auth.logout();
                  await Future.wait([
                    dependencies.favorites.load(),
                    dependencies.vehicles.load(),
                  ]);
                  if (context.mounted) {
                    context.go('/explore');
                  }
                },
              ),
          ],
        ),
      );
    },
  );
}

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  Widget build(BuildContext context) {
    final user = dependencies.auth.user;
    return VtsaPageShell(
      eyebrow: 'IDENTITY',
      title: 'Profile',
      body: ListView(
        children: [
          _ReadOnlyField(label: 'Name', value: user?.name ?? 'Unavailable'),
          const SizedBox(height: VtsaSpacing.md),
          _ReadOnlyField(label: 'Email', value: user?.email ?? 'Unavailable'),
          const SizedBox(height: VtsaSpacing.md),
          _ReadOnlyField(
            label: 'Email status',
            value: user?.emailVerified == true ? 'Verified' : 'Not verified',
          ),
          if (user?.mobileNumber != null) ...[
            const SizedBox(height: VtsaSpacing.md),
            _ReadOnlyField(label: 'Mobile number', value: user!.mobileNumber!),
          ],
          const SizedBox(height: VtsaSpacing.lg),
          Text(
            'Profile editing will be enabled when the customer profile mutation contract is approved. This release never guesses or stores server-owned identity changes locally.',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: context.vtsaColors.textMuted,
            ),
          ),
        ],
      ),
    );
  }
}

class FoundationDestinationScreen extends StatelessWidget {
  const FoundationDestinationScreen({
    required this.title,
    required this.description,
    required this.icon,
    super.key,
  });

  final String title;
  final String description;
  final IconData icon;

  @override
  Widget build(BuildContext context) => VtsaPageShell(
    title: title,
    body: VtsaEmptyState(
      icon: icon,
      title: '$title is not part of this release',
      description: description,
    ),
  );
}

class _AnalyticsConsent extends StatefulWidget {
  const _AnalyticsConsent({required this.service});

  final AnalyticsConsentService service;

  @override
  State<_AnalyticsConsent> createState() => _AnalyticsConsentState();
}

class _AnalyticsConsentState extends State<_AnalyticsConsent> {
  bool? _consent;

  @override
  void initState() {
    super.initState();
    widget.service.readConsent().then((value) {
      if (mounted) {
        setState(() => _consent = value ?? false);
      }
    });
  }

  @override
  Widget build(BuildContext context) => SwitchListTile.adaptive(
    contentPadding: EdgeInsets.zero,
    title: const Text('Optional analytics'),
    subtitle: const Text(
      'Allow product-usage analytics. Crash reporting remains separately controlled by release policy.',
    ),
    value: _consent ?? false,
    onChanged: _consent == null
        ? null
        : (value) async {
            setState(() => _consent = value);
            await widget.service.setConsent(value);
          },
  );
}

class _AccountTile extends StatelessWidget {
  const _AccountTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => VtsaCard(
    padding: EdgeInsets.zero,
    child: ListTile(
      leading: Icon(icon),
      title: Text(title),
      subtitle: Text(subtitle),
      trailing: const Icon(Icons.chevron_right),
      onTap: onTap,
    ),
  );
}

class _ReadOnlyField extends StatelessWidget {
  const _ReadOnlyField({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => VtsaCard(
    eyebrow: label,
    child: SelectableText(value, style: Theme.of(context).textTheme.bodyLarge),
  );
}
