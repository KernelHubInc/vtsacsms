import 'package:google_sign_in/google_sign_in.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/features/auth/domain/google_auth.dart';

final class NativeGoogleIdentity extends GoogleIdentityProvider {
  NativeGoogleIdentity(this.serverClientId, {this.clientId});

  final String serverClientId;
  final String? clientId;
  Future<void>? _initialization;

  Future<void> _initialize(GoogleSignIn google) async {
    try {
      await (_initialization ??= google.initialize(
        clientId: clientId,
        serverClientId: serverClientId,
      ));
    } on Object {
      _initialization = null;
      rethrow;
    }
  }

  @override
  Future<String?> authenticate() async {
    try {
      final google = GoogleSignIn.instance;
      await _initialize(google);
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
