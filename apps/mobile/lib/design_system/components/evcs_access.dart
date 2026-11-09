import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

ThemeData evcsAccessTheme(ThemeData base) => base.copyWith(
  textTheme: base.textTheme.apply(fontFamily: 'Inter'),
  primaryTextTheme: base.primaryTextTheme.apply(fontFamily: 'Inter'),
  inputDecorationTheme: base.inputDecorationTheme.copyWith(
    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
  ),
  filledButtonTheme: FilledButtonThemeData(
    style: FilledButton.styleFrom(
      minimumSize: const Size(48, 52),
      textStyle: const TextStyle(
        fontFamily: 'Inter',
        fontSize: 16,
        fontWeight: FontWeight.w600,
      ),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
    ),
  ),
  outlinedButtonTheme: OutlinedButtonThemeData(
    style: OutlinedButton.styleFrom(
      minimumSize: const Size(48, 52),
      foregroundColor: base.colorScheme.primary,
      side: BorderSide(color: base.extension<VtsaSemanticColors>()!.border),
      textStyle: const TextStyle(
        fontFamily: 'Inter',
        fontSize: 16,
        fontWeight: FontWeight.w600,
      ),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
    ),
  ),
);

class EvcsIcon extends StatelessWidget {
  const EvcsIcon(this.asset, {this.size = 24, this.color, super.key});

  final String asset;
  final double size;
  final Color? color;

  @override
  Widget build(BuildContext context) => ExcludeSemantics(
    child: SizedBox.square(
      dimension: size,
      child: SvgPicture.asset(
        'assets/evcs/$asset.svg',
        colorFilter: color == null
            ? null
            : ColorFilter.mode(color!, BlendMode.srcIn),
      ),
    ),
  );
}

class EvcsLogo extends StatelessWidget {
  const EvcsLogo({this.width = 210, super.key});
  final double width;

  @override
  Widget build(BuildContext context) => Semantics(
    label: 'EVCS Power Solutions',
    image: true,
    child: Image.asset(
      'assets/evcs/access-rectangle.png',
      width: width,
      height: width * 71 / 210,
      fit: BoxFit.contain,
      excludeFromSemantics: true,
    ),
  );
}

/// Keeps artwork proportions on wide browsers and scrolls at large text sizes.
class EvcsWelcomeLayout extends StatelessWidget {
  const EvcsWelcomeLayout({
    required this.title,
    required this.subtitle,
    required this.actions,
    required this.footer,
    super.key,
  });

  final String title;
  final String subtitle;
  final Widget actions;
  final Widget footer;

  @override
  Widget build(BuildContext context) => Theme(
    data: evcsAccessTheme(VtsaTheme.light()),
    child: Scaffold(
      backgroundColor: PowerSolutionsColors.brandDarkNavy,
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 480),
          child: LayoutBuilder(
            builder: (context, constraints) {
              final padding = MediaQuery.paddingOf(context);
              final height = constraints.maxHeight;
              return Stack(
                children: [
                  Positioned.fill(
                    child: Image.asset(
                      'assets/evcs/access-access-ev-charging-scene.png',
                      fit: BoxFit.cover,
                      excludeFromSemantics: true,
                    ),
                  ),
                  Positioned.fill(
                    top: height * 450 / 852,
                    child: const DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          stops: [0, .53, 1],
                          colors: [
                            Color(0x000B2448),
                            Color(0xE00B2448),
                            Color(0xFA0B2448),
                          ],
                        ),
                      ),
                    ),
                  ),
                  SingleChildScrollView(
                    child: ConstrainedBox(
                      constraints: BoxConstraints(minHeight: height),
                      child: IntrinsicHeight(
                        child: Padding(
                          padding: EdgeInsets.fromLTRB(
                            20,
                            (height * 77 / 852).clamp(padding.top + 20, 100),
                            20,
                            padding.bottom + 26,
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              const Center(child: EvcsLogo()),
                              const SizedBox(height: 37),
                              Text(
                                title,
                                textAlign: TextAlign.center,
                                style: const TextStyle(
                                  fontSize: 28,
                                  height: 34 / 28,
                                  fontWeight: FontWeight.w600,
                                  color: PowerSolutionsColors.brandNavy,
                                ),
                              ),
                              const SizedBox(height: 9),
                              Text(
                                subtitle,
                                textAlign: TextAlign.center,
                                style: const TextStyle(
                                  fontSize: 14,
                                  height: 20 / 14,
                                  color: PowerSolutionsColors.brandNavy,
                                ),
                              ),
                              const Spacer(),
                              const SizedBox(height: 180),
                              actions,
                              const SizedBox(height: 18),
                              footer,
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    ),
  );
}

class EvcsAccessCard extends StatelessWidget {
  const EvcsAccessCard({required this.child, this.padding = 20, super.key});
  final Widget child;
  final double padding;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.white,
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(VtsaRadii.xl),
      side: const BorderSide(color: PowerSolutionsColors.borderDefault),
    ),
    clipBehavior: Clip.antiAlias,
    child: Padding(padding: EdgeInsets.all(padding), child: child),
  );
}

class EvcsFooterLink extends StatelessWidget {
  const EvcsFooterLink({
    required this.label,
    required this.onPressed,
    super.key,
  });
  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) => TextButton(
    onPressed: onPressed,
    style: TextButton.styleFrom(
      foregroundColor: Colors.white,
      disabledForegroundColor: Colors.white70,
      minimumSize: const Size(44, 44),
      textStyle: const TextStyle(
        fontFamily: 'Inter',
        fontSize: 14,
        fontWeight: FontWeight.w400,
      ),
    ),
    child: Text(label, textAlign: TextAlign.center),
  );
}

class EvcsAuthScaffold extends StatelessWidget {
  const EvcsAuthScaffold({
    required this.title,
    required this.subtitle,
    required this.child,
    this.actions,
    this.onBack,
    super.key,
  });

  final String title;
  final String subtitle;
  final Widget child;
  final Widget? actions;
  final VoidCallback? onBack;

  @override
  Widget build(BuildContext context) => Theme(
    data: evcsAccessTheme(Theme.of(context)),
    child: Builder(
      builder: (context) => Scaffold(
        backgroundColor: context.vtsaColors.canvas,
        body: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: LayoutBuilder(
                builder: (context, constraints) => SingleChildScrollView(
                  keyboardDismissBehavior:
                      ScrollViewKeyboardDismissBehavior.onDrag,
                  child: ConstrainedBox(
                    constraints: BoxConstraints(
                      minHeight: constraints.maxHeight,
                    ),
                    child: IntrinsicHeight(
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(20, 32, 20, 20),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                if (onBack != null) ...[
                                  IconButton.filledTonal(
                                    tooltip: 'Back',
                                    onPressed: onBack,
                                    style: IconButton.styleFrom(
                                      backgroundColor:
                                          context.vtsaColors.surfaceRaised,
                                      minimumSize: const Size(44, 44),
                                      shape: RoundedRectangleBorder(
                                        borderRadius: BorderRadius.circular(14),
                                      ),
                                    ),
                                    icon: EvcsIcon(
                                      'auth-icon-back',
                                      color: Theme.of(
                                        context,
                                      ).colorScheme.onSurface,
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                ],
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        title,
                                        style: const TextStyle(
                                          fontSize: 22,
                                          height: 28 / 22,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                      const SizedBox(height: 4),
                                      Text(
                                        subtitle,
                                        style: const TextStyle(
                                          fontSize: 14,
                                          height: 20 / 14,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 24),
                            child,
                            if (actions != null) ...[
                              const Spacer(),
                              const SizedBox(height: 32),
                              actions!,
                            ],
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

Future<void> showEvcsAccessHelp(
  BuildContext context, {
  VoidCallback? onUsePassword,
  VoidCallback? onUsePin,
}) => showModalBottomSheet<void>(
  context: context,
  showDragHandle: true,
  isScrollControlled: true,
  useSafeArea: true,
  builder: (sheetContext) => SingleChildScrollView(
    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          'Help with signing in',
          style: Theme.of(context).textTheme.headlineSmall,
        ),
        const SizedBox(height: 20),
        const Text(
          'Forgot your app PIN?',
          style: TextStyle(fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 6),
        const Text(
          'Sign in with your account password. You can then set up a new app PIN in Profile.',
        ),
        const SizedBox(height: 20),
        const Text(
          'Fingerprint or Face ID unavailable?',
          style: TextStyle(fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 6),
        const Text(
          'Use your app PIN, or check that biometrics are enrolled and enabled in your phone settings.',
        ),
        const SizedBox(height: 20),
        const Text(
          'Can’t connect?',
          style: TextStyle(fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 6),
        const Text(
          'Check your internet connection. Your saved session needs an online check before the app can open.',
        ),
        const SizedBox(height: 24),
        if (onUsePin != null)
          VtsaButton(
            label: 'Use app PIN',
            onPressed: () {
              Navigator.pop(sheetContext);
              onUsePin();
            },
          ),
        if (onUsePassword != null) ...[
          const SizedBox(height: 12),
          VtsaButton(
            label: 'Use your password',
            onPressed: () {
              Navigator.pop(sheetContext);
              onUsePassword();
            },
          ),
        ],
      ],
    ),
  ),
);
