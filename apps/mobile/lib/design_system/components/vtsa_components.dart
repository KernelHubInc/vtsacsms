import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_app_bar.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

enum VtsaButtonVariant { primary, secondary, quiet, danger }

class VtsaButton extends StatelessWidget {
  const VtsaButton({
    required this.label,
    required this.onPressed,
    this.icon,
    this.variant = VtsaButtonVariant.primary,
    this.loading = false,
    super.key,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final VtsaButtonVariant variant;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    final colors = context.vtsaColors;
    final callback = loading ? null : onPressed;
    final content = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (loading) ...[
          const SizedBox.square(
            dimension: 16,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
          const SizedBox(width: VtsaSpacing.xs),
        ] else if (icon != null) ...[
          Icon(icon, size: 18),
          const SizedBox(width: VtsaSpacing.xs),
        ],
        Text(loading ? '$label…' : label),
      ],
    );

    return Semantics(
      button: true,
      label: label,
      value: loading ? 'In progress' : null,
      child: switch (variant) {
        VtsaButtonVariant.primary => FilledButton(
          onPressed: callback,
          child: content,
        ),
        VtsaButtonVariant.secondary => OutlinedButton(
          onPressed: callback,
          child: content,
        ),
        VtsaButtonVariant.quiet => TextButton(
          onPressed: callback,
          child: content,
        ),
        VtsaButtonVariant.danger => FilledButton(
          onPressed: callback,
          style: FilledButton.styleFrom(
            backgroundColor: colors.danger,
            foregroundColor: Theme.of(context).brightness == Brightness.light
                ? Colors.white
                : const Color(0xFF2C0810),
          ),
          child: content,
        ),
      },
    );
  }
}

class VtsaTextField extends StatelessWidget {
  const VtsaTextField({
    required this.label,
    this.controller,
    this.hint,
    this.error,
    this.required = false,
    this.enabled = true,
    this.keyboardType,
    this.obscureText = false,
    this.textInputAction,
    this.autofillHints,
    this.onSubmitted,
    this.onChanged,
    super.key,
  });

  final String label;
  final TextEditingController? controller;
  final String? hint;
  final String? error;
  final bool required;
  final bool enabled;
  final TextInputType? keyboardType;
  final bool obscureText;
  final TextInputAction? textInputAction;
  final Iterable<String>? autofillHints;
  final ValueChanged<String>? onSubmitted;
  final ValueChanged<String>? onChanged;

  @override
  Widget build(BuildContext context) => TextField(
    controller: controller,
    enabled: enabled,
    keyboardType: keyboardType,
    obscureText: obscureText,
    textInputAction: textInputAction,
    autofillHints: autofillHints,
    onSubmitted: onSubmitted,
    onChanged: onChanged,
    decoration: InputDecoration(
      labelText: required ? '$label (required)' : label,
      helperText: hint,
      errorText: error,
    ),
  );
}

class VtsaCard extends StatelessWidget {
  const VtsaCard({
    required this.child,
    this.title,
    this.eyebrow,
    this.footer,
    this.selected = false,
    this.padding = const EdgeInsets.all(VtsaSpacing.lg),
    super.key,
  });

  final Widget child;
  final String? title;
  final String? eyebrow;
  final Widget? footer;
  final bool selected;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = context.vtsaColors;

    return Card(
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(VtsaRadii.lg),
        side: BorderSide(
          color: selected ? theme.colorScheme.primary : colors.border,
          width: selected ? 2 : 1,
        ),
      ),
      child: Padding(
        padding: padding,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            if (eyebrow != null) ...[
              Text(
                eyebrow!.toUpperCase(),
                style: theme.textTheme.labelSmall?.copyWith(
                  color: theme.colorScheme.primary,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 1.2,
                ),
              ),
              const SizedBox(height: VtsaSpacing.xs),
            ],
            if (title != null) ...[
              Text(title!, style: theme.textTheme.titleMedium),
              const SizedBox(height: VtsaSpacing.sm),
            ],
            child,
            if (footer != null) ...[
              const SizedBox(height: VtsaSpacing.lg),
              Divider(color: colors.border),
              const SizedBox(height: VtsaSpacing.sm),
              footer!,
            ],
          ],
        ),
      ),
    );
  }
}

class VtsaStatusChip extends StatelessWidget {
  const VtsaStatusChip({required this.label, required this.state, super.key});

  final String label;
  final VtsaOperationalState state;

  @override
  Widget build(BuildContext context) {
    final color = context.vtsaColors.forState(state);

    return Semantics(
      label: 'Status: $label',
      child: Container(
        padding: const EdgeInsets.symmetric(
          horizontal: VtsaSpacing.sm,
          vertical: VtsaSpacing.xs,
        ),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.12),
          border: Border.all(color: color.withValues(alpha: 0.38)),
          borderRadius: BorderRadius.circular(VtsaRadii.pill),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            _StatusMark(state: state, color: color),
            const SizedBox(width: VtsaSpacing.xs),
            Text(
              label,
              style: Theme.of(context).textTheme.labelMedium?.copyWith(
                color: color,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StatusMark extends StatelessWidget {
  const _StatusMark({required this.state, required this.color});

  final VtsaOperationalState state;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final isDiamond =
        state == VtsaOperationalState.faulted ||
        state == VtsaOperationalState.suspended;

    return Transform.rotate(
      angle: isDiamond ? 0.785398 : 0,
      child: Container(
        width: 8,
        height: 8,
        decoration: BoxDecoration(
          color: state == VtsaOperationalState.offline
              ? Colors.transparent
              : color,
          border: Border.all(color: color, width: 1.5),
          borderRadius: BorderRadius.circular(isDiamond ? 1 : 99),
        ),
      ),
    );
  }
}

class VtsaSkeleton extends StatefulWidget {
  const VtsaSkeleton({this.lines = 3, super.key});

  final int lines;

  @override
  State<VtsaSkeleton> createState() => _VtsaSkeletonState();
}

class _VtsaSkeletonState extends State<VtsaSkeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      duration: const Duration(milliseconds: 1400),
      vsync: this,
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final reduceMotion = MediaQuery.disableAnimationsOf(context);
    final baseColor = context.vtsaColors.surfaceSubtle;
    final highlight = context.vtsaColors.border;

    Widget line(int index, double value) => Container(
      height: index == 0 ? 16 : 12,
      width: index == widget.lines - 1 ? 180 : double.infinity,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(VtsaRadii.sm),
        gradient: LinearGradient(
          begin: Alignment(-1.5 + (value * 3), 0),
          end: Alignment(-0.5 + (value * 3), 0),
          colors: [baseColor, highlight, baseColor],
        ),
      ),
    );

    return Semantics(
      container: true,
      excludeSemantics: true,
      label: 'Loading content',
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, child) => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (var index = 0; index < widget.lines; index++) ...[
              line(index, reduceMotion ? 0.5 : _controller.value),
              if (index < widget.lines - 1)
                const SizedBox(height: VtsaSpacing.sm),
            ],
          ],
        ),
      ),
    );
  }
}

class VtsaEmptyState extends StatelessWidget {
  const VtsaEmptyState({
    required this.title,
    required this.description,
    this.action,
    this.icon = Icons.inbox_outlined,
    super.key,
  });

  final String title;
  final String description;
  final Widget? action;
  final IconData icon;

  @override
  Widget build(BuildContext context) => _VtsaStatePanel(
    icon: icon,
    iconColor: context.vtsaColors.information,
    title: title,
    description: description,
    action: action,
  );
}

class VtsaErrorState extends StatelessWidget {
  const VtsaErrorState({
    required this.title,
    required this.description,
    this.correlationId,
    this.action,
    super.key,
  });

  final String title;
  final String description;
  final String? correlationId;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Semantics(
    liveRegion: true,
    child: _VtsaStatePanel(
      icon: Icons.error_outline,
      iconColor: context.vtsaColors.danger,
      title: title,
      description: description,
      detail: correlationId == null ? null : 'Reference $correlationId',
      action: action,
    ),
  );
}

class _VtsaStatePanel extends StatelessWidget {
  const _VtsaStatePanel({
    required this.icon,
    required this.iconColor,
    required this.title,
    required this.description,
    this.detail,
    this.action,
  });

  final IconData icon;
  final Color iconColor;
  final String title;
  final String description;
  final String? detail;
  final Widget? action;

  @override
  Widget build(BuildContext context) => VtsaCard(
    child: Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, color: iconColor, size: 30),
            const SizedBox(height: VtsaSpacing.md),
            Text(
              title,
              style: Theme.of(context).textTheme.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: VtsaSpacing.xs),
            Text(
              description,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                color: context.vtsaColors.textMuted,
              ),
              textAlign: TextAlign.center,
            ),
            if (detail != null) ...[
              const SizedBox(height: VtsaSpacing.sm),
              SelectableText(
                detail!,
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ],
            if (action != null) ...[
              const SizedBox(height: VtsaSpacing.lg),
              action!,
            ],
          ],
        ),
      ),
    ),
  );
}

Future<bool> showVtsaConfirmationDialog({
  required BuildContext context,
  required String title,
  required String description,
  required String confirmLabel,
  bool dangerous = false,
}) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Text(description),
      actions: [
        VtsaButton(
          label: 'Cancel',
          variant: VtsaButtonVariant.quiet,
          onPressed: () => Navigator.of(context).pop(false),
        ),
        VtsaButton(
          label: confirmLabel,
          variant: dangerous
              ? VtsaButtonVariant.danger
              : VtsaButtonVariant.primary,
          onPressed: () => Navigator.of(context).pop(true),
        ),
      ],
    ),
  );

  return result ?? false;
}

void showVtsaToast(
  BuildContext context, {
  required String message,
  String? actionLabel,
  VoidCallback? onAction,
}) {
  ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(
      content: Text(message),
      action: actionLabel == null
          ? null
          : SnackBarAction(label: actionLabel, onPressed: onAction ?? () {}),
    ),
  );
}

class VtsaPageShell extends StatelessWidget {
  const VtsaPageShell({
    required this.title,
    required this.body,
    this.eyebrow,
    this.actions = const [],
    this.bottomNavigationBar,
    super.key,
  });

  final String title;
  final String? eyebrow;
  final Widget body;
  final List<Widget> actions;
  final Widget? bottomNavigationBar;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: PowerSolutionsAppBar(
      title: title,
      eyebrow: eyebrow,
      actions: actions,
    ),
    body: SafeArea(
      child: Align(
        alignment: Alignment.topCenter,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 1240),
          child: Padding(
            padding: const EdgeInsets.all(VtsaSpacing.lg),
            child: body,
          ),
        ),
      ),
    ),
    bottomNavigationBar: bottomNavigationBar,
  );
}

class VtsaResponsiveNavigation extends StatelessWidget {
  const VtsaResponsiveNavigation({
    required this.destinations,
    required this.selectedIndex,
    required this.onDestinationSelected,
    required this.child,
    this.title = 'Power Solutions',
    super.key,
  });

  final List<NavigationDestination> destinations;
  final int selectedIndex;
  final ValueChanged<int> onDestinationSelected;
  final Widget child;
  final String title;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      if (constraints.maxWidth >= 960) {
        return Scaffold(
          body: SafeArea(
            child: Row(
              children: [
                NavigationRail(
                  selectedIndex: selectedIndex,
                  onDestinationSelected: onDestinationSelected,
                  labelType: NavigationRailLabelType.all,
                  leading: Padding(
                    padding: const EdgeInsets.symmetric(
                      vertical: VtsaSpacing.lg,
                    ),
                    child: Semantics(
                      header: true,
                      child: Text(
                        title,
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                    ),
                  ),
                  destinations: [
                    for (final destination in destinations)
                      NavigationRailDestination(
                        icon: destination.icon,
                        selectedIcon: destination.selectedIcon,
                        label: Text(destination.label),
                      ),
                  ],
                ),
                VerticalDivider(width: 1, color: context.vtsaColors.border),
                Expanded(child: child),
              ],
            ),
          ),
        );
      }

      return Scaffold(
        appBar: PowerSolutionsAppBar(title: title),
        drawer: NavigationDrawer(
          selectedIndex: selectedIndex,
          onDestinationSelected: (index) {
            Navigator.of(context).pop();
            onDestinationSelected(index);
          },
          children: [
            const SizedBox(height: VtsaSpacing.md),
            for (final destination in destinations)
              NavigationDrawerDestination(
                icon: destination.icon,
                selectedIcon: destination.selectedIcon,
                label: Text(destination.label),
              ),
          ],
        ),
        body: child,
      );
    },
  );
}

class VtsaBottomNavigationShell extends StatelessWidget {
  const VtsaBottomNavigationShell({
    required this.destinations,
    required this.selectedIndex,
    required this.onDestinationSelected,
    required this.child,
    this.activeSession,
    super.key,
  });

  final List<NavigationDestination> destinations;
  final int selectedIndex;
  final ValueChanged<int> onDestinationSelected;
  final Widget child;
  final Widget? activeSession;

  @override
  Widget build(BuildContext context) => Scaffold(
    body: child,
    bottomNavigationBar: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (activeSession != null)
          Material(
            color: context.vtsaColors.brandSoft,
            child: SafeArea(
              top: false,
              bottom: false,
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: VtsaSpacing.md,
                  vertical: VtsaSpacing.sm,
                ),
                child: activeSession,
              ),
            ),
          ),
        NavigationBar(
          selectedIndex: selectedIndex,
          onDestinationSelected: onDestinationSelected,
          destinations: destinations,
        ),
      ],
    ),
  );
}
