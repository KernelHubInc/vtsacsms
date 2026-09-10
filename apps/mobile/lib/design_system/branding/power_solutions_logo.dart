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
    final asset = switch (variant) {
      PowerSolutionsLogoVariant.mark =>
        'assets/branding/power-solutions-mark.png',
      PowerSolutionsLogoVariant.horizontal when inverse =>
        'assets/branding/power-solutions-logo-horizontal-dark.png',
      PowerSolutionsLogoVariant.horizontal =>
        'assets/branding/power-solutions-logo-horizontal.png',
    };

    return Semantics(
      label: 'Power Solutions',
      image: true,
      child: Image.asset(
        asset,
        height: height,
        fit: BoxFit.contain,
        excludeFromSemantics: true,
        errorBuilder: (context, error, stackTrace) => Text(
          'Power Solutions',
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
            color: inverse
                ? Colors.white
                : Theme.of(context).colorScheme.primary,
            fontWeight: FontWeight.w800,
          ),
        ),
      ),
    );
  }
}
