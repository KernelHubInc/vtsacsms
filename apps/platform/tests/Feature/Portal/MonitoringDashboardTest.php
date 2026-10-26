<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Platform\Pages\Dashboard;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Billing\Domain\Models\PrepaidAccount;
use App\Modules\Billing\Domain\Models\PrepaidEntry;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Domain\Models\StationConnection;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Reporting\Application\MonitoringDashboardQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantSecurityTestCase;

final class MonitoringDashboardTest extends TenantSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 16)->setTime(2, 0));
        ConnectorStandard::query()->create(['code' => 'TYPE2', 'name' => 'Type 2']);
        ChargingCurrentType::query()->create(['code' => 'AC', 'name' => 'AC']);
    }

    public function test_availability_separates_occupied_reserved_offline_and_non_operational_equipment(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('monitoring-status');
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $this->assignDirectly($tenant, $this->createMembership($tenant, $user), $this->createRole($tenant, 'reporter', [PermissionKey::ReportingView, PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::ChargingSessionView]));
            foreach (['available', 'occupied', 'reserved', 'offline', 'draft'] as $status) {
                $connector = $this->connector();
                if ($status === 'draft') {
                    $connector->update(['lifecycle_status' => 'draft']);
                }
                $connection = StationConnection::query()->create(['charging_station_id' => $connector->evse->charging_station_id,
                    'connection_id' => (string) Str::ulid(), 'connected' => true, 'connected_at' => now(), 'last_event_at' => now(), 'last_seen_at' => $status === 'offline' ? now()->subHour() : now()]);
                ConnectorStatus::query()->create(['connector_id' => $connector->getKey(), 'status' => in_array($status, ['offline', 'draft']) ? 'available' : $status,
                    'reported_status' => $status === 'occupied' ? 'charging' : null, 'ocpp_connection_id' => $connection->connection_id, 'observed_at' => now(), 'stale_after_seconds' => 180]);
            }
            $legacy = $this->connector();
            ConnectorStatus::query()->create(['connector_id' => $legacy->getKey(), 'status' => 'available', 'observed_at' => now(), 'stale_after_seconds' => 180]);
            $data = app(MonitoringDashboardQuery::class)->read($user);
            self::assertSame(6, $data['connector_count']);
            self::assertSame(1, $data['breakdown']['Unknown']);
            self::assertSame(1, $data['breakdown']['Available']);
            self::assertSame(1, $data['breakdown']['Charging']);
            self::assertSame(1, $data['breakdown']['Reserved']);
            self::assertSame(1, $data['breakdown']['Offline']);
            self::assertSame(1, $data['breakdown']['Draft']);
            self::assertSame(16.7, $data['available_percent']);
            $this->travel(181)->seconds();
            self::assertSame(0, app(MonitoringDashboardQuery::class)->read($user)['breakdown']['Available']);
        });
    }

    public function test_period_uses_local_stop_date_keeps_currencies_separate_and_excludes_simulated_wallet_credits(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('monitoring-finance');
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $this->assignDirectly($tenant, $this->createMembership($tenant, $user), $this->createRole($tenant, 'finance', [PermissionKey::ReportingView, PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::ChargingSessionView, PermissionKey::PaymentView]));
            foreach (['PHP' => 125, 'USD' => 250] as $currency => $amount) {
                ChargingSession::factory()->create(['state' => 'completed', 'requested_at' => now()->subDays(40), 'stopped_at' => '2026-10-15 17:00:00', 'energy_wh' => 1500, 'final_cost_minor' => $amount, 'currency' => $currency]);
            }
            ChargingSession::factory()->create(['state' => 'starting', 'requested_at' => now()->subMinutes(10), 'energy_wh' => 9000]);
            ChargingSession::factory()->create(['state' => 'charging', 'started_at' => now()->subMinutes(15), 'energy_wh' => 750]);
            ChargingSession::factory()->create(['state' => 'completed', 'stopped_at' => now()->subDays(8), 'energy_wh' => 9000]);
            foreach (['live' => 100, 'simulated' => 900] as $book => $amount) {
                $account = PrepaidAccount::query()->create(['subject_id' => $user->public_id, 'currency' => 'PHP', 'book' => $book]);
                PrepaidEntry::query()->create(['account_id' => $account->getKey(), 'reference_id' => (string) Str::ulid(), 'kind' => 'topup', 'amount_minor' => $amount, 'created_at' => now()]);
            }
            $data = app(MonitoringDashboardQuery::class)->read($user);
            self::assertSame(3000, $data['energy_wh']);
            self::assertSame(2, $data['completed_sessions']);
            self::assertSame(3000, array_sum(array_column($data['top_sites'], 'energy_wh')));
            self::assertCount(1, $data['live_sessions']);
            self::assertSame(750, $data['live_sessions'][0]['energy_wh']);
            self::assertSame(['PHP' => 125, 'USD' => 250], array_column($data['charges'], 'amount_minor', 'currency'));
            self::assertSame([['currency' => 'PHP', 'amount_minor' => 100]], $data['topups']);
            self::assertSame(1, $data['waiting']);
            self::assertSame(1, $data['session_states']['charging'] ?? 0);
            self::assertSame(3000, array_column($data['trend'], 'energy_wh', 'day')['2026-10-16']);
            self::assertSame(0, array_column($data['trend'], 'energy_wh', 'day')['2026-10-15']);
            DB::table('role_permissions')->where('tenant_id', $tenant->getKey())->where('permission_key', PermissionKey::PaymentView->value)->delete();
            $restricted = app(MonitoringDashboardQuery::class)->read($user);
            self::assertFalse($restricted['wallet_visible']);
            self::assertSame([], $restricted['topups']);
            self::assertSame([], $restricted['charges']);
        });
    }

    public function test_scope_filters_and_livewire_dashboard_do_not_leak_other_sites_or_wallets(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('monitoring-scope');
        $site = $this->withinTenant($tenant, $user, function () use ($tenant, $user): string {
            Organization::query()->create(['name' => 'Platform', 'code' => 'PLATFORM', 'type' => 'platform', 'is_active' => true]);
            $visible = $this->connector();
            $hidden = $this->connector();
            $site = (string) $visible->evse->station->site_id;
            $role = $this->createRole($tenant, 'scoped', [PermissionKey::PlatformPanelAccess, PermissionKey::ReportingView, PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::ChargingSessionView, PermissionKey::PaymentView]);
            $membership = $this->createMembership($tenant, $user);
            $this->assignDirectly($tenant, $membership, $this->createRole($tenant, 'panel', [PermissionKey::PlatformPanelAccess]));
            $this->assignDirectly($tenant, $membership, $role, new ResourceScope(ScopeType::Site, $site));
            $data = app(MonitoringDashboardQuery::class)->read($user);
            self::assertSame(1, $data['station_count']);
            self::assertFalse($data['wallet_visible']);
            self::assertSame([], $data['topups']);
            self::assertSame(0, app(MonitoringDashboardQuery::class)->read($user, search: 'no-station-with-this-name')['matches']);
            try {
                app(MonitoringDashboardQuery::class)->read($user, siteId: (string) $hidden->evse->station->site_id);
                self::fail('An unauthorized filter must fail closed.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }

            return $site;
        });
        $this->actingAs($user)->get('/admin')->assertOk()->assertSee('Operations overview')->assertSee('Confirmed wallet top-ups')
            ->assertSee('Connector status')->assertSee('Needs attention')->assertSee('Station management')->assertDontSee('ps-eyebrow');
        $this->withinTenant($tenant, $user, function () use ($site): void {
            Livewire::test(Dashboard::class)->set('site', $site)->set('days', 30)->set('search', 'nothing-matches')
                ->assertSee('No stations match your filters')->assertSee('Last 30 days')->call('nextStations')->assertSee('page 1 of 1');
        });
        $other = $this->createTenant('monitoring-other');
        $this->withinTenant($other, $user, function (): void {
            $this->connector();
        });
        $this->withinTenant($tenant, $user, function () use ($user): void {
            self::assertSame(1, app(MonitoringDashboardQuery::class)->read($user)['station_count']);
        });
    }

    public function test_retired_demo_locations_are_absent_from_operational_dashboard(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('monitoring-retired-sites');
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $this->assignDirectly($tenant, $this->createMembership($tenant, $user), $this->createRole($tenant, 'reporter', [PermissionKey::ReportingView, PermissionKey::LocationView, PermissionKey::AssetView, PermissionKey::ChargingSessionView]));
            $active = $this->connector()->evse->station->site;
            $active->update(['name' => 'LUMINA SUBIC', 'lifecycle_status' => 'active']);
            $retired = $this->connector()->evse->station->site;
            $retired->update(['name' => 'Bayside Exchange', 'is_active' => false, 'lifecycle_status' => 'retired']);

            $data = app(MonitoringDashboardQuery::class)->read($user);
            self::assertSame(['LUMINA SUBIC'], array_column($data['sites'], 'name'));
            self::assertSame(1, $data['station_count']);
            try {
                app(MonitoringDashboardQuery::class)->read($user, siteId: (string) $retired->getKey());
                self::fail('A retired location must not be selectable on the operational dashboard.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        });
    }

    private function connector(): Connector
    {
        $connector = Connector::factory()->create(['lifecycle_status' => 'active']);
        $connector->evse->update(['lifecycle_status' => 'active']);
        $connector->evse->station->update(['lifecycle_status' => 'active']);

        return $connector;
    }
}
