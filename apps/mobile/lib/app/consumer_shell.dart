import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/evcs_navigation.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/charging/domain/charging_models.dart';
import 'package:vtsa_mobile/features/notices/driver_notice_popup.dart';
import 'package:vtsa_mobile/l10n/app_localizations.dart';

class ConsumerShell extends StatelessWidget {
  const ConsumerShell({
    required this.dependencies,
    required this.location,
    required this.child,
    super.key,
  });

  final AppDependencies dependencies;
  final String location;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final strings = AppLocalizations.of(context);
    final destinations = <NavigationDestination>[
      NavigationDestination(
        icon: const Icon(Icons.explore_outlined),
        selectedIcon: const Icon(Icons.explore),
        label: strings.explore,
      ),
      NavigationDestination(
        icon: const Icon(Icons.receipt_long_outlined),
        selectedIcon: const Icon(Icons.receipt_long),
        label: 'Sessions',
      ),
      const NavigationDestination(
        icon: Icon(Icons.qr_code_scanner),
        label: 'Scan',
      ),
      NavigationDestination(
        icon: const Icon(Icons.account_balance_wallet_outlined),
        selectedIcon: const Icon(Icons.account_balance_wallet),
        label: strings.wallet,
      ),
      NavigationDestination(
        icon: const Icon(Icons.person_outline),
        selectedIcon: const Icon(Icons.person),
        label: 'Profile',
      ),
    ];
    final index = switch (location) {
      final String value when value.startsWith('/activity') => 1,
      final String value when value.startsWith('/wallet') => 3,
      final String value when value.startsWith('/account') => 4,
      _ => 0,
    };
    return AnimatedBuilder(
      animation: Listenable.merge([
        dependencies.charging,
        if (dependencies.notices != null) dependencies.notices!,
      ]),
      builder: (context, _) {
        final charging = dependencies.charging;
        final openingNotice = dependencies.notices?.openingBanner;
        final showActive =
            charging.session != null &&
            charging.phase != ChargingFlowPhase.idle &&
            charging.phase != ChargingFlowPhase.completed &&
            charging.phase != ChargingFlowPhase.failed;
        return Theme(
          data: DriverTheme.of(context),
          child: VtsaBottomNavigationShell(
            destinations: destinations,
            selectedIndex: index,
            onDestinationSelected: (selected) => _navigate(context, selected),
            navigationBar: EvcsNavigationBar(
              selectedIndex: index,
              onSelected: (selected) => _navigate(context, selected),
            ),
            activeSession: showActive
                ? _ActiveSessionBanner(dependencies: dependencies)
                : null,
            child: Column(
              children: [
                if (openingNotice != null)
                  DriverNoticePopup(
                    notice: openingNotice,
                    onDismiss: () =>
                        dependencies.notices?.dismissBanner(openingNotice.id),
                  ),
                Expanded(child: child),
              ],
            ),
          ),
        );
      },
    );
  }

  void _navigate(BuildContext context, int selected) {
    if (selected == 2) {
      context.push('/charge');
    } else {
      context.go(
        const [
          '/explore',
          '/activity',
          '/charge',
          '/wallet',
          '/account',
        ][selected],
      );
    }
  }
}

class _ActiveSessionBanner extends StatelessWidget {
  const _ActiveSessionBanner({required this.dependencies});

  final AppDependencies dependencies;

  @override
  Widget build(BuildContext context) {
    final charging = dependencies.charging;
    final session = charging.session;
    if (session == null) {
      return const SizedBox.shrink();
    }
    return InkWell(
      onTap: () => context.push('/charging/${session.id}'),
      child: Row(
        children: [
          Icon(
            charging.phase == ChargingFlowPhase.live ? Icons.bolt : Icons.sync,
            color: Theme.of(context).colorScheme.primary,
          ),
          const SizedBox(width: VtsaSpacing.sm),
          Expanded(
            child: Text(
              charging.phase == ChargingFlowPhase.live
                  ? '${(session.energyWh / 1000).toStringAsFixed(2)} kWh · Charging'
                  : _phaseLabel(charging.phase),
              style: Theme.of(context).textTheme.labelLarge,
            ),
          ),
          const Icon(Icons.chevron_right),
        ],
      ),
    );
  }

  String _phaseLabel(ChargingFlowPhase phase) => switch (phase) {
    ChargingFlowPhase.startPending => 'Waiting for charger to start',
    ChargingFlowPhase.startTimedOut => 'Start needs attention',
    ChargingFlowPhase.stopPending => 'Stopping session',
    ChargingFlowPhase.finalizing => 'Finalizing session',
    ChargingFlowPhase.paymentProcessing => 'Payment processing',
    _ => 'Charging session in progress',
  };
}
