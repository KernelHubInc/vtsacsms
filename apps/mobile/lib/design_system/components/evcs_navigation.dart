import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/components/evcs_access.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

class EvcsNavigationBar extends StatelessWidget {
  const EvcsNavigationBar({
    required this.selectedIndex,
    required this.onSelected,
    super.key,
  });

  final int selectedIndex;
  final ValueChanged<int> onSelected;

  static const _items = [
    ('Explore', 'map-icon-map'),
    ('Sessions', 'map-icon-activity'),
    ('Scan', 'map-icon-scan'),
    ('Wallet', 'map-icon-wallet1'),
    ('Profile', 'map-icon-user'),
  ];

  @override
  Widget build(BuildContext context) => Material(
    color: Theme.of(context).colorScheme.surface,
    child: DecoratedBox(
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: context.vtsaColors.border)),
      ),
      child: SafeArea(
        top: false,
        minimum: const EdgeInsets.fromLTRB(12, 6, 12, 6),
        child: Row(
          children: List.generate(_items.length, (index) {
            final (label, asset) = _items[index];
            final scan = index == 2;
            final color = scan
                ? Colors.white
                : selectedIndex == index
                ? Theme.of(context).colorScheme.primary
                : context.vtsaColors.textMuted;
            return Expanded(
              child: Tooltip(
                message: scan ? 'Scan charger code' : label,
                child: Semantics(
                  excludeSemantics: true,
                  onTap: () => onSelected(index),
                  selected: selectedIndex == index,
                  button: true,
                  label: label,
                  child: Material(
                    color: scan
                        ? PowerSolutionsColors.brandNavy
                        : Colors.transparent,
                    borderRadius: BorderRadius.circular(14),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(14),
                      onTap: () => onSelected(index),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          vertical: 10,
                          horizontal: 2,
                        ),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            EvcsIcon(asset, color: color),
                            const SizedBox(height: 4),
                            Text(
                              label,
                              style: TextStyle(
                                fontFamily: 'Inter',
                                color: color,
                                fontSize: 12,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            );
          }),
        ),
      ),
    ),
  );
}
