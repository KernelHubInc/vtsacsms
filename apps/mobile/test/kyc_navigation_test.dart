import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vtsa_mobile/app/vtsa_app.dart';
import 'package:vtsa_mobile/features/kyc/presentation/kyc_screen.dart';

import 'kyc_test.dart' show FakeKycRepository;
import 'support/fakes.dart';

void main() {
  testWidgets('verified driver opens KYC from the account screen', (
    tester,
  ) async {
    final repository = FakeKycRepository();
    final fixture = await buildTestDependencies(
      signedIn: true,
      kycRepository: repository,
    );
    await tester.pumpWidget(VtsaApp(dependencies: fixture.dependencies));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Account'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Identity verification'));
    await tester.pumpAndSettle();

    expect(find.byType(KycScreen), findsOneWidget);
    expect(find.text('Get started'), findsOneWidget);
    final screen = tester.widget<KycScreen>(find.byType(KycScreen));
    expect(screen.repository, same(repository));
    expect(screen.name, 'Ada Driver');
    final router = GoRouter.of(tester.element(find.byType(Scaffold).first));
    expect(router.state.uri.path, '/kyc');

    await fixture.dependencies.auth.logout();
    await tester.pumpAndSettle();
    expect(find.byType(KycScreen), findsNothing);
    expect(find.text('Sign in to your drive.'), findsOneWidget);
  });
}
