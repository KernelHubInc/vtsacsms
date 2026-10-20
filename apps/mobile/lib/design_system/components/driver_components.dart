import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

/// Scoped to the driver journey so account, identity and payment screens retain
/// their established presentation. Figma direction board: 7:2.
abstract final class DriverTheme {
  static const blue = Color(0xFF1457C2);
  static const navy = Color(0xFF142640);
  static const muted = Color(0xFF617085);
  static const ice = Color(0xFFEBF5FF);
  static const canvas = Color(0xFFF7FAFC);
  static const border = Color(0xFFE0E5ED);
  static const green = Color(0xFF148552);
  static const greenSurface = Color(0xFFE5F7EB);

  static ThemeData of(BuildContext context) {
    final base = Theme.of(context);
    if (base.brightness == Brightness.dark) return base;
    final text = base.textTheme.apply(bodyColor: navy, displayColor: navy);
    final shape = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(VtsaRadii.lg),
    );
    return base.copyWith(
      scaffoldBackgroundColor: Colors.white,
      colorScheme: base.colorScheme.copyWith(primary: blue, onSurface: navy),
      textTheme: text.copyWith(
        headlineSmall: text.headlineSmall?.copyWith(fontSize: 22, height: 1.25),
        titleLarge: text.titleLarge?.copyWith(
          fontSize: 18,
          fontWeight: FontWeight.w600,
        ),
        titleMedium: text.titleMedium?.copyWith(
          fontSize: 14,
          fontWeight: FontWeight.w600,
        ),
        bodyMedium: text.bodyMedium?.copyWith(fontSize: 13, height: 1.4),
        bodySmall: text.bodySmall?.copyWith(
          fontSize: 12,
          color: muted,
          height: 1.4,
        ),
      ),
      extensions: [
        context.vtsaColors.copyWith(
          canvas: Colors.white,
          surfaceSubtle: canvas,
          brandSoft: ice,
          textMuted: muted,
          border: border,
          success: green,
          successSurface: greenSurface,
          available: green,
          charging: blue,
        ),
      ],
      appBarTheme: AppBarTheme(
        backgroundColor: Colors.white,
        foregroundColor: navy,
        elevation: 0,
        scrolledUnderElevation: 0,
        titleTextStyle: text.titleLarge?.copyWith(
          fontSize: 18,
          fontWeight: FontWeight.w600,
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: blue,
          foregroundColor: Colors.white,
          minimumSize: const Size(48, 48),
          shape: shape,
          textStyle: text.labelLarge?.copyWith(
            fontSize: 14,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: blue,
          minimumSize: const Size(48, 48),
          shape: shape,
          side: const BorderSide(color: border),
        ),
      ),
      cardTheme: const CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
      ),
      listTileTheme: ListTileThemeData(
        titleTextStyle: text.titleMedium?.copyWith(fontSize: 14),
        subtitleTextStyle: text.bodySmall?.copyWith(fontSize: 12, color: muted),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: blue,
        linearTrackColor: border,
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: Colors.white,
        indicatorColor: Colors.transparent,
        height: 68,
        elevation: 0,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontFamily: text.bodyMedium?.fontFamily,
            fontSize: 11,
            color: states.contains(WidgetState.selected) ? blue : muted,
            fontWeight: states.contains(WidgetState.selected)
                ? FontWeight.w600
                : FontWeight.w400,
          ),
        ),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(
            size: 22,
            color: states.contains(WidgetState.selected) ? blue : muted,
          ),
        ),
      ),
    );
  }
}

class DriverNavigation extends StatelessWidget {
  const DriverNavigation({this.selectedIndex = 0, super.key});
  final int selectedIndex;

  @override
  Widget build(BuildContext context) => DecoratedBox(
    decoration: BoxDecoration(
      border: Border(top: BorderSide(color: context.vtsaColors.border)),
    ),
    child: NavigationBar(
      selectedIndex: selectedIndex,
      onDestinationSelected: (index) => context.go(
        const ['/explore', '/activity', '/wallet', '/account'][index],
      ),
      destinations: const [
        NavigationDestination(icon: Icon(Icons.search), label: 'Explore'),
        NavigationDestination(icon: Icon(Icons.history), label: 'Activity'),
        NavigationDestination(
          icon: Icon(Icons.account_balance_wallet_outlined),
          label: 'Wallet',
        ),
        NavigationDestination(
          icon: Icon(Icons.person_outline),
          label: 'Account',
        ),
      ],
    ),
  );
}

class DriverPage extends StatelessWidget {
  const DriverPage({
    required this.title,
    required this.body,
    this.actions = const [],
    this.selectedIndex = 0,
    this.onBack,
    super.key,
  });
  final String title;
  final Widget body;
  final List<Widget> actions;
  final int selectedIndex;
  final VoidCallback? onBack;

  @override
  Widget build(BuildContext context) => Theme(
    data: DriverTheme.of(context),
    child: Builder(
      builder: (context) => Scaffold(
        appBar: AppBar(
          leading: IconButton(
            tooltip: 'Back',
            icon: const Icon(Icons.chevron_left),
            onPressed:
                onBack ??
                () => context.canPop() ? context.pop() : context.go('/explore'),
          ),
          title: Text(title),
          actions: actions,
        ),
        body: SafeArea(
          top: false,
          bottom: false,
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 600),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
                child: body,
              ),
            ),
          ),
        ),
        bottomNavigationBar: DriverNavigation(selectedIndex: selectedIndex),
      ),
    ),
  );
}

/// Main-tab content; the consumer shell owns the bottom navigation.
class DriverTabPage extends StatelessWidget {
  const DriverTabPage({
    required this.title,
    required this.subtitle,
    required this.body,
    this.actions = const [],
    super.key,
  });

  final String title;
  final String subtitle;
  final Widget body;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) => Theme(
    data: DriverTheme.of(context),
    child: Builder(
      builder: (context) => Scaffold(
        body: SafeArea(
          bottom: false,
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 600),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Align(
                            alignment: Alignment.centerLeft,
                            child: PowerSolutionsLogo(
                              height: 30,
                              inverse:
                                  Theme.of(context).brightness ==
                                  Brightness.dark,
                            ),
                          ),
                        ),
                        ...actions,
                      ],
                    ),
                    const SizedBox(height: 20),
                    Text(
                      title,
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                    const SizedBox(height: 6),
                    Text(
                      subtitle,
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    const SizedBox(height: 20),
                    Expanded(child: body),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

class DriverFilterChip extends StatelessWidget {
  const DriverFilterChip({
    required this.label,
    required this.selected,
    required this.onSelected,
    super.key,
  });
  final String label;
  final bool selected;
  final ValueChanged<bool> onSelected;

  @override
  Widget build(BuildContext context) => ChoiceChip(
    label: Text(label),
    selected: selected,
    onSelected: onSelected,
    showCheckmark: false,
    shape: const StadiumBorder(),
    backgroundColor: Theme.of(context).colorScheme.surface,
    selectedColor: Theme.of(context).colorScheme.primary,
    side: BorderSide(
      color: selected ? Colors.transparent : context.vtsaColors.border,
    ),
    labelStyle: Theme.of(context).textTheme.labelLarge?.copyWith(
      color: selected
          ? Theme.of(context).colorScheme.onPrimary
          : context.vtsaColors.textMuted,
    ),
  );
}

class DriverNotice extends StatelessWidget {
  const DriverNotice({
    required this.title,
    required this.message,
    this.positive = false,
    super.key,
  });
  final String title;
  final String message;
  final bool positive;

  @override
  Widget build(BuildContext context) => Container(
    width: double.infinity,
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: positive
          ? context.vtsaColors.successSurface
          : context.vtsaColors.brandSoft,
      borderRadius: BorderRadius.circular(VtsaRadii.lg),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
            color: positive ? context.vtsaColors.success : null,
          ),
        ),
        const SizedBox(height: 6),
        Text(message, style: Theme.of(context).textTheme.bodySmall),
      ],
    ),
  );
}
