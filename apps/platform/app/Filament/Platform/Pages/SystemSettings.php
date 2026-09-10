<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Models\User;
use App\Modules\Integrations\Application\MapConfigurationResolver;
use App\Modules\Integrations\Application\MapSettingsData;
use App\Modules\Integrations\Application\MapSettingsManager;
use App\Modules\Integrations\Domain\MapProvider;
use App\Modules\Integrations\Domain\MapSurface;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

final class SystemSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Platform governance';

    protected static ?string $navigationLabel = 'System settings';

    protected string $view = 'filament.platform.pages.system-settings';

    /** @var array<string, mixed> */
    public array $mapSettings = [];

    public function mount(MapConfigurationResolver $maps): void
    {
        $default = $maps->forSurface(MapSurface::Default);
        $raw = $maps->rawSettings() ?? [];

        $this->mapSettings = [
            'default_provider' => $raw['default_provider'] ?? $default->requestedProvider->value,
            'admin_provider' => $raw['admin_provider'] ?? $maps->forSurface(MapSurface::Admin)->requestedProvider->value,
            'operator_provider' => $raw['operator_provider'] ?? $maps->forSurface(MapSurface::Operator)->requestedProvider->value,
            'user_web_provider' => $raw['user_web_provider'] ?? $maps->forSurface(MapSurface::UserWeb)->requestedProvider->value,
            'public_provider' => $raw['public_provider'] ?? $maps->forSurface(MapSurface::Public)->requestedProvider->value,
            'mobile_provider' => $raw['mobile_provider'] ?? $maps->forSurface(MapSurface::Mobile)->requestedProvider->value,
            'default_latitude' => $raw['default_latitude'] ?? $default->defaultLatitude,
            'default_longitude' => $raw['default_longitude'] ?? $default->defaultLongitude,
            'default_zoom' => $raw['default_zoom'] ?? $default->defaultZoom,
            'minimum_zoom' => $raw['minimum_zoom'] ?? $default->minimumZoom,
            'maximum_zoom' => $raw['maximum_zoom'] ?? $default->maximumZoom,
            'tile_url_template' => $raw['tile_url_template'] ?? $default->tileUrlTemplate,
            'tile_attribution' => $raw['tile_attribution'] ?? $default->tileAttribution,
            'tile_maximum_native_zoom' => $raw['tile_maximum_native_zoom'] ?? $default->tileMaximumNativeZoom,
            'clustering_enabled' => $raw['clustering_enabled'] ?? $default->clusteringEnabled,
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->allows($user, PermissionKey::TenantSettingsView);
    }

    public function saveMapSettings(MapSettingsManager $settings): void
    {
        abort_unless($this->canManage(), 403);

        $validated = $this->validate([
            'mapSettings.default_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.admin_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.operator_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.user_web_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.public_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.mobile_provider' => ['required', 'in:openstreetmap,google'],
            'mapSettings.default_latitude' => ['required', 'numeric', 'between:-90,90'],
            'mapSettings.default_longitude' => ['required', 'numeric', 'between:-180,180'],
            'mapSettings.default_zoom' => ['required', 'integer', 'between:0,22'],
            'mapSettings.minimum_zoom' => ['required', 'integer', 'between:0,22'],
            'mapSettings.maximum_zoom' => ['required', 'integer', 'between:0,22', 'gte:mapSettings.minimum_zoom'],
            'mapSettings.tile_url_template' => ['required', 'string', 'max:1000'],
            'mapSettings.tile_attribution' => ['required', 'string', 'max:500'],
            'mapSettings.tile_maximum_native_zoom' => ['required', 'integer', 'between:0,22'],
            'mapSettings.clustering_enabled' => ['required', 'boolean'],
        ]);

        $settings->update(MapSettingsData::fromArray($validated['mapSettings']));

        Notification::make()
            ->title('Map settings saved')
            ->body('The provider cache was refreshed. Missing Google configuration will continue to fall back safely.')
            ->success()
            ->send();
    }

    public function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->allows($user, PermissionKey::TenantSettingsManage);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $maps = app(MapConfigurationResolver::class);
        $admin = $maps->forSurface(MapSurface::Admin);

        return [
            'providerOptions' => collect(MapProvider::cases())
                ->mapWithKeys(fn (MapProvider $provider): array => [$provider->value => $provider->label()])
                ->all(),
            'mapStatus' => [
                'google' => $admin->googleBrowserReady ? 'Configured' : 'Not configured',
                'fallback' => $admin->fallbackActive ? 'Provider fallback active' : 'Not active',
                'resolved' => $admin->provider->label(),
            ],
            'settings' => [
                ['name' => 'Application timezone', 'value' => (string) config('app.timezone'), 'source' => 'deployment'],
                ['name' => 'Queue connection', 'value' => (string) config('queue.default'), 'source' => 'deployment'],
                ['name' => 'Private storage disk', 'value' => (string) config('filesystems.default'), 'source' => 'deployment'],
                ['name' => 'Google Maps browser readiness', 'value' => $admin->googleBrowserReady ? 'Configured' : 'Not configured', 'source' => 'secret injection'],
                ['name' => 'OCPP gateway', 'value' => config('services.ocpp_gateway.base_url') ? 'Configured' : 'Not configured', 'source' => 'environment'],
            ],
        ];
    }
}
