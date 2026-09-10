import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

abstract final class VtsaTheme {
  static const Color _lightBrand = Color(0xFF12366B);
  static const Color _darkBrand = Color(0xFF57DDD2);

  static ThemeData light() => _build(
    brightness: Brightness.light,
    brand: _lightBrand,
    surface: const Color(0xFFFFFFFF),
    foreground: const Color(0xFF17243A),
    semantic: VtsaSemanticColors.light,
  );

  static ThemeData dark() => _build(
    brightness: Brightness.dark,
    brand: _darkBrand,
    surface: const Color(0xFF0B2448),
    foreground: const Color(0xFFF5FBFF),
    semantic: VtsaSemanticColors.dark,
  );

  static ThemeData _build({
    required Brightness brightness,
    required Color brand,
    required Color surface,
    required Color foreground,
    required VtsaSemanticColors semantic,
  }) {
    final colorScheme =
        ColorScheme.fromSeed(
          seedColor: brand,
          brightness: brightness,
          surface: surface,
        ).copyWith(
          primary: brand,
          onPrimary: brightness == Brightness.light
              ? Colors.white
              : const Color(0xFF081B35),
          error: semantic.danger,
          onSurface: foreground,
          outline: semantic.border,
          outlineVariant: semantic.border,
        );
    final base = ThemeData(
      brightness: brightness,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: semantic.canvas,
      useMaterial3: true,
      extensions: <ThemeExtension<dynamic>>[semantic],
    );
    final textTheme = base.textTheme
        .copyWith(
          displayLarge: base.textTheme.displayLarge?.copyWith(
            fontWeight: FontWeight.w700,
            height: 1.02,
            letterSpacing: -2,
          ),
          headlineLarge: base.textTheme.headlineLarge?.copyWith(
            fontWeight: FontWeight.w700,
            letterSpacing: -0.8,
          ),
          headlineSmall: base.textTheme.headlineSmall?.copyWith(
            fontWeight: FontWeight.w700,
          ),
          titleMedium: base.textTheme.titleMedium?.copyWith(
            fontWeight: FontWeight.w700,
          ),
          bodyLarge: base.textTheme.bodyLarge?.copyWith(height: 1.5),
          bodyMedium: base.textTheme.bodyMedium?.copyWith(height: 1.45),
          labelLarge: base.textTheme.labelLarge?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        )
        .apply(bodyColor: foreground, displayColor: foreground);
    final focusBorder = OutlineInputBorder(
      borderRadius: BorderRadius.circular(VtsaRadii.md),
      borderSide: BorderSide(color: semantic.focus, width: 2),
    );

    return base.copyWith(
      textTheme: textTheme,
      focusColor: semantic.focus,
      hoverColor: semantic.brandSoft.withValues(alpha: 0.6),
      dividerColor: semantic.border,
      appBarTheme: AppBarTheme(
        backgroundColor: brightness == Brightness.light
            ? semantic.surfaceSubtle
            : semantic.surfaceRaised,
        foregroundColor: foreground,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        shape: Border(bottom: BorderSide(color: semantic.border)),
        titleTextStyle: textTheme.titleMedium,
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: semantic.accent,
        linearTrackColor: semantic.border,
        circularTrackColor: semantic.border,
      ),
      searchBarTheme: SearchBarThemeData(
        backgroundColor: WidgetStatePropertyAll(surface),
        surfaceTintColor: const WidgetStatePropertyAll(Colors.transparent),
        elevation: const WidgetStatePropertyAll(2),
        shadowColor: const WidgetStatePropertyAll(Color(0x2412366B)),
        shape: WidgetStatePropertyAll(
          RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(VtsaRadii.pill),
            side: BorderSide(color: semantic.border),
          ),
        ),
        hintStyle: WidgetStatePropertyAll(
          textTheme.bodyMedium?.copyWith(color: semantic.textMuted),
        ),
        textStyle: WidgetStatePropertyAll(textTheme.bodyMedium),
      ),
      segmentedButtonTheme: SegmentedButtonThemeData(
        style: ButtonStyle(
          backgroundColor: WidgetStateProperty.resolveWith(
            (states) => states.contains(WidgetState.selected)
                ? semantic.brandSoft
                : surface,
          ),
          foregroundColor: WidgetStateProperty.resolveWith(
            (states) =>
                states.contains(WidgetState.selected) ? brand : foreground,
          ),
          side: WidgetStatePropertyAll(BorderSide(color: semantic.border)),
        ),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: semantic.brandSoft,
        foregroundColor: brand,
        elevation: 3,
        focusColor: semantic.focus.withValues(alpha: 0.16),
      ),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(VtsaRadii.lg),
          side: BorderSide(color: semantic.border),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: VtsaSpacing.lg),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(VtsaRadii.md),
          ),
          textStyle: textTheme.labelLarge,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: foreground,
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: VtsaSpacing.lg),
          side: BorderSide(color: semantic.borderStrong),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(VtsaRadii.md),
          ),
          textStyle: textTheme.labelLarge,
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: VtsaSpacing.md,
          vertical: VtsaSpacing.sm,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(VtsaRadii.md),
          borderSide: BorderSide(color: semantic.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(VtsaRadii.md),
          borderSide: BorderSide(color: semantic.border),
        ),
        focusedBorder: focusBorder,
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(VtsaRadii.md),
          borderSide: BorderSide(color: semantic.danger),
        ),
        focusedErrorBorder: focusBorder,
        labelStyle: TextStyle(color: semantic.textMuted),
        helperStyle: TextStyle(color: semantic.textMuted),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: semantic.surfaceRaised,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(VtsaRadii.xl),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: semantic.surfaceRaised,
        contentTextStyle: textTheme.bodyMedium,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(VtsaRadii.md),
          side: BorderSide(color: semantic.border),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: surface,
        indicatorColor: brightness == Brightness.light
            ? const Color(0xFFD8F7F3)
            : const Color(0xFF164B60),
        elevation: 4,
        shadowColor: const Color(0x2412366B),
        surfaceTintColor: Colors.transparent,
        iconTheme: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return IconThemeData(
            color: selected ? brand : semantic.textMuted,
            size: selected ? 25 : 23,
          );
        }),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return textTheme.labelSmall?.copyWith(
            color: selected ? brand : semantic.textMuted,
            fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
          );
        }),
      ),
      navigationRailTheme: NavigationRailThemeData(
        backgroundColor: surface,
        indicatorColor: semantic.brandSoft,
        selectedIconTheme: IconThemeData(color: brand),
        selectedLabelTextStyle: textTheme.labelMedium?.copyWith(color: brand),
      ),
    );
  }
}
