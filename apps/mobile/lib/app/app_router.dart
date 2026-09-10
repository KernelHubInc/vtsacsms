import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/app/consumer_shell.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/presentation/auth_screens.dart';
import 'package:vtsa_mobile/features/charging/presentation/activity_screens.dart';
import 'package:vtsa_mobile/features/charging/presentation/charging_start_screen.dart';
import 'package:vtsa_mobile/features/charging/presentation/live_charging_screen.dart';
import 'package:vtsa_mobile/features/discovery/presentation/discovery_screen.dart';
import 'package:vtsa_mobile/features/discovery/presentation/station_detail_screen.dart';
import 'package:vtsa_mobile/features/onboarding/presentation/onboarding_screens.dart';
import 'package:vtsa_mobile/features/profile/presentation/account_screens.dart';
import 'package:vtsa_mobile/features/vehicles/presentation/vehicle_screens.dart';

GoRouter createAppRouter(AppDependencies dependencies) => GoRouter(
  initialLocation: '/splash',
  refreshListenable: Listenable.merge([
    dependencies.bootstrap,
    dependencies.auth,
  ]),
  redirect: (context, state) {
    final ready = dependencies.bootstrap.isReady;
    final authChecked = dependencies.auth.status != AuthStatus.checking;
    if (!ready || !authChecked) {
      return state.matchedLocation == '/splash' ? null : '/splash';
    }
    if (!dependencies.bootstrap.onboardingComplete) {
      return state.matchedLocation == '/onboarding' ? null : '/onboarding';
    }
    if (state.matchedLocation == '/splash' ||
        state.matchedLocation == '/onboarding') {
      return '/explore';
    }
    final protected =
        state.matchedLocation == '/profile' ||
        state.matchedLocation.startsWith('/vehicles') ||
        state.matchedLocation == '/charge' ||
        state.matchedLocation.startsWith('/charging/') ||
        (state.matchedLocation.startsWith('/activity/') &&
            state.matchedLocation != '/activity');
    if (protected && dependencies.auth.status == AuthStatus.guest) {
      return '/login';
    }
    if (state.matchedLocation == '/login' &&
        dependencies.auth.status == AuthStatus.authenticated) {
      return '/account';
    }
    return null;
  },
  routes: [
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
            child: ActivityScreen(dependencies: dependencies),
          ),
        ),
        GoRoute(
          path: '/wallet',
          pageBuilder: (context, state) => const NoTransitionPage(
            child: FoundationDestinationScreen(
              title: 'Wallet',
              icon: Icons.account_balance_wallet_outlined,
              description:
                  'Payment methods and billing documents remain outside this mobile phase.',
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
      builder: (context, state) =>
          ChargingStartScreen(dependencies: dependencies),
    ),
    GoRoute(
      path: '/charging/:sessionId',
      builder: (context, state) => LiveChargingScreen(
        dependencies: dependencies,
        sessionId: state.pathParameters['sessionId']!,
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
      builder: (context, state) => StationDetailScreen(
        dependencies: dependencies,
        stationId: state.pathParameters['stationId']!,
      ),
    ),
    GoRoute(
      path: '/profile',
      builder: (context, state) => ProfileScreen(dependencies: dependencies),
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
