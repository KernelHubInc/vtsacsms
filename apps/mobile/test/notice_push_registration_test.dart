import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/features/auth/domain/user_profile.dart';
import 'package:vtsa_mobile/features/notices/notice_push_registration.dart';

import 'support/fakes.dart';

final class RecordingNoticePushRegistration implements NoticePushRegistration {
  int registrations = 0;
  int unregistrations = 0;

  @override
  Future<void> register() async {
    registrations++;
  }

  @override
  Future<void> unregister() async {
    unregistrations++;
  }

  @override
  void dispose() {}
}

void main() {
  test(
    'verified sign-in registers for notices and sign-out unregisters',
    () async {
      final push = RecordingNoticePushRegistration();
      final fixture = await buildTestDependencies(noticePush: push);
      addTearDown(fixture.dependencies.dispose);

      expect(push.registrations, 0);
      await fixture.dependencies.auth.login(
        email: 'ada@example.test',
        password: 'test-only',
      );
      await Future<void>.delayed(Duration.zero);
      expect(push.registrations, 1);

      await fixture.dependencies.auth.logout();
      await Future<void>.delayed(Duration.zero);
      expect(push.unregistrations, greaterThanOrEqualTo(1));
    },
  );
  test('unverified sign-in does not register for notifications', () async {
    final push = RecordingNoticePushRegistration();
    final fixture = await buildTestDependencies(noticePush: push);
    addTearDown(fixture.dependencies.dispose);
    fixture.auth.user = const UserProfile(
      id: '01K0M0JJ5X0M0JJ5X0M0JJ5X0M',
      name: 'Ada Driver',
      email: 'ada@example.test',
      emailVerified: false,
    );

    await fixture.dependencies.auth.login(
      email: 'ada@example.test',
      password: 'test-only',
    );
    await Future<void>.delayed(Duration.zero);

    expect(push.registrations, 0);
  });
}
