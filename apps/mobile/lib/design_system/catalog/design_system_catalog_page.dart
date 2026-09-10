import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';

class DesignSystemCatalogPage extends StatelessWidget {
  const DesignSystemCatalogPage({super.key});

  @override
  Widget build(BuildContext context) => VtsaPageShell(
    eyebrow: 'Currentline catalog',
    title: 'Visual foundations',
    actions: [
      IconButton(
        tooltip: 'Show feedback example',
        onPressed: () => showVtsaToast(
          context,
          message: 'Fresh evidence retrieved.',
          actionLabel: 'Dismiss',
        ),
        icon: const Icon(Icons.notifications_none),
      ),
    ],
    body: ListView(
      children: [
        const _CatalogHeading(
          index: '01',
          title: 'Actions and fields',
          description: 'Persistent labels, clear hierarchy, and visible focus.',
        ),
        Wrap(
          spacing: VtsaSpacing.sm,
          runSpacing: VtsaSpacing.sm,
          children: [
            VtsaButton(label: 'Primary', onPressed: () {}),
            VtsaButton(
              label: 'Secondary',
              variant: VtsaButtonVariant.secondary,
              onPressed: () {},
            ),
            VtsaButton(
              label: 'Quiet',
              variant: VtsaButtonVariant.quiet,
              onPressed: () {},
            ),
            VtsaButton(
              label: 'Danger',
              variant: VtsaButtonVariant.danger,
              onPressed: () => showVtsaConfirmationDialog(
                context: context,
                title: 'Confirm example',
                description: 'This catalog action changes no product data.',
                confirmLabel: 'Confirm example',
                dangerous: true,
              ),
            ),
            const VtsaButton(
              label: 'Submitting',
              onPressed: null,
              loading: true,
            ),
          ],
        ),
        const SizedBox(height: VtsaSpacing.lg),
        const Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: VtsaTextField(
                label: 'Display label',
                hint: 'Labels remain visible while typing.',
                required: true,
              ),
            ),
            SizedBox(width: VtsaSpacing.md),
            Expanded(
              child: VtsaTextField(
                label: 'Connector reference',
                error: 'Check the identifier printed on the connector.',
              ),
            ),
          ],
        ),
        const SizedBox(height: VtsaSpacing.xxl),
        const _CatalogHeading(
          index: '02',
          title: 'Operational state',
          description: 'Color is always paired with a label and shape.',
        ),
        const Wrap(
          spacing: VtsaSpacing.xs,
          runSpacing: VtsaSpacing.xs,
          children: [
            VtsaStatusChip(
              label: 'Available',
              state: VtsaOperationalState.available,
            ),
            VtsaStatusChip(
              label: 'Preparing',
              state: VtsaOperationalState.preparing,
            ),
            VtsaStatusChip(
              label: 'Charging',
              state: VtsaOperationalState.charging,
            ),
            VtsaStatusChip(
              label: 'Suspended',
              state: VtsaOperationalState.suspended,
            ),
            VtsaStatusChip(
              label: 'Reserved',
              state: VtsaOperationalState.reserved,
            ),
            VtsaStatusChip(
              label: 'Faulted',
              state: VtsaOperationalState.faulted,
            ),
            VtsaStatusChip(
              label: 'Offline · 8 min',
              state: VtsaOperationalState.offline,
            ),
          ],
        ),
        const SizedBox(height: VtsaSpacing.xxl),
        const _CatalogHeading(
          index: '03',
          title: 'Content and recovery',
          description:
              'Every state explains what happened and what comes next.',
        ),
        LayoutBuilder(
          builder: (context, constraints) {
            final cards = <Widget>[
              const VtsaCard(
                title: 'Loading evidence',
                child: VtsaSkeleton(lines: 5),
              ),
              VtsaEmptyState(
                title: 'No matching records',
                description:
                    'Clear the current filters without losing context.',
                action: VtsaButton(
                  label: 'Clear filters',
                  variant: VtsaButtonVariant.secondary,
                  onPressed: () {},
                ),
              ),
              VtsaErrorState(
                title: 'Evidence could not refresh',
                description: 'Last known values may be stale. Retry safely.',
                correlationId: '01K0M0JJ5X0M0JJ5X0M0JJ5X0M',
                action: VtsaButton(
                  label: 'Retry',
                  variant: VtsaButtonVariant.secondary,
                  onPressed: () {},
                ),
              ),
            ];
            final columns = constraints.maxWidth >= 900 ? 3 : 1;

            return GridView.count(
              crossAxisCount: columns,
              crossAxisSpacing: VtsaSpacing.md,
              mainAxisSpacing: VtsaSpacing.md,
              childAspectRatio: columns == 1 ? 2.2 : 0.92,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              children: cards,
            );
          },
        ),
        const SizedBox(height: VtsaSpacing.xxl),
      ],
    ),
  );
}

class _CatalogHeading extends StatelessWidget {
  const _CatalogHeading({
    required this.index,
    required this.title,
    required this.description,
  });

  final String index;
  final String title;
  final String description;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: VtsaSpacing.md),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '$index · FOUNDATION',
          style: Theme.of(context).textTheme.labelSmall?.copyWith(
            color: Theme.of(context).colorScheme.primary,
            fontWeight: FontWeight.w800,
            letterSpacing: 1.2,
          ),
        ),
        const SizedBox(height: VtsaSpacing.xs),
        Text(title, style: Theme.of(context).textTheme.headlineSmall),
        const SizedBox(height: VtsaSpacing.xs),
        Text(
          description,
          style: Theme.of(
            context,
          ).textTheme.bodyMedium?.copyWith(color: context.vtsaColors.textMuted),
        ),
      ],
    ),
  );
}
