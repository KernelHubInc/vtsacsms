<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application;

use App\Modules\Integrations\Domain\MapProvider;

final readonly class MapSettingsData
{
    public function __construct(
        public MapProvider $defaultProvider,
        public MapProvider $adminProvider,
        public MapProvider $operatorProvider,
        public MapProvider $userWebProvider,
        public MapProvider $publicProvider,
        public MapProvider $mobileProvider,
        public float $defaultLatitude,
        public float $defaultLongitude,
        public int $defaultZoom,
        public int $minimumZoom,
        public int $maximumZoom,
        public string $tileUrlTemplate,
        public string $tileAttribution,
        public int $tileMaximumNativeZoom,
        public bool $clusteringEnabled,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            defaultProvider: MapProvider::from((string) $data['default_provider']),
            adminProvider: MapProvider::from((string) $data['admin_provider']),
            operatorProvider: MapProvider::from((string) $data['operator_provider']),
            userWebProvider: MapProvider::from((string) $data['user_web_provider']),
            publicProvider: MapProvider::from((string) $data['public_provider']),
            mobileProvider: MapProvider::from((string) $data['mobile_provider']),
            defaultLatitude: (float) $data['default_latitude'],
            defaultLongitude: (float) $data['default_longitude'],
            defaultZoom: (int) $data['default_zoom'],
            minimumZoom: (int) $data['minimum_zoom'],
            maximumZoom: (int) $data['maximum_zoom'],
            tileUrlTemplate: (string) $data['tile_url_template'],
            tileAttribution: (string) $data['tile_attribution'],
            tileMaximumNativeZoom: (int) $data['tile_maximum_native_zoom'],
            clusteringEnabled: filter_var($data['clustering_enabled'], FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * @return array{
     *   default_provider:string,
     *   admin_provider:string,
     *   operator_provider:string,
     *   user_web_provider:string,
     *   public_provider:string,
     *   mobile_provider:string,
     *   default_latitude:float,
     *   default_longitude:float,
     *   default_zoom:int,
     *   minimum_zoom:int,
     *   maximum_zoom:int,
     *   tile_url_template:string,
     *   tile_attribution:string,
     *   tile_maximum_native_zoom:int,
     *   clustering_enabled:bool
     * }
     */
    public function toArray(): array
    {
        return [
            'default_provider' => $this->defaultProvider->value,
            'admin_provider' => $this->adminProvider->value,
            'operator_provider' => $this->operatorProvider->value,
            'user_web_provider' => $this->userWebProvider->value,
            'public_provider' => $this->publicProvider->value,
            'mobile_provider' => $this->mobileProvider->value,
            'default_latitude' => $this->defaultLatitude,
            'default_longitude' => $this->defaultLongitude,
            'default_zoom' => $this->defaultZoom,
            'minimum_zoom' => $this->minimumZoom,
            'maximum_zoom' => $this->maximumZoom,
            'tile_url_template' => $this->tileUrlTemplate,
            'tile_attribution' => $this->tileAttribution,
            'tile_maximum_native_zoom' => $this->tileMaximumNativeZoom,
            'clustering_enabled' => $this->clusteringEnabled,
        ];
    }
}
