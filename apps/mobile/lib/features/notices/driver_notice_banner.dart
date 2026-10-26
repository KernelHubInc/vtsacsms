import 'package:flutter/material.dart';
import 'package:vtsa_mobile/features/notices/driver_notices.dart';

class DriverNoticeBanner extends StatelessWidget {
  const DriverNoticeBanner({
    required this.notice,
    required this.onDismiss,
    super.key,
  });

  final DriverNotice notice;
  final VoidCallback onDismiss;

  @override
  Widget build(BuildContext context) => Material(
    color: Theme.of(context).colorScheme.primaryContainer,
    child: SafeArea(
      bottom: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(switch (notice.category) {
              'maintenance' => Icons.build_outlined,
              'promotion' => Icons.local_offer_outlined,
              _ => Icons.campaign_outlined,
            }),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    notice.title,
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    notice.message,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            IconButton(
              tooltip: 'Dismiss update',
              onPressed: onDismiss,
              icon: const Icon(Icons.close),
            ),
          ],
        ),
      ),
    ),
  );
}
