import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/domain/google_auth.dart';

import 'support/fakes.dart';
import 'support/registration_steps.dart';

class TestGoogleProvider implements GoogleIdentityProvider {
  String? token = 'google-test-id-token';
  int calls = 0;
  Completer<String?>? pending;
  AppFailure? failure;

  @override
  Future<String?> authenticate() async {
    calls++;
    if (failure != null) throw failure!;
    return pending == null ? token : pending!.future;
  }
}

class TestGoogleRepository extends FakeAuthRepository
    implements GoogleAuthRepository {
  GoogleProfile? profile;
  bool linkRequired = false;
  AppFailure? failure;
  Map<String, Object?>? registrationProfile;
  String? receivedToken;
  int loginCalls = 0;

  @override
  Future<GoogleLoginResult> signInWithGoogle(
    String idToken, {
    String? password,
  }) async {
    loginCalls++;
    receivedToken = idToken;
    if (failure != null) throw failure!;
    if (linkRequired && password != 'ExistingPass1!') {
      throw AppFailure(
        kind: FailureKind.unauthenticated,
        code: password == null ? 'google_link_required' : 'invalid_credentials',
        message: 'Confirm your existing password.',
      );
    }
    if (profile != null) return GoogleLoginResult.registration(profile!);
    return GoogleLoginResult.signedIn(
      await login(email: user.email, password: 'test-only'),
    );
  }

  @override
  Future<MobileAuthSession> registerWithGoogle(
    String idToken,
    Map<String, Object?> details,
  ) async {
    receivedToken = idToken;
    registrationProfile = details;
    if (failure != null) throw failure!;
    return login(email: user.email, password: 'test-only');
  }
}

void main() {
  const profile = GoogleProfile(
    email: 'ada@example.test',
    firstName: 'Ada',
    lastName: 'Driver',
  );

  test(
    'Google cancellation keeps a guest session and never calls the API',
    () async {
      final provider = TestGoogleProvider()..token = null;
      final repository = TestGoogleRepository();
      final tokens = MemoryTokenStore();
      final auth = AuthController(
        repository: repository,
        tokens: tokens,
        googleIdentityProvider: provider,
      );
      addTearDown(auth.dispose);
      await auth.restore();
      await auth.signInWithGoogle();
      expect(auth.status, AuthStatus.guest);
      expect(auth.failure, isNull);
      expect(repository.loginCalls, 0);
      expect(await tokens.read(), isNull);
    },
  );

  test(
    'Google session uses server identity and cannot enroll password-only quick unlock',
    () async {
      final repository = TestGoogleRepository();
      final tokens = MemoryTokenStore();
      final auth = AuthController(
        repository: repository,
        tokens: tokens,
        googleIdentityProvider: TestGoogleProvider(),
      );
      addTearDown(auth.dispose);
      await auth.restore();
      await auth.signInWithGoogle();
      expect(auth.status, AuthStatus.authenticated);
      expect(auth.user, repository.user);
      expect(await tokens.read(), isNotNull);
      expect(auth.canConfigureQuickUnlock, isFalse);
      expect(auth.googleRegistration, isNull);
    },
  );

  test('parallel taps start only one Google authentication', () async {
    final provider = TestGoogleProvider()..pending = Completer<String?>();
    final repository = TestGoogleRepository();
    final auth = AuthController(
      repository: repository,
      tokens: MemoryTokenStore(),
      googleIdentityProvider: provider,
    );
    addTearDown(auth.dispose);
    final first = auth.signInWithGoogle();
    await auth.signInWithGoogle();
    expect(provider.calls, 1);
    provider.pending!.complete('google-test-id-token');
    await first;
    expect(repository.loginCalls, 1);
  });

  test(
    'linking keeps Google proof through password retry and clears it on cancel',
    () async {
      final provider = TestGoogleProvider();
      final repository = TestGoogleRepository()..linkRequired = true;
      final auth = AuthController(
        repository: repository,
        tokens: MemoryTokenStore(),
        googleIdentityProvider: provider,
      );
      addTearDown(auth.dispose);
      await auth.restore();
      await auth.signInWithGoogle();
      expect(auth.googleLinkRequired, isTrue);
      await auth.signInWithGoogle(password: 'wrong');
      expect(auth.googleLinkRequired, isTrue);
      expect(auth.status, AuthStatus.guest);
      await auth.signInWithGoogle(password: 'ExistingPass1!');
      expect(auth.status, AuthStatus.authenticated);
      expect(provider.calls, 1);
      expect(auth.googleLinkRequired, isFalse);
      await auth.logout();
      await auth.signInWithGoogle();
      auth.cancelGoogle();
      final calls = repository.loginCalls;
      await auth.signInWithGoogle(password: 'ExistingPass1!');
      expect(repository.loginCalls, calls);
    },
  );

  test(
    'expired registration proof remains recoverable without creating a local session',
    () async {
      final repository = TestGoogleRepository()..profile = profile;
      final tokens = MemoryTokenStore();
      final auth = AuthController(
        repository: repository,
        tokens: tokens,
        googleIdentityProvider: TestGoogleProvider(),
      );
      addTearDown(auth.dispose);
      await auth.restore();
      await auth.signInWithGoogle();
      repository.failure = const AppFailure(
        kind: FailureKind.unauthenticated,
        code: 'google_token_invalid',
        message: 'Choose your Google account again.',
      );
      expect(
        await auth.register(
          name: 'Ada Driver',
          email: '',
          password: '',
          passwordConfirmation: '',
          firstName: 'Ada',
          lastName: 'Driver',
          birthDate: '1990-01-01',
          platePending: true,
          termsAccepted: true,
        ),
        isFalse,
      );
      expect(auth.status, AuthStatus.guest);
      expect(auth.failure?.code, 'google_token_invalid');
      expect(await tokens.read(), isNull);
    },
  );

  testWidgets(
    'Google signup completes profile and vehicle without requesting a password',
    (tester) async {
      final repository = TestGoogleRepository()..profile = profile;
      final fixture = await buildTestDependencies(
        authRepository: repository,
        googleIdentityProvider: TestGoogleProvider(),
      );
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Sign in'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const ValueKey('continue-with-google')),
      );
      await tester.tap(find.byKey(const ValueKey('continue-with-google')));
      await tester.pumpAndSettle();
      expect(find.text('Personal information'), findsOneWidget);
      expect(find.text('Create a password'), findsNothing);
      await fillRegistrationPersonal(tester);
      await pressRegistrationAction(tester, 'Continue');
      await tester.ensureVisible(find.text('Plate pending'));
      await tester.tap(find.text('Plate pending'));
      await tester.pumpAndSettle();
      final agreement = find.widgetWithText(
        CheckboxListTile,
        'I agree to the Terms of service and Privacy policy.',
      );
      await tester.ensureVisible(agreement);
      await tester.tap(agreement);
      await tester.pumpAndSettle();
      await pressRegistrationAction(tester, 'Create account');
      expect(fixture.dependencies.auth.status, AuthStatus.authenticated);
      expect(repository.registrationProfile?['terms_accepted'], isTrue);
      expect(repository.registrationProfile?.containsKey('password'), isFalse);
      expect(repository.receivedToken, 'google-test-id-token');
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('Google sign-in stays hidden without a configured provider', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sign in'));
    await tester.pumpAndSettle();
    expect(find.text('Continue with Google'), findsNothing);
  });

  testWidgets(
    'existing users can confirm the account link in the sign-in screen',
    (tester) async {
      final repository = TestGoogleRepository()..linkRequired = true;
      final fixture = await buildTestDependencies(
        authRepository: repository,
        googleIdentityProvider: TestGoogleProvider(),
      );
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Sign in'));
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const ValueKey('continue-with-google')),
      );
      await tester.tap(find.byKey(const ValueKey('continue-with-google')));
      await tester.pumpAndSettle();
      expect(find.text('Connect your existing account'), findsOneWidget);
      await tester.ensureVisible(
        find.byKey(const ValueKey('google-link-password')),
      );
      await tester.enterText(
        find.byKey(const ValueKey('google-link-password')),
        'ExistingPass1!',
      );
      await tester.ensureVisible(find.text('Connect Google account'));
      await tester.tap(find.text('Connect Google account'));
      await tester.pumpAndSettle();
      expect(fixture.dependencies.auth.status, AuthStatus.authenticated);
      expect(tester.takeException(), isNull);
    },
  );
}
