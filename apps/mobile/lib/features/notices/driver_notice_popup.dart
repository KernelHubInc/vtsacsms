import 'package:flutter/material.dart';
import 'package:vtsa_mobile/design_system/components/vtsa_components.dart';
import 'package:vtsa_mobile/features/notices/driver_notices.dart';

class DriverNoticePopup extends StatelessWidget {
  const DriverNoticePopup({
    required this.notice,
    required this.onDismiss,
    super.key,
  });

  final DriverNotice notice;
  final VoidCallback onDismiss;

  @override
  Widget build(BuildContext context) => VtsaAlertRegion(
    key: ValueKey(notice.id),
    title: notice.title,
    message: notice.message,
    tone: notice.category == 'maintenance'
        ? VtsaAlertTone.warning
        : VtsaAlertTone.info,
    onDismiss: onDismiss,
  );
}
