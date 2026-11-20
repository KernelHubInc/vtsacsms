import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vtsa_mobile/app/app_dependencies.dart';
import 'package:vtsa_mobile/core/config/app_environment.dart';

void main() {
  test('iOS chooses the matching environment project', () {
    expect(
      noticeFirebaseProject(
        environment: AppEnvironmentName.production,
        platform: TargetPlatform.iOS,
      ),
      'evcs-power-solutions',
    );
    expect(
      noticeFirebaseProject(
        environment: AppEnvironmentName.staging,
        platform: TargetPlatform.iOS,
      ),
      'evcs-power-solutions-staging',
    );
  });

  test('production Android selects the production Firebase project', () {
    expect(
      noticeFirebaseProject(
        environment: AppEnvironmentName.production,
        platform: TargetPlatform.android,
      ),
      'evcs-power-solutions',
    );
  });

  test(
    'staging Android and the staging website select the staging project',
    () {
      for (final host in [null, 'staging.evcspowersolutions.com']) {
        expect(
          noticeFirebaseProject(
            environment: AppEnvironmentName.staging,
            platform: TargetPlatform.android,
            browserHost: host,
          ),
          'evcs-power-solutions-staging',
        );
      }
    },
  );

  test('unsupported clients cannot register for push', () {
    expect(
      noticeFirebaseProject(
        environment: AppEnvironmentName.production,
        platform: TargetPlatform.android,
        browserHost: 'evcspowersolutions.com',
      ),
      isNull,
    );
    expect(
      noticeFirebaseProject(
        environment: AppEnvironmentName.staging,
        platform: TargetPlatform.android,
        browserHost: 'evcspowersolutions.com',
      ),
      isNull,
    );
    expect(
      noticeFirebaseProject(
        environment: AppEnvironmentName.production,
        platform: TargetPlatform.linux,
      ),
      isNull,
    );
    expect(
      noticeFirebaseProject(
        environment: AppEnvironmentName.local,
        platform: TargetPlatform.android,
      ),
      isNull,
    );
  });
}
