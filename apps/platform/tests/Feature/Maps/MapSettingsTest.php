<?php

declare(strict_types=1);

namespace Tests\Feature\Maps;

use App\Modules\Integrations\Application\MapConfigurationResolver;
use App\Modules\Integrations\Application\MapSettingsData;
use App\Modules\Integrations\Application\MapSettingsManager;
use App\Modules\Integrations\Domain\MapProvider;
use App\Modules\Integrations\Domain\MapSurface;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Validation\ValidationException;
use Tests\Support\TenantSecurityTestCase;

final class MapSettingsTest extends TenantSecurityTestCase
{
    public function test_authorized_change_is_persisted_cached_and_audited(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('map-settings');

        $this->withinTenant($tenant, $user, function () use ($tenant): void {
            $setting = app(MapSettingsManager::class)->update($this->validSettings([
                'admin_provider' => 'google',
            ]));

            $this->assertDatabaseHas('map_settings', [
                'id' => $setting->getKey(),
                'scope' => 'platform',
                'admin_provider' => 'google',
            ]);
            $this->assertDatabaseHas('audit_events', [
                'tenant_id' => $tenant->getKey(),
                'action' => 'integrations.map_settings.updated',
                'target_id' => $setting->getKey(),
            ]);
            $configuration = app(MapConfigurationResolver::class)->forSurface(MapSurface::Public);
            $this->assertSame(MapProvider::OpenStreetMap, $configuration->provider);
            $this->assertSame(14.61, $configuration->defaultLatitude);
            $this->assertSame(121.01, $configuration->defaultLongitude);
        });
    }

    public function test_settings_page_denies_panel_user_without_settings_permission(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('map-settings-denied');

        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'platform-without-settings', [
                PermissionKey::PlatformPanelAccess,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
        });

        $this->actingAs($user)->get('/admin/system-settings')->assertForbidden();
    }

    public function test_tile_configuration_rejects_javascript_and_missing_placeholders(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('map-settings-validation');
        $this->expectException(ValidationException::class);

        $this->withinTenant(
            $tenant,
            $user,
            fn () => app(MapSettingsManager::class)->update($this->validSettings([
                'tile_url_template' => 'javascript:alert(1)',
            ])),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function validSettings(array $overrides = []): MapSettingsData
    {
        return MapSettingsData::fromArray([
            'default_provider' => 'openstreetmap',
            'admin_provider' => 'openstreetmap',
            'operator_provider' => 'openstreetmap',
            'user_web_provider' => 'openstreetmap',
            'public_provider' => 'openstreetmap',
            'mobile_provider' => 'openstreetmap',
            'default_latitude' => 14.61,
            'default_longitude' => 121.01,
            'default_zoom' => 11,
            'minimum_zoom' => 3,
            'maximum_zoom' => 19,
            'tile_url_template' => 'https://tiles.example.test/{z}/{x}/{y}.png',
            'tile_attribution' => '© Example Tiles',
            'tile_maximum_native_zoom' => 19,
            'clustering_enabled' => true,
            ...$overrides,
        ]);
    }
}
