<?php

declare(strict_types=1);

namespace Tests\Feature\Maps;

use App\Modules\Integrations\Application\MapConfigurationResolver;
use App\Modules\Integrations\Domain\MapProvider;
use App\Modules\Integrations\Domain\MapSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class MapConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(MapConfigurationResolver::class)->forget();
    }

    public function test_openstreetmap_is_the_default_for_every_surface(): void
    {
        $resolver = app(MapConfigurationResolver::class);

        foreach (MapSurface::cases() as $surface) {
            $configuration = $resolver->forSurface($surface);
            $this->assertSame(MapProvider::OpenStreetMap, $configuration->provider);
            $this->assertFalse($configuration->fallbackActive);
        }
    }

    public function test_invalid_provider_logs_warning_and_uses_openstreetmap(): void
    {
        $warning = null;
        Log::listen(function (MessageLogged $event) use (&$warning): void {
            if ($event->level === 'warning') {
                $warning = $event;
            }
        });
        config()->set('maps.providers.public', 'invalid-provider');

        $configuration = app(MapConfigurationResolver::class)->forSurface(MapSurface::Public);

        $this->assertSame(MapProvider::OpenStreetMap, $configuration->provider);
        $this->assertInstanceOf(MessageLogged::class, $warning);
        $this->assertSame('Unknown map provider; OpenStreetMap selected.', $warning->message);
        $this->assertSame(MapSurface::Public->value, $warning->context['surface']);
    }

    public function test_surface_specific_google_provider_resolves_when_ready(): void
    {
        config()->set([
            'maps.providers.admin' => 'google',
            'maps.providers.public' => 'openstreetmap',
            'maps.google.browser_api_key' => 'browser-restricted-test-key',
        ]);

        $resolver = app(MapConfigurationResolver::class);

        $this->assertSame(MapProvider::Google, $resolver->forSurface(MapSurface::Admin)->provider);
        $this->assertSame(MapProvider::OpenStreetMap, $resolver->forSurface(MapSurface::Public)->provider);
    }

    public function test_google_without_readiness_falls_back_without_throwing(): void
    {
        Log::spy();
        config()->set([
            'maps.providers.operator' => 'google',
            'maps.google.browser_api_key' => '',
        ]);

        $configuration = app(MapConfigurationResolver::class)->forSurface(MapSurface::Operator);

        $this->assertSame(MapProvider::Google, $configuration->requestedProvider);
        $this->assertSame(MapProvider::OpenStreetMap, $configuration->provider);
        $this->assertTrue($configuration->fallbackActive);
        $this->assertSame('google_not_configured', $configuration->status);
    }

    public function test_mobile_configuration_endpoint_never_returns_google_credentials(): void
    {
        config()->set([
            'maps.providers.mobile' => 'google',
            'maps.google.mobile_ready' => true,
            'maps.google.browser_api_key' => 'must-not-leak',
            'services.google_maps.browser_key' => 'legacy-must-not-leak',
        ]);

        $response = $this->getJson('/api/v1/app/config')
            ->assertOk()
            ->assertJsonPath('data.map.provider', 'google')
            ->assertJsonPath('data.map.defaultLatitude', 14.5995)
            ->assertJsonPath('data.map.clusteringEnabled', true);

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('must-not-leak', $payload);
        $this->assertStringNotContainsString('googleBrowserApiKey', $payload);
        $this->assertStringNotContainsString('googleMapsApiKey', $payload);
    }
}
