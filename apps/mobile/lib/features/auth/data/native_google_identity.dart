import 'package:google_sign_in/google_sign_in.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/features/auth/domain/google_auth.dart';

final class NativeGoogleIdentity implements GoogleIdentityProvider {
  NativeGoogleIdentity(this.serverClientId);

  final String serverClientId;
  Future<void>? _initialization;

  @override
  Future<String?> authenticate() async {
    try {
      final google = GoogleSignIn.instance;
      await (_initialization ??= google.initialize(
        serverClientId: serverClientId,
      ));
      final account = await google.authenticate();
      final token = account.authentication.idToken;
      if (token == null || token.isEmpty) {
        throw const AppFailure(
          kind: FailureKind.server,
          message: 'Google could not verify this account. Please try again.',
        );
      }
      return token;
    } on GoogleSignInException catch (error) {
      if (error.code == GoogleSignInExceptionCode.canceled) return null;
      throw const AppFailure(
        kind: FailureKind.server,
        message:
            'Google sign-in is unavailable. Please try again or use your email and password.',
      );
    }
  }
}
