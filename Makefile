.PHONY: demo-up demo-status demo-test demo-reset demo-logs demo-down demo-verify mobile-web mobile-android mobile-ios maps-test maps-openstreetmap-test maps-google-contract-test

demo-up:
	powershell -NoProfile -ExecutionPolicy Bypass -File scripts/demo-up.ps1

demo-status:
	powershell -NoProfile -ExecutionPolicy Bypass -File scripts/demo-status.ps1

demo-test:
	powershell -NoProfile -ExecutionPolicy Bypass -File scripts/demo-test.ps1

demo-reset:
	powershell -NoProfile -ExecutionPolicy Bypass -File scripts/demo-reset.ps1

demo-logs:
	powershell -NoProfile -ExecutionPolicy Bypass -File scripts/demo-logs.ps1

demo-down:
	powershell -NoProfile -ExecutionPolicy Bypass -File scripts/demo-down.ps1

demo-verify:
	powershell -NoProfile -ExecutionPolicy Bypass -File scripts/demo-verify.ps1

mobile-web:
	cd apps/mobile && flutter run -d chrome --dart-define=APP_ENVIRONMENT=local --dart-define=API_BASE_URL=http://localhost:8000 --dart-define=DEFAULT_TENANT_ID=01J0000000VTSADEMA00000000 --dart-define=MAP_PROVIDER=openstreetmap

mobile-android:
	cd apps/mobile && flutter run --dart-define=APP_ENVIRONMENT=local --dart-define=API_BASE_URL=http://10.0.2.2:8000 --dart-define=DEFAULT_TENANT_ID=01J0000000VTSADEMA00000000 --dart-define=MAP_PROVIDER=openstreetmap

mobile-ios:
	cd apps/mobile && flutter run -d ios --dart-define=APP_ENVIRONMENT=local --dart-define=API_BASE_URL=http://127.0.0.1:8000 --dart-define=DEFAULT_TENANT_ID=01J0000000VTSADEMA00000000 --dart-define=MAP_PROVIDER=openstreetmap

maps-test:
	cd apps/platform && npm run test:maps
	cd apps/platform && php artisan test tests/Feature/Maps
	cd apps/mobile && flutter test test/map_configuration_test.dart test/station_map_test.dart test/station_marker_mapper_test.dart test/openstreetmap_station_map_test.dart

maps-openstreetmap-test:
	cd apps/platform && npm run test:maps
	cd apps/mobile && flutter test test/openstreetmap_station_map_test.dart

maps-google-contract-test:
	cd apps/platform && npm run test:maps
	cd apps/mobile && flutter test test/station_map_test.dart --plain-name "ready Google selection builds only the Google adapter"
