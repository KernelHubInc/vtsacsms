<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\PublicCmsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_foundation_page_is_available(): void
    {
        $this->seed(PublicCmsSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertSee('Power Solutions')
            ->assertDontSee('VTSA CSMS')
            ->assertDontSee('VSTA CSMS');
    }

    public function test_liveness_returns_service_metadata_and_ulid_headers(): void
    {
        $response = $this->getJson('/health/live');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'platform');

        $this->assertMatchesRegularExpression(
            '/\A[0-9A-HJKMNP-TV-Z]{26}\z/',
            (string) $response->headers->get('X-Request-ID'),
        );
        $this->assertSame(
            $response->headers->get('X-Request-ID'),
            $response->headers->get('X-Correlation-ID'),
        );
    }

    public function test_readiness_checks_the_database_connection(): void
    {
        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', 'ok');
    }

    public function test_valid_request_and_correlation_ids_are_propagated(): void
    {
        $requestId = '01K0M0JJ5X0M0JJ5X0M0JJ5X0M';
        $correlationId = '01K0M0KK6Y0M0KK6Y0M0KK6Y0M';

        $this->withHeaders([
            'X-Request-ID' => $requestId,
            'X-Correlation-ID' => $correlationId,
        ])->getJson('/health/live')
            ->assertOk()
            ->assertHeader('X-Request-ID', $requestId)
            ->assertHeader('X-Correlation-ID', $correlationId)
            ->assertJsonPath('request_id', $requestId)
            ->assertJsonPath('correlation_id', $correlationId);
    }

    public function test_filament_admin_panel_is_bootstrapped(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('power-solutions-logo-horizontal', false)
            ->assertDontSee('VTSA Platform Administration');
    }

    public function test_power_solutions_assets_and_tokens_are_centralized(): void
    {
        $this->assertFileExists(public_path('branding/power-solutions-logo-horizontal.svg'));
        $this->assertFileExists(public_path('branding/power-solutions-logo-horizontal-dark.svg'));
        $this->assertFileExists(public_path('branding/power-solutions-email-logo.png'));
        $this->assertFileExists(public_path('branding/power-solutions-favicon.png'));

        $tokens = file_get_contents(resource_path('css/tokens.css'));

        $this->assertIsString($tokens);
        $this->assertStringContainsString('--ps-brand-navy: #12366b', $tokens);
        $this->assertStringContainsString('--ps-brand-cyan: #45cfc7', $tokens);
        $this->assertStringContainsString('--ps-focus-ring: #45cfc7', $tokens);
    }
}
