import 'package:flutter/material.dart';

enum PowerSolutionsLogoVariant { horizontal, mark }

class PowerSolutionsLogo extends StatelessWidget {
  const PowerSolutionsLogo({
    this.variant = PowerSolutionsLogoVariant.horizontal,
    this.height = 44,
    this.inverse = false,
    super.key,
  });

  final PowerSolutionsLogoVariant variant;
  final double height;
  final bool inverse;

  @override
  Widget build(BuildContext context) {
    final useWhite = inverse || Theme.of(context).brightness == Brightness.dark;
    final asset = switch (variant) {
      PowerSolutionsLogoVariant.mark =>
        useWhite
            ? 'assets/branding/power-solutions-mark-white.png'
            : 'assets/branding/power-solutions-mark-transparent.png',
      PowerSolutionsLogoVariant.horizontal =>
        useWhite
            ? 'assets/branding/power-solutions-logo-horizontal-dark.png'
            : 'assets/branding/power-solutions-logo-horizontal.png',
    };

    return Semantics(
      label: 'Power Solutions',
      image: true,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(3),
        child: Image.asset(
          asset,
          height: height,
          fit: BoxFit.contain,
          excludeFromSemantics: true,
          errorBuilder: (context, error, stackTrace) => Text(
            'Power Solutions',
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
              color: useWhite
                  ? Colors.white
                  : Theme.of(context).colorScheme.primary,
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
      ),
    );
  }
}
