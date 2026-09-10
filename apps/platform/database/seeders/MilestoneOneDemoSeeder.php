<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Foundation\Demo\DemoEnvironment;
use App\Foundation\Features\Feature;
use App\Foundation\Features\FeatureFlags;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

final class MilestoneOneDemoSeeder extends Seeder
{
    public const DEMO_TENANT_ID = DemoEnvironment::TENANT_ID;

    public const PASSWORD = 'VstaDemo!2026';

    /** @var list<array{string, string, float, float, string}> */
    private const LOCATIONS = [
        ['Bayside Exchange', 'Makati', 14.5547, 121.0244, 'retail'],
        ['North Commons', 'Quezon City', 14.6760, 121.0437, 'public_parking'],
        ['Harborview Center', 'Pasay', 14.5378, 120.9958, 'retail'],
        ['Riverwalk Hub', 'Mandaluyong', 14.5794, 121.0359, 'public_parking'],
        ['Eastgate Works', 'Pasig', 14.5764, 121.0851, 'workplace'],
        ['Garden District', 'Taguig', 14.5176, 121.0509, 'retail'],
        ['Heritage Square', 'Manila', 14.5995, 120.9842, 'public_parking'],
        ['Southlink Pavilion', 'Muntinlupa', 14.4081, 121.0415, 'highway'],
        ['Lakeside Stop', 'San Pedro', 14.3583, 121.0583, 'highway'],
        ['Ridgeview Market', 'Antipolo', 14.5863, 121.1760, 'retail'],
        ['Pineway Center', 'Baguio', 16.4023, 120.5960, 'hospitality'],
        ['Capitol Junction', 'Cebu City', 10.3157, 123.8854, 'public_parking'],
        ['Gulfside Plaza', 'Iloilo City', 10.7202, 122.5621, 'retail'],
        ['Orchard Exchange', 'Davao City', 7.1907, 125.4553, 'workplace'],
        ['Northern Gateway', 'Cagayan de Oro', 8.4542, 124.6319, 'fleet'],
    ];

    /** @var array<string, array{name: string, organization: string, role: string, scope: string}> */
    public const ACCOUNTS = [
        'superadmin@demo.vsta.local' => ['name' => 'Demo Super Administrator', 'organization' => 'platform', 'role' => 'platform-super-admin', 'scope' => 'tenant'],
        'admin@demo.vsta.local' => ['name' => 'Demo Platform Administrator', 'organization' => 'platform', 'role' => 'platform-admin', 'scope' => 'tenant'],
        'auditor@demo.vsta.local' => ['name' => 'Demo Security Auditor', 'organization' => 'platform', 'role' => 'auditor', 'scope' => 'tenant'],
        'operator.admin@demo.vsta.local' => ['name' => 'Demo Operator Administrator', 'organization' => 'cpo-one', 'role' => 'operator-admin', 'scope' => 'organization'],
        'operator.ops@demo.vsta.local' => ['name' => 'Demo Operations Manager', 'organization' => 'cpo-one', 'role' => 'operator-ops', 'scope' => 'organization'],
        'sitehost@demo.vsta.local' => ['name' => 'Demo Site Host Manager', 'organization' => 'host-one', 'role' => 'site-host', 'scope' => 'site'],
        'site.manager@demo.vsta.local' => ['name' => 'Demo Site Manager', 'organization' => 'cpo-one', 'role' => 'site-manager', 'scope' => 'site'],
        'requester@demo.vsta.local' => ['name' => 'Demo Procurement Requester', 'organization' => 'cpo-one', 'role' => 'procurement-requester-demo', 'scope' => 'organization'],
        'approver@demo.vsta.local' => ['name' => 'Demo Procurement Approver', 'organization' => 'cpo-one', 'role' => 'procurement-approver-demo', 'scope' => 'organization'],
        'procurement@demo.vsta.local' => ['name' => 'Demo Procurement Officer', 'organization' => 'cpo-one', 'role' => 'procurement-officer', 'scope' => 'organization'],
        'inventory.manager@demo.vsta.local' => ['name' => 'Demo Inventory Manager', 'organization' => 'cpo-one', 'role' => 'inventory-manager', 'scope' => 'organization'],
        'warehouse@demo.vsta.local' => ['name' => 'Demo Warehouse Staff', 'organization' => 'cpo-one', 'role' => 'warehouse-staff', 'scope' => 'organization'],
        'maintenance.manager@demo.vsta.local' => ['name' => 'Demo Maintenance Manager', 'organization' => 'cpo-one', 'role' => 'maintenance-manager', 'scope' => 'organization'],
        'technician@demo.vsta.local' => ['name' => 'Demo Maintenance Technician', 'organization' => 'contractor', 'role' => 'maintenance-technician-demo', 'scope' => 'organization'],
        'finance@demo.vsta.local' => ['name' => 'Demo Finance Manager', 'organization' => 'cpo-one', 'role' => 'finance-manager', 'scope' => 'organization'],
        'support@demo.vsta.local' => ['name' => 'Demo Support Agent', 'organization' => 'platform', 'role' => 'support-agent', 'scope' => 'tenant'],
        'fleet.manager@demo.vsta.local' => ['name' => 'Demo Fleet Manager', 'organization' => 'fleet', 'role' => 'fleet-manager', 'scope' => 'organization'],
        'executive@demo.vsta.local' => ['name' => 'Demo Read-only Executive', 'organization' => 'platform', 'role' => 'executive-readonly', 'scope' => 'tenant'],
        'driver@demo.vsta.local' => ['name' => 'Demo Consumer Driver', 'organization' => 'fleet', 'role' => 'consumer-driver', 'scope' => 'organization'],
    ];

    public function run(FeatureFlags $flags): void
    {
        if (app()->environment('production')) {
            throw new LogicException('Milestone 1 demo data is forbidden in production.');
        }
        if (! app()->environment(['local', 'development', 'testing']) && ! app()->environment('demo')) {
            throw new LogicException('Milestone 1 demo data is limited to local, development, testing, or demo environments.');
        }
        if ($flags->disabled(Feature::DemoMode)) {
            throw new LogicException('Set FEATURE_DEMO_MODE=true before creating local demo accounts.');
        }

        $this->call([PermissionSeeder::class, MasterDataSeeder::class, PublicCmsSeeder::class]);
        $this->seedTenantAndOrganizations();
        $this->call([
            FoundationRoleSeeder::class,
            ChargingTariffSeeder::class,
            FinancialFoundationSeeder::class,
            ProcurementInventorySeeder::class,
            MaintenanceSeeder::class,
        ]);
        $this->seedRolesAndUsers();
        $this->seedGeographyAndAssets();
        $this->seedVehiclesAndTariffs();
        $this->seedOperationalExamples();
    }

    private function seedTenantAndOrganizations(): void
    {
        $now = now('UTC');
        DB::table('tenants')->upsert([[
            'id' => self::DEMO_TENANT_ID,
            'name' => 'Power Solutions Milestone 1 Demo (Test data)',
            'slug' => 'vtsa-milestone-one-demo',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['name', 'slug', 'status', 'updated_at']);

        $organizations = [
            'platform' => ['Power Solutions Demo Platform', 'DEMO-PLATFORM', 'platform'],
            'cpo-one' => ['Amihan Chargeworks (Demo)', 'DEMO-CPO-A', 'charge_point_operator'],
            'cpo-two' => ['Habagat Mobility Network (Demo)', 'DEMO-CPO-B', 'charge_point_operator'],
            'host-one' => ['Sampaguita Places (Demo)', 'DEMO-HOST-A', 'site_host'],
            'host-two' => ['Lakbay Properties (Demo)', 'DEMO-HOST-B', 'site_host'],
            'fleet' => ['Tala Fleet Services (Demo)', 'DEMO-FLEET', 'fleet'],
            'vendor-one' => ['Kislap Electrical Supply (Demo)', 'DEMO-VENDOR-A', 'vendor'],
            'vendor-two' => ['Sinag Parts Trading (Demo)', 'DEMO-VENDOR-B', 'vendor'],
            'contractor' => ['Bayanihan Field Service (Demo)', 'DEMO-SERVICE', 'service_contractor'],
        ];
        foreach ($organizations as $key => [$name, $code, $type]) {
            DB::table('organizations')->upsert([[
                'id' => $this->id('organization:'.$key),
                'tenant_id' => self::DEMO_TENANT_ID,
                'type' => $type,
                'name' => $name,
                'code' => $code,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['id'], ['type', 'name', 'code', 'is_active', 'updated_at']);
        }
    }

    private function seedRolesAndUsers(): void
    {
        $all = PermissionKey::cases();
        $read = [
            PermissionKey::OperatorPanelAccess, PermissionKey::IdentityContextView,
            PermissionKey::OrganizationView, PermissionKey::LocationView, PermissionKey::AssetView,
            PermissionKey::ChargingSessionView, PermissionKey::TariffView, PermissionKey::PaymentView,
            PermissionKey::BillingView, PermissionKey::SettlementView, PermissionKey::ProcurementView,
            PermissionKey::InventoryView, PermissionKey::MaintenanceView, PermissionKey::SupportView,
            PermissionKey::ReportingView,
        ];
        $roles = [
            'platform-super-admin' => $all,
            'platform-admin' => array_values(array_filter($all, fn (PermissionKey $key): bool => ! in_array($key, [PermissionKey::RoleManage, PermissionKey::RoleAssign, PermissionKey::SupportSensitiveReveal], true))),
            'auditor' => [PermissionKey::PlatformPanelAccess, PermissionKey::AuditView, PermissionKey::AuditExport, PermissionKey::OrganizationView, PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::ReportingView, PermissionKey::ReportingExport],
            'operator-panel' => [PermissionKey::OperatorPanelAccess],
            'operator-admin' => [PermissionKey::MembershipView, PermissionKey::MembershipManage, PermissionKey::InvitationManage, PermissionKey::RoleView, PermissionKey::RoleAssign, PermissionKey::LocationView, PermissionKey::LocationManage, PermissionKey::AssetView, PermissionKey::AssetManage, PermissionKey::TariffView, PermissionKey::TariffManage, PermissionKey::ReportingView, PermissionKey::ReportingExport],
            'operator-ops' => [PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::ChargingSessionView, PermissionKey::MaintenanceView, PermissionKey::ReportingView],
            'site-host' => [PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::MaintenanceView, PermissionKey::ReportingView],
            'site-manager' => [PermissionKey::LocationView, PermissionKey::LocationManage, PermissionKey::AssetView, PermissionKey::InventoryView, PermissionKey::MaintenanceView, PermissionKey::MaintenanceDispatch],
            'procurement-requester-demo' => [PermissionKey::ProcurementView, PermissionKey::ProcurementManage],
            'procurement-approver-demo' => [PermissionKey::ProcurementView, PermissionKey::ProcurementApprove],
            'procurement-officer' => [PermissionKey::ProcurementView, PermissionKey::ProcurementManage, PermissionKey::InventoryView],
            'inventory-manager' => [PermissionKey::InventoryView, PermissionKey::InventoryOperate, PermissionKey::InventoryAdjust, PermissionKey::ReportingView, PermissionKey::ReportingExport],
            'warehouse-staff' => [PermissionKey::InventoryView, PermissionKey::InventoryOperate],
            'maintenance-manager' => [PermissionKey::MaintenanceView, PermissionKey::MaintenanceDispatch, PermissionKey::MaintenanceVerify, PermissionKey::InventoryView, PermissionKey::ReportingView],
            'maintenance-technician-demo' => [PermissionKey::MaintenanceView, PermissionKey::MaintenancePerform, PermissionKey::InventoryView, PermissionKey::InventoryOperate],
            'finance-manager' => [PermissionKey::PaymentView, PermissionKey::BillingView, PermissionKey::SettlementView, PermissionKey::ReportingView, PermissionKey::ReportingExport],
            'support-agent' => [PermissionKey::OperatorPanelAccess, PermissionKey::SupportView, PermissionKey::SupportManage, PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::ChargingSessionView],
            'fleet-manager' => [PermissionKey::MembershipView, PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::ReportingView],
            'executive-readonly' => $read,
            'consumer-driver' => [PermissionKey::IdentityContextView, PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::SessionView],
        ];

        foreach ($roles as $key => $permissions) {
            $this->upsertRole($key, Str::headline($key), $permissions);
        }

        $firstSite = $this->id('site:0');
        foreach (self::ACCOUNTS as $email => $account) {
            if (! str_ends_with($email, '.local')) {
                throw new LogicException('Demo accounts must use the .local domain.');
            }
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'public_id' => $this->id('user:'.$email),
                    'name' => $account['name'].' · Demo',
                    'password' => Hash::make(self::PASSWORD),
                    'activated_at' => now('UTC'),
                    'disabled_at' => null,
                    'mfa_required' => false,
                ],
            );
            $user->forceFill(['email_verified_at' => now('UTC')])->save();

            $membershipId = $this->id('membership:'.$email);
            DB::table('memberships')->upsert([[
                'id' => $membershipId,
                'tenant_id' => self::DEMO_TENANT_ID,
                'user_id' => $user->getKey(),
                'organization_id' => $this->id('organization:'.$account['organization']),
                'status' => 'active',
                'joined_at' => now('UTC'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]], ['id'], ['user_id', 'organization_id', 'status', 'joined_at', 'updated_at']);

            if (! str_starts_with($account['role'], 'platform-') && ! in_array($account['role'], ['auditor', 'support-agent', 'executive-readonly', 'consumer-driver'], true)) {
                $this->assignRole($membershipId, 'operator-panel', 'tenant', null);
            } else {
                DB::table('role_assignments')->where('id', $this->id('assignment:'.$membershipId.':operator-panel:tenant'))->delete();
            }
            $scopeId = match ($account['scope']) {
                'organization' => $this->id('organization:'.$account['organization']),
                'site' => $firstSite,
                default => null,
            };
            $this->assignRole($membershipId, $account['role'], $account['scope'], $scopeId);
        }
    }

    private function seedGeographyAndAssets(): void
    {
        $now = now('UTC');
        $country = (string) DB::table('countries')->where('iso_alpha_2', 'PH')->value('id');
        $region = $this->id('region:demo');
        $province = $this->id('province:demo');
        DB::table('regions')->upsert([['id' => $region, 'country_id' => $country, 'code' => 'DEMO-PH', 'name' => 'Demo Philippines', 'created_at' => $now, 'updated_at' => $now]], ['id'], ['name', 'updated_at']);
        DB::table('provinces')->upsert([['id' => $province, 'region_id' => $region, 'code' => 'DEMO', 'name' => 'Demo Cities', 'created_at' => $now, 'updated_at' => $now]], ['id'], ['name', 'updated_at']);

        foreach ([
            ['Aurora EV Systems', 'AURORA', 22000],
            ['Balangaw Charging Labs', 'BALANGAW', 60000],
            ['Luntian Power Equipment', 'LUNTIAN', 180000],
        ] as $index => [$name, $code, $power]) {
            $manufacturerId = $this->id('manufacturer:'.$index);
            DB::table('charger_manufacturers')->upsert([['id' => $manufacturerId, 'name' => $name.' (Demo)', 'code' => $code, 'created_at' => $now, 'updated_at' => $now]], ['id'], ['name', 'updated_at']);
            DB::table('charger_models')->upsert([[
                'id' => $this->id('charger-model:'.$index),
                'charger_manufacturer_id' => $manufacturerId,
                'name' => ['Sinta AC22', 'Agos DC60', 'Kidlat DC180'][$index].' (Demo)',
                'code' => 'DEMO-MODEL-'.($index + 1),
                'rated_power_w' => $power,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['id'], ['name', 'rated_power_w', 'updated_at']);
        }

        $standards = DB::table('connector_standards')->whereIn('code', ['TYPE_2', 'CCS_2', 'CHADEMO'])->pluck('id', 'code');
        $currents = DB::table('charging_current_types')->pluck('id', 'code');
        $amenities = DB::table('site_amenities')->pluck('id')->values();

        foreach (self::LOCATIONS as $siteIndex => [$name, $cityName, $latitude, $longitude, $siteType]) {
            $cityId = $this->id('city:'.$cityName);
            DB::table('cities')->upsert([['id' => $cityId, 'province_id' => $province, 'code' => 'DEMO-'.($siteIndex + 1), 'name' => $cityName, 'created_at' => $now, 'updated_at' => $now]], ['id'], ['name', 'updated_at']);
            $siteId = $this->id('site:'.$siteIndex);
            $operatorKey = $siteIndex < 8 ? 'cpo-one' : 'cpo-two';
            $hostKey = $siteIndex % 2 === 0 ? 'host-one' : 'host-two';
            DB::table('sites')->upsert([[
                'id' => $siteId,
                'tenant_id' => self::DEMO_TENANT_ID,
                'operator_organization_id' => $this->id('organization:'.$operatorKey),
                'site_host_organization_id' => $this->id('organization:'.$hostKey),
                'name' => $name.' · Demo',
                'code' => 'DEMO-SITE-'.str_pad((string) ($siteIndex + 1), 2, '0', STR_PAD_LEFT),
                'is_active' => true,
                'public_slug' => Str::slug($name).'-demo',
                'description' => 'Fictional Milestone 1 test location. Not a real operational charger.',
                'address_line_1' => ($siteIndex + 1).' Demo Avenue',
                'postal_code' => '0000',
                'country_id' => $country,
                'region_id' => $region,
                'province_id' => $province,
                'city_id' => $cityId,
                'timezone' => 'Asia/Manila',
                'site_type' => $siteType,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'lifecycle_status' => 'active',
                'is_public' => true,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['id'], ['name', 'operator_organization_id', 'site_host_organization_id', 'city_id', 'latitude', 'longitude', 'updated_at']);

            for ($day = 0; $day < 7; $day++) {
                DB::table('site_operating_hours')->upsert([[
                    'id' => $this->id("hours:{$siteIndex}:{$day}"),
                    'tenant_id' => self::DEMO_TENANT_ID,
                    'site_id' => $siteId,
                    'day_of_week' => $day,
                    'opens_at' => $siteIndex % 3 === 0 ? '00:00:00' : '06:00:00',
                    'closes_at' => $siteIndex % 3 === 0 ? '23:59:59' : '22:00:00',
                    'is_closed' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]], ['tenant_id', 'site_id', 'day_of_week'], ['opens_at', 'closes_at', 'updated_at']);
            }
            foreach ($amenities->slice($siteIndex % 3, 2) as $amenityId) {
                DB::table('site_amenity')->insertOrIgnore([
                    'tenant_id' => self::DEMO_TENANT_ID,
                    'site_id' => $siteId,
                    'site_amenity_id' => $amenityId,
                    'created_at' => $now,
                ]);
            }

            for ($stationOffset = 0; $stationOffset < 2; $stationOffset++) {
                $stationIndex = $siteIndex * 2 + $stationOffset;
                $stationId = $this->id('station:'.$stationIndex);
                $modelIndex = $stationIndex % 3;
                DB::table('charging_stations')->upsert([[
                    'id' => $stationId,
                    'tenant_id' => self::DEMO_TENANT_ID,
                    'site_id' => $siteId,
                    'charger_model_id' => $this->id('charger-model:'.$modelIndex),
                    'name' => 'Demo Charger '.str_pad((string) ($stationIndex + 1), 2, '0', STR_PAD_LEFT),
                    'charge_point_identity' => 'DEMO-CP-'.str_pad((string) ($stationIndex + 1), 3, '0', STR_PAD_LEFT),
                    'serial_number' => 'TEST-SN-'.str_pad((string) ($stationIndex + 1), 4, '0', STR_PAD_LEFT),
                    'qr_identifier' => 'DEMO-QR-'.str_pad((string) ($stationIndex + 1), 4, '0', STR_PAD_LEFT),
                    'lifecycle_status' => 'active',
                    'is_public' => true,
                    'commissioned_at' => $now->copy()->subDays(30),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]], ['id'], ['name', 'charger_model_id', 'updated_at']);

                $evseCount = $stationIndex < 15 ? 2 : 1;
                for ($evseOffset = 0; $evseOffset < $evseCount; $evseOffset++) {
                    $evseGlobal = $stationIndex < 15 ? $stationIndex * 2 + $evseOffset : 30 + ($stationIndex - 15);
                    $evseId = $this->id('evse:'.$evseGlobal);
                    DB::table('evses')->upsert([[
                        'id' => $evseId,
                        'tenant_id' => self::DEMO_TENANT_ID,
                        'charging_station_id' => $stationId,
                        'evse_number' => $evseOffset + 1,
                        'uid' => 'DEMO-EVSE-'.($evseGlobal + 1),
                        'lifecycle_status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]], ['id'], ['charging_station_id', 'evse_number', 'updated_at']);

                    $connectorCount = $evseGlobal < 15 ? 2 : 1;
                    for ($connectorOffset = 0; $connectorOffset < $connectorCount; $connectorOffset++) {
                        $connectorGlobal = $evseGlobal < 15 ? $evseGlobal * 2 + $connectorOffset : 30 + ($evseGlobal - 15);
                        $isAc = ($connectorGlobal % 3) === 0;
                        $standard = $isAc ? 'TYPE_2' : (($connectorGlobal % 2) === 0 ? 'CCS_2' : 'CHADEMO');
                        $power = $isAc ? 22000 : (($connectorGlobal % 2) === 0 ? 60000 : 180000);
                        $connectorId = $this->id('connector:'.$connectorGlobal);
                        DB::table('connectors')->upsert([[
                            'id' => $connectorId,
                            'tenant_id' => self::DEMO_TENANT_ID,
                            'evse_id' => $evseId,
                            'connector_standard_id' => $standards[$standard],
                            'charging_current_type_id' => $currents[$isAc ? 'AC' : 'DC'],
                            'connector_number' => $connectorOffset + 1,
                            'serial_number' => 'TEST-CON-'.str_pad((string) ($connectorGlobal + 1), 4, '0', STR_PAD_LEFT),
                            'qr_identifier' => 'DEMO-CON-'.str_pad((string) ($connectorGlobal + 1), 4, '0', STR_PAD_LEFT),
                            'maximum_power_w' => $power,
                            'lifecycle_status' => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]], ['id'], ['evse_id', 'connector_standard_id', 'charging_current_type_id', 'maximum_power_w', 'updated_at']);
                        $status = ['available', 'available', 'occupied', 'offline', 'faulted'][$connectorGlobal % 5];
                        $observedAt = $connectorGlobal % 7 === 0 ? $now->copy()->subHours(3) : $now;
                        DB::table('charging_connector_statuses')->upsert([[
                            'id' => $this->id('connector-status:'.$connectorGlobal),
                            'tenant_id' => self::DEMO_TENANT_ID,
                            'connector_id' => $connectorId,
                            'status' => $status,
                            'observed_at' => $observedAt,
                            'stale_after_seconds' => 600,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]], ['id'], ['status', 'observed_at', 'updated_at']);
                    }
                }
            }
        }
    }

    private function seedVehiclesAndTariffs(): void
    {
        $now = now('UTC');
        $manufacturers = [
            ['araw', 'Araw Motorworks (Demo)', 'DEMO-ARAW', 'E-One', 'CITY', 48000, 11000, 80000],
            ['tala', 'Tala Automotive (Demo)', 'DEMO-TALA', 'Lakbay', 'TOURING', 72000, 22000, 150000],
        ];

        foreach ($manufacturers as [$key, $name, $code, $modelName, $variantCode, $batteryWh, $acPowerW, $dcPowerW]) {
            $manufacturerId = $this->id('vehicle-manufacturer:'.$key);
            $modelId = $this->id('vehicle-model:'.$key);
            $variantId = $this->id('vehicle-variant:'.$key);
            DB::table('vehicle_manufacturers')->upsert([[
                'id' => $manufacturerId, 'name' => $name, 'code' => $code,
                'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['name', 'updated_at']);
            DB::table('vehicle_models')->upsert([[
                'id' => $modelId, 'vehicle_manufacturer_id' => $manufacturerId,
                'name' => $modelName.' (Demo)', 'code' => 'DEMO-'.$modelName,
                'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['name', 'updated_at']);
            DB::table('vehicle_variants')->upsert([[
                'id' => $variantId, 'vehicle_model_id' => $modelId,
                'name' => Str::headline($variantCode).' (Demo)', 'code' => $variantCode,
                'battery_capacity_wh' => $batteryWh, 'maximum_ac_power_w' => $acPowerW,
                'maximum_dc_power_w' => $dcPowerW, 'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['name', 'battery_capacity_wh', 'maximum_ac_power_w', 'maximum_dc_power_w', 'updated_at']);

            foreach ([
                ['TYPE_2', 'AC', $acPowerW],
                ['CCS_2', 'DC', $dcPowerW],
            ] as [$standard, $current, $maximumPowerW]) {
                DB::table('vehicle_connector_compatibilities')->upsert([[
                    'id' => $this->id("vehicle-compatibility:{$key}:{$standard}"),
                    'vehicle_variant_id' => $variantId,
                    'connector_standard_id' => DB::table('connector_standards')->where('code', $standard)->value('id'),
                    'charging_current_type_id' => DB::table('charging_current_types')->where('code', $current)->value('id'),
                    'maximum_power_w' => $maximumPowerW,
                    'created_at' => $now, 'updated_at' => $now,
                ]], ['id'], ['maximum_power_w', 'updated_at']);
            }
        }

        $tariffId = $this->id('tariff:demo');
        $versionId = $this->id('tariff-version:demo');
        DB::table('tariffs')->upsert([[
            'id' => $tariffId, 'tenant_id' => self::DEMO_TENANT_ID,
            'name' => 'Metro Demo Tariff', 'description' => 'Demo test data. Not a real charging price.',
            'currency' => 'PHP', 'status' => 'published',
            'created_by' => $this->id('user:operator.admin@demo.vsta.local'),
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['name', 'description', 'status', 'updated_at']);
        DB::table('tariff_versions')->upsert([[
            'id' => $versionId, 'tenant_id' => self::DEMO_TENANT_ID, 'tariff_id' => $tariffId,
            'version' => 1, 'status' => 'published', 'effective_from' => $now->copy()->subMonth(),
            'tax_treatment' => 'inclusive', 'tax_rate_basis_points' => null,
            'minimum_fee_minor' => 5000, 'maximum_fee_minor' => 250000,
            'operator_id' => $this->id('organization:cpo-one'), 'timezone' => 'Asia/Manila',
            'published_at' => $now->copy()->subMonth(),
            'published_by' => $this->id('user:operator.admin@demo.vsta.local'),
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['status', 'effective_from', 'minimum_fee_minor', 'maximum_fee_minor', 'updated_at']);
        foreach ([
            ['energy', 850, 1000],
            ['session', 2500, 1],
            ['idle', 200, 60],
        ] as $index => [$dimension, $priceMinor, $unitQuantity]) {
            DB::table('tariff_components')->upsert([[
                'id' => $this->id('tariff-component:demo:'.$index),
                'tenant_id' => self::DEMO_TENANT_ID, 'tariff_version_id' => $versionId,
                'dimension' => $dimension, 'price_minor' => $priceMinor, 'unit_quantity' => $unitQuantity,
                'day_of_week_mask' => 127, 'priority' => $index,
                'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['price_minor', 'unit_quantity', 'updated_at']);
        }
    }

    private function seedOperationalExamples(): void
    {
        $now = now('UTC');
        $tenant = self::DEMO_TENANT_ID;
        $category = DB::table('inventory_item_categories')->where('tenant_id', $tenant)->where('code', 'SPARES')->value('id');
        $uom = DB::table('inventory_units_of_measure')->where('tenant_id', $tenant)->where('code', 'EA')->value('id');
        $warehouse = DB::table('inventory_warehouses')->where('tenant_id', $tenant)->where('code', 'CENTRAL')->value('id');
        $bin = DB::table('inventory_bins')->where('tenant_id', $tenant)->where('code', 'A-001')->value('id');
        DB::table('inventory_warehouses')->where('id', $warehouse)->update([
            'site_id' => $this->id('site:0'),
            'updated_at' => $now,
        ]);
        foreach ([
            'inventory.manager@demo.vsta.local' => 'inventory-manager',
            'warehouse@demo.vsta.local' => 'warehouse-staff',
            'maintenance.manager@demo.vsta.local' => 'maintenance-manager',
            'technician@demo.vsta.local' => 'maintenance-technician-demo',
        ] as $email => $role) {
            $this->assignRole($this->id('membership:'.$email), $role, 'warehouse', (string) $warehouse);
        }
        $this->assignRole(
            $this->id('membership:finance@demo.vsta.local'),
            'finance-manager',
            'tenant',
            null,
        );
        foreach ([
            ['CABLE-T2', 'Type 2 service cable · Demo', 'serial', 1850000],
            ['CONTACTOR-DC', 'DC contactor module · Demo', 'none', 920000],
            ['FILTER-KIT', 'Preventive maintenance filter kit · Demo', 'lot', 125000],
            ['SCREEN-7', 'Seven-inch charger display · Demo', 'serial', 770000],
        ] as $index => [$sku, $name, $tracking, $cost]) {
            $itemId = $this->id('item:'.$index);
            DB::table('inventory_items')->upsert([[
                'id' => $itemId, 'tenant_id' => $tenant, 'category_id' => $category, 'base_uom_id' => $uom,
                'sku' => 'DEMO-'.$sku, 'name' => $name, 'description' => 'Test data; not real stock.',
                'tracking_type' => $tracking, 'valuation_method' => 'moving_average', 'currency' => 'PHP',
                'standard_cost_minor' => $cost, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['name', 'tracking_type', 'standard_cost_minor', 'updated_at']);
            DB::table('inventory_stock_movements')->insertOrIgnore([[
                'id' => $this->id('movement:'.$index), 'tenant_id' => $tenant, 'item_id' => $itemId, 'uom_id' => $uom,
                'from_bin_id' => null, 'to_bin_id' => $bin, 'movement_type' => 'receipt',
                'quantity_base' => 10 + $index, 'currency' => 'PHP', 'unit_cost_minor' => $cost,
                'total_cost_minor' => (10 + $index) * $cost, 'reference_type' => 'demo_opening',
                'reference_id' => $this->id('opening:'.$index), 'reason_code' => 'DEMO',
                'idempotency_key' => 'demo-opening-'.$index, 'posted_by' => $this->id('user:inventory.manager@demo.vsta.local'),
                'occurred_at' => $now->copy()->subDays(3), 'posted_at' => $now->copy()->subDays(3),
                'created_at' => $now, 'updated_at' => $now,
            ]]);
        }
        DB::table('inventory_serials')->upsert([[
            'id' => $this->id('inventory-serial:demo'), 'tenant_id' => $tenant,
            'item_id' => $this->id('item:0'), 'serial_number' => 'DEMO-SERIAL-CABLE-0001',
            'status' => 'available', 'current_bin_id' => $bin,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['status', 'current_bin_id', 'updated_at']);
        $lotId = $this->id('inventory-lot:demo');
        DB::table('inventory_lots')->upsert([[
            'id' => $lotId, 'tenant_id' => $tenant, 'item_id' => $this->id('item:2'),
            'lot_number' => 'DEMO-LOT-2026-01', 'manufactured_on' => $now->copy()->subMonths(2)->toDateString(),
            'expires_on' => $now->copy()->addYear()->toDateString(), 'status' => 'available',
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['status', 'expires_on', 'updated_at']);
        DB::table('inventory_stock_movements')->insertOrIgnore([[
            'id' => $this->id('movement:lot-demo'), 'tenant_id' => $tenant, 'item_id' => $this->id('item:2'),
            'uom_id' => $uom, 'from_bin_id' => null, 'to_bin_id' => $bin, 'lot_id' => $lotId,
            'movement_type' => 'receipt', 'quantity_base' => 4, 'currency' => 'PHP',
            'unit_cost_minor' => 125000, 'total_cost_minor' => 500000,
            'reference_type' => 'demo_opening', 'reference_id' => $this->id('opening:lot-demo'),
            'reason_code' => 'DEMO', 'idempotency_key' => 'demo-opening-lot',
            'posted_by' => $this->id('user:inventory.manager@demo.vsta.local'),
            'occurred_at' => $now->copy()->subDays(2), 'posted_at' => $now->copy()->subDays(2),
            'created_at' => $now, 'updated_at' => $now,
        ]]);
        DB::table('inventory_reorder_points')->upsert([[
            'id' => $this->id('reorder-point:demo'), 'tenant_id' => $tenant,
            'item_id' => $this->id('item:0'), 'warehouse_id' => $warehouse,
            'reorder_quantity_base' => 20, 'minimum_quantity_base' => 15,
            'target_quantity_base' => 30, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['reorder_quantity_base', 'minimum_quantity_base', 'target_quantity_base', 'updated_at']);

        $countPlanId = $this->id('count-plan:demo');
        $stockLocation = DB::table('inventory_stock_locations')->where('tenant_id', $tenant)->where('code', 'AVAILABLE')->value('id');
        DB::table('inventory_count_plans')->upsert([[
            'id' => $countPlanId, 'tenant_id' => $tenant, 'plan_number' => 'DEMO-COUNT-0001',
            'count_type' => 'physical', 'warehouse_id' => $warehouse, 'status' => 'in_progress',
            'blind_count' => true, 'scheduled_for' => $now->toDateString(),
            'created_by' => $this->id('user:inventory.manager@demo.vsta.local'),
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['status', 'scheduled_for', 'updated_at']);
        $countSheetId = $this->id('count-sheet:demo');
        DB::table('inventory_count_sheets')->upsert([[
            'id' => $countSheetId, 'tenant_id' => $tenant, 'count_plan_id' => $countPlanId,
            'stock_location_id' => $stockLocation, 'round' => 1, 'status' => 'open',
            'assigned_to' => $this->id('user:warehouse@demo.vsta.local'), 'started_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['status', 'assigned_to', 'updated_at']);
        DB::table('inventory_count_lines')->upsert([[
            'id' => $this->id('count-line:demo'), 'tenant_id' => $tenant, 'count_sheet_id' => $countSheetId,
            'item_id' => $this->id('item:0'), 'bin_id' => $bin, 'system_quantity_base' => null,
            'counted_quantity_base' => null, 'variance_quantity_base' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['updated_at']);
        $adjustmentId = $this->id('adjustment:demo');
        DB::table('inventory_adjustment_requests')->upsert([[
            'id' => $adjustmentId, 'tenant_id' => $tenant, 'adjustment_number' => 'DEMO-ADJ-0001',
            'status' => 'submitted', 'warehouse_id' => $warehouse, 'reason_code' => 'COUNT_VARIANCE',
            'reason_notes' => 'Fictional adjustment awaiting approval for UAT.',
            'source_type' => 'physical_count', 'source_id' => $countPlanId,
            'requested_by' => $this->id('user:warehouse@demo.vsta.local'),
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['status', 'reason_notes', 'updated_at']);
        DB::table('inventory_adjustment_lines')->upsert([[
            'id' => $this->id('adjustment-line:demo'), 'tenant_id' => $tenant,
            'adjustment_request_id' => $adjustmentId, 'item_id' => $this->id('item:0'),
            'uom_id' => $uom, 'bin_id' => $bin, 'quantity_delta_base' => -1,
            'unit_cost_minor' => 1850000, 'currency' => 'PHP', 'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['quantity_delta_base', 'updated_at']);

        $department = DB::table('procurement_departments')->where('tenant_id', $tenant)->first();
        $costCenter = DB::table('procurement_cost_centers')->where('tenant_id', $tenant)->first();
        if ($department && $costCenter) {
            $requestId = $this->id('purchase-request:demo');
            DB::table('purchase_requests')->upsert([[
                'id' => $requestId, 'tenant_id' => $tenant, 'request_number' => 'DEMO-PR-0001',
                'requested_by' => $this->id('user:requester@demo.vsta.local'), 'department_id' => $department->id,
                'cost_center_id' => $costCenter->id, 'status' => 'submitted', 'revision' => 1, 'currency' => 'PHP',
                'total_minor' => 4600000, 'needed_by' => $now->copy()->addDays(14)->toDateString(),
                'business_reason' => 'Demo replenishment for test charging assets.', 'submitted_at' => $now->copy()->subDay(),
                'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['status', 'total_minor', 'updated_at']);
            $requestLineId = $this->id('purchase-request-line:demo');
            DB::table('purchase_request_lines')->upsert([[
                'id' => $requestLineId, 'tenant_id' => $tenant, 'purchase_request_id' => $requestId,
                'item_id' => $this->id('item:1'), 'uom_id' => $uom, 'description' => 'DC contactor module · Demo',
                'quantity_base' => 5, 'estimated_unit_minor' => 920000, 'estimated_total_minor' => 4600000,
                'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['quantity_base', 'estimated_total_minor', 'updated_at']);
            $supplier = DB::table('procurement_suppliers')->where('tenant_id', $tenant)->value('id');
            $purchaseOrderId = $this->id('purchase-order:demo');
            DB::table('purchase_orders')->upsert([[
                'id' => $purchaseOrderId, 'tenant_id' => $tenant, 'purchase_request_id' => $requestId,
                'supplier_id' => $supplier, 'po_number' => 'DEMO-PO-0001', 'status' => 'partially_received',
                'revision' => 1, 'currency' => 'PHP', 'subtotal_minor' => 4600000, 'tax_minor' => 0,
                'shipping_minor' => 0, 'total_minor' => 4600000, 'delivery_warehouse_id' => $warehouse,
                'terms' => 'Demo test data only; not a real purchase order.',
                'created_by' => $this->id('user:procurement@demo.vsta.local'),
                'approved_by' => $this->id('user:approver@demo.vsta.local'), 'approved_at' => $now->copy()->subDay(),
                'issued_at' => $now->copy()->subDay(), 'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['status', 'updated_at']);
            $purchaseOrderLineId = $this->id('purchase-order-line:demo');
            DB::table('purchase_order_lines')->upsert([[
                'id' => $purchaseOrderLineId, 'tenant_id' => $tenant, 'purchase_order_id' => $purchaseOrderId,
                'purchase_request_line_id' => $requestLineId, 'item_id' => $this->id('item:1'), 'uom_id' => $uom,
                'description' => 'DC contactor module · Demo', 'ordered_quantity_base' => 5,
                'received_quantity_base' => 2, 'accepted_quantity_base' => 2, 'returned_quantity_base' => 0,
                'unit_price_minor' => 920000, 'line_total_minor' => 4600000, 'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['received_quantity_base', 'accepted_quantity_base', 'updated_at']);
            DB::table('vendor_invoices')->upsert([[
                'id' => $this->id('vendor-invoice:demo'), 'tenant_id' => $tenant, 'supplier_id' => $supplier,
                'purchase_order_id' => $purchaseOrderId, 'invoice_reference' => 'DEMO-INV-0001',
                'status' => 'submitted', 'currency' => 'PHP', 'subtotal_minor' => 1840000, 'tax_minor' => 0,
                'total_minor' => 1840000, 'invoice_date' => $now->toDateString(),
                'due_date' => $now->copy()->addDays(30)->toDateString(), 'accounting_export_state' => 'not_ready',
                'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['status', 'total_minor', 'updated_at']);
            DB::table('inventory_goods_receipts')->upsert([[
                'id' => $this->id('goods-receipt:demo'), 'tenant_id' => $tenant,
                'purchase_order_id' => $purchaseOrderId, 'supplier_id' => $supplier, 'warehouse_id' => $warehouse,
                'receipt_number' => 'DEMO-GR-0001', 'status' => 'inspected',
                'supplier_delivery_reference' => 'DEMO-DELIVERY', 'received_by' => $this->id('user:warehouse@demo.vsta.local'),
                'received_at' => $now->copy()->subHours(4), 'inspected_by' => $this->id('user:inventory.manager@demo.vsta.local'),
                'inspected_at' => $now->copy()->subHours(3), 'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['status', 'updated_at']);
        }

        $billingProfileId = $this->id('billing-profile:driver-demo');
        DB::table('billing_profiles')->upsert([[
            'id' => $billingProfileId, 'tenant_id' => $tenant,
            'user_id' => $this->id('user:driver@demo.vsta.local'),
            'profile_type' => 'individual', 'legal_name' => 'Demo Consumer Driver',
            'email' => 'driver@demo.vsta.local', 'country_code' => 'PH', 'is_default' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['legal_name', 'email', 'updated_at']);
        $invoiceId = $this->id('invoice:demo');
        DB::table('invoices')->insertOrIgnore([[
            'id' => $invoiceId, 'tenant_id' => $tenant, 'billing_profile_id' => $billingProfileId,
            'document_reference' => 'DEMO-INVOICE-0001', 'legal_invoice_number' => null,
            'status' => 'draft', 'currency' => 'PHP', 'subtotal_minor' => 12500,
            'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 12500,
            'amount_paid_minor' => 0, 'legal_review_required' => true,
            'issued_at' => null, 'due_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]]);
        DB::table('invoice_lines')->insertOrIgnore([[
            'id' => $this->id('invoice-line:demo'), 'tenant_id' => $tenant, 'invoice_id' => $invoiceId,
            'rated_charge_id' => null, 'description' => 'Demo report preview — not a real transaction',
            'quantity' => 1, 'unit_amount_minor' => 12500, 'subtotal_minor' => 12500,
            'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 12500,
            'tax_snapshot' => json_encode(['label' => 'Test data', 'legal_review_required' => true], JSON_THROW_ON_ERROR),
            'created_at' => $now, 'updated_at' => $now,
        ]]);
        DB::table('finance_reviews')->upsert([[
            'id' => $this->id('finance-review:demo'), 'tenant_id' => $tenant,
            'source_type' => 'demo_invoice', 'source_id' => $invoiceId,
            'reason_code' => 'DEMO_RECONCILIATION_PLACEHOLDER', 'status' => 'open',
            'severity' => 'info',
            'evidence' => json_encode([
                'label' => 'Demo test data',
                'message' => 'Real payment reconciliation is disabled until Milestone 2.',
                'real_transaction' => false,
            ], JSON_THROW_ON_ERROR),
            'opened_by' => $this->id('user:finance@demo.vsta.local'),
            'opened_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['status', 'severity', 'evidence', 'updated_at']);

        $priority = DB::table('maintenance_priorities')->where('tenant_id', $tenant)->where('code', 'HIGH')->value('id');
        $standardPriority = DB::table('maintenance_priorities')->where('tenant_id', $tenant)->orderBy('rank')->value('id');
        $sla = DB::table('maintenance_sla_policies')->where('tenant_id', $tenant)->value('id');
        $station = $this->id('station:0');
        $site = $this->id('site:0');
        foreach ([
            ['DATE', 'date', 90, $now->copy()->addDays(7)],
            ['RUNTIME', 'runtime_seconds', 3600000, null],
            ['SESSIONS', 'session_count', 500, null],
            ['ENERGY', 'energy_wh', 5000000, null],
        ] as $index => [$suffix, $trigger, $interval, $nextDue]) {
            DB::table('maintenance_preventive_plans')->upsert([[
                'id' => $this->id('preventive-plan:demo:'.$index), 'tenant_id' => $tenant,
                'plan_number' => 'DEMO-PM-'.$suffix, 'site_id' => $site, 'asset_type' => 'station',
                'asset_id' => $this->id('station:'.$index), 'name' => Str::headline($trigger).' inspection (Demo)',
                'trigger_type' => $trigger, 'interval_value' => $interval, 'next_due_at' => $nextDue,
                'priority_id' => $standardPriority, 'sla_policy_id' => $sla, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['name', 'interval_value', 'next_due_at', 'is_active', 'updated_at']);
        }
        DB::table('maintenance_incidents')->upsert([[
            'id' => $this->id('incident:demo'), 'tenant_id' => $tenant, 'incident_number' => 'DEMO-INC-0001',
            'source' => 'manual', 'source_key' => 'demo-fault-001', 'site_id' => $site, 'asset_type' => 'station',
            'asset_id' => $station, 'fault_code' => 'DEMO_COOLING_WARNING', 'fingerprint' => hash('sha256', 'demo-fault-001'),
            'state' => 'open', 'priority_id' => $priority, 'title' => 'Cooling inspection required · Demo',
            'details' => 'Simulated maintenance alert. No charger message was received.', 'occurrence_count' => 2,
            'escalation_level' => 1, 'first_observed_at' => $now->copy()->subHours(6), 'last_observed_at' => $now->copy()->subHour(),
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['state', 'last_observed_at', 'updated_at']);
        DB::table('maintenance_work_orders')->upsert([[
            'id' => $this->id('work-order:demo'), 'tenant_id' => $tenant, 'work_order_number' => 'DEMO-WO-0001',
            'work_type' => 'corrective', 'site_id' => $site, 'asset_type' => 'station', 'asset_id' => $station,
            'priority_id' => $priority, 'state' => 'assigned', 'title' => 'Inspect charger cooling path · Demo',
            'description' => 'Phone-ready technician walkthrough using fictional test data.', 'schedule_timezone' => 'Asia/Manila',
            'resolve_target_at' => $now->copy()->addHours(2), 'created_by' => $this->id('user:maintenance.manager@demo.vsta.local'),
            'currency' => 'PHP', 'estimated_cost_minor' => 250000, 'actual_cost_minor' => 0, 'aggregate_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['state', 'title', 'resolve_target_at', 'updated_at']);
        $technician = User::query()->where('email', 'technician@demo.vsta.local')->value('id');
        DB::table('maintenance_work_order_assignments')->upsert([[
            'id' => $this->id('work-order-assignment:demo'), 'tenant_id' => $tenant,
            'work_order_id' => $this->id('work-order:demo'), 'technician_user_id' => $technician,
            'vendor_organization_id' => $this->id('organization:contractor'), 'status' => 'assigned',
            'assigned_by' => $this->id('user:maintenance.manager@demo.vsta.local'), 'assigned_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['status', 'assigned_at', 'updated_at']);

        DB::table('maintenance_work_orders')->upsert([[
            'id' => $this->id('work-order:sla-demo'), 'tenant_id' => $tenant,
            'work_order_number' => 'DEMO-WO-SLA-0002', 'work_type' => 'corrective',
            'site_id' => $site, 'asset_type' => 'station', 'asset_id' => $this->id('station:1'),
            'priority_id' => $priority, 'sla_policy_id' => $sla, 'state' => 'in_progress',
            'title' => 'Resolve overdue communications inspection (Demo)',
            'description' => 'Test data with a past SLA target for dashboard warning validation.',
            'schedule_timezone' => 'Asia/Manila', 'resolve_target_at' => $now->copy()->subHour(),
            'acknowledged_at' => $now->copy()->subHours(3),
            'created_by' => $this->id('user:maintenance.manager@demo.vsta.local'),
            'currency' => 'PHP', 'estimated_cost_minor' => 175000, 'actual_cost_minor' => 0,
            'aggregate_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['state', 'title', 'resolve_target_at', 'updated_at']);
        DB::table('maintenance_work_orders')->upsert([[
            'id' => $this->id('work-order:completed-demo'), 'tenant_id' => $tenant,
            'work_order_number' => 'DEMO-WO-0003', 'work_type' => 'preventive',
            'site_id' => $site, 'asset_type' => 'station', 'asset_id' => $this->id('station:2'),
            'preventive_plan_id' => $this->id('preventive-plan:demo:0'),
            'priority_id' => $standardPriority, 'sla_policy_id' => $sla, 'state' => 'closed',
            'title' => 'Quarterly inspection completed (Demo)',
            'description' => 'Completed fictional work order for report and dashboard testing.',
            'work_performed' => 'Completed a simulated visual inspection; no physical charger was contacted.',
            'schedule_timezone' => 'Asia/Manila', 'started_at' => $now->copy()->subDays(5),
            'completed_at' => $now->copy()->subDays(5)->addHours(2),
            'verified_at' => $now->copy()->subDays(4), 'closed_at' => $now->copy()->subDays(4),
            'created_by' => $this->id('user:maintenance.manager@demo.vsta.local'),
            'completed_by' => $this->id('user:technician@demo.vsta.local'),
            'verified_by' => $this->id('user:maintenance.manager@demo.vsta.local'),
            'closed_by' => $this->id('user:maintenance.manager@demo.vsta.local'),
            'currency' => 'PHP', 'estimated_cost_minor' => 125000, 'actual_cost_minor' => 118000,
            'aggregate_version' => 4, 'created_at' => $now, 'updated_at' => $now,
        ]], ['id'], ['state', 'title', 'actual_cost_minor', 'updated_at']);

        $this->seedSupportAndAuditExamples($now);
    }

    private function seedSupportAndAuditExamples(Carbon $now): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        $tenant = self::DEMO_TENANT_ID;
        foreach ([
            ['DEMO-SUP-0001', 'open', 'normal', 'Station information question (Demo)', 0],
            ['DEMO-SUP-0002', 'escalated', 'high', 'Accessibility assistance request (Demo)', 1],
        ] as $index => [$number, $status, $priority, $subject, $escalationLevel]) {
            $ticketId = $this->id('support-ticket:demo:'.$index);
            DB::table('support_tickets')->upsert([[
                'id' => $ticketId, 'tenant_id' => $tenant, 'ticket_number' => $number,
                'requester_user_id' => User::query()->where('email', 'driver@demo.vsta.local')->value('id'),
                'site_id' => $this->id('site:'.$index), 'assigned_to_user_id' => User::query()->where('email', 'support@demo.vsta.local')->value('id'),
                'status' => $status, 'priority' => $priority, 'category' => 'general',
                'subject' => $subject, 'description' => 'Fictional customer-support test data.',
                'escalation_level' => $escalationLevel, 'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['status', 'priority', 'assigned_to_user_id', 'escalation_level', 'updated_at']);
            DB::table('support_ticket_messages')->insertOrIgnore([[
                'id' => $this->id('support-message:demo:'.$index), 'tenant_id' => $tenant,
                'support_ticket_id' => $ticketId,
                'author_user_id' => User::query()->where('email', 'support@demo.vsta.local')->value('id'),
                'body' => 'Demo response only. No customer or external system was contacted.',
                'is_internal' => false, 'created_at' => $now, 'updated_at' => $now,
            ]]);
        }

        app(CurrentTenant::class)->run(
            new TenantContext(
                $tenant,
                ActorType::Platform,
                null,
                $this->id('audit-correlation:demo'),
            ),
            function () use ($tenant): void {
                foreach ([
                    ['demo.environment.seeded', 'demo_environment', self::DEMO_TENANT_ID],
                    ['demo.station.reviewed', 'site', $this->id('site:0')],
                    ['demo.inventory.count_opened', 'inventory_count_plan', $this->id('count-plan:demo')],
                ] as [$action, $targetType, $targetId]) {
                    if (DB::table('audit_events')->where('tenant_id', $tenant)->where('action', $action)->exists()) {
                        continue;
                    }
                    app(AuditRecorder::class)->record(
                        new AuditEntry(
                            $action,
                            $targetType,
                            $targetId,
                            AuditResult::Succeeded,
                            reason: 'Milestone 1 demo test data',
                            metadata: ['label' => 'Demo', 'real_transaction' => false],
                            before: null,
                            after: ['demo' => true],
                            sourceIp: '127.0.0.1',
                            userAgent: 'VTSA Milestone 1 Demo Seeder',
                        ),
                    );
                }
            },
        );
    }

    /** @param list<PermissionKey> $permissions */
    private function upsertRole(string $key, string $name, array $permissions): void
    {
        $roleId = $this->id('role:'.$key);
        DB::table('roles')->upsert([[
            'id' => $roleId, 'tenant_id' => self::DEMO_TENANT_ID, 'key' => $key,
            'name' => $name, 'is_system' => true, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]], ['id'], ['name', 'updated_at']);
        foreach ($permissions as $permission) {
            DB::table('role_permissions')->insertOrIgnore([
                'tenant_id' => self::DEMO_TENANT_ID,
                'role_id' => $roleId,
                'permission_key' => $permission->value,
                'created_at' => now('UTC'),
            ]);
        }
    }

    private function assignRole(string $membershipId, string $roleKey, string $scopeType, ?string $scopeId): void
    {
        DB::table('role_assignments')->upsert([[
            'id' => $this->id('assignment:'.$membershipId.':'.$roleKey.':'.$scopeType),
            'tenant_id' => self::DEMO_TENANT_ID,
            'membership_id' => $membershipId,
            'role_id' => $this->id('role:'.$roleKey),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]], ['id'], ['role_id', 'scope_type', 'scope_id', 'updated_at']);
    }

    private function id(string $key): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $bytes = hash('sha256', $key, true);
        $suffix = '';
        for ($index = 0; $index < 16; $index++) {
            $suffix .= $alphabet[ord($bytes[$index]) % 32];
        }

        return '01J0000000'.$suffix;
    }
}
