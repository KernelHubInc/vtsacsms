import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/design_system/branding/power_solutions_logo.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

abstract final class RegistrationColors {
  static const navy = Color(0xFF102D42);
  static const mint = Color(0xFF61D6CF);
  static const teal = Color(0xFF087B78);
  static const soft = Color(0xFFEAF8F6);
  static const canvas = Color(0xFFF3F7FA);
  static const muted = Color(0xFF627687);
  static const border = Color(0xFFD7E2E9);

  static ThemeData theme(BuildContext context) {
    final base = Theme.of(context);
    final dark = base.brightness == Brightness.dark;
    final foreground = dark ? base.colorScheme.onSurface : navy;
    final mutedText = dark ? context.vtsaColors.textMuted : muted;
    final surface = base.colorScheme.surface;
    final outline = dark ? base.colorScheme.outline : border;
    final text = base.textTheme.apply(
      bodyColor: foreground,
      displayColor: foreground,
    );
    return base.copyWith(
      scaffoldBackgroundColor: dark ? base.scaffoldBackgroundColor : canvas,
      colorScheme: base.colorScheme.copyWith(
        primary: dark ? mint : teal,
        onSurface: foreground,
        outline: outline,
      ),
      hintColor: dark ? mutedText : const Color(0xFF7B8C9A),
      textTheme: text.copyWith(
        bodyMedium: text.bodyMedium?.copyWith(
          fontSize: 15,
          height: 1.5,
          fontWeight: FontWeight.w400,
        ),
        bodySmall: text.bodySmall?.copyWith(
          fontSize: 12,
          height: 1.5,
          color: mutedText,
        ),
      ),
      extensions: [
        context.vtsaColors.copyWith(textMuted: mutedText, border: outline),
      ],
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        isDense: true,
        contentPadding: const EdgeInsets.all(16),
        constraints: const BoxConstraints(minHeight: 54),
        hintStyle: TextStyle(
          fontSize: 15,
          color: dark ? mutedText : const Color(0xFF7B8C9A),
          height: 1.5,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: outline),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: outline),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: dark ? mint : teal, width: 2),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: mint,
          foregroundColor: navy,
          minimumSize: const Size(double.infinity, 56),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          textStyle: text.labelLarge?.copyWith(
            fontSize: 15,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: dark ? mint : teal,
          textStyle: text.labelLarge?.copyWith(
            fontSize: 13,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
      checkboxTheme: CheckboxThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(5)),
      ),
    );
  }
}

class RegistrationIcon extends StatelessWidget {
  const RegistrationIcon(this.name, this.size, {super.key});
  final String name;
  final double size;

  @override
  Widget build(BuildContext context) => Image.asset(
    'assets/registration/$name.png',
    width: size,
    height: size,
    excludeFromSemantics: true,
    fit: BoxFit.contain,
  );
}

class RegistrationLayout extends StatelessWidget {
  const RegistrationLayout({
    required this.child,
    required this.step,
    required this.scrollController,
    required this.canExit,
    super.key,
  });
  final int step;
  final ScrollController scrollController;
  final bool Function() canExit;
  final Widget child;

  @override
  Widget build(BuildContext context) => Theme(
    data: RegistrationColors.theme(context),
    child: Builder(
      builder: (context) => AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.light,
        child: Scaffold(
          backgroundColor: RegistrationColors.navy,
          body: SafeArea(
            bottom: false,
            child: SizedBox.expand(
              child: ColoredBox(
                color: Theme.of(context).scaffoldBackgroundColor,
                child: SingleChildScrollView(
                  controller: scrollController,
                  keyboardDismissBehavior:
                      ScrollViewKeyboardDismissBehavior.onDrag,
                  child: Center(
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 640),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Container(
                            color: RegistrationColors.navy,
                            padding: const EdgeInsets.fromLTRB(24, 24, 24, 28),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  children: [
                                    const Expanded(
                                      child: Align(
                                        alignment: Alignment.centerLeft,
                                        child: PowerSolutionsLogo(
                                          height: 28,
                                          inverse: true,
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: 16),
                                    TextButton(
                                      onPressed: () {
                                        if (canExit()) context.go('/login');
                                      },
                                      style: TextButton.styleFrom(
                                        foregroundColor:
                                            RegistrationColors.mint,
                                      ),
                                      child: const Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          Text('Sign in'),
                                          SizedBox(width: 6),
                                          RegistrationIcon(
                                            'arrow-up-right',
                                            15,
                                          ),
                                        ],
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 26),
                                const Text(
                                  'Create your account.',
                                  style: TextStyle(
                                    fontSize: 34,
                                    fontWeight: FontWeight.w400,
                                    height: 1.12,
                                    letterSpacing: -1.2,
                                    color: Colors.white,
                                  ),
                                ),
                                const SizedBox(height: 12),
                                const Text(
                                  'Your details, your vehicle, one simple setup.',
                                  style: TextStyle(
                                    fontSize: 14,
                                    height: 1.6,
                                    color: Color(0xFFBBCCD8),
                                  ),
                                ),
                                const SizedBox(height: 26),
                                Semantics(
                                  liveRegion: true,
                                  child: Text(
                                    'Step ${step + 1} of 3',
                                    style: const TextStyle(
                                      color: RegistrationColors.mint,
                                      fontSize: 13,
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 12),
                                LinearProgressIndicator(
                                  value: (step + 1) / 3,
                                  minHeight: 4,
                                  color: RegistrationColors.mint,
                                  backgroundColor: const Color(0xFF365164),
                                  borderRadius: BorderRadius.circular(2),
                                  semanticsLabel:
                                      'Registration, step ${step + 1} of 3',
                                ),
                              ],
                            ),
                          ),
                          AutofillGroup(child: child),
                          SizedBox(
                            height: MediaQuery.viewPaddingOf(context).bottom,
                          ),
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
  );
}

class RegistrationSection extends StatelessWidget {
  const RegistrationSection({
    required this.title,
    required this.description,
    required this.icon,
    required this.child,
    this.requiredNotice = false,
    super.key,
  });
  final String title;
  final String description;
  final String icon;
  final Widget child;
  final bool requiredNotice;

  @override
  Widget build(BuildContext context) => Material(
    color: Theme.of(context).colorScheme.surface,
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (requiredNotice) ...[
            Text(
              '* Required fields · Optional fields are marked',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 22),
          ],
          Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: RegistrationColors.soft,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Center(child: RegistrationIcon(icon, 20)),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Semantics(
                  header: true,
                  child: Text(
                    title,
                    style: const TextStyle(
                      fontSize: 21,
                      fontWeight: FontWeight.w400,
                      letterSpacing: -.5,
                      height: 1.2,
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            description,
            style: Theme.of(
              context,
            ).textTheme.bodySmall?.copyWith(fontSize: 13),
          ),
          const SizedBox(height: 22),
          child,
        ],
      ),
    ),
  );
}

class RegistrationPasswordGuide extends StatelessWidget {
  const RegistrationPasswordGuide({super.key});

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: Theme.of(context).scaffoldBackgroundColor,
      borderRadius: BorderRadius.circular(12),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Your password needs',
          style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
        ),
        for (final requirement in [
          'At least 8 characters',
          'Uppercase and lowercase letters',
          'At least one number',
          'At least one special character',
        ]) ...[
          const SizedBox(height: 9),
          Row(
            children: [
              const RegistrationIcon('requirement-marker', 4),
              const SizedBox(width: 9),
              Expanded(
                child: Text(
                  requirement,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            ],
          ),
        ],
      ],
    ),
  );
}
