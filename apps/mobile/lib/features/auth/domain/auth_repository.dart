import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';

abstract interface class AuthRepository {
  Future<({TokenBundle tokens, UserProfile user})> login({
    required String email,
    required String password,
  });
  Future<RegistrationResult> register({
    required String name,
    required String email,
    required String password,
  });
  Future<void> requestPasswordReset(String email);
  Future<void> resendEmailVerification();
  Future<UserProfile> currentUser();
  Future<void> logout();
}

final class RegistrationResult {
  const RegistrationResult({required this.verificationEmailSent});

  final bool verificationEmailSent;
}
