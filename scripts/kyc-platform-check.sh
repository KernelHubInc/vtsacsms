#!/bin/sh
set -eu
cd /app
if [ "${KYC_COPY_SOURCE:-0}" = 1 ]; then
    for directory in app bootstrap routes config tests database resources; do
        cp -R "/workspace/apps/platform/$directory/." "/app/$directory/"
    done
fi
export APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
export APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
composer dump-autoload --no-interaction --no-scripts
php vendor/bin/pint app/Modules/Identity/Application/Kyc app/Modules/Identity/Infrastructure/Kyc app/Modules/Identity/Domain/KycStatus.php app/Modules/Identity/Domain/Models/KycVerification.php app/Modules/Identity/Domain/Models/KycSetting.php app/Http/Requests/Api/V1 app/Http/Controllers/Api/V1/KycController.php app/Http/Controllers/Api/V1/KycCallbackController.php app/Http/Middleware/EnsureKycEnabled.php app/Console/Commands/ReconcileKyc.php app/Filament/Platform/Pages/KycVerifications.php app/Filament/Platform/Pages/KycSettings.php config/kyc.php database/migrations/2026_09_23_000001_create_kyc_verifications.php database/migrations/2026_09_24_000001_add_kyc_review_settings.php tests/Feature/Identity routes/api.php routes/console.php app/Modules/Organizations/Domain/PermissionKey.php app/Modules/Payments/Application/PaymentIntentService.php app/Modules/Charging/Application/RemoteStartService.php
check_result=0
if [ "${KYC_FULL_SUITE:-0}" = 1 ]; then
    php vendor/bin/phpunit --do-not-cache-result || check_result=1
else
    php vendor/bin/phpunit --do-not-cache-result --filter Kyc || check_result=1
fi
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress || check_result=1
composer validate --strict || check_result=1
composer audit || check_result=1
exit "$check_result"
