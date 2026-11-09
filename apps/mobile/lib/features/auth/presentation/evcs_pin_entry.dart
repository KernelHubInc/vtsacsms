import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

class EvcsPinEntry extends StatelessWidget {
  const EvcsPinEntry({
    required this.length,
    required this.busy,
    required this.onDigit,
    required this.onDelete,
    required this.onClose,
    required this.onPassword,
    required this.onHelp,
    this.onBiometric,
    this.biometricLabel = 'Biometrics',
    this.failure,
    super.key,
  });

  final int length;
  final bool busy;
  final Future<void> Function(String) onDigit;
  final VoidCallback onDelete;
  final VoidCallback onClose;
  final VoidCallback onPassword;
  final VoidCallback onHelp;
  final VoidCallback? onBiometric;
  final String biometricLabel;
  final String? failure;

  @override
  Widget build(BuildContext context) => Theme(
    data: evcsAccessTheme(Theme.of(context)),
    child: Builder(
      builder: (context) => Scaffold(
        backgroundColor: context.vtsaColors.canvas,
        body: Focus(
          autofocus: true,
          onKeyEvent: (_, event) {
            if (event is! KeyDownEvent || busy) return KeyEventResult.ignored;
            final character = event.character;
            if (character != null && RegExp(r'^\d$').hasMatch(character)) {
              onDigit(character);
            } else if (event.logicalKey == LogicalKeyboardKey.backspace) {
              onDelete();
            } else if (event.logicalKey == LogicalKeyboardKey.escape) {
              onClose();
            } else {
              return KeyEventResult.ignored;
            }
            return KeyEventResult.handled;
          },
          child: SafeArea(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(20, 24, 20, 20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        children: [
                          const EvcsLogo(width: 156),
                          const Spacer(),
                          IconButton(
                            tooltip: 'Back to welcome',
                            onPressed: busy ? null : onClose,
                            icon: EvcsIcon(
                              'pin-icon-close',
                              color: Theme.of(context).colorScheme.onSurface,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 24),
                      Text(
                        'QUICK ACCESS · THIS PHONE',
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          color: Theme.of(context).colorScheme.primary,
                        ),
                      ),
                      const SizedBox(height: 20),
                      const Text(
                        'Unlock your drive.',
                        style: TextStyle(
                          fontSize: 28,
                          height: 34 / 28,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 12),
                      const Text('Enter your six-digit app PIN.'),
                      const SizedBox(height: 28),
                      Semantics(
                        label: 'App PIN',
                        value: '$length of 6 digits entered',
                        child: ExcludeSemantics(
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                            children: List.generate(
                              6,
                              (index) => Container(
                                width: 38,
                                height: 48,
                                decoration: BoxDecoration(
                                  color: context.vtsaColors.surfaceSubtle,
                                  borderRadius: BorderRadius.circular(10),
                                  border: Border.all(
                                    width: index == length ? 2 : 1,
                                    color: failure != null
                                        ? context.vtsaColors.danger
                                        : index == length
                                        ? Theme.of(context).colorScheme.primary
                                        : context.vtsaColors.border,
                                  ),
                                ),
                                child: Center(
                                  child: busy || index < length
                                      ? Container(
                                          width: 10,
                                          height: 10,
                                          decoration: BoxDecoration(
                                            color: Theme.of(
                                              context,
                                            ).colorScheme.primary,
                                            shape: BoxShape.circle,
                                          ),
                                        )
                                      : null,
                                ),
                              ),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(height: 16),
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          busy
                              ? 'Checking your saved session…'
                              : failure ??
                                    'Your app PIN unlocks EVCS on this phone.',
                          style: TextStyle(
                            fontSize: 14,
                            height: 20 / 14,
                            color: failure == null
                                ? null
                                : context.vtsaColors.danger,
                          ),
                        ),
                      ),
                      const SizedBox(height: 24),
                      for (final row in ['123', '456', '789']) ...[
                        Row(
                          children: [
                            for (final digit in row.split(''))
                              Expanded(
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 4,
                                  ),
                                  child: _key(
                                    context,
                                    label: digit,
                                    onPressed: () => onDigit(digit),
                                    child: Text(
                                      digit,
                                      style: const TextStyle(
                                        fontSize: 28,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                          ],
                        ),
                        const SizedBox(height: 12),
                      ],
                      Row(
                        children: [
                          Expanded(
                            child: onBiometric == null
                                ? const SizedBox(height: 64)
                                : _key(
                                    context,
                                    label: biometricLabel,
                                    onPressed: onBiometric!,
                                    tinted: true,
                                    child: Column(
                                      mainAxisAlignment:
                                          MainAxisAlignment.center,
                                      children: [
                                        const EvcsIcon('pin-fingerprint'),
                                        const SizedBox(height: 4),
                                        Text(
                                          biometricLabel,
                                          style: const TextStyle(fontSize: 11),
                                        ),
                                      ],
                                    ),
                                  ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: _key(
                              context,
                              label: '0',
                              onPressed: () => onDigit('0'),
                              child: const Text(
                                '0',
                                style: TextStyle(
                                  fontSize: 28,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: _key(
                              context,
                              label: 'Delete last PIN digit',
                              onPressed: onDelete,
                              tinted: true,
                              child: EvcsIcon(
                                'pin-backspace',
                                color: Theme.of(context).colorScheme.primary,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 24),
                      TextButton(
                        onPressed: busy ? null : onPassword,
                        child: const Text('Forgot PIN? Use your password'),
                      ),
                      const SizedBox(height: 8),
                      TextButton(
                        onPressed: busy ? null : onHelp,
                        child: const Text('Help with unlocking'),
                      ),
                      const SizedBox(height: 16),
                      const Text(
                        'Protected on this phone',
                        textAlign: TextAlign.center,
                        style: TextStyle(fontSize: 12),
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
  );

  Widget _key(
    BuildContext context, {
    required String label,
    required VoidCallback onPressed,
    required Widget child,
    bool tinted = false,
  }) => Semantics(
    button: true,
    enabled: !busy,
    onTap: busy ? null : onPressed,
    label: label,
    excludeSemantics: true,
    child: SizedBox(
      height:
          64 + (MediaQuery.textScalerOf(context).scale(1) - 1).clamp(0, 2) * 32,
      child: OutlinedButton(
        onPressed: busy ? null : onPressed,
        style: OutlinedButton.styleFrom(
          padding: EdgeInsets.zero,
          backgroundColor: tinted
              ? context.vtsaColors.surfaceSubtle
              : context.vtsaColors.surfaceRaised,
          side: BorderSide(
            color: tinted ? Colors.transparent : context.vtsaColors.border,
          ),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
        child: child,
      ),
    ),
  );
}
