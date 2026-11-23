import 'package:google_sign_in/google_sign_in.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/features/auth/domain/google_auth.dart';

GoogleIdentityProvider createBrowserGoogleIdentity(String clientId) =>
    BrowserGoogleIdentity(clientId);

final class BrowserGoogleIdentity extends GoogleIdentityProvider {
  BrowserGoogleIdentity(this.clientId);

  final String clientId;
  Future<void>? _initialization;

  @override
  bool get usesBrowserButton => true;

  Future<void> initialize() async {
    try {
      await (_initialization ??= GoogleSignIn.instance.initialize(
        clientId: clientId,
      ));
    } on Object {
      _initialization = null;
      rethrow;
    }
  }

  @override
  Future<String?> authenticate() async => throw const AppFailure(
    kind: FailureKind.server,
    message: 'Choose your account using the Google sign-in button.',
  );
}
