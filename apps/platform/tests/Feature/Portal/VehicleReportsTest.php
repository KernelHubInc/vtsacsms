<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Platform\Pages\Reports;
use App\Models\User;
use App\Modules\Assets\Application\NormalizeVehicleNames;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\DriverGarage;
use App\Modules\Assets\Domain\Models\DriverVehicle;
use App\Modules\Charging\Domain\Models\ChargingQuote;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\RoleAssignment;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Reporting\Application\PortalExportRowsQuery;
use App\Modules\Reporting\Application\PortalExportService;
use App\Modules\Reporting\Application\PortalPdfReport;
use App\Modules\Reporting\Application\PortalReportAccess;
use App\Modules\Reporting\Domain\Models\PortalExport;
use App\Modules\Reporting\Jobs\GeneratePortalExport;
use App\Modules\Tariffs\Domain\Models\TariffVersion;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class VehicleReportsTest extends TenantSecurityTestCase
{
    public function test_portal_filters_queue_the_selected_report_and_recheck_access_in_the_worker(): void
    {
        Queue::fake();
        Storage::fake('local');
        config()->set('filesystems.report_disk', 'local');
        $tenant = $this->createTenant('vehicle-report-ui');
        $admin = $this->createUser();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $assignment = $this->withinTenant($tenant, $admin, function () use ($tenant, $admin) {
            Organization::factory()->create(['type' => 'platform', 'is_active' => true]);
            $assignment = $this->grant($tenant, $admin);
            DB::table('role_permissions')->insert([
                'tenant_id' => $tenant->getKey(), 'role_id' => $assignment->role_id,
                'permission_key' => PermissionKey::PlatformPanelAccess->value, 'created_at' => now(),
            ]);
            $vehicle = $this->vehicle($admin);
            $vehicle->update(['nickname' => '=1+2']);
            $this->chargingSession($admin, $vehicle, Site::factory()->create());

            return $assignment;
        });
        $this->get('/admin/reports')->assertOk()->assertSee('Vehicle report filters')->assertSee('Brand and model comparison');
        $this->withinTenant($tenant, $admin, function () use ($assignment): void {
            Livewire::test(Reports::class)
                ->set('vehicleFilters.manufacturer', ' byd ')
                ->set('from', '2026-10-27')->set('to', '2026-10-28')
                ->call('queueExport', 'vehicle_sessions', 'csv')->assertHasNoErrors();
            $export = PortalExport::query()->firstOrFail();
            self::assertSame('BYD', $export->filters['manufacturer']);
            $job = null;
            Queue::assertPushed(GeneratePortalExport::class, function ($pushed) use (&$job): bool {
                $job = $pushed;

                return true;
            });
            self::assertInstanceOf(GeneratePortalExport::class, $job);
            $job->handle(app(PortalExportRowsQuery::class), app(PortalReportAccess::class));
            $export->refresh();
            self::assertSame('completed', $export->status);
            self::assertStringContainsString("'=1+2", Storage::disk('local')->get($export->path));
            $page = new Reports;
            self::assertSame('text/csv; charset=UTF-8', $page->downloadExport((string) $export->getKey())->headers->get('Content-Type'));
            $assignment->delete();
            $job->handle(app(PortalExportRowsQuery::class), app(PortalReportAccess::class));
            self::assertSame('authorization_revoked', $export->refresh()->failure_code);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 27)->startOfDay());
        ConnectorStandard::query()->create(['code' => 'TEST-CCS', 'name' => 'Test CCS']);
        ChargingCurrentType::query()->create(['code' => 'TEST-DC', 'name' => 'Test DC']);
    }

    public function test_normalization_is_idempotent_batched_and_tenant_scoped(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('normalize-a');
        $other = $this->createTenant('normalize-b');
        foreach ([$tenant, $other] as $scope) {
            $this->withinTenant($scope, $user, function () use ($user): void {
                $vehicle = $this->vehicle($user);
                // Represents a row saved before normalization was introduced.
                DB::table('driver_vehicles')->where('id', $vehicle->getKey())->update(['manufacturer' => '  Byd ', 'model' => "  seal\t 5  "]);
            });
        }
        $this->withinTenant($tenant, $user, function (): void {
            self::assertSame(1, app(NormalizeVehicleNames::class)->run(false));
            self::assertSame('  Byd ', DriverVehicle::query()->value('manufacturer'));
            self::assertSame(1, app(NormalizeVehicleNames::class)->run(true));
            self::assertSame(0, app(NormalizeVehicleNames::class)->run(true));
            self::assertSame('BYD', DriverVehicle::query()->value('manufacturer'));
            self::assertSame('SEAL 5', DriverVehicle::query()->value('model'));
        });
        self::assertSame('  Byd ', DB::table('driver_vehicles')->where('tenant_id', $other->getKey())->value('manufacturer'));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'action' => 'assets.vehicle_names.normalized']);
    }

    public function test_register_scope_and_owner_filters_do_not_expose_unvisited_or_other_tenant_vehicles(): void
    {
        $tenant = $this->createTenant('vehicle-register');
        $other = $this->createTenant('private-register');
        $admin = $this->createUser();
        $operator = $this->createUser();
        $owner = $this->createUser();
        $owner->update(['name' => 'Alex Owner']);
        $this->withinTenant($other, $admin, fn () => $this->vehicle($owner));
        $this->withinTenant($tenant, $admin, function () use ($tenant, $admin, $operator, $owner): void {
            $site = Site::factory()->create();
            $hidden = Site::factory()->create();
            $visited = $this->vehicle($owner);
            $unvisited = $this->vehicle($owner);
            $this->chargingSession($owner, $visited, $site);
            $this->chargingSession($owner, $unvisited, $hidden);
            $this->grant($tenant, $admin);
            $this->grant($tenant, $operator, $site);
            self::assertCount(2, $this->rows('vehicles', $admin));
            $rows = $this->rows('vehicles', $operator);
            self::assertCount(1, $rows);
            self::assertSame((string) $visited->getKey(), $rows[0]['vehicle_id']);
            self::assertSame('Alex Owner', $rows[0]['owner']);
            self::assertArrayNotHasKey('email', $rows[0]);
            self::assertArrayNotHasKey('plate_number', $rows[0]);
            self::assertCount(1, $this->rows('vehicles', $operator, ['owner' => 'alex', 'manufacturer' => 'BYD', 'registered_from' => '2026-10-27', 'registered_to' => '2026-10-28']));
            self::assertSame([], $this->rows('vehicles', $operator, ['site_id' => (string) $hidden->getKey()]));
            self::assertSame([], $this->rows('vehicles', $admin, ['owner' => '%']));
            self::assertSame([], $this->rows('vehicles', $admin, ['registered_to' => '2026-10-27']));
        });
    }

    public function test_shared_account_owner_and_session_driver_are_distinct_and_filters_are_applied(): void
    {
        Queue::fake();
        $tenant = $this->createTenant('fleet-report');
        $admin = $this->createUser();
        $driver = $this->createUser();
        $driver->update(['name' => 'Jamie Driver']);
        $this->withinTenant($tenant, $admin, function () use ($tenant, $admin, $driver): void {
            $this->grant($tenant, $admin);
            $account = Organization::factory()->create(['name' => 'Delivery Fleet', 'charging_account_type' => 'fleet']);
            $garage = DriverGarage::query()->create(['tenant_id' => $tenant->getKey(), 'charging_account_id' => $account->getKey()]);
            $vehicle = $this->vehicle($driver, $garage);
            $site = Site::factory()->create();
            $session = $this->chargingSession($driver, $vehicle, $site, ['charging_account_id' => $account->getKey()]);
            $export = app(PortalExportService::class)->queue($admin, 'vehicle_sessions', [
                'manufacturer' => '  byd ', 'model' => ' dolphin ', 'owner' => 'Delivery', 'driver' => 'Jamie',
                'account_type' => 'fleet', 'from' => '2026-10-27', 'to' => '2026-10-28', 'state' => 'completed',
            ]);
            self::assertSame('BYD', $export->filters['manufacturer']);
            $rows = $this->rows('vehicle_sessions', $admin, $export->filters);
            self::assertCount(1, $rows);
            self::assertSame((string) $session->getKey(), $rows[0]['session_id']);
            self::assertSame('Jamie Driver', $rows[0]['driver']);
            self::assertSame('Delivery Fleet', $rows[0]['account']);
            self::assertSame('1.250', $rows[0]['energy_kwh']);
            self::assertSame('12500', $rows[0]['final_cost_minor']);
            self::assertSame('2026-10-27 01:00:00', $rows[0]['started_at_utc']);
            self::assertSame('2026-10-27 02:00:00', $rows[0]['stopped_at_utc']);
            self::assertSame([], $this->rows('vehicle_sessions', $admin, ['from' => '2026-10-28']));
            self::assertSame([], $this->rows('vehicle_sessions', $admin, ['account_type' => 'personal']));
            self::assertSame([], $this->rows('vehicle_sessions', $admin, ['state' => 'charging']));
            $register = $this->rows('vehicles', $admin, ['account_type' => 'fleet']);
            self::assertNull($register[0]['owner']);
            self::assertSame('Delivery Fleet', $register[0]['account']);
        });
    }

    public function test_usage_summaries_separate_currencies_exclude_active_sessions_and_preserve_unknown_costs(): void
    {
        $tenant = $this->createTenant('usage-report');
        $admin = $this->createUser();
        $this->withinTenant($tenant, $admin, function () use ($tenant, $admin): void {
            $this->grant($tenant, $admin);
            $vehicle = $this->vehicle($admin);
            $site = Site::factory()->create();
            $this->chargingSession($admin, $vehicle, $site);
            $this->chargingSession($admin, $vehicle, $site, [], ['final_cost_minor' => null]);
            $this->chargingSession($admin, $vehicle, $site, [], ['currency' => 'USD', 'final_cost_minor' => 250]);
            $this->chargingSession($admin, $vehicle, $site, [], ['state' => 'charging', 'final_cost_minor' => null, 'stopped_at' => null]);
            foreach (['vehicle_usage', 'vehicle_models'] as $type) {
                $rows = $this->rows($type, $admin);
                self::assertCount(2, $rows);
                self::assertSame('PHP', $rows[0]['currency']);
                self::assertSame('2', $rows[0]['sessions']);
                self::assertSame('1', $rows[0]['vehicles']);
                self::assertSame('2.500', $rows[0]['energy_kwh']);
                self::assertSame('12500', $rows[0]['final_cost_minor']);
                self::assertSame('1', $rows[0]['unpriced_sessions']);
                self::assertSame('USD', $rows[1]['currency']);
                self::assertSame('250', $rows[1]['final_cost_minor']);
            }
            $vehicle->delete();
            $details = $this->rows('vehicle_sessions', $admin, ['vehicle_id' => (string) $vehicle->getKey()]);
            self::assertCount(4, $details);
            self::assertNull($details[0]['brand']);
            self::assertSame((string) $vehicle->getKey(), $details[0]['vehicle_id']);
            ChargingSession::factory()->create(['site_id' => $site->getKey()]);
            self::assertCount(5, $this->rows('vehicle_sessions', $admin));
        });
    }

    public function test_all_vehicle_pdf_types_render_and_whole_register_access_is_in_download_fingerprint(): void
    {
        $tenant = $this->createTenant('vehicle-pdf');
        $admin = $this->createUser();
        $this->withinTenant($tenant, $admin, function () use ($tenant, $admin): void {
            $assignment = $this->grant($tenant, $admin);
            $vehicle = $this->vehicle($admin);
            $site = Site::factory()->create();
            $this->chargingSession($admin, $vehicle, $site);
            foreach (['vehicles', 'vehicle_sessions', 'vehicle_usage', 'vehicle_models'] as $type) {
                $report = app(PortalExportRowsQuery::class)->for($type, $admin);
                $pdf = app(PortalPdfReport::class)->render($type, $report, []);
                self::assertSame(1, $pdf['count']);
                self::assertStringStartsWith('%PDF-', $pdf['bytes']);
            }
            $access = app(PortalReportAccess::class);
            $before = $access->scopeFingerprint($admin, 'vehicles');
            $assignment->update(['scope_type' => 'site', 'scope_id' => $site->getKey()]);
            self::assertNotSame($before, $access->scopeFingerprint($admin, 'vehicles'));
            DB::table('role_permissions')->where('role_id', $assignment->role_id)->where('permission_key', PermissionKey::AssetView->value)->delete();
            self::assertFalse($access->canExport($admin, 'vehicles'));
        });
    }

    public function test_invalid_vehicle_filters_are_rejected_before_queueing(): void
    {
        Queue::fake();
        $tenant = $this->createTenant('vehicle-validation');
        $admin = $this->createUser();
        $this->withinTenant($tenant, $admin, function () use ($tenant, $admin): void {
            $this->grant($tenant, $admin);
            foreach ([['vehicle_id' => 'not-an-id'], ['registered_from' => '2026-02-30'], ['registered_from' => '2026-10-28', 'registered_to' => '2026-10-27'], ['state' => 'pretend'], ['manufacturer' => str_repeat('x', 81)]] as $filters) {
                try {
                    app(PortalExportService::class)->queue($admin, 'vehicle_sessions', $filters);
                    self::fail('Invalid filters must not queue an export.');
                } catch (\DomainException $exception) {
                    self::assertNotEmpty($exception->getMessage());
                }
            }
        });
        Queue::assertNothingPushed();
    }

    private function vehicle(User $owner, ?DriverGarage $garage = null): DriverVehicle
    {
        $tenantId = app(CurrentTenant::class)->get()->tenantId;
        $garage ??= DriverGarage::query()->firstOrCreate(['tenant_id' => $tenantId, 'subject_id' => $owner->public_id]);

        return DriverVehicle::query()->create([
            'tenant_id' => $tenantId, 'garage_id' => $garage->getKey(), 'nickname' => 'Delivery car',
            'manufacturer' => ' byd ', 'model' => ' dolphin ', 'plate_pending' => true, 'connector_standards' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attribution
     * @param  array<string, mixed>  $attributes
     */
    private function chargingSession(User $driver, DriverVehicle $vehicle, Site $site, array $attribution = [], array $attributes = []): ChargingSession
    {
        $session = ChargingSession::factory()->create(array_replace([
            'site_id' => $site->getKey(), 'state' => 'completed', 'currency' => 'PHP',
            'requested_at' => '2026-10-27 00:59:00', 'started_at' => '2026-10-27 01:00:00', 'stopped_at' => '2026-10-27 02:00:00',
            'energy_wh' => 1250, 'duration_seconds' => 3600, 'final_cost_minor' => 12500,
        ], $attributes));
        $quote = ChargingQuote::query()->create([
            'tenant_id' => $site->tenant_id, 'subject_id' => $driver->public_id, 'connector_id' => $session->connector_id,
            'tariff_version_id' => TariffVersion::factory()->create()->getKey(), 'budget_minor' => 50000,
            'disclosure' => [], 'expires_at' => now()->addMinutes(5),
        ]);
        CustomerChargingSession::query()->create(array_replace([
            'tenant_id' => $site->tenant_id, 'subject_id' => $driver->public_id, 'session_id' => $session->getKey(),
            'quote_id' => $quote->getKey(), 'vehicle_id' => $vehicle->getKey(),
            'budget_minor' => 50000, 'idempotency_hash' => hash('sha256', (string) Str::ulid()), 'payment_state' => 'settled',
        ], $attribution));

        return $session;
    }

    private function grant(Tenant $tenant, User $user, ?Site $site = null): RoleAssignment
    {
        $member = $this->createMembership($tenant, $user);
        $role = $this->createRole($tenant, (string) Str::ulid(), [PermissionKey::ReportingView, PermissionKey::ReportingExport, PermissionKey::AssetView, PermissionKey::ChargingSessionView]);

        return $this->assignDirectly($tenant, $member, $role, $site === null ? null : new ResourceScope(ScopeType::Site, (string) $site->getKey()));
    }

    /**
     * @param  array<string, scalar|null>  $filters
     * @return list<array<string, scalar|null>>
     */
    private function rows(string $type, User $user, array $filters = []): array
    {
        $report = app(PortalExportRowsQuery::class)->for($type, $user, $filters);

        return array_values(array_map(fn (array $row): array => array_combine($report['headers'], $row), iterator_to_array($report['rows'])));
    }
}
