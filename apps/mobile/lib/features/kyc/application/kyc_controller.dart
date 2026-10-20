import 'package:flutter/foundation.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/ids/ulid_generator.dart';
import 'package:vtsa_mobile/features/kyc/domain/kyc_repository.dart';
import 'package:vtsa_mobile/features/kyc/domain/kyc_verification.dart';

final class KycController extends ChangeNotifier {
  KycController(this.repository);
  final KycRepository repository;
  KycVerification? verification;
  Map<String, dynamic> documents = {};
  Map<String, dynamic> consent = {};
  bool enabled = false;
  bool busy = false;
  bool _disposed = false;
  String? error;
  String? _startKey;

  void beginAttempt() {
    _startKey = null;
  }

  Future<bool> _run(
    Future<Map<String, dynamic>> Function() operation, {
    bool config = false,
  }) async {
    if (busy || _disposed) return false;
    busy = true;
    error = null;
    notifyListeners();
    try {
      final data = await operation();
      if (_disposed) return false;
      verification = KycVerification.fromJson(data);
      if (config) {
        enabled = data['enabled'] == true;
        documents = Map<String, dynamic>.from(data['documents'] as Map? ?? {});
        consent = Map<String, dynamic>.from(data['consent'] as Map? ?? {});
      }
      return true;
    } on AppFailure catch (failure) {
      error = failure.message;
      return false;
    } catch (_) {
      error = 'Verification is temporarily unavailable. Please try again.';
      return false;
    } finally {
      busy = false;
      if (!_disposed) notifyListeners();
    }
  }

  Future<bool> refresh() => _run(repository.status, config: true);

  Future<bool> start(String document, Map<String, dynamic> personal) {
    _startKey ??= UlidGenerator().next();
    return _run(
      () => repository.start({
        'idempotency_key': _startKey,
        'consent': true,
        'consent_version': consent['version'],
        'document_type': document,
        'personal': personal,
      }, resubmit: verification?.status.canResubmit ?? false),
    );
  }

  Future<bool> upload(String kind, Uint8List bytes) =>
      _run(() => repository.upload(verification!.id!, kind, bytes));

  Future<bool> submit() => _run(() => repository.submit(verification!.id!));

  Future<bool> cancel() async {
    final success = await _run(() => repository.cancel(verification!.id!));
    if (success) _startKey = null;
    return success;
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
