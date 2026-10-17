<?php

declare(strict_types=1);

namespace Tests\Feature\Charging;

use App\Models\User;
use App\Modules\Assets\Application\DriverVehicles;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Assets\Domain\Models\OcppVersion;
use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Charging\Application\CommandOutcomeService;
use App\Modules\Charging\Application\CustomerCharging;
use App\Modules\Charging\Application\MeterValueService;
use App\Modules\Charging\Application\OcppEventConsumer;
use App\Modules\Charging\Application\PrepaidChargingMonitor;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Application\ChargingAccounts;
use App\Modules\Organizations\Domain\ChargingAccountRole;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Tariffs\Application\PortalPricing;
use App\Modules\Tariffs\Application\TariffManagementService;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantSecurityTestCase;

final class CustomerChargingTest extends TenantSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Queue::fake();
        ConnectorStandard::query()->create(['code' => 'type2', 'name' => 'Type 2']);
        ChargingCurrentType::query()->create(['code' => 'ac', 'name' => 'AC']);
        config(['kyc.required_for_charging' => false]);
    }

    public function test_customer_start_reserves_once_and_final_energy_settles_once(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('mobile-charging');
        $this->withinTenant($tenant, $actor, function () use ($actor, $tenant): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            self::assertSame(100, $quote['quote']['lines'][0]['price_minor']);
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'customer-start-01');
            self::assertSame((string) $session->getKey(), $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'customer-start-01')->id);
            self::assertSame('starting', $session->state->value);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
            $start = now('UTC');
            $this->event((string) $tenant->getKey(), $connector, 'start_transaction', ['connector_id' => 1, 'protocol_transaction_id' => 91, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted']);
            $this->travel(150)->seconds();
            app(MeterValueService::class)->record($session->refresh(), (string) Str::ulid(), [
                ['timestamp' => now('UTC')->toISOString(), 'measurand' => 'Energy.Active.Import.Register', 'unit' => 'Wh', 'value' => 1500],
                ['timestamp' => now('UTC')->toISOString(), 'measurand' => 'Energy.Active.Import.Register', 'unit' => 'Wh', 'value' => 500, 'phase' => 'L1'],
            ], CarbonImmutable::now('UTC'));
            self::assertSame(500, $session->refresh()->energy_wh);
            $this->travel(150)->seconds();
            $this->event((string) $tenant->getKey(), $connector, 'stop_transaction', ['protocol_transaction_id' => 91, 'meter_stop_wh' => 1750, 'reason' => 'Remote', 'samples' => []]);
            $session->refresh();
            self::assertSame('completed', $session->state->value);
            self::assertSame(75, $session->final_cost_minor);
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            $balance = app(PrepaidWallet::class)->summary($actor->public_id, 'live');
            self::assertSame(25, $balance['available_minor']);
            self::assertSame(0, $balance['reserved_minor']);
            self::assertSame('captured', $service->present($session)['payment_state']);
            $this->assertDatabaseCount('charging_customer_sessions', 1);
        });
    }

    public function test_stale_quote_and_different_owner_are_rejected_without_reserving_funds(): void
    {
        $actor = $this->createUser();
        $other = $this->createUser();
        $tenant = $this->createTenant('quote-ownership');
        $this->withinTenant($tenant, $actor, function () use ($actor, $other): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            try {
                $service->start($other->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'other-owner-01');
                self::fail('Another customer must not consume this quote.');
            } catch (ModelNotFoundException) {
                self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['available_minor']);
            }
            $this->travel(121)->seconds();
            $this->expectException(DomainException::class);
            $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'expired-start-01');
        });
    }

    public function test_fractional_meter_timestamps_store_whole_duration_seconds(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13T08:00:00Z'));
        $actor = $this->createUser();
        $tenant = $this->createTenant('fractional-meter');
        $this->withinTenant($tenant, $actor, function () use ($actor, $tenant): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'fractional-meter-01');
            $this->event((string) $tenant->getKey(), $connector, 'start_transaction', ['connector_id' => 1, 'protocol_transaction_id' => 92, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted']);
            $receivedAt = CarbonImmutable::parse('2026-10-13T08:00:01.875Z');
            app(MeterValueService::class)->record($session->refresh(), (string) Str::ulid(), [
                ['timestamp' => $receivedAt->toISOString(), 'measurand' => 'Energy.Active.Import.Register', 'unit' => 'Wh', 'value' => 1001],
            ], $receivedAt);
            self::assertSame(1, $session->refresh()->duration_seconds);
            self::assertSame(1, $session->energy_wh);
        });
    }

    public function test_budget_monitor_requests_stop_but_does_not_settle_before_final_evidence(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('budget-stop');
        $this->withinTenant($tenant, $actor, function () use ($actor, $tenant): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'budget-start-01');
            $this->event((string) $tenant->getKey(), $connector, 'start_transaction', ['connector_id' => 1, 'protocol_transaction_id' => 92, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted']);
            $session->refresh()->forceFill(['energy_wh' => 900, 'estimated_cost_minor' => 90])->save();
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            self::assertSame('stopping', $session->refresh()->state->value);
            self::assertSame(1, ChargerCommand::query()->where('type', 'remote_stop')->count());
            self::assertNull(CustomerChargingSession::query()->firstOrFail()->settled_at);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
        });
    }

    public function test_mobile_http_routes_scope_sessions_to_token_owner_and_validate_start(): void
    {
        config(['features.remote_charging' => true]);
        $actor = $this->createUser();
        $other = $this->createUser();
        $tenant = $this->createTenant('mobile-http');
        $otherTenant = $this->createTenant('mobile-http-other');
        $this->withinTenant($tenant, $actor, fn () => $this->createMembership($tenant, $actor));
        $this->withinTenant($tenant, $other, fn () => $this->createMembership($tenant, $other));
        $this->withinTenant($otherTenant, $actor, fn () => $this->createMembership($otherTenant, $actor));
        $connector = $this->withinTenant($tenant, $actor, fn () => $this->fixture($actor));
        $login = fn (User $user, string $tenantId): string => (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password', 'tenant_id' => $tenantId, 'device_name' => 'Charging acceptance',
        ])->assertOk()->json('data.token');
        $token = $login($actor, (string) $tenant->getKey());
        $headers = ['Authorization' => 'Bearer '.$token];
        $quote = $this->postJson('/api/v1/mobile/charging/prepare', ['charger_code' => (string) $connector->getKey()], $headers)->assertOk()->json('data.quote.id');
        $data = ['quote_id' => $quote, 'connector_id' => (string) $connector->getKey(), 'payment_method_id' => 'prepaid_wallet'];
        $this->postJson('/api/v1/mobile/charging-sessions/remote-start', $data, $headers)->assertUnprocessable();
        $started = $this->postJson('/api/v1/mobile/charging-sessions/remote-start', $data, $headers + ['Idempotency-Key' => 'http-start-001'])->assertStatus(202);
        $id = $started->json('data.id');
        $this->getJson('/api/v1/mobile/charging-sessions/'.$id, $headers)->assertOk();
        $this->getJson('/api/v1/mobile/charging-sessions/active', $headers)->assertOk()->assertJsonPath('data.id', $id);
        $otherHeaders = ['Authorization' => 'Bearer '.$login($other, (string) $tenant->getKey())];
        $this->getJson('/api/v1/mobile/charging-sessions/'.$id, $otherHeaders)->assertNotFound();
        $this->postJson('/api/v1/mobile/charging-sessions/'.$id.'/remote-stop', [], $otherHeaders + ['Idempotency-Key' => 'other-stop-001'])->assertNotFound();
        $this->postJson('/api/v1/mobile/charging-sessions/'.$id.'/cancel', ['reason' => 'customer_cancelled_before_start'], $otherHeaders)->assertNotFound();
        $this->getJson('/api/v1/mobile/charging-sessions', $otherHeaders)->assertOk()->assertJsonCount(0, 'data');
        $crossHeaders = ['Authorization' => 'Bearer '.$login($actor, (string) $otherTenant->getKey())];
        $this->getJson('/api/v1/mobile/charging-sessions/'.$id, $crossHeaders)->assertNotFound();
        $this->postJson('/api/v1/mobile/charging-sessions/'.$id.'/cancel', ['reason' => 'customer_cancelled_before_start'], $crossHeaders)->assertNotFound();
    }

    public function test_unknown_start_retains_hold_and_connector_reservation(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('unknown-start');
        $this->withinTenant($tenant, $actor, function () use ($actor): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'unknown-start-01');
            $command = ChargerCommand::query()->where('session_id', $session->getKey())->firstOrFail();
            $command->forceFill(['state' => 'delivery_unknown', 'dispatched_at' => now()])->save();
            app(CommandOutcomeService::class)->apply($command);
            self::assertSame('starting', $session->refresh()->state->value);
            self::assertSame('prepaid_start_unconfirmed', $session->failure_reason);
            $this->travel(600)->seconds();
            $currentTenant = app(CurrentTenant::class);
            $context = $currentTenant->get();
            $currentTenant->clear();
            self::assertSame(0, Artisan::call('charging:expire-operations'));
            $currentTenant->establish($context);
            self::assertSame('starting', $session->refresh()->state->value);
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
            self::assertSame('requires_action', $service->present($session)['payment_state']);
            self::assertNotNull($service->active($actor->public_id));
        });
    }

    public function test_cancel_dispatched_start_is_durable_and_stops_a_late_transaction_once(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('late-cancel');
        $this->withinTenant($tenant, $actor, function () use ($actor, $tenant): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'late-cancel-start');
            $id = (string) $session->getKey();
            $command = ChargerCommand::query()->where('session_id', $id)->firstOrFail();
            $command->forceFill(['state' => 'delivery_unknown', 'dispatched_at' => now()])->save();
            app(CommandOutcomeService::class)->apply($command);
            $service->cancel($actor->public_id, $id, null);
            $service->cancel($actor->public_id, $id, null);
            self::assertTrue($service->present($session->refresh())['cancellation_requested']);
            self::assertSame('starting', $session->state->value);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame(0, ChargerCommand::query()->where('session_id', $id)->where('type', 'remote_stop')->count());
            $this->event((string) $tenant->getKey(), $connector, 'start_transaction', ['connector_id' => 1, 'protocol_transaction_id' => 98, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted']);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame('stopping', $session->refresh()->state->value);
            self::assertSame(1, ChargerCommand::query()->where('session_id', $id)->where('type', 'remote_stop')->count());
            $this->event((string) $tenant->getKey(), $connector, 'stop_transaction', ['protocol_transaction_id' => 98, 'meter_stop_wh' => 1010, 'reason' => 'Remote', 'samples' => []]);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame(0, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
            self::assertSame(99, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['available_minor']);
        });
    }

    public function test_cancelled_pending_start_releases_hold_only_after_definite_rejection(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('cancel-rejection');
        $this->withinTenant($tenant, $actor, function () use ($actor): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'cancel-rejected-start');
            $id = (string) $session->getKey();
            $command = ChargerCommand::query()->where('session_id', $id)->firstOrFail();
            $command->forceFill(['state' => 'dispatched', 'dispatched_at' => now()])->save();
            $service->cancel($actor->public_id, $id, null);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
            $command->forceFill(['state' => 'rejected'])->save();
            app(CommandOutcomeService::class)->apply($command);
            $service->cancel($actor->public_id, $id, null);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame(0, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['available_minor']);
            self::assertNull($service->active($actor->public_id));
        });
    }

    public function test_cancel_before_dispatch_releases_wallet_once(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('unsent-cancel');
        $this->withinTenant($tenant, $actor, function () use ($actor): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'cancel-start-01');
            $service->cancel($actor->public_id, (string) $session->getKey(), null);
            $service->cancel($actor->public_id, (string) $session->getKey(), null);
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['available_minor']);
            self::assertSame(0, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
        });
    }

    public function test_shared_vehicle_session_keeps_original_payer_after_driver_revocation_and_settles_once(): void
    {
        $actor = $this->createUser();
        $driver = $this->createUser();
        $tenant = $this->createTenant('shared-charge');
        $this->withinTenant($tenant, $actor, function () use ($actor, $driver, $tenant): void {
            $connector = $this->fixture($actor);
            $this->createMembership($tenant, $driver);
            $accounts = app(ChargingAccounts::class);
            $account = (string) $accounts->create($actor, 'Fleet payer', 'fleet')->getKey();
            $accounts->join($driver, $actor, $account, ChargingAccountRole::Driver);
            $vehicles = app(DriverVehicles::class);
            $vehicle = (string) Str::ulid();
            $vehicles->save($actor->public_id, $vehicle, ['nickname' => 'Shared car', 'plate_pending' => true], $account);
            $vehicles->assign($actor->public_id, $account, $vehicle, $driver->public_id, true);
            app(PrepaidWallet::class)->credit($actor->public_id, 'live', (string) Str::ulid(), 100, $account);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($driver->public_id, (string) $connector->getKey(), $account, $vehicle);
            self::assertSame($driver->public_id, $quote['attribution']['driver_id']);
            self::assertSame($vehicle, $quote['attribution']['vehicle']['id']);
            self::assertSame($account, $quote['attribution']['account_id']);
            $session = $service->start($driver->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'shared-start-01', $account);
            self::assertSame((string) $session->getKey(), $service->start($driver->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'shared-start-01', $account)->id);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['available_minor']);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live', $account)['reserved_minor']);
            $this->event((string) $tenant->getKey(), $connector, 'start_transaction', ['connector_id' => 1, 'protocol_transaction_id' => 97, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted']);
            $accounts->member($actor, $account, $driver->public_id, null);
            self::assertSame((string) $session->getKey(), (string) $service->controllable($actor->public_id, (string) $session->getKey())->getKey());
            try {
                $service->owned($driver->public_id, (string) $session->getKey());
                self::fail('Revoked driver read shared session');
            } catch (HttpException $e) {
                self::assertSame(404, $e->getStatusCode());
            }
            $this->travel(300)->seconds();
            $this->event((string) $tenant->getKey(), $connector, 'stop_transaction', ['protocol_transaction_id' => 97, 'meter_stop_wh' => 1750, 'reason' => 'Remote', 'samples' => []]);
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            self::assertSame(25, app(PrepaidWallet::class)->summary($actor->public_id, 'live', $account)['available_minor']);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['available_minor']);
            $owner = CustomerChargingSession::query()->firstOrFail();
            self::assertSame($account, $owner->payer_account_id);
            self::assertSame($vehicle, $owner->vehicle_id);
            self::assertNotNull($owner->prepaid_account_id);
            self::assertSame('captured', $owner->payment_state);
        });
    }

    public function test_start_rechecks_account_and_vehicle_permission_and_changed_spending_cap(): void
    {
        $actor = $this->createUser();
        $driver = $this->createUser();
        $tenant = $this->createTenant('shared-recheck');
        $this->withinTenant($tenant, $actor, function () use ($actor, $driver, $tenant): void {
            $connector = $this->fixture($actor);
            $this->createMembership($tenant, $driver);
            $accounts = app(ChargingAccounts::class);
            $account = (string) $accounts->create($actor, 'Recheck fleet', 'fleet')->getKey();
            $accounts->join($driver, $actor, $account, ChargingAccountRole::Driver);
            $vehicles = app(DriverVehicles::class);
            $vehicle = (string) Str::ulid();
            $vehicles->save($actor->public_id, $vehicle, ['nickname' => 'Fleet car', 'plate_pending' => true], $account);
            $wallet = app(PrepaidWallet::class);
            $wallet->credit($actor->public_id, 'live', (string) Str::ulid(), 100, $account);
            $service = app(CustomerCharging::class);
            try {
                $service->prepare($driver->public_id, (string) $connector->getKey(), $account, $vehicle);
                self::fail('Unassigned vehicle used');
            } catch (HttpException $e) {
                self::assertSame(404, $e->getStatusCode());
            }
            $vehicles->assign($actor->public_id, $account, $vehicle, $driver->public_id, true);
            $quote = $service->prepare($driver->public_id, (string) $connector->getKey(), $account, $vehicle);
            $vehicles->assign($actor->public_id, $account, $vehicle, $driver->public_id, false);
            try {
                $service->start($driver->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'shared-denied-01', $account);
                self::fail('Revoked assignment started');
            } catch (HttpException $e) {
                self::assertSame(404, $e->getStatusCode());
            }
            $vehicles->assign($actor->public_id, $account, $vehicle, $driver->public_id, true);
            $wallet->setLimit($actor->public_id, $account, 'vehicle', $vehicle, 50);
            try {
                $service->start($driver->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'shared-denied-01', $account);
                self::fail('Changed cap bypassed');
            } catch (ValidationException) {
            }
            self::assertSame(0, ChargerCommand::query()->count());
            self::assertSame(0, CustomerChargingSession::query()->count());
            self::assertSame(100, $wallet->summary($actor->public_id, 'live', $account)['available_minor']);
            $quote = $service->prepare($driver->public_id, (string) $connector->getKey(), $account, $vehicle);
            self::assertSame(50, $quote['quote']['estimated_preauthorization']['amount_minor']);
            $accounts->member($actor, $account, $driver->public_id, null);
            try {
                $service->start($driver->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'shared-denied-02', $account);
                self::fail('Revoked membership started');
            } catch (HttpException $e) {
                self::assertSame(404, $e->getStatusCode());
            }
        });
    }

    public function test_personal_account_history_includes_legacy_sessions_but_does_not_leak_to_other_people(): void
    {
        $actor = $this->createUser();
        $other = $this->createUser();
        $tenant = $this->createTenant('legacy-account-history');
        [$account, $sessionId] = $this->withinTenant($tenant, $actor, function () use ($actor, $other, $tenant): array {
            $connector = $this->fixture($actor);
            $this->createMembership($tenant, $other);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'legacy-start-001');
            $service->cancel($actor->public_id, (string) $session->getKey(), null);
            CustomerChargingSession::query()->where('session_id', $session->getKey())->update(['charging_account_id' => null]);

            return [(string) app(ChargingAccounts::class)->personal($actor)->getKey(), (string) $session->getKey()];
        });
        $token = $this->postJson('/api/v1/auth/login', ['email' => $actor->email, 'password' => 'password', 'tenant_id' => $tenant->getKey(), 'device_name' => 'History test'])->assertOk()->json('data.token');
        $this->getJson('/api/v1/mobile/charging-sessions', ['Authorization' => 'Bearer '.$token, 'X-Charging-Account-ID' => $account])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sessionId);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'password', 'tenant_id' => $tenant->getKey(), 'device_name' => 'Other test'])->assertOk()->json('data.token');
        $this->getJson('/api/v1/mobile/charging-sessions', ['Authorization' => 'Bearer '.$token, 'X-Charging-Account-ID' => $account])->assertNotFound();
    }

    private function fixture(User $actor): Connector
    {
        Membership::query()->firstOrCreate(['user_id' => $actor->getKey()], ['status' => 'active', 'joined_at' => now('UTC')]);
        $site = Site::factory()->activePublic()->create();
        $ocpp = OcppVersion::query()->firstOrCreate(['code' => '1.6J'], ['name' => 'OCPP 1.6J']);
        $station = ChargingStation::factory()->activePublic()->create(['site_id' => $site->id, 'ocpp_version_id' => $ocpp->id]);
        $evse = Evse::factory()->create(['charging_station_id' => $station->id, 'lifecycle_status' => 'active', 'evse_number' => 1]);
        $connector = Connector::factory()->create(['evse_id' => $evse->id, 'lifecycle_status' => 'active', 'connector_number' => 1, 'maximum_power_w' => 7000]);
        ConnectorStatus::query()->create(['connector_id' => (string) $connector->getKey(), 'status' => 'occupied', 'reported_status' => 'preparing', 'observed_at' => now(), 'stale_after_seconds' => 300]);
        $tariff = app(TariffManagementService::class)->create(['name' => 'PHP 1/kWh', 'currency' => 'PHP']);
        app(PortalPricing::class)->publish($tariff, ['mode' => 'energy', 'price' => '1.00', 'time_unit' => 'minute', 'timezone' => 'Asia/Manila', 'tax_treatment' => 'inclusive', 'tax_rate_basis_points' => 0, 'connector_id' => (string) $connector->getKey()]);
        app(PrepaidWallet::class)->credit($actor->public_id, 'live', (string) Str::ulid(), 100);

        return $connector;
    }

    /** @param array<string, mixed> $data */
    private function event(string $tenant, Connector $connector, string $action, array $data): void
    {
        $result = app(OcppEventConsumer::class)->consume([
            'event_id' => (string) Str::ulid(), 'event_type' => 'gateway.ocpp.'.$action.'.received.v1',
            'schema_version' => 1, 'tenant_id' => $tenant, 'occurred_at' => now('UTC')->toISOString(),
            'aggregate_type' => 'charging_station', 'aggregate_id' => (string) $connector->evse->station->id,
            'correlation_id' => (string) Str::ulid(), 'causation_id' => (string) Str::ulid(),
            'data' => ['charge_point_identity' => $connector->evse->station->charge_point_identity, 'protocol' => 'ocpp1.6', 'protocol_timestamp' => now('UTC')->toISOString(), 'clock_skew_detected' => false, ...$data],
        ]);
        self::assertSame('processed', $result->outcome, (string) $result->errorCode);
    }
}
