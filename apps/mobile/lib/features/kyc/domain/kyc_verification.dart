enum KycStatus {
  notStarted('NOT_STARTED', 'Not verified'),
  inProgress('IN_PROGRESS', 'Verification in progress'),
  pendingUpload('PENDING_UPLOAD', 'Complete your photos'),
  submitted('SUBMITTED', 'Verification submitted'),
  processing('PROCESSING', 'Verification in progress'),
  needsReview('NEEDS_REVIEW', 'Under review'),
  actionRequired('ACTION_REQUIRED', 'Action required'),
  approved('APPROVED', 'Verified'),
  rejected('REJECTED', 'Verification failed'),
  expired('EXPIRED', 'Verification expired'),
  cancelled('CANCELLED', 'Verification cancelled');

  const KycStatus(this.value, this.label);
  final String value;
  final String label;

  static KycStatus parse(String value) => KycStatus.values.firstWhere(
    (status) => status.value == value,
    orElse: () => throw const FormatException('Unknown verification status'),
  );

  bool get pending => this == submitted || this == processing;
  bool get canResubmit =>
      const {actionRequired, rejected, expired, cancelled}.contains(this);
}

final class KycVerification {
  KycVerification.fromJson(Map<String, dynamic> data)
    : id = data['id'] as String?,
      status = KycStatus.parse(data['status']! as String),
      documentType = data['document_type'] as String?,
      uploaded = ((data['evidence'] as List?) ?? const [])
          .map((item) => (item as Map)['kind']! as String)
          .toSet(),
      reasonCode = data['reason_code'] as String?,
      liveCaptureRequired = data['live_capture_required'] == true,
      unavailable = data['service_unavailable'] == true;

  final String? id;
  final KycStatus status;
  final String? documentType;
  final Set<String> uploaded;
  final String? reasonCode;
  final bool unavailable;
  final bool liveCaptureRequired;
}
