import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';

import 'support/fakes.dart';

void main() {
  test(
    'offline sign-out clears local credentials without an uncaught error',
    () async {
      final repository = FakeAuthRepository()
        ..logoutFailure = const AppFailure(
          kind: FailureKind.offline,
          message: 'Offline',
        );
      final tokens = MemoryTokenStore();
      final auth = AuthController(repository: repository, tokens: tokens);
      addTearDown(auth.dispose);
      await auth.login(
        email: 'driver@example.test',
        password: 'fixture-password',
      );
      await expectLater(auth.logout(), completes);
      expect(auth.status, AuthStatus.guest);
      expect(await tokens.read(), isNull);
      expect(
        auth.failure?.message,
        contains('server sign-out could not be confirmed'),
      );
    },
  );
}
