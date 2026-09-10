<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\User;
use App\Modules\Assets\Application\AssetMediaStore;
use App\Modules\Assets\Application\FirmwareHistoryService;
use App\Modules\Assets\Application\StationCsvService;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
use App\Modules\Assets\Domain\Models\ChargerManufacturer;
use App\Modules\Assets\Domain\Models\ChargerModel;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Assets\Domain\Models\FirmwareVersion;
use App\Modules\Charging\Domain\ConnectorAvailability;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Locations\Domain\Models\SiteOperatingHour;
use App\Modules\Locations\Domain\SiteLifecycleStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantSecurityTestCase;

final class MasterDataAssetFoundationTest extends TenantSecurityTestCase
{
    public function test_public_map_filters_connector_power_availability_operator_site_type_and_open_hours(): void
    {
        CarbonImmutable::setTestNow('2026-07-22T04:00:00Z');
        $actor = $this->createUser();
        $tenant = $this->createTenant('map-filters');
        [$operator, $connector] = $this->withinTenant($tenant, $actor, function () use ($tenant): array {
            $operator = $this->operator($tenant, 'FILTER');
            $site = $this->site($tenant, $operator, 'FILTER', 14.5995, 120.9842, true);
            $site->update(['site_type' => 'retail']);
            $station = $this->station($tenant, $site, 'CP-FILTER', true);
            $standard = ConnectorStandard::query()->create(['code' => 'ccs2', 'name' => 'CCS2']);
            $current = ChargingCurrentType::query()->create(['code' => 'dc', 'name' => 'DC']);
            $evse = Evse::query()->create(['tenant_id' => $tenant->getKey(), 'charging_station_id' => $station->getKey(), 'evse_number' => 1, 'lifecycle_status' => 'active']);
            $connector = Connector::query()->create(['tenant_id' => $tenant->getKey(), 'evse_id' => $evse->getKey(), 'connector_standard_id' => $standard->getKey(), 'charging_current_type_id' => $current->getKey(), 'connector_number' => 1, 'qr_identifier' => 'QR-FILTER-CONNECTOR', 'maximum_power_w' => 150000, 'lifecycle_status' => 'active']);
            ConnectorStatus::query()->create(['tenant_id' => $tenant->getKey(), 'connector_id' => $connector->getKey(), 'status' => ConnectorAvailability::Available, 'observed_at' => now('UTC'), 'stale_after_seconds' => 300]);
            SiteOperatingHour::query()->create(['tenant_id' => $tenant->getKey(), 'site_id' => $site->getKey(), 'day_of_week' => 3, 'opens_at' => '08:00:00', 'closes_at' => '20:00:00', 'is_closed' => false]);

            return [$operator, $connector];
        });

        $query = http_build_query(['connector' => 'ccs2', 'min_power_w' => 100000, 'availability' => 'available', 'operator_id' => $operator->getKey(), 'site_type' => 'retail', 'open_now' => 1]);
        $response = $this->getJson('/api/v1/public/stations?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.availability', 'available')->assertJsonPath('data.0.maximum_power_w', 150000)
            ->assertJsonPath('data.0.connectors.0.standard', 'ccs2')->assertJsonPath('data.0.open_now', true);
        $this->assertStringContainsString('stale-while-revalidate=120', (string) $response->headers->get('Cache-Control'));
        $this->withHeader('If-None-Match', (string) $response->headers->get('ETag'))
            ->getJson('/api/v1/public/stations?'.$query)->assertStatus(304);

        $this->withinTenant($tenant, $actor, function () use ($connector): void {
            ConnectorStatus::query()->where('connector_id', $connector->getKey())->update(['observed_at' => now('UTC')->subMinutes(10)]);
        });
        $this->getJson('/api/v1/public/stations?availability=stale')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_stale', true);
        CarbonImmutable::setTestNow();
    }

    public function test_public_search_enforces_visibility_and_supports_radius_bounds_and_distance_order(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('public-map');

        $this->withinTenant($tenant, $actor, function () use ($tenant): void {
            $operator = $this->operator($tenant, 'MAP');
            $manila = $this->site($tenant, $operator, 'MNL', 14.5995, 120.9842, true);
            $cebu = $this->site($tenant, $operator, 'CEB', 10.3157, 123.8854, true);
            $hidden = $this->site($tenant, $operator, 'HID', 14.6000, 120.9850, false);
            $this->station($tenant, $manila, 'CP-MNL', true);
            $this->station($tenant, $cebu, 'CP-CEB', true);
            $this->station($tenant, $hidden, 'CP-HIDDEN', true);
        });

        $nearby = $this->getJson('/api/v1/public/stations?latitude=14.5995&longitude=120.9842&radius_m=50000')
            ->assertOk()->assertJsonCount(1, 'data');
        $nearby->assertJsonPath('data.0.name', 'CP-MNL');
        $this->assertLessThan(1, (float) $nearby->json('data.0.distance_m'));

        $this->getJson('/api/v1/public/stations?west=123&south=10&east=124.5&north=11')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'CP-CEB');
    }

    public function test_station_administration_and_site_scope_never_leak_between_sites_or_tenants(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('station-scope');
        $otherTenant = $this->createTenant('station-other');

        [$siteA, $siteB, $stationA, $stationB] = $this->withinTenant($tenant, $user, function () use ($tenant, $user): array {
            $operator = $this->operator($tenant, 'SCOPED');
            $siteA = $this->site($tenant, $operator, 'A', 14.5, 121.0);
            $siteB = $this->site($tenant, $operator, 'B', 14.6, 121.1);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'site-asset-manager', [PermissionKey::AssetView, PermissionKey::AssetManage]);
            $this->assignDirectly($tenant, $membership, $role, new ResourceScope(ScopeType::Site, (string) $siteA->getKey()));

            return [$siteA, $siteB, $this->station($tenant, $siteA, 'CP-A'), $this->station($tenant, $siteB, 'CP-B')];
        });
        $otherStation = $this->withinTenant($otherTenant, $user, function () use ($otherTenant): ChargingStation {
            $site = $this->site($otherTenant, $this->operator($otherTenant, 'OTHER'), 'O', 14.7, 121.2);

            return $this->station($otherTenant, $site, 'CP-OTHER');
        });
        $token = $this->login($user, $tenant);

        $this->withToken($token)->getJson('/api/v1/stations')->assertOk()
            ->assertJsonPath('data.0.id', $stationA->getKey())
            ->assertJsonMissing(['id' => $stationB->getKey()])
            ->assertJsonMissing(['id' => $otherStation->getKey()]);
        $this->withToken($token)->getJson('/api/v1/stations/'.$stationB->getKey())->assertNotFound();
        $this->withToken($token)->patchJson('/api/v1/stations/'.$stationB->getKey(), ['name' => 'Leak'])->assertNotFound();

        $created = $this->withToken($token)->postJson('/api/v1/stations', [
            'site_id' => $siteA->getKey(), 'name' => 'New station', 'charge_point_identity' => 'CP-NEW',
            'serial_number' => 'SERIAL-NEW', 'qr_identifier' => 'QR-NEW',
        ])->assertCreated();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'action' => 'assets.station.created', 'target_id' => $created->json('data.id')]);
        $this->withToken($token)->postJson('/api/v1/stations', [
            'site_id' => $siteB->getKey(), 'name' => 'Forbidden', 'charge_point_identity' => 'CP-FORBIDDEN',
            'serial_number' => 'SERIAL-FORBIDDEN', 'qr_identifier' => 'QR-FORBIDDEN',
        ])->assertForbidden();
    }

    public function test_firmware_history_is_effective_dated_and_model_compatible(): void
    {
        $manufacturer = ChargerManufacturer::query()->create(['name' => 'Test manufacturer', 'code' => 'TEST-MAKE']);
        $model = ChargerModel::query()->create(['charger_manufacturer_id' => $manufacturer->getKey(), 'name' => 'Model A', 'code' => 'A']);
        $firmwareA = FirmwareVersion::query()->create(['charger_model_id' => $model->getKey(), 'version' => '1.0.0']);
        $firmwareB = FirmwareVersion::query()->create(['charger_model_id' => $model->getKey(), 'version' => '1.1.0']);
        $actor = $this->createUser();
        $tenant = $this->createTenant('firmware');

        $this->withinTenant($tenant, $actor, function () use ($tenant, $model, $firmwareA, $firmwareB): void {
            $site = $this->site($tenant, $this->operator($tenant, 'FW'), 'FW', 14.5, 121.0);
            $station = $this->station($tenant, $site, 'CP-FW');
            $station->update(['charger_model_id' => $model->getKey()]);
            $firstAt = CarbonImmutable::parse('2026-01-01T00:00:00Z');
            $secondAt = CarbonImmutable::parse('2026-02-01T00:00:00Z');
            app(FirmwareHistoryService::class)->install($station, $firmwareA, $firstAt);
            app(FirmwareHistoryService::class)->install($station, $firmwareB, $secondAt);

            $this->assertDatabaseHas('charging_station_firmware_history', ['charging_station_id' => $station->getKey(), 'firmware_version_id' => $firmwareA->getKey(), 'effective_to' => $secondAt->format('Y-m-d H:i:s')]);
            $this->assertDatabaseHas('charging_station_firmware_history', ['charging_station_id' => $station->getKey(), 'firmware_version_id' => $firmwareB->getKey(), 'effective_to' => null]);
        });
    }

    public function test_global_charger_identity_is_unique_and_referenced_master_data_is_archived(): void
    {
        $manufacturer = ChargerManufacturer::query()->create(['name' => 'Archive test', 'code' => 'ARCHIVE']);
        ChargerModel::query()->create(['charger_manufacturer_id' => $manufacturer->getKey(), 'name' => 'Referenced', 'code' => 'REF']);
        $this->assertTrue($manufacturer->archive());
        $this->assertDatabaseHas('charger_manufacturers', ['id' => $manufacturer->getKey(), 'archived_at' => $manufacturer->archived_at]);
        $this->assertFalse(ChargerManufacturer::query()->available()->whereKey($manufacturer->getKey())->exists());

        $actor = $this->createUser();
        $tenantA = $this->createTenant('identity-a');
        $tenantB = $this->createTenant('identity-b');
        $this->withinTenant($tenantA, $actor, function () use ($tenantA): void {
            $this->station($tenantA, $this->site($tenantA, $this->operator($tenantA, 'IA'), 'IA', 14.5, 121), 'GLOBAL-ID');
        });
        $this->expectException(QueryException::class);
        $this->withinTenant($tenantB, $actor, function () use ($tenantB): void {
            $this->station($tenantB, $this->site($tenantB, $this->operator($tenantB, 'IB'), 'IB', 14.6, 121), 'GLOBAL-ID');
        });
    }

    public function test_media_and_csv_operations_remain_inside_tenant_and_site_scope(): void
    {
        Storage::fake('s3');
        $user = $this->createUser();
        $tenant = $this->createTenant('transfer');
        [$site] = $this->withinTenant($tenant, $user, function () use ($tenant, $user): array {
            $site = $this->site($tenant, $this->operator($tenant, 'CSV'), 'CSV', 14.5, 121);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'transfer', [PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::AssetManage]);
            $this->assignDirectly($tenant, $membership, $role);

            return [$site];
        });

        $this->withinTenant($tenant, $user, function () use ($tenant, $site, $user): void {
            $photo = app(AssetMediaStore::class)->storeSitePhoto($site, UploadedFile::fake()->image('site.jpg'), 'Site entrance');
            Storage::disk('s3')->assertExists($photo->path);
            $this->assertStringStartsWith('tenants/'.$tenant->getKey().'/sites/'.$site->getKey().'/', $photo->path);

            $csv = "site_code,name,charge_point_identity,serial_number,qr_identifier\nCSV,Imported,CP-CSV,SN-CSV,QR-CSV\n";
            $file = UploadedFile::fake()->createWithContent('stations.csv', $csv);
            $result = app(StationCsvService::class)->import($user, $file);
            $this->assertSame(1, $result['imported']);
            $this->assertStringContainsString('CP-CSV', app(StationCsvService::class)->export($user));
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'action' => 'locations.site_photo.created']);
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'action' => 'assets.stations.imported']);
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'action' => 'reporting.stations.exported']);
        });
    }

    private function operator(Tenant $tenant, string $code): Organization
    {
        return Organization::query()->create(['tenant_id' => $tenant->getKey(), 'name' => "Operator {$code}", 'code' => $code, 'type' => OrganizationType::ChargePointOperator, 'is_active' => true]);
    }

    private function site(Tenant $tenant, Organization $operator, string $code, float $latitude, float $longitude, bool $public = false): Site
    {
        return Site::query()->create(['tenant_id' => $tenant->getKey(), 'operator_organization_id' => $operator->getKey(), 'name' => "Site {$code}", 'code' => $code, 'timezone' => 'Asia/Manila', 'latitude' => $latitude, 'longitude' => $longitude, 'lifecycle_status' => $public ? SiteLifecycleStatus::Active : SiteLifecycleStatus::Draft, 'is_public' => $public, 'published_at' => $public ? now('UTC') : null]);
    }

    private function station(Tenant $tenant, Site $site, string $identity, bool $public = false): ChargingStation
    {
        return ChargingStation::query()->create(['tenant_id' => $tenant->getKey(), 'site_id' => $site->getKey(), 'name' => $identity, 'charge_point_identity' => $identity, 'serial_number' => 'SN-'.$identity, 'qr_identifier' => 'QR-'.$identity, 'lifecycle_status' => $public ? AssetLifecycleStatus::Active : AssetLifecycleStatus::Draft, 'is_public' => $public]);
    }

    private function login(User $user, Tenant $tenant): string
    {
        return (string) $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'tenant_id' => $tenant->getKey(), 'device_name' => 'Asset tests'])->assertOk()->json('data.token');
    }
}
