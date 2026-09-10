import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_backdrop.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
    body: PowerSolutionsBackdrop(
      child: SafeArea(
        child: Center(
          child: Semantics(
            label: 'Power Solutions is starting',
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const PowerSolutionsLogo(height: 78),
                const SizedBox(height: VtsaSpacing.xl),
                ClipRRect(
                  borderRadius: BorderRadius.circular(VtsaRadii.pill),
                  child: const SizedBox(
                    width: 180,
                    child: LinearProgressIndicator(minHeight: 6),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    ),
  );
}

class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({required this.dependencies, super.key});

  final AppDependencies dependencies;

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final _controller = PageController();
  int _page = 0;

  static const _pages = [
    (
      icon: Icons.electric_car_outlined,
      eyebrow: 'DISCOVER',
      title: 'A charger that fits your drive.',
      body:
          'Search a live, bounded area. Compare connector standards, power, hours, and availability without downloading the whole network.',
    ),
    (
      icon: Icons.radar_outlined,
      eyebrow: 'TRUST THE SIGNAL',
      title: 'Freshness is part of the status.',
      body:
          'Power Solutions distinguishes available, busy, offline, and stale data so an old signal is never presented as live availability.',
    ),
    (
      icon: Icons.route_outlined,
      eyebrow: 'YOUR ROUTE',
      title: 'Hand off. Keep moving.',
      body:
          'Open directions in an installed navigation app, save useful stations, and keep vehicle compatibility close at hand.',
    ),
  ];

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: PowerSolutionsBackdrop(
      intensity: 0.45,
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(VtsaSpacing.lg),
          child: Column(
            children: [
              Row(
                children: [
                  const PowerSolutionsLogo(height: 32),
                  const Spacer(),
                  TextButton(onPressed: _finish, child: const Text('Skip')),
                ],
              ),
              Expanded(
                child: PageView.builder(
                  controller: _controller,
                  itemCount: _pages.length,
                  onPageChanged: (page) => setState(() => _page = page),
                  itemBuilder: (context, index) {
                    final item = _pages[index];
                    return Semantics(
                      liveRegion: true,
                      child: Align(
                        alignment: Alignment.centerLeft,
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 560),
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              PowerSolutionsFeatureArt(icon: item.icon),
                              const SizedBox(height: VtsaSpacing.xl),
                              Text(
                                item.eyebrow,
                                style: Theme.of(context).textTheme.labelMedium
                                    ?.copyWith(
                                      color: Theme.of(
                                        context,
                                      ).colorScheme.primary,
                                      letterSpacing: 1.5,
                                      fontWeight: FontWeight.w800,
                                    ),
                              ),
                              const SizedBox(height: VtsaSpacing.sm),
                              Text(
                                item.title,
                                style: Theme.of(context).textTheme.displayLarge,
                              ),
                              const SizedBox(height: VtsaSpacing.lg),
                              Text(
                                item.body,
                                style: Theme.of(context).textTheme.bodyLarge
                                    ?.copyWith(
                                      color: context.vtsaColors.textMuted,
                                    ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
              Row(
                children: [
                  for (var index = 0; index < _pages.length; index++)
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 180),
                      width: index == _page ? 28 : 8,
                      height: 8,
                      margin: const EdgeInsets.only(right: VtsaSpacing.xs),
                      decoration: BoxDecoration(
                        color: index == _page
                            ? Theme.of(context).colorScheme.primary
                            : context.vtsaColors.borderStrong,
                        borderRadius: BorderRadius.circular(VtsaRadii.pill),
                      ),
                    ),
                  const Spacer(),
                  VtsaButton(
                    label: _page == _pages.length - 1
                        ? 'Explore chargers'
                        : 'Next',
                    icon: Icons.arrow_forward,
                    onPressed: () {
                      if (_page == _pages.length - 1) {
                        _finish();
                      } else {
                        _controller.nextPage(
                          duration: const Duration(milliseconds: 240),
                          curve: Curves.easeOutCubic,
                        );
                      }
                    },
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    ),
  );

  Future<void> _finish() async {
    await widget.dependencies.bootstrap.finishOnboarding();
    if (mounted) {
      context.go('/explore');
    }
  }
}
