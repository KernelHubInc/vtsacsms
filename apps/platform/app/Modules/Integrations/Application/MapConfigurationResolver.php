<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application;

use App\Modules\Integrations\Domain\MapProvider;
use App\Modules\Integrations\Domain\MapSurface;
use App\Modules\Integrations\Domain\Models\MapSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class MapConfigurationResolver
{
    private const CACHE_KEY = 'maps:platform-settings:v1';

    public function forSurface(MapSurface $surface): MapConfiguration
    {
        $settings = $this->settings();
        $configured = $this->setting($settings, $surface->settingsColumn())
            ?? config("maps.providers.{$surface->value}")
            ?? config('maps.providers.default', MapProvider::OpenStreetMap->value);
        $requested = $this->provider((string) $configured, $surface);
        $googleBrowserReady = trim((string) config('maps.google.browser_api_key', '')) !== '';
        $googleMobileReady = (bool) config('maps.google.mobile_ready', false);
        $googleReady = $surface === MapSurface::Mobile ? $googleMobileReady : $googleBrowserReady;
        $provider = $requested;
        $fallbackActive = false;
        $status = 'ready';

        if ($requested === MapProvider::Google && ! $googleReady) {
            $provider = MapProvider::OpenStreetMap;
            $fallbackActive = true;
            $status = 'google_not_configured';
            Log::warning('Map provider fallback active.', [
                'requested_provider' => $requested->value,
                'resolved_provider' => $provider->value,
                'surface' => $surface->value,
                'reason' => $status,
            ]);
        }

        $minimumZoom = $this->integer($settings, 'minimum_zoom', 'maps.viewport.minimum_zoom');
        $maximumZoom = $this->integer($settings, 'maximum_zoom', 'maps.viewport.maximum_zoom');
        $defaultZoom = max($minimumZoom, min(
            $maximumZoom,
            $this->integer($settings, 'default_zoom', 'maps.viewport.zoom'),
        ));

        return new MapConfiguration(
            surface: $surface,
            requestedProvider: $requested,
            provider: $provider,
            fallbackActive: $fallbackActive,
            status: $status,
            defaultLatitude: $this->float($settings, 'default_latitude', 'maps.viewport.latitude'),
            defaultLongitude: $this->float($settings, 'default_longitude', 'maps.viewport.longitude'),
            defaultZoom: $defaultZoom,
            minimumZoom: $minimumZoom,
            maximumZoom: $maximumZoom,
            tileUrlTemplate: $this->string($settings, 'tile_url_template', 'maps.tiles.url_template'),
            tileAttribution: $this->string($settings, 'tile_attribution', 'maps.tiles.attribution'),
            tileSubdomains: array_values(config('maps.tiles.subdomains', [])),
            tileMaximumNativeZoom: $this->integer($settings, 'tile_maximum_native_zoom', 'maps.tiles.maximum_native_zoom'),
            tileRetina: (bool) config('maps.tiles.retina', false),
            tileRequestTimeoutSeconds: (int) config('maps.tiles.request_timeout_seconds', 10),
            clusteringEnabled: $this->boolean($settings, 'clustering_enabled', 'maps.clustering_enabled'),
            googleBrowserReady: $googleBrowserReady,
            googleMobileReady: $googleMobileReady,
            googleBrowserApiKey: (string) config('maps.google.browser_api_key', ''),
            googleMapId: (string) config('maps.google.map_id', ''),
            directionsUrlTemplate: (string) config('maps.directions.web_url_template'),
        );
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed>|null */
    public function rawSettings(): ?array
    {
        return $this->settings();
    }

    /** @return array<string, mixed>|null */
    private function settings(): ?array
    {
        if (! Schema::hasTable('map_settings')) {
            return null;
        }

        return Cache::remember(
            self::CACHE_KEY,
            (int) config('maps.settings_cache_seconds', 300),
            fn (): ?array => MapSetting::query()->where('scope', 'platform')->first()?->toArray(),
        );
    }

    private function provider(string $value, MapSurface $surface): MapProvider
    {
        $provider = MapProvider::tryFrom(mb_strtolower(trim($value)));

        if ($provider !== null) {
            return $provider;
        }

        Log::warning('Unknown map provider; OpenStreetMap selected.', [
            'configured_provider' => $value,
            'surface' => $surface->value,
        ]);

        return MapProvider::OpenStreetMap;
    }

    /** @param array<string, mixed>|null $settings */
    private function setting(?array $settings, string $key): mixed
    {
        return $settings[$key] ?? null;
    }

    /** @param array<string, mixed>|null $settings */
    private function string(?array $settings, string $key, string $config): string
    {
        return (string) ($this->setting($settings, $key) ?? config($config));
    }

    /** @param array<string, mixed>|null $settings */
    private function integer(?array $settings, string $key, string $config): int
    {
        return (int) ($this->setting($settings, $key) ?? config($config));
    }

    /** @param array<string, mixed>|null $settings */
    private function float(?array $settings, string $key, string $config): float
    {
        return (float) ($this->setting($settings, $key) ?? config($config));
    }

    /** @param array<string, mixed>|null $settings */
    private function boolean(?array $settings, string $key, string $config): bool
    {
        return (bool) ($this->setting($settings, $key) ?? config($config));
    }
}
