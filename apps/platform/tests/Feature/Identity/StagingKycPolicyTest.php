<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Http\Controllers\StagingKycPolicyController;
use App\Providers\AppServiceProvider;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StagingKycPolicyTest extends TestCase
{
    #[DataProvider('stagingEnvironments')]
    public function test_notices_are_public_before_kyc_is_enabled(string $environment): void
    {
        $this->app->instance('env', $environment);
        config()->set(['kyc.enabled' => false, 'kyc.retention_days' => 7]);

        foreach (['privacy', 'terms', 'consent'] as $page) {
            $this->get('/staging/kyc/'.$page)->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertSee('STAGING ONLY')->assertSee(StagingKycPolicyController::VERSION);
        }

        $this->get('/staging/kyc/privacy')->assertSee('7 days')->assertSee('Background cleanup');
        $this->get('/staging/kyc/consent')->assertSee(StagingKycPolicyController::CONSENT);
        $this->get('/staging/kyc/unknown')->assertNotFound();
    }

    /** @return list<array{string}> */
    public static function stagingEnvironments(): array
    {
        return [['staging'], ['demo']];
    }

    public function test_staging_notices_are_not_served_in_production(): void
    {
        $this->app->instance('env', 'production');
        foreach (['privacy', 'terms', 'consent'] as $page) {
            $this->get('/staging/kyc/'.$page)->assertNotFound();
        }
    }

    public function test_enabled_staging_can_boot_with_the_test_notices(): void
    {
        $this->configureKyc('demo');
        (new AppServiceProvider($this->app))->boot();
        $this->get('/staging/kyc/consent')->assertOk();
    }

    #[DataProvider('invalidConfigurations')]
    public function test_startup_rejects_incomplete_or_misused_test_consent(string $environment, string $key, mixed $value): void
    {
        $this->configureKyc($environment);
        config()->set('kyc.'.$key, $value);
        $this->expectException(LogicException::class);
        (new AppServiceProvider($this->app))->boot();
    }

    /** @return array<string, array{string, string, string|bool}> */
    public static function invalidConfigurations(): array
    {
        return [
            'missing privacy URL' => ['demo', 'privacy_url', ''],
            'missing terms URL' => ['demo', 'terms_url', ''],
            'missing consent URL' => ['demo', 'consent_url', ''],
            'development consent' => ['demo', 'consent_version', 'development-v1'],
            'empty consent' => ['demo', 'consent_version', ''],
            'automatic approval' => ['staging', 'automatic_verification_enabled', true],
            'production reuse' => ['production', 'automatic_verification_enabled', false],
        ];
    }

    private function configureKyc(string $environment): void
    {
        $this->app->instance('env', $environment);
        config()->set([
            'features.demo_mode' => false,
            'kyc.enabled' => true,
            'kyc.automatic_verification_enabled' => false,
            'kyc.request_secret' => str_repeat('a', 32),
            'kyc.callback_secret' => str_repeat('b', 32),
            'kyc.url' => 'https://kyc.example.test:8443',
            'kyc.privacy_url' => 'https://app.example.test/staging/kyc/privacy',
            'kyc.terms_url' => 'https://app.example.test/staging/kyc/terms',
            'kyc.consent_url' => 'https://app.example.test/staging/kyc/consent',
            'kyc.consent_version' => StagingKycPolicyController::VERSION,
        ]);
    }
}
