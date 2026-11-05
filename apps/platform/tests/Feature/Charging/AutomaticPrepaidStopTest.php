<?php

declare(strict_types=1);

namespace Tests\Feature\Charging;

use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Assets\Domain\Models\OcppVersion;
use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Charging\Application\CustomerCharging;
use App\Modules\Charging\Application\Gateway\GatewayCommandClient;
use App\Modules\Charging\Application\Gateway\GatewayCommandResult;
use App\Modules\Charging\Application\OcppEventConsumer;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ChargingStateTransition;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\Charging\Domain\Models\MeterReading;
use App\Modules\Charging\Domain\Models\StationConnection;
use App\Modules\Charging\Jobs\DispatchChargerCommand;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Tariffs\Application\PortalPricing;
use App\Modules\Tariffs\Application\TariffManagementService;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\Queue\InvalidTenantJobContext;
use App\Modules\Tenancy\Application\Queue\TenantJobEnvelope;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TenantSecurityTestCase;

final class AutomaticPrepaidStopTest extends TenantSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Queue::fake();
        config(['kyc.required_for_charging' => false, 'services.ocpp_gateway.timeout_seconds' => 20]);
        ConnectorStandard::query()->create(['code' => 'type2', 'name' => 'Type 2']);
        ChargingCurrentType::query()->create(['code' => 'ac', 'name' => 'AC']);
    }

    /** @return array<string, array{int, int}> */
    public static function budgets(): array
    {
        return ['one peso' => [100, 800], 'five hundred pesos' => [50000, 499800]];
    }

    #[DataProvider('budgets')]
    public function test_forecast_accounts_for_reading_age_before_exhausting_budget(int $budget, int $energyWh): void
    {
        [$tenant, $actor, $session] = $this->start($budget);
        $this->withinTenant($tenant, $actor, function () use ($session, $energyWh): void {
            $session->forceFill(['energy_wh' => $energyWh])->save();
            $this->reading($session, 1000 + $energyWh, 'Energy.Active.Import.Register', 'Wh');
        });
        Artisan::call('charging:monitor-prepaid');
        $this->withinTenant($tenant, $actor, fn () => self::assertSame(0, $session->commands()->where('type', 'remote_stop')->count()));
        $this->travel(20)->seconds();
        Artisan::call('charging:monitor-prepaid');
        Artisan::call('charging:monitor-prepaid');
        $this->withinTenant($tenant, $actor, function () use ($session, $budget): void {
            self::assertSame(1, $session->commands()->where('type', 'remote_stop')->count());
            $evidence = ChargingStateTransition::query()->where('reason', 'prepaid_stop_forecast')->sole()->evidence;
            self::assertSame(110, $evidence['lookahead_seconds']);
            self::assertSame(20, $evidence['reading_age_seconds']);
            self::assertGreaterThanOrEqual($budget, $evidence['projected_cost_minor']);
            self::assertSame('stopping', $session->refresh()->state->value);
        });
    }

    public function test_forecast_includes_recent_observed_queue_delay(): void
    {
        [$tenant, $actor, $session] = $this->start();
        $this->withinTenant($tenant, $actor, function () use ($session): void {
            $session->forceFill(['energy_wh' => 800])->save();
            $this->reading($session, 1800, 'Energy.Active.Import.Register', 'Wh');
            $session->commands()->where('type', 'remote_start')->firstOrFail()->forceFill([
                'created_at' => now('UTC')->subSeconds(20), 'dispatched_at' => now('UTC'),
            ])->save();
        });
        Artisan::call('charging:monitor-prepaid');
        $this->withinTenant($tenant, $actor, function (): void {
            $evidence = ChargingStateTransition::query()->where('reason', 'prepaid_stop_forecast')->sole()->evidence;
            self::assertSame(20, $evidence['queue_delay_seconds']);
            self::assertSame(110, $evidence['lookahead_seconds']);
        });
    }

    /** @return array<string, array{string}> */
    public static function uncertainOutcomes(): array
    {
        return ['accepted without final evidence' => ['completed'], 'timeout' => ['timed_out'], 'offline' => ['not_connected']];
    }

    #[DataProvider('uncertainOutcomes')]
    public function test_uncertain_stop_retries_only_after_fresh_continuing_power_on_same_transaction(string $outcome): void
    {
        [$tenant, $actor, $session] = $this->start();
        $this->withinTenant($tenant, $actor, fn () => $session->forceFill(['energy_wh' => 900])->save());
        Artisan::call('charging:monitor-prepaid');
        $command = $this->stopCommand($tenant, $actor, $session);
        $gateway = $this->createMock(GatewayCommandClient::class);
        $gateway->expects(self::once())->method('dispatch')->willReturn(new GatewayCommandResult($outcome, ['status' => 'Accepted'], null));
        $this->runJob($command, $gateway);
        // Duplicate worker delivery never issues another physical command.
        $this->runJob($command, $gateway);
        $this->travel(90)->seconds();
        $this->withinTenant($tenant, $actor, function () use ($session): void {
            StationConnection::query()->updateOrCreate(['charging_station_id' => $session->charging_station_id], [
                'connection_id' => (string) Str::ulid(), 'connected' => true, 'connected_at' => now('UTC')->subSeconds(90),
                'last_event_at' => now('UTC'), 'last_seen_at' => now('UTC'),
            ]);
        });
        Artisan::call('charging:monitor-prepaid');
        $this->withinTenant($tenant, $actor, function () use ($session): void {
            self::assertSame(1, $session->commands()->where('type', 'remote_stop')->count());
            $this->reading($session, 0);
        });
        Artisan::call('charging:monitor-prepaid');
        $this->travel(1)->seconds();
        $this->withinTenant($tenant, $actor, function () use ($session): void {
            self::assertSame(1, $session->commands()->where('type', 'remote_stop')->count());
            $this->reading($session, 7000);
            StationConnection::query()->where('charging_station_id', $session->charging_station_id)->update(['connected' => false]);
        });
        Artisan::call('charging:monitor-prepaid');
        $this->withinTenant($tenant, $actor, function () use ($session): void {
            self::assertSame(1, $session->commands()->where('type', 'remote_stop')->count());
            StationConnection::query()->where('charging_station_id', $session->charging_station_id)->update(['connected' => true]);
        });
        Artisan::call('charging:monitor-prepaid');
        Artisan::call('charging:monitor-prepaid');
        $this->withinTenant($tenant, $actor, function () use ($session): void {
            self::assertSame(2, $session->commands()->where('type', 'remote_stop')->count());
            self::assertNull(CustomerChargingSession::query()->where('session_id', $session->id)->firstOrFail()->settled_at);
            self::assertSame(1, ChargingStateTransition::query()->where('reason', 'automatic_stop_unconfirmed')->count());
        });
    }

    public function test_service_stop_cannot_cross_tenant_or_forge_intent_or_driver(): void
    {
        [$tenant, $actor, $session] = $this->start();
        $other = $this->createTenant('stop-other-tenant');
        $this->withinTenant($tenant, $actor, fn () => $session->forceFill(['energy_wh' => 900])->save());
        Artisan::call('charging:monitor-prepaid');
        $command = $this->stopCommand($tenant, $actor, $session);
        foreach ([[$other->id, $command->id], [$tenant->id, (string) Str::ulid()]] as [$tenantId, $cause]) {
            $job = new DispatchChargerCommand((string) $command->id, new TenantJobEnvelope((string) Str::ulid(), 1,
                (string) $tenantId, ActorType::Service, null, (string) Str::ulid(), (string) $cause));
            $this->rejectJob($job);
        }
        $this->withinTenant($tenant, $actor, fn () => $command->update(['actor_id' => (string) Str::ulid()]));
        $this->rejectJob($this->queuedJob($command));
        $this->withinTenant($tenant, $actor, fn () => $command->update(['actor_id' => $actor->public_id, 'reason_code' => 'driver.remote_stop']));
        $this->rejectJob($this->queuedJob($command));
    }

    public function test_wh_only_charger_recovers_an_unconfirmed_stop_after_two_increasing_readings(): void
    {
        [$tenant, $actor, $session] = $this->start();
        $this->withinTenant($tenant, $actor, fn () => $session->forceFill(['energy_wh' => 900])->save());
        Artisan::call('charging:monitor-prepaid');
        $command = $this->stopCommand($tenant, $actor, $session);
        $gateway = $this->createMock(GatewayCommandClient::class);
        $gateway->expects(self::once())->method('dispatch')->willReturn(new GatewayCommandResult('completed', ['status' => 'Accepted'], null));
        $this->runJob($command, $gateway);
        $this->travel(90)->seconds();
        $this->withinTenant($tenant, $actor, function () use ($session): void {
            StationConnection::query()->updateOrCreate(['charging_station_id' => $session->charging_station_id], [
                'connection_id' => (string) Str::ulid(), 'connected' => true, 'connected_at' => now('UTC')->subSeconds(90),
                'last_event_at' => now('UTC'), 'last_seen_at' => now('UTC'),
            ]);
            $this->reading($session, 1900, 'Energy.Active.Import.Register', 'Wh');
        });
        Artisan::call('charging:monitor-prepaid');
        $this->withinTenant($tenant, $actor, fn () => self::assertSame(1, $session->commands()->where('type', 'remote_stop')->count()));
        $this->travel(10)->seconds();
        $this->withinTenant($tenant, $actor, fn () => $this->reading($session, 1920, 'Energy.Active.Import.Register', 'Wh'));
        Artisan::call('charging:monitor-prepaid');
        $this->withinTenant($tenant, $actor, fn () => self::assertSame(2, $session->commands()->where('type', 'remote_stop')->count()));
    }

    public function test_in_flight_duplicate_job_never_sends_again_and_service_cannot_retarget_201_transaction(): void
    {
        [$tenant, $actor, $session] = $this->start();
        $this->withinTenant($tenant, $actor, fn () => $session->forceFill(['energy_wh' => 900])->save());
        Artisan::call('charging:monitor-prepaid');
        $command = $this->stopCommand($tenant, $actor, $session);
        $this->withinTenant($tenant, $actor, fn () => $command->update(['state' => 'dispatched', 'dispatched_at' => now('UTC')]));
        $gateway = $this->createMock(GatewayCommandClient::class);
        $gateway->expects(self::never())->method('dispatch');
        $this->runJob($command, $gateway);
        $this->withinTenant($tenant, $actor, function () use ($command, $session): void {
            $session->update(['protocol' => 'ocpp2.0.1', 'protocol_transaction_id' => 'car-session-201']);
            $command->update(['state' => 'requested', 'dispatched_at' => null,
                'ocpp_action' => 'RequestStopTransaction', 'payload' => ['transaction_id' => 'car-session-201']]);
            self::assertTrue($command->refresh()->targetsTransaction($session->refresh()));
            $session->update(['protocol_transaction_id' => 'different-transaction']);
            self::assertFalse($command->targetsTransaction($session->refresh()));
        });
        $this->rejectJob($this->queuedJob($command));
    }

    public function test_service_job_cannot_dispatch_a_start_command(): void
    {
        [$tenant, $actor, $session] = $this->start();
        $command = $this->withinTenant($tenant, $actor, fn () => $session->commands()->where('type', 'remote_start')->firstOrFail());
        $job = new DispatchChargerCommand((string) $command->id, new TenantJobEnvelope((string) Str::ulid(), 1,
            (string) $tenant->id, ActorType::Service, null, (string) Str::ulid(), (string) $command->id));
        $this->rejectJob($job);
    }

    public function test_late_stop_job_does_not_dispatch_after_physical_completion_or_retarget_transaction(): void
    {
        [$tenant, $actor, $session] = $this->start();
        $this->withinTenant($tenant, $actor, fn () => $session->forceFill(['energy_wh' => 900])->save());
        Artisan::call('charging:monitor-prepaid');
        $command = $this->stopCommand($tenant, $actor, $session);
        $this->withinTenant($tenant, $actor, fn () => $session->update(['protocol_transaction_id' => '94']));
        $this->rejectJob($this->queuedJob($command));
        $this->withinTenant($tenant, $actor, fn () => $session->update(['protocol_transaction_id' => '92', 'state' => 'completed', 'stopped_at' => now('UTC')]));
        $gateway = $this->createMock(GatewayCommandClient::class);
        $gateway->expects(self::never())->method('dispatch');
        $this->runJob($command, $gateway);
    }

    private function rejectJob(DispatchChargerCommand $job): void
    {
        try {
            $job->middleware()[0]->handle($job, fn () => self::fail('Unauthorized job reached handler.'));
            self::fail('Unauthorized service stop was accepted.');
        } catch (InvalidTenantJobContext) {
            self::assertFalse(app(CurrentTenant::class)->has());
        }
    }

    private function queuedJob(ChargerCommand $command): DispatchChargerCommand
    {
        $job = null;
        Queue::assertPushed(DispatchChargerCommand::class, function (DispatchChargerCommand $candidate) use ($command, &$job): bool {
            if ($candidate->commandId !== $command->id) {
                return false;
            }
            $job = $candidate;

            return true;
        });
        self::assertInstanceOf(DispatchChargerCommand::class, $job);

        return $job;
    }

    private function runJob(ChargerCommand $command, GatewayCommandClient $gateway): void
    {
        $this->app->instance(GatewayCommandClient::class, $gateway);
        $job = $this->queuedJob($command);
        $job->middleware()[0]->handle($job, fn (DispatchChargerCommand $job) => app()->call([$job, 'handle']));
    }

    private function stopCommand(Tenant $tenant, User $actor, ChargingSession $session): ChargerCommand
    {
        return $this->withinTenant($tenant, $actor, fn () => $session->commands()->where('type', 'remote_stop')->latest('id')->firstOrFail());
    }

    private function reading(ChargingSession $session, int $value, string $measurand = 'Power.Active.Import', string $unit = 'W'): void
    {
        MeterReading::query()->create(['session_id' => $session->id, 'event_id' => (string) Str::ulid(), 'sample_index' => 0,
            'sampled_at' => now('UTC'), 'received_at' => now('UTC'), 'measurand' => $measurand, 'unit' => $unit, 'value' => $value]);
    }

    /** @return array{Tenant, User, ChargingSession} */
    private function start(int $budget = 100): array
    {
        config(['mobile_charging.maximum_reservation_minor' => $budget]);
        $tenant = $this->createTenant('automatic-'.Str::lower((string) Str::ulid()));
        $actor = $this->createUser();
        $session = $this->withinTenant($tenant, $actor, function () use ($tenant, $actor, $budget): ChargingSession {
            $this->createMembership($tenant, $actor);
            $site = Site::factory()->activePublic()->create();
            $ocpp = OcppVersion::query()->firstOrCreate(['code' => '1.6J'], ['name' => 'OCPP 1.6J']);
            $station = ChargingStation::factory()->activePublic()->create(['site_id' => $site->id, 'ocpp_version_id' => $ocpp->id]);
            $evse = Evse::factory()->create(['charging_station_id' => $station->id, 'lifecycle_status' => 'active', 'evse_number' => 1]);
            $connector = Connector::factory()->create(['evse_id' => $evse->id, 'lifecycle_status' => 'active', 'connector_number' => 1, 'maximum_power_w' => 7000]);
            ConnectorStatus::query()->create(['connector_id' => $connector->id, 'status' => 'occupied', 'reported_status' => 'preparing', 'observed_at' => now(), 'stale_after_seconds' => 300]);
            $tariff = app(TariffManagementService::class)->create(['name' => 'PHP 1/kWh', 'currency' => 'PHP']);
            app(PortalPricing::class)->publish($tariff, ['mode' => 'energy', 'price' => '1.00', 'time_unit' => 'minute', 'timezone' => 'Asia/Manila', 'tax_treatment' => 'inclusive', 'tax_rate_basis_points' => 0, 'connector_id' => $connector->id]);
            app(PrepaidWallet::class)->credit($actor->public_id, 'live', (string) Str::ulid(), $budget);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->id);
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->id, 'automatic-start');
            $result = app(OcppEventConsumer::class)->consume([
                'event_id' => (string) Str::ulid(), 'event_type' => 'gateway.ocpp.start_transaction.received.v1', 'schema_version' => 1,
                'tenant_id' => $tenant->id, 'occurred_at' => now('UTC')->toISOString(), 'aggregate_type' => 'charging_station',
                'aggregate_id' => $station->id, 'correlation_id' => (string) Str::ulid(), 'causation_id' => (string) Str::ulid(),
                'data' => ['charge_point_identity' => $station->charge_point_identity, 'protocol' => 'ocpp1.6',
                    'protocol_timestamp' => now('UTC')->toISOString(), 'clock_skew_detected' => false,
                    'connector_id' => 1, 'protocol_transaction_id' => 92, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted'],
            ]);
            self::assertSame('processed', $result->outcome);

            return $session->refresh();
        });

        return [$tenant, $actor, $session];
    }
}
