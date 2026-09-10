<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application;

use App\Modules\Integrations\Domain\MapProvider;
use App\Modules\Integrations\Domain\MapSurface;

final readonly class MapConfiguration
{
    /**
     * @param  list<string>  $tileSubdomains
     */
    public function __construct(
        public MapSurface $surface,
        public MapProvider $requestedProvider,
        public MapProvider $provider,
        public bool $fallbackActive,
        public string $status,
        public float $defaultLatitude,
        public float $defaultLongitude,
        public int $defaultZoom,
        public int $minimumZoom,
        public int $maximumZoom,
        public string $tileUrlTemplate,
        public string $tileAttribution,
        public array $tileSubdomains,
        public int $tileMaximumNativeZoom,
        public bool $tileRetina,
        public int $tileRequestTimeoutSeconds,
        public bool $clusteringEnabled,
        public bool $googleBrowserReady,
        public bool $googleMobileReady,
        public string $googleBrowserApiKey,
        public string $googleMapId,
        public string $directionsUrlTemplate,
    ) {}

    /** @return array<string, mixed> */
    public function toWebArray(): array
    {
        return [
            ...$this->toPublicArray(),
            'requestedProvider' => $this->requestedProvider->value,
            'fallbackActive' => $this->fallbackActive,
            'status' => $this->status,
            'googleBrowserApiKey' => $this->provider === MapProvider::Google ? $this->googleBrowserApiKey : '',
            'googleMapId' => $this->provider === MapProvider::Google ? $this->googleMapId : '',
            'directionsUrlTemplate' => $this->directionsUrlTemplate,
        ];
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'defaultLatitude' => $this->defaultLatitude,
            'defaultLongitude' => $this->defaultLongitude,
            'defaultZoom' => $this->defaultZoom,
            'minimumZoom' => $this->minimumZoom,
            'maximumZoom' => $this->maximumZoom,
            'tileUrlTemplate' => $this->tileUrlTemplate,
            'tileAttribution' => $this->tileAttribution,
            'tileSubdomains' => $this->tileSubdomains,
            'tileMaximumNativeZoom' => $this->tileMaximumNativeZoom,
            'tileRetina' => $this->tileRetina,
            'tileRequestTimeoutSeconds' => $this->tileRequestTimeoutSeconds,
            'clusteringEnabled' => $this->clusteringEnabled,
        ];
    }
}
