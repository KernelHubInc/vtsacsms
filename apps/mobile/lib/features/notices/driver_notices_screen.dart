import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/theme/vtsa_tokens.dart';
import 'package:vtsa_mobile/features/notices/driver_notices.dart';

class DriverNoticesScreen extends StatelessWidget {
  const DriverNoticesScreen({required this.controller, super.key});

  final DriverNoticesController? controller;

  @override
  Widget build(BuildContext context) {
    final notices = controller;
    return Scaffold(
      appBar: AppBar(title: const Text('Updates')),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 600),
            child: notices == null
                ? const Center(child: Text('Updates are unavailable.'))
                : AnimatedBuilder(
                    animation: notices,
                    builder: (context, _) => RefreshIndicator(
                      onRefresh: notices.refresh,
                      child: ListView(
                        padding: const EdgeInsets.all(20),
                        children: [
                          Text(
                            'Charging network updates',
                            style: Theme.of(context).textTheme.headlineSmall,
                          ),
                          const SizedBox(height: 6),
                          Text(
                            'Maintenance, announcements and offers from Power Solutions.',
                            style: Theme.of(context).textTheme.bodyMedium,
                          ),
                          const SizedBox(height: 20),
                          if (notices.failed)
                            Card(
                              child: Padding(
                                padding: const EdgeInsets.all(16),
                                child: Row(
                                  children: [
                                    const Icon(Icons.wifi_off_outlined),
                                    const SizedBox(width: 12),
                                    const Expanded(
                                      child: Text('Could not refresh updates.'),
                                    ),
                                    TextButton(
                                      onPressed: notices.refresh,
                                      child: const Text('Retry'),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          if (notices.loading && notices.items.isEmpty)
                            const Center(child: CircularProgressIndicator())
                          else if (notices.items.isEmpty)
                            const Card(
                              child: Padding(
                                padding: EdgeInsets.all(20),
                                child: Text('There are no current updates.'),
                              ),
                            ),
                          for (final item in notices.items) ...[
                            Card(
                              child: Padding(
                                padding: const EdgeInsets.all(18),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Icon(_icon(item.category), size: 20),
                                        const SizedBox(width: 8),
                                        Text(
                                          _label(item.category),
                                          style: Theme.of(
                                            context,
                                          ).textTheme.labelLarge,
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: VtsaSpacing.sm),
                                    Text(
                                      item.title,
                                      style: Theme.of(
                                        context,
                                      ).textTheme.titleMedium,
                                    ),
                                    const SizedBox(height: 6),
                                    Text(item.message),
                                  ],
                                ),
                              ),
                            ),
                            const SizedBox(height: 8),
                          ],
                        ],
                      ),
                    ),
                  ),
          ),
        ),
      ),
    );
  }

  static String _label(String category) => switch (category) {
    'maintenance' => 'Maintenance',
    'promotion' => 'Promotion',
    _ => 'Announcement',
  };

  static IconData _icon(String category) => switch (category) {
    'maintenance' => Icons.build_outlined,
    'promotion' => Icons.local_offer_outlined,
    _ => Icons.campaign_outlined,
  };
}
