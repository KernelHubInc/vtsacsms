import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/app/consumer_shell.dart';
import 'package:vtsa_mobile/design_system/components/driver_components.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/accounts/charging_account_screen.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/presentation/auth_screens.dart';
import 'package:vtsa_mobile/features/auth/presentation/quick_unlock_screens.dart';
import 'package:vtsa_mobile/features/charging/presentation/activity_screens.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_start_screen.dart';
import 'package:vtsa_mobile/features/charging/presentation/live_charging_screen.dart';
import 'package:vtsa_mobile/features/discovery/presentation/discovery_screen.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_detail_screen.dart';
import 'package:vtsa_mobile/features/kyc/presentation/kyc_screen.dart';
import 'package:vtsa_mobile/features/onboarding/presentation/onboarding_screens.dart';
import 'package:vtsa_mobile/features/profile/presentation/account_screens.dart';
import 'package:vtsa_mobile/features/vehicles/presentation/vehicle_screens.dart';
import 'package:vtsa_mobile/features/wallet/wallet_screen.dart';

GoRouter createAppRouter(AppDependencies dependencies) => GoRouter(
  initialLocation: '/splash',
  refreshListenable: Listenable.merge([
    dependencies.bootstrap,
    dependencies.auth,
    ?dependencies.accounts,
  ]),
  redirect: (context, state) {
    final ready = dependencies.bootstrap.isReady;
    final authChecked = dependencies.auth.status != AuthStatus.checking;
    if (!ready || !authChecked) {
      return state.matchedLocation == '/splash' ? null : '/splash';
    }
    final location = state.matchedLocation;
    if (dependencies.auth.status == AuthStatus.locked) {
      return location == '/unlock' ? null : '/unlock';
    }
    const authRoutes = {
      '/login',
      '/register',
      '/forgot-password',
      '/verify-email',
    };
    // New destinations are private unless explicitly part of signing in.
    if (dependencies.auth.status == AuthStatus.guest) {
      return authRoutes.contains(location) ? null : '/login';
    }
    if (dependencies.auth.needsEmailVerification) {
      return location == '/verify-email' ? null : '/verify-email';
    }
    if (!dependencies.bootstrap.onboardingComplete) {
      return state.matchedLocation == '/onboarding' ? null : '/onboarding';
    }
    if (state.matchedLocation == '/splash' ||
        state.matchedLocation == '/onboarding') {
      return '/explore';
    }
    if (authRoutes.contains(location) || location == '/unlock') {
      return '/account';
    }
    return null;
  },
  routes: [
    GoRoute(
      path: '/charging-accounts',
      builder: (context, state) =>
          ChargingAccountsScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/unlock',
      builder: (context, state) => UnlockScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/account/security',
      builder: (context, state) =>
          QuickUnlockSettingsScreen(dependencies: dependencies),
    ),
    GoRoute(path: '/splash', builder: (context, state) => const SplashScreen()),
    GoRoute(
      path: '/onboarding',
      builder: (context, state) => OnboardingScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/login',
      builder: (context, state) => LoginScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/register',
      builder: (context, state) =>
          RegistrationScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/forgot-password',
      builder: (context, state) =>
          ForgotPasswordScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/verify-email',
      builder: (context, state) => EmailVerificationScreen(
        dependencies: dependencies,
        email: state.uri.queryParameters['email'],
        deliveryFailed: state.uri.queryParameters['delivery'] == 'failed',
      ),
    ),
    ShellRoute(
      builder: (context, state, child) => ConsumerShell(
        dependencies: dependencies,
        location: state.uri.path,
        child: child,
      ),
      routes: [
        GoRoute(
          path: '/explore',
          pageBuilder: (context, state) => NoTransitionPage(
            child: DiscoveryScreen(dependencies: dependencies),
          ),
        ),
        GoRoute(
          path: '/activity',
          pageBuilder: (context, state) => NoTransitionPage(
            child: ActivityScreen(
              key: ValueKey(dependencies.accounts?.accountId),
              dependencies: dependencies,
            ),
          ),
        ),
        GoRoute(
          path: '/wallet',
          pageBuilder: (context, state) => NoTransitionPage(
            child:
                dependencies.accounts?.shared == true &&
                    dependencies.accounts?.selected?.can('wallet_view') != true
                ? DriverTabPage(
                    title: 'Account wallet',
                    subtitle:
                        '${dependencies.accounts!.selected!.name} · ${dependencies.accounts!.selected!.roleLabel}',
                    body: ListView(
                      children: [
                        ChargingAccountSelector(dependencies: dependencies),
                        const SizedBox(height: 16),
                        Card(
                          child: Padding(
                            padding: const EdgeInsets.all(20),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Icon(Icons.lock_outline, size: 28),
                                const SizedBox(height: 12),
                                Text(
                                  'Shared wallet access is restricted',
                                  style: Theme.of(
                                    context,
                                  ).textTheme.titleMedium,
                                ),
                                const SizedBox(height: 8),
                                Text(
                                  'Your ${dependencies.accounts!.selected!.roleLabel.toLowerCase()} role can charge with assigned vehicles, but cannot view the shared balance or payment history. Ask the account owner to grant billing access, or switch to an account whose wallet you can view.',
                                ),
                                const SizedBox(height: 16),
                                VtsaButton(
                                  label: 'Choose another account',
                                  icon: Icons.swap_horiz,
                                  variant: VtsaButtonVariant.secondary,
                                  onPressed: () =>
                                      context.push('/charging-accounts'),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                  )
                : WalletScreen(
                    key: ValueKey(dependencies.accounts?.accountId),
                    accountName: dependencies.accounts?.selected?.name,
                    accountSelector: ChargingAccountSelector(
                      dependencies: dependencies,
                    ),
                    repository: dependencies.walletRepository,
                    allowRealPayments: dependencies.featureFlags.realPayments,
                  ),
          ),
        ),
        GoRoute(
          path: '/account',
          pageBuilder: (context, state) => NoTransitionPage(
            child: AccountScreen(dependencies: dependencies),
          ),
        ),
      ],
    ),
    GoRoute(
      path: '/charge',
      builder: (context, state) => Theme(
        data: DriverTheme.of(context),
        child: ChargingStartScreen(
          key: ValueKey(dependencies.accounts?.accountId),
          dependencies: dependencies,
          initialCode: state.uri.queryParameters['code'],
        ),
      ),
    ),
    GoRoute(
      path: '/charging/:sessionId',
      builder: (context, state) => Theme(
        data: DriverTheme.of(context),
        child: LiveChargingScreen(
          dependencies: dependencies,
          sessionId: state.pathParameters['sessionId']!,
        ),
      ),
    ),
    GoRoute(
      path: '/activity/:sessionId/refund',
      builder: (context, state) => RefundRequestScreen(
        dependencies: dependencies,
        sessionId: state.pathParameters['sessionId']!,
      ),
    ),
    GoRoute(
      path: '/activity/:sessionId/issue',
      builder: (context, state) => IssueReportScreen(
        dependencies: dependencies,
        sessionId: state.pathParameters['sessionId']!,
      ),
    ),
    GoRoute(
      path: '/activity/:sessionId',
      builder: (context, state) => SessionDetailScreen(
        dependencies: dependencies,
        sessionId: state.pathParameters['sessionId']!,
      ),
    ),
    GoRoute(
      path: '/favorites',
      builder: (context, state) => FavoritesScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/stations/:stationId',
      builder: (context, state) => Theme(
        data: DriverTheme.of(context),
        child: StationDetailScreen(
          dependencies: dependencies,
          stationId: state.pathParameters['stationId']!,
        ),
      ),
    ),
    GoRoute(
      path: '/profile',
      builder: (context, state) => ProfileScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/kyc',
      builder: (context, state) => KycScreen(
        repository: dependencies.kycRepository,
        name: dependencies.auth.user?.name ?? '',
        profile: dependencies.auth.user,
      ),
    ),
    GoRoute(
      path: '/vehicles',
      builder: (context, state) =>
          VehicleListScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/vehicles/new',
      builder: (context, state) =>
          VehicleFormScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/vehicles/:vehicleId/edit',
      builder: (context, state) => VehicleFormScreen(
        dependencies: dependencies,
        vehicleId: state.pathParameters['vehicleId'],
      ),
    ),
  ],
);
