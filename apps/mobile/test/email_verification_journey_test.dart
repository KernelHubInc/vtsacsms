import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/core/errors/app_failure.dart';
import 'package:vtsa_mobile/core/storage/token_store.dart';
import 'package:vtsa_mobile/features/auth/application/auth_controller.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';

import 'support/fakes.dart';

const unverifiedUser = UserProfile(
  id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0M',
  name: 'Ada Driver',
  email: 'ada@example.test',
  emailVerified: false,
);

void main() {
  test('nested API errors preserve the verification code', () {
    final request = RequestOptions(path: '/api/v1/me');
    final failure = AppFailure.fromDio(
      DioException(
        requestOptions: request,
        response: Response(
          requestOptions: request,
          statusCode: 403,
          data: {
            'error': {
              'code': 'email_unverified',
              'message': 'Email verification is required.',
              'correlation_id': 'request-test',
            },
          },
        ),
      ),
    );
    expect(failure.code, 'email_unverified');
    expect(failure.correlationId, 'request-test');
    expect(failure.kind, FailureKind.forbidden);
  });

  test('restoring an unverified session preserves access to resend', () async {
    final fixture = await buildTestDependencies();
    fixture.auth.user = unverifiedUser;
    await fixture.dependencies.auth.login(
      email: 'ada@example.test',
      password: 'test-only',
    );
    await fixture.dependencies.auth.restore();
    expect(fixture.dependencies.auth.status, AuthStatus.authenticated);
    expect(fixture.dependencies.auth.needsEmailVerification, isTrue);
    expect(await fixture.dependencies.auth.resendVerification(), isTrue);
    expect(fixture.auth.verificationEmailsSent, 1);
    expect(fixture.dependencies.auth.needsEmailVerification, isTrue);
  });

  for (final failure in [
    const AppFailure(
      kind: FailureKind.forbidden,
      code: 'email_unverified',
      message: 'Email verification is required.',
    ),
    const AppFailure(kind: FailureKind.offline, message: 'Offline'),
    const AppFailure(kind: FailureKind.timeout, message: 'Timed out'),
  ]) {
    test('cold restore keeps verification gated for ${failure.kind}', () async {
      final repository = FakeAuthRepository(signedIn: true)
        ..currentUserFailure = failure;
      final tokens = MemoryTokenStore();
      final session = await repository.login(
        email: 'ada@example.test',
        password: 'test-only',
      );
      await tokens.write(session.tokens);
      final auth = AuthController(repository: repository, tokens: tokens);
      addTearDown(auth.dispose);

      await auth.restore();
      expect(auth.status, AuthStatus.authenticated);
      expect(auth.needsEmailVerification, isTrue);
      expect(await tokens.read(), isNotNull);
      expect(await auth.refreshVerification(), isFalse);
      expect(auth.needsEmailVerification, isTrue);

      repository.currentUserFailure = null;
      expect(await auth.refreshVerification(), isTrue);
      expect(auth.needsEmailVerification, isFalse);
    });
  }

  test('an expired verification session clears its token', () async {
    final repository = FakeAuthRepository()..user = unverifiedUser;
    final tokens = MemoryTokenStore();
    final auth = AuthController(repository: repository, tokens: tokens);
    addTearDown(auth.dispose);
    await auth.login(email: 'ada@example.test', password: 'test-only');
    repository.currentUserFailure = const AppFailure(
      kind: FailureKind.unauthenticated,
      message: 'Please sign in again.',
    );

    expect(await auth.refreshVerification(), isFalse);
    expect(auth.status, AuthStatus.guest);
    expect(auth.user, isNull);
    expect(await tokens.read(), isNull);
  });

  testWidgets('unverified login supports resend and checks before continuing', (
    tester,
  ) async {
    final fixture = await buildTestDependencies();
    final verifiedUser = fixture.auth.user;
    fixture.auth.user = unverifiedUser;
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    expect(find.text('Sign in to your drive.'), findsOneWidget);
    await tester.enterText(
      find.widgetWithText(TextField, 'Email address (required)'),
      'ada@example.test',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Password (required)'),
      'test-only-password',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pumpAndSettle();
    expect(find.text('One tap from verified.'), findsOneWidget);
    await tester.tap(find.text('Resend verification email'));
    await tester.pumpAndSettle();
    expect(fixture.auth.verificationEmailsSent, 1);
    await tester.tap(find.text("I've verified my email"));
    await tester.pumpAndSettle();
    expect(find.text('Email verification is required.'), findsOneWidget);
    expect(find.text('One tap from verified.'), findsOneWidget);
    fixture.auth.user = verifiedUser;
    await tester.tap(find.text("I've verified my email"));
    await tester.pumpAndSettle();
    expect(find.text('Ada Driver'), findsOneWidget);
    expect(fixture.dependencies.auth.user?.emailVerified, isTrue);
  });

  for (final delivered in [true, false]) {
    testWidgets('registration explains email recovery; sent=$delivered', (
      tester,
    ) async {
      final fixture = await buildTestDependencies();
      fixture.auth.verificationEmailSent = delivered;
      await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
      await tester.pumpAndSettle();
      expect(find.text('Sign in to your drive.'), findsOneWidget);
      await tester.tap(find.text('Create an account'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextField, 'Full name (required)'),
        'Ada Driver',
      );
      await tester.enterText(
        find.widgetWithText(TextField, 'Email address (required)'),
        'ada@example.test',
      );
      await tester.enterText(
        find.widgetWithText(TextField, 'Password (required)'),
        'test-only-password',
      );
      await tester.ensureVisible(
        find.widgetWithText(FilledButton, 'Create account'),
      );
      await tester.tap(find.widgetWithText(FilledButton, 'Create account'));
      await tester.pumpAndSettle();
      expect(fixture.auth.registrationRequested, isTrue);
      expect(
        find.text(
          delivered ? 'One tap from verified.' : 'Your account is created.',
        ),
        findsOneWidget,
      );
      if (!delivered) {
        expect(
          find.textContaining('We could not send your verification email.'),
          findsOneWidget,
        );
        expect(find.text('Could not create account'), findsNothing);
        expect(find.text('Explore'), findsNothing);
      }
      expect(find.text('Sign in to continue'), findsOneWidget);
      await tester.tap(find.text('Sign in to continue'));
      await tester.pumpAndSettle();
      expect(find.text('Sign in to your drive.'), findsOneWidget);
    });
  }
}
