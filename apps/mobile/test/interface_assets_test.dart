import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test(
    'Figma station pins and status badges are packaged with the app',
    () async {
      for (final name in [
        'pin-available',
        'pin-busy',
        'pin-faulted',
        'pin-offline',
        'pin-stale',
        'pin-unknown',
        'pin-selected',
        'pin-other-operator',
        'status-available',
        'status-busy',
        'status-faulted',
        'status-offline',
        'status-stale',
        'status-unknown',
        'icon-ccs2',
        'icon-type2',
        'icon-chademo',
        'icon-wifi',
        'icon-food',
        'icon-shopping',
        'icon-restroom',
        'icon-accessible',
        'icon-parking',
        'icon-charger',
      ]) {
        final data = await rootBundle.load('assets/interface/$name.png');
        expect(data.lengthInBytes, greaterThan(0), reason: name);
      }
    },
  );
}
