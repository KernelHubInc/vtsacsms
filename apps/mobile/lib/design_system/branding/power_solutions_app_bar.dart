import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

class PowerSolutionsAppBar extends StatelessWidget
    implements PreferredSizeWidget {
  const PowerSolutionsAppBar({
    required this.title,
    this.eyebrow,
    this.actions = const [],
    this.automaticallyImplyLeading = true,
    super.key,
  });

  final String title;
  final String? eyebrow;
  final List<Widget> actions;
  final bool automaticallyImplyLeading;

  @override
  Size get preferredSize => const Size.fromHeight(96);

  @override
  Widget build(BuildContext context) => AppBar(
    toolbarHeight: preferredSize.height,
    automaticallyImplyLeading: automaticallyImplyLeading,
    backgroundColor: PowerSolutionsColors.brandNavy,
    foregroundColor: Colors.white,
    surfaceTintColor: Colors.transparent,
    iconTheme: const IconThemeData(color: Colors.white),
    actionsIconTheme: const IconThemeData(color: Colors.white),
    titleSpacing: VtsaSpacing.md,
    title: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        const PowerSolutionsLogo(height: 27, inverse: true),
        const SizedBox(height: 6),
        if (eyebrow != null) ...[
          Text(
            eyebrow!.toUpperCase(),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: PowerSolutionsColors.brandTurquoise,
              fontSize: 9,
              fontWeight: FontWeight.w800,
              letterSpacing: 1.1,
              height: 1.1,
            ),
          ),
          const SizedBox(height: 2),
        ],
        Text(
          title,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 15,
            fontWeight: FontWeight.w700,
            height: 1.15,
          ),
        ),
      ],
    ),
    flexibleSpace: const _PowerSolutionsHeaderDecoration(),
    actions: [
      for (final action in actions)
        Padding(
          padding: const EdgeInsets.only(left: VtsaSpacing.xs),
          child: IconButtonTheme(
            data: IconButtonThemeData(
              style: IconButton.styleFrom(
                foregroundColor: Colors.white,
                backgroundColor: Colors.white.withValues(alpha: 0.12),
                hoverColor: PowerSolutionsColors.brandTurquoise.withValues(
                  alpha: 0.2,
                ),
                focusColor: PowerSolutionsColors.brandTurquoise.withValues(
                  alpha: 0.22,
                ),
              ),
            ),
            child: action,
          ),
        ),
      if (actions.isNotEmpty) const SizedBox(width: VtsaSpacing.xs),
    ],
  );
}

class _PowerSolutionsHeaderDecoration extends StatelessWidget {
  const _PowerSolutionsHeaderDecoration();

  @override
  Widget build(BuildContext context) => IgnorePointer(
    child: Stack(
      fit: StackFit.expand,
      children: [
        const DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.centerLeft,
              end: Alignment.centerRight,
              colors: [
                PowerSolutionsColors.brandDarkNavy,
                PowerSolutionsColors.brandNavy,
                Color(0xFF164F78),
              ],
            ),
          ),
        ),
        Positioned(
          right: -26,
          top: -44,
          child: _HeaderShape(
            width: 138,
            height: 138,
            radius: 69,
            color: PowerSolutionsColors.brandTurquoise,
            opacity: 0.14,
          ),
        ),
        Positioned(
          right: 98,
          bottom: -18,
          child: _HeaderShape(
            width: 82,
            height: 42,
            radius: VtsaRadii.xl,
            color: PowerSolutionsColors.brandBlue,
            opacity: 0.24,
          ),
        ),
      ],
    ),
  );
}

class _HeaderShape extends StatelessWidget {
  const _HeaderShape({
    required this.width,
    required this.height,
    required this.radius,
    required this.color,
    required this.opacity,
  });

  final double width;
  final double height;
  final double radius;
  final Color color;
  final double opacity;

  @override
  Widget build(BuildContext context) => Container(
    width: width,
    height: height,
    decoration: BoxDecoration(
      borderRadius: BorderRadius.circular(radius),
      color: color.withValues(alpha: opacity),
    ),
  );
}
