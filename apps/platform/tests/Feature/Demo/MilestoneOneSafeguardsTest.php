<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Foundation\Features\Feature;
use App\Foundation\Features\FeatureFlags;
use App\Http\Middleware\RequireEnabledFeature;
use Database\Seeders\MilestoneOneDemoSeeder;
use Illuminate\Http\Request;
use LogicException;
use Tests\TestCase;

final class MilestoneOneSafeguardsTest extends TestCase
{
    public function test_demo_seeder_requires_the_central_demo_flag(): void
    {
        config()->set('features.demo_mode', false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FEATURE_DEMO_MODE=true');

        app(MilestoneOneDemoSeeder::class)->run(app(FeatureFlags::class));
    }

    public function test_disabled_milestone_two_feature_returns_a_safe_machine_readable_response(): void
    {
        config()->set('features.remote_charging', false);
        $request = Request::create('/api/v1/charging-sessions/remote-start', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->attributes->set('correlation_id', '01J0000000VTSADEMA00000001');

        $response = app(RequireEnabledFeature::class)->handle(
            $request,
            fn () => response()->json(['unsafe' => true]),
            Feature::RemoteCharging->value,
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString(FeatureFlags::MILESTONE_TWO_MESSAGE, (string) $response->getContent());
        self::assertStringNotContainsString('unsafe', (string) $response->getContent());
    }

    public function test_production_boot_refuses_demo_mode(): void
    {
        $original = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');
        config()->set('features.demo_mode', true);

        try {
            $this->expectException(LogicException::class);
            app(FeatureFlags::class)->assertProductionSafe();
        } finally {
            app()->detectEnvironment(static fn (): string => $original);
        }
    }

    public function test_demo_accounts_use_local_domains_and_are_removed_from_the_production_runtime(): void
    {
        foreach (array_keys(MilestoneOneDemoSeeder::ACCOUNTS) as $email) {
            self::assertStringEndsWith('.local', $email);
        }

        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
        self::assertStringContainsString(
            'rm -f database/seeders/MilestoneOneDemoSeeder.php',
            $dockerfile,
        );

        $registrationController = (string) file_get_contents(
            app_path('Http/Controllers/Api/V1/RegistrationController.php'),
        );
        self::assertStringNotContainsString('Database\\Seeders', $registrationController);
    }
}
