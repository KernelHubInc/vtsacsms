import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';

typedef MobileAuthSession = ({TokenBundle tokens, UserProfile user});

abstract interface class GoogleIdentityProvider {
  Future<String?> authenticate();
}

abstract interface class GoogleAuthRepository {
  Future<GoogleLoginResult> signInWithGoogle(
    String idToken, {
    String? password,
  });
  Future<MobileAuthSession> registerWithGoogle(
    String idToken,
    Map<String, Object?> profile,
  );
}

final class GoogleProfile {
  const GoogleProfile({
    required this.email,
    required this.firstName,
    required this.lastName,
  });

  final String email;
  final String firstName;
  final String lastName;
}

final class GoogleLoginResult {
  const GoogleLoginResult.signedIn(MobileAuthSession value)
    : session = value,
      profile = null;
  const GoogleLoginResult.registration(GoogleProfile value)
    : session = null,
      profile = value;

  final MobileAuthSession? session;
  final GoogleProfile? profile;
}
