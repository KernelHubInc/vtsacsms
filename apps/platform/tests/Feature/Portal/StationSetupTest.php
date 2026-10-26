<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Operator\Resources\ChargingStations\Pages\ListChargingStations;
use App\Filament\Operator\Resources\ChargingStations\Pages\SetupChargingStation;
use App\Filament\Operator\Resources\Connectors\Pages\ListConnectors;
use App\Filament\Operator\Resources\Evses\Pages\ListEvses;
use App\Filament\Operator\Resources\OperatingHours\Pages\ListOperatingHours;
use App\Filament\Operator\Resources\Sites\Pages\EditSite;
use App\Filament\Operator\Resources\Sites\Pages\ListSites;
use App\Modules\Assets\Application\ProvisionChargingEquipment;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Assets\Domain\Models\OcppVersion;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Domain\Models\StationConnection;
use App\Modules\Locations\Application\ConfigureSiteAccess;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Locations\Domain\Models\SiteOperatingHour;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Reporting\Application\StationSetupQuery;
use App\Modules\Tenancy\Application\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class StationSetupTest extends TenantSecurityTestCase
{
    public function test_operational_lists_hide_retired_sites_and_stations_until_history_is_requested(): void
    {
        $this->manager(function (): void {
            $site = Site::factory()->create(['lifecycle_status' => 'active']);
            $retiredSite = Site::factory()->create(['lifecycle_status' => 'retired', 'is_active' => false]);
            $station = ChargingStation::factory()->create(['site_id' => $site->getKey(), 'lifecycle_status' => 'active']);
            $retiredStation = ChargingStation::factory()->create(['site_id' => $retiredSite->getKey(), 'lifecycle_status' => 'retired']);

            Livewire::test(ListSites::class)
                ->assertCanSeeTableRecords([$site])
                ->assertCanNotSeeTableRecords([$retiredSite])
                ->filterTable('historical_records', true)
                ->assertCanSeeTableRecords([$site, $retiredSite]);
            Livewire::test(ListChargingStations::class)
                ->assertCanSeeTableRecords([$station])
                ->assertCanNotSeeTableRecords([$retiredStation])
                ->filterTable('historical_records', true)
                ->assertCanSeeTableRecords([$station, $retiredStation]);
        });
    }

    public function test_setup_guide_is_available_before_creation_and_explains_missing_live_status(): void
    {
        $this->manager(function ($user): void {
            Livewire::test(ListChargingStations::class)->mountAction('setupGuide')
                ->assertMountedActionModalSee('Set up a charging station')->assertMountedActionModalSee('Online + Unknown')
                ->assertMountedActionModalSee('Close guide')->assertMountedActionModalSee('Energy-flow time');
            $this->assertSame(0, ChargingStation::query()->count());

            $station = ChargingStation::factory()->create(['lifecycle_status' => 'active']);
            $service = app(ProvisionChargingEquipment::class);
            $evse = $service->evse($user, ['charging_station_id' => $station->getKey(), 'evse_number' => 1, 'lifecycle_status' => 'active']);
            $connector = $service->connector($user, $this->connectorData($evse));
            $connectionId = (string) Str::ulid();
            StationConnection::query()->create([
                'tenant_id' => $station->tenant_id, 'charging_station_id' => $station->getKey(),
                'connection_id' => $connectionId, 'connected' => true,
                'connected_at' => now(), 'last_event_at' => now(), 'last_seen_at' => now(),
            ]);
            Livewire::test(SetupChargingStation::class, ['record' => $station->getKey()])
                ->assertSee('Guided station setup')->assertSee('Prepare the site')
                ->assertSee('Next step')->assertSee('Operator instructions')
                ->assertSee('Connected, waiting for a plug status');
            ConnectorStatus::query()->create([
                'tenant_id' => $station->tenant_id, 'connector_id' => $connector->getKey(),
                'status' => 'available', 'reported_status' => 'available', 'observed_at' => now(),
                'last_ocpp_event_at' => now(), 'ocpp_connection_id' => $connectionId, 'stale_after_seconds' => 300,
            ]);
            Livewire::test(SetupChargingStation::class, ['record' => $station->getKey()])
                ->assertDontSee('Connected, waiting for a plug status');
        });
    }

    public function test_staff_can_complete_equipment_and_location_steps_through_panel_actions(): void
    {
        $this->manager(function ($user): void {
            $station = ChargingStation::factory()->create(['lifecycle_status' => 'active']);
            Livewire::test(ListEvses::class)->callAction('create', data: [
                'charging_station_id' => $station->getKey(), 'evse_number' => 1, 'uid' => 'Outlet A', 'lifecycle_status' => 'active',
            ])->assertHasNoActionErrors();
            $evse = Evse::query()->sole();
            Livewire::test(ListConnectors::class)->callAction('create', data: $this->connectorData($evse))
                ->assertHasNoActionErrors();
            $connector = Connector::query()->sole();
            $this->assertSame(7000, $connector->maximum_power_w);
            $this->assertSame($station->tenant_id, $connector->tenant_id);
            $this->assertNotEmpty($connector->qr_identifier);
            $this->assertDatabaseHas('audit_events', ['target_id' => $connector->getKey(), 'action' => 'assets.connector.created']);
            Livewire::test(ListConnectors::class)->callAction('create', data: $this->connectorData($evse))
                ->assertHasActionErrors(['connector_number']);
            Livewire::test(ListOperatingHours::class)->callAction('create', data: [
                'site_id' => $station->site_id, 'day_of_week' => 0, 'is_closed' => false, 'opens_at' => '08:00', 'closes_at' => '22:00',
            ])->assertHasNoActionErrors();
            $hour = SiteOperatingHour::query()->sole();
            Livewire::test(ListOperatingHours::class)->callTableAction('edit', $hour, data: [
                'is_closed' => false, 'opens_at' => '21:00', 'closes_at' => '06:00',
            ])->assertHasNoActionErrors();
            Livewire::test(ListOperatingHours::class)->callTableAction('edit', $hour, data: [
                'is_closed' => false, 'is_open_all_day' => true,
            ])->assertHasNoActionErrors();
            $this->assertStringStartsWith('24:00', $hour->fresh()->closes_at);
            Livewire::test(ListOperatingHours::class)->mountTableAction('edit', $hour)
                ->assertSchemaStateSet(['is_open_all_day' => true]);
            $site = $station->site;
            $site->update(['lifecycle_status' => 'active', 'is_public' => true, 'published_at' => null, 'address_line_1' => 'Test location', 'latitude' => 14, 'longitude' => 120, 'timezone' => 'Asia/Manila']);
            Livewire::test(EditSite::class, ['record' => $site->getKey()])->callAction('publication')->assertNotified();
            $this->assertNotNull($site->fresh()->published_at);
            Livewire::test(SetupChargingStation::class, ['record' => $station->getKey()])
                ->assertSuccessful()->assertSee('Configuration checklist')->assertSee('Type 2')->assertSee('No device messages yet');
            $setup = app(StationSetupQuery::class)->for($user, $station);
            $this->assertSame('unknown', $setup['connection']);
            $this->assertFalse($setup['checks']['Every connector has effective published pricing']);
        });
    }

    public function test_ocpp16_rejects_repeated_numbers_across_evses_but_ocpp201_allows_per_evse_numbers(): void
    {
        $this->manager(function ($user): void {
            $version = OcppVersion::query()->firstOrCreate(['code' => '1.6J'], ['name' => 'OCPP 1.6J']);
            $station = ChargingStation::factory()->create(['ocpp_version_id' => $version->getKey()]);
            $service = app(ProvisionChargingEquipment::class);
            $first = $service->evse($user, ['charging_station_id' => $station->getKey(), 'evse_number' => 1, 'lifecycle_status' => 'active']);
            $second = $service->evse($user, ['charging_station_id' => $station->getKey(), 'evse_number' => 2, 'lifecycle_status' => 'active']);
            $service->connector($user, $this->connectorData($first));
            $this->invalid(fn () => $service->evse($user, ['charging_station_id' => $station->getKey(), 'evse_number' => 1, 'lifecycle_status' => 'active']), 'evse_number');
            $this->invalid(fn () => $service->connector($user, $this->connectorData($second)), 'connector_number');
            $this->invalid(fn () => $service->connector($user, array_replace($this->connectorData($second), ['connector_number' => 0])), 'connector_number');
            $version2 = OcppVersion::query()->firstOrCreate(['code' => '2.0.1'], ['name' => 'OCPP 2.0.1']);
            $station->update(['ocpp_version_id' => $version2->getKey()]);
            $service->connector($user, $this->connectorData($second));
            $this->assertSame(2, Connector::query()->count());
        });
    }

    public function test_forged_parent_ids_are_denied_for_viewers_other_sites_and_other_tenants(): void
    {
        $this->manager(function ($user, $tenant): void {
            $allowed = ChargingStation::factory()->create();
            $outside = ChargingStation::factory()->create();
            $scoped = $this->createUser();
            $membership = $this->createMembership($tenant, $scoped);
            $role = $this->createRole($tenant, 'site-equipment', [PermissionKey::AssetManage]);
            $this->assignDirectly($tenant, $membership, $role, new ResourceScope(ScopeType::Site, $allowed->site_id));
            $service = app(ProvisionChargingEquipment::class);
            $data = ['charging_station_id' => $outside->getKey(), 'evse_number' => 1, 'lifecycle_status' => 'draft'];
            foreach ([$scoped, $this->createUser()] as $actor) {
                try {
                    $service->evse($actor, $data);
                    $this->fail('Equipment creation outside scope succeeded.');
                } catch (AuthorizationException) {
                    $this->assertSame(0, Evse::query()->count());
                }
            }
            $other = $this->createTenant('equipment-other');
            $context = app(CurrentTenant::class);
            $previous = $context->get();
            $context->clear();
            $foreign = $this->withinTenant($other, $user, fn () => ChargingStation::factory()->create());
            $context->establish($previous);
            try {
                $service->evse($user, array_replace($data, ['charging_station_id' => $foreign->getKey()]));
                $this->fail('Cross-tenant parent accepted.');
            } catch (ModelNotFoundException) {
                $this->assertSame(0, Evse::query()->count());
            }
        });
    }

    public function test_schedules_reject_duplicates_and_invalid_times_and_publication_requires_complete_location(): void
    {
        $this->manager(function ($user): void {
            $site = ChargingStation::factory()->create()->site;
            $service = app(ConfigureSiteAccess::class);
            $data = ['site_id' => $site->getKey(), 'day_of_week' => 1, 'is_closed' => false, 'opens_at' => '08:00', 'closes_at' => '08:00'];
            $this->invalid(fn () => $service->hours($user, $data), 'closes_at');
            $this->invalid(fn () => $service->hours($user, array_replace($data, ['opens_at' => null])), 'opens_at');
            $closed = array_replace($data, ['is_closed' => true, 'opens_at' => null, 'closes_at' => null]);
            $service->hours($user, $closed);
            $this->invalid(fn () => $service->hours($user, $closed), 'day_of_week');
            $site->update(['lifecycle_status' => 'draft', 'published_at' => null]);
            $this->invalid(fn () => $service->publish($user, $site, true), 'publication');
            $site->update(['lifecycle_status' => 'active', 'is_public' => true, 'latitude' => null]);
            $this->invalid(fn () => $service->publish($user, $site, true), 'latitude');
            $this->assertNull($site->fresh()->published_at);
        });
    }

    /** @return array<string, mixed> */
    private function connectorData(Evse $evse): array
    {
        $standard = ConnectorStandard::query()->firstOrCreate(['code' => 'TYPE_2'], ['name' => 'Type 2']);
        $current = ChargingCurrentType::query()->firstOrCreate(['code' => 'AC'], ['name' => 'AC']);

        return ['evse_id' => $evse->getKey(), 'connector_number' => 1, 'connector_standard_id' => $standard->getKey(), 'charging_current_type_id' => $current->getKey(), 'maximum_power_w' => 7000, 'lifecycle_status' => 'active'];
    }

    public function test_operator_can_create_equipment_in_scope_and_viewer_cannot_create_or_renumber_it(): void
    {
        $this->manager(function ($user, $tenant): void {
            Filament::setCurrentPanel(Filament::getPanel('operator'));
            $station = ChargingStation::factory()->create();
            $operator = $this->createUser();
            $membership = $this->createMembership($tenant, $operator);
            $role = $this->createRole($tenant, 'scoped-operator', [PermissionKey::OperatorPanelAccess, PermissionKey::AssetManage, PermissionKey::AssetView, PermissionKey::LocationView]);
            $this->assignDirectly($tenant, $membership, $role, new ResourceScope(ScopeType::Site, $station->site_id));
            $this->actingAs($operator);
            Livewire::test(ListEvses::class)->callAction('create', data: [
                'charging_station_id' => $station->getKey(), 'evse_number' => 1, 'lifecycle_status' => 'active',
            ])->assertHasNoActionErrors();
            $evse = Evse::query()->sole();
            Livewire::test(ListConnectors::class)->callAction('create', data: $this->connectorData($evse))->assertHasNoActionErrors();
            $connector = Connector::query()->sole();
            Livewire::test(ListConnectors::class)->callTableAction('edit', $connector, data: ['connector_number' => 7, 'maximum_power_w' => 7400])->assertHasNoActionErrors();
            $this->assertSame(1, $connector->fresh()->connector_number);
            $this->assertSame(7400, $connector->fresh()->maximum_power_w);
            $viewer = $this->createUser();
            $viewerMembership = $this->createMembership($tenant, $viewer);
            $viewerRole = $this->createRole($tenant, 'setup-viewer', [PermissionKey::OperatorPanelAccess, PermissionKey::AssetView, PermissionKey::LocationView]);
            $this->assignDirectly($tenant, $viewerMembership, $viewerRole);
            $this->actingAs($viewer);
            Livewire::test(ListEvses::class)->assertActionHidden('create');
            Livewire::test(ListConnectors::class)->assertActionHidden('create');
            Livewire::test(SetupChargingStation::class, ['record' => $station->getKey()])->assertSuccessful()->assertSee('Configuration checklist')->assertSee('Operator instructions');
            Livewire::test(ListChargingStations::class)->mountAction('setupGuide')
                ->assertMountedActionModalSee('Set up a charging station')->assertMountedActionModalSee('Close guide');
        });
    }

    private function manager(\Closure $operation): void
    {
        $this->freezeTime();
        $user = $this->createUser();
        $tenant = $this->createTenant('equipment-setup');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $user, function () use ($user, $tenant, $operation): void {
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'setup-admin', [PermissionKey::PlatformPanelAccess, PermissionKey::AssetView, PermissionKey::AssetManage, PermissionKey::LocationView, PermissionKey::LocationManage]);
            $this->assignDirectly($tenant, $membership, $role);
            $operation($user, $tenant);
        });
    }

    public function test_setup_links_keep_station_and_site_filters_in_the_list_pages(): void
    {
        $this->manager(function ($user): void {
            $station = ChargingStation::factory()->create();
            $other = ChargingStation::factory()->create();
            $service = app(ProvisionChargingEquipment::class);
            $first = $service->evse($user, ['charging_station_id' => $station->getKey(), 'evse_number' => 1, 'lifecycle_status' => 'active']);
            $second = $service->evse($user, ['charging_station_id' => $other->getKey(), 'evse_number' => 1, 'lifecycle_status' => 'active']);
            $connector = $service->connector($user, $this->connectorData($first));
            $otherConnector = $service->connector($user, $this->connectorData($second));
            Livewire::test(SetupChargingStation::class, ['record' => $station->getKey()])
                ->assertSee('filters%5Bstation%5D%5Bvalue%5D', false)->assertSee('filters%5Bsite%5D%5Bvalue%5D', false);
            Livewire::withQueryParams(['filters' => ['station' => ['value' => $station->getKey()]]]);
            Livewire::test(ListEvses::class)
                ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
            Livewire::withQueryParams(['filters' => ['station' => ['value' => $station->getKey()]]]);
            Livewire::test(ListConnectors::class)
                ->assertCanSeeTableRecords([$connector])->assertCanNotSeeTableRecords([$otherConnector]);
        });
    }

    private function invalid(\Closure $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Invalid configuration was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
