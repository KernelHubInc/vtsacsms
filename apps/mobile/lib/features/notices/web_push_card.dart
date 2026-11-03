import 'package:flutter/material.dart';
import 'package:vtsa_mobile/features/notices/notice_push_registration.dart';

class WebPushCard extends StatelessWidget {
  const WebPushCard({required this.push, super.key});
  final FirebaseNoticePushRegistration push;

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: push,
    builder: (context, _) {
      final enabled =
          push.status == NoticePushStatus.enabled ||
          push.status == NoticePushStatus.disableFailed;
      final busy =
          push.status == NoticePushStatus.enabling ||
          push.status == NoticePushStatus.disabling;
      final message = switch (push.status) {
        NoticePushStatus.enabled =>
          'Notifications are enabled for this browser.',
        NoticePushStatus.disabling => 'Turning off notifications…',
        NoticePushStatus.disableFailed =>
          'Could not turn off notifications. Check your connection and retry, or block notifications in Chrome site settings.',
        NoticePushStatus.blocked =>
          'Notifications are blocked. In Chrome, open Site settings → Notifications, allow them, then try again.',
        NoticePushStatus.failed =>
          'Could not enable notifications. Check your connection and try again.',
        NoticePushStatus.unsupported =>
          'This browser does not support notifications. Try Android Chrome.',
        NoticePushStatus.enabling => 'Connecting notifications…',
        _ =>
          'Receive maintenance updates, announcements and offers, even when this page is in the background.',
      };
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Icon(
                    enabled
                        ? Icons.notifications_active_outlined
                        : Icons.notifications_none_outlined,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'Stay informed',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Text(message, semanticsLabel: message),
              const SizedBox(height: 12),
              if (push.status != NoticePushStatus.unsupported)
                FilledButton(
                  onPressed: busy
                      ? null
                      : enabled
                      ? push.disable
                      : push.enable,
                  child: Text(
                    busy
                        ? 'Updating…'
                        : enabled
                        ? 'Turn off notifications'
                        : 'Enable notifications',
                  ),
                ),
            ],
          ),
        ),
      );
    },
  );
}
