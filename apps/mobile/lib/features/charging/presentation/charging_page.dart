import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_theme.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

class ChargingPage extends StatelessWidget {
  const ChargingPage({
    required this.title,
    required this.body,
    this.subtitle,
    this.actions = const [],
    this.bottomActions,
    this.onBack,
    this.step,
    super.key,
  });
  final String title;
  final String? subtitle;
  final Widget body;
  final List<Widget> actions;
  final Widget? bottomActions;
  final VoidCallback? onBack;
  final int? step;

  @override
  Widget build(BuildContext context) => Theme(
    data: evcsAccessTheme(
      Theme.of(context).brightness == Brightness.dark
          ? VtsaTheme.dark()
          : VtsaTheme.light(),
    ),
    child: Builder(
      builder: (context) => Scaffold(
        backgroundColor: context.vtsaColors.canvas,
        body: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 600),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
                child: Column(
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        IconButton(
                          tooltip: 'Back',
                          onPressed:
                              onBack ??
                              () => context.canPop()
                                  ? context.pop()
                                  : context.go('/explore'),
                          style: IconButton.styleFrom(
                            backgroundColor: context.vtsaColors.surfaceRaised,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(14),
                            ),
                          ),
                          icon: EvcsIcon(
                            'auth-icon-back',
                            color: Theme.of(context).colorScheme.onSurface,
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                title,
                                style: const TextStyle(
                                  fontSize: 22,
                                  height: 28 / 22,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              if (subtitle != null) ...[
                                const SizedBox(height: 4),
                                Text(
                                  subtitle!,
                                  style: const TextStyle(
                                    fontSize: 14,
                                    height: 20 / 14,
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ),
                        ...actions,
                      ],
                    ),
                    if (step != null) ...[
                      const SizedBox(height: 20),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: List.generate(
                          3,
                          (index) => Expanded(
                            child: Padding(
                              padding: EdgeInsets.only(
                                right: index == 2 ? 0 : 12,
                              ),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  Text(
                                    [
                                      '1  Set up',
                                      '2  Start',
                                      '3  Session',
                                    ][index],
                                    style: const TextStyle(fontSize: 12),
                                  ),
                                  const SizedBox(height: 6),
                                  Container(
                                    height: 3,
                                    color: index <= step!
                                        ? Theme.of(context).colorScheme.primary
                                        : context.vtsaColors.border,
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      ),
                    ],
                    const SizedBox(height: 16),
                    Expanded(child: body),
                  ],
                ),
              ),
            ),
          ),
        ),
        bottomNavigationBar: bottomActions == null
            ? null
            : SafeArea(
                top: false,
                child: Center(
                  heightFactor: 1,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 600),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(20, 12, 20, 8),
                      child: SizedBox(
                        width: double.infinity,
                        child: bottomActions,
                      ),
                    ),
                  ),
                ),
              ),
      ),
    ),
  );
}
