<?php

declare(strict_types=1);

namespace Tests\Feature\Charging;

use App\Filament\Operator\Resources\ChargingStations\Pages\SetupChargingStation;
use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Assets\Domain\Models\OcppVersion;
use App\Modules\Charging\Application\ChargerCommandStateMachine;
use App\Modules\Charging\Application\CommandOutcomeService;
use App\Modules\Charging\Application\Gateway\GatewayCommandClient;
use App\Modules\Charging\Application\Gateway\GatewayCommandResult;
use App\Modules\Charging\Application\StationRecovery;
use App\Modules\Charging\Domain\ChargerCommandType;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Domain\Models\StationConnection;
use App\Modules\Charging\Jobs\DispatchChargerCommand;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\Queue\TenantJobEnvelope;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TenantSecurityTestCase;

final class StationRecoveryTest extends TenantSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Queue::fake();
        config(['features.ocpp' => true, 'features.remote_charging' => true]);
        ConnectorStandard::query()->create(['code' => 'TYPE_2', 'name' => 'Type 2']);
        ChargingCurrentType::query()->create(['code' => 'AC', 'name' => 'AC']);
    }

    public function test_station_commands_are_idempotent_and_do_not_create_sessions(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            $service = app(StationRecovery::class);
            $data = $this->data($connector);
            $command = $service->request($actor, (string) $station->getKey(), ChargerCommandType::UnlockConnector, $data, 'unlock-once');
            $again = $service->request($actor, (string) $station->getKey(), ChargerCommandType::UnlockConnector, $data, 'unlock-once');
            self::assertSame($command->getKey(), $again->getKey());
            self::assertNull($command->session_id);
            self::assertSame(['connector_id' => 1], $command->payload);
            self::assertSame(0, ChargingSession::query()->count());
            self::assertSame(1, ChargerCommand::query()->count());
            $this->assertDatabaseHas('audit_events', ['action' => 'charging.unlock_connector.requested', 'target_id' => $station->getKey()]);
            Queue::assertPushed(DispatchChargerCommand::class, 1);
            $this->expectException(ValidationException::class);
            $service->request($actor, (string) $station->getKey(), ChargerCommandType::RestartCharger, $data, 'restart-conflict');
        });
    }

    /** @return array<string,array{string,string,array<string,int|string>}> */
    public static function protocols(): array
    {
        return [
            '16 unlock' => ['1.6J', 'unlock_connector', ['connector_id' => 1]],
            '201 unlock' => ['2.0.1', 'unlock_connector', ['connector_id' => 1, 'evse_id' => 2]],
            '16 reset' => ['1.6J', 'restart_charger', ['type' => 'Soft']],
            '201 reset' => ['2.0.1', 'restart_charger', ['type' => 'OnIdle']],
        ];
    }

    /** @param array<string, int|string> $payload */
    #[DataProvider('protocols')]
    public function test_protocol_payloads_and_duplicate_dispatch(string $protocol, string $type, array $payload): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector) use ($type, $payload): void {
            $command = app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::from($type), $this->data($connector), 'protocol');
            self::assertSame($payload, $command->payload);
            $gateway = $this->createMock(GatewayCommandClient::class);
            $gateway->expects(self::once())->method('dispatch')->willReturn(new GatewayCommandResult('completed', ['status' => $type === 'unlock_connector' ? 'Unlocked' : 'Accepted'], null));
            $job = $this->job($command);
            $job->handle($gateway, app(ChargerCommandStateMachine::class), app(CommandOutcomeService::class));
            $job->handle($gateway, app(ChargerCommandStateMachine::class), app(CommandOutcomeService::class));
            self::assertSame('acknowledged', $command->fresh()->state->value);
            $this->assertDatabaseHas('integration_outbox_events', ['event_type' => 'charging.station_command.state_changed.v1']);
        }, $protocol);
    }

    public function test_revoked_authority_is_rechecked_before_dispatch(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            $command = app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::UnlockConnector, $this->data($connector), 'revoked');
            DB::table('role_permissions')->where('permission_key', PermissionKey::ChargingConnectorUnlock->value)->delete();
            $gateway = $this->createMock(GatewayCommandClient::class);
            $gateway->expects(self::never())->method('dispatch');
            $this->job($command)->handle($gateway, app(ChargerCommandStateMachine::class), app(CommandOutcomeService::class));
            self::assertSame('rejected', $command->fresh()->state->value);
        });
    }

    public function test_another_site_cannot_be_controlled(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            $other = ChargingStation::factory()->activePublic()->create();
            $this->expectException(ModelNotFoundException::class);
            app(StationRecovery::class)->request($actor, (string) $other->getKey(), ChargerCommandType::RestartCharger, $this->data($connector), 'wrong-site');
        });
    }

    public function test_cross_tenant_station_is_rejected_even_with_the_same_actor(): void
    {
        $foreignId = null;
        $foreign = $this->createTenant('foreign-recovery');
        $user = $this->createUser();
        $this->withinTenant($foreign, $user, function () use (&$foreignId): void {
            $foreignId = (string) ChargingStation::factory()->activePublic()->create()->getKey();
        });
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector) use ($foreignId): void {
            $this->expectException(ModelNotFoundException::class);
            app(StationRecovery::class)->request($actor, (string) $foreignId, ChargerCommandType::RestartCharger, $this->data($connector), 'wrong-tenant');
        });
    }

    public function test_pending_or_review_session_prevents_a_restart(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            ChargingSession::factory()->create([
                'charging_station_id' => $station->getKey(), 'site_id' => $station->site_id,
                'evse_id' => $connector->evse_id, 'connector_id' => $connector->getKey(),
                'charge_point_identity' => $station->charge_point_identity, 'state' => 'review_required',
            ]);
            $this->expectException(ValidationException::class);
            app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::RestartCharger, $this->data($connector), 'review');
        });
    }

    public function test_fresh_connection_cannot_reuse_a_stale_idle_report(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            ConnectorStatus::query()->where('connector_id', $connector->getKey())->update(['observed_at' => now('UTC')->subMinutes(10)]);
            $this->expectException(ValidationException::class);
            app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::UnlockConnector, $this->data($connector), 'stale');
        });
    }

    public function test_uncertain_delivery_is_recorded_once_and_blocks_immediate_repetition(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            $command = app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::RestartCharger, $this->data($connector), 'timeout');
            $gateway = $this->createMock(GatewayCommandClient::class);
            $gateway->expects(self::once())->method('dispatch')->willReturn(new GatewayCommandResult('timed_out', null, null));
            $job = $this->job($command);
            $job->handle($gateway, app(ChargerCommandStateMachine::class), app(CommandOutcomeService::class));
            $job->handle($gateway, app(ChargerCommandStateMachine::class), app(CommandOutcomeService::class));
            self::assertSame('timed_out', $command->fresh()->state->value);
            $this->expectException(ValidationException::class);
            app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::RestartCharger, $this->data($connector), 'duplicate-timeout');
        });
    }

    public function test_restart_requires_a_new_connection_and_fresh_idle_reports_before_reuse(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            $service = app(StationRecovery::class);
            $command = $service->request($actor, (string) $station->getKey(), ChargerCommandType::RestartCharger, $this->data($connector), 'accepted-reset');
            $gateway = $this->createMock(GatewayCommandClient::class);
            $gateway->expects(self::once())->method('dispatch')->willReturn(new GatewayCommandResult('completed', ['status' => 'Accepted'], null));
            $this->job($command)->handle($gateway, app(ChargerCommandStateMachine::class), app(CommandOutcomeService::class));
            try {
                $service->assertNoRecovery((string) $station->getKey());
                self::fail('A restart acknowledgement allowed an operation before reconnection.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('command', $exception->errors());
            }
            $this->travel(10)->seconds();
            $connection = (string) Str::ulid();
            StationConnection::query()->where('charging_station_id', $station->getKey())->update([
                'connection_id' => $connection, 'connected_at' => now('UTC'), 'last_seen_at' => now('UTC'),
            ]);
            ConnectorStatus::query()->where('connector_id', $connector->getKey())->update([
                'ocpp_connection_id' => $connection, 'observed_at' => now('UTC'),
            ]);
            $service->assertNoRecovery((string) $station->getKey());
            self::assertSame('acknowledged', $command->fresh()->state->value);
        });
    }

    /** @return list<array{string}> */
    public static function unsafeStates(): array
    {
        return array_map(fn (string $state): array => [$state], ['charging', 'preparing', 'suspendedev', 'suspendedevse', 'reserved', 'unknown']);
    }

    #[DataProvider('unsafeStates')]
    public function test_recovery_rejects_non_idle_device_status(string $state): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector) use ($state): void {
            ConnectorStatus::query()->where('connector_id', $connector->getKey())->update(['reported_status' => $state]);
            $this->expectException(ValidationException::class);
            app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::RestartCharger, $this->data($connector), 'unsafe-status');
        });
    }

    public function test_offline_and_stale_evidence_cannot_send_recovery(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            StationConnection::query()->where('charging_station_id', $station->getKey())->update(['connected' => false]);
            $this->expectException(ValidationException::class);
            app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::UnlockConnector, $this->data($connector), 'offline');
        });
    }

    public function test_connection_change_after_request_prevents_dispatch(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            $command = app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::RestartCharger, $this->data($connector), 'changed-socket');
            StationConnection::query()->where('charging_station_id', $station->getKey())->update(['connection_id' => (string) Str::ulid()]);
            $gateway = $this->createMock(GatewayCommandClient::class);
            $gateway->expects(self::never())->method('dispatch');
            $this->job($command)->handle($gateway, app(ChargerCommandStateMachine::class), app(CommandOutcomeService::class));
            self::assertSame('rejected', $command->fresh()->state->value);
        });
    }

    public function test_expiry_and_missing_protocol_status_never_report_success(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            $command = app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::UnlockConnector, $this->data($connector), 'missing-response');
            $gateway = $this->createMock(GatewayCommandClient::class);
            $gateway->expects(self::once())->method('dispatch')->willReturn(new GatewayCommandResult('completed', [], null));
            $this->job($command)->handle($gateway, app(ChargerCommandStateMachine::class), app(CommandOutcomeService::class));
            self::assertSame('rejected', $command->fresh()->state->value);
            $command = app(StationRecovery::class)->request($actor, (string) $station->getKey(), ChargerCommandType::RestartCharger, $this->data($connector), 'expire');
            $this->travel(60)->seconds();
            $tenant = app(CurrentTenant::class);
            $context = $tenant->get();
            $tenant->clear();
            try {
                Artisan::call('charging:expire-operations');
            } finally {
                $tenant->establish($context);
            }
            self::assertSame('expired', $command->fresh()->state->value);
        });
    }

    public function test_portal_confirmation_and_permission_visibility(): void
    {
        $this->fixture(function (User $actor, ChargingStation $station, Connector $connector): void {
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            $this->actingAs($actor);
            Livewire::test(SetupChargingStation::class, ['record' => $station->getKey()])
                ->assertActionVisible('unlock_connector')->assertActionVisible('restart_charger')
                ->callAction('unlock_connector', data: [...$this->data($connector), 'idle_confirmed' => false])
                ->assertHasActionErrors(['idle_confirmed']);
            DB::table('role_permissions')->where('permission_key', PermissionKey::ChargingStationRestart->value)->delete();
            Livewire::test(SetupChargingStation::class, ['record' => $station->getKey()])->assertActionHidden('restart_charger');
        });
    }

    private function job(ChargerCommand $command): DispatchChargerCommand
    {
        $context = app(CurrentTenant::class)->get();

        return new DispatchChargerCommand((string) $command->getKey(), new TenantJobEnvelope(
            jobId: (string) Str::ulid(), schemaVersion: 1, tenantId: $context->tenantId,
            actorType: $context->actorType, actorId: $context->actorId,
            correlationId: $context->correlationId, causationId: (string) $command->getKey(),
        ));
    }

    /** @return array{reason:string,idle_confirmed:bool,connector_id:string} */
    private function data(Connector $connector): array
    {
        return ['reason' => 'Driver reported a stuck cable after a completed session.', 'idle_confirmed' => true, 'connector_id' => (string) $connector->getKey()];
    }

    private function fixture(\Closure $operation, string $protocol = '1.6J'): void
    {
        $tenant = $this->createTenant('recovery');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function () use ($operation, $actor, $tenant, $protocol): void {
            $ocpp = OcppVersion::query()->firstOrCreate(['code' => $protocol], ['name' => $protocol]);
            $station = ChargingStation::factory()->activePublic()->create(['ocpp_version_id' => $ocpp->getKey()]);
            $evse = Evse::factory()->create(['charging_station_id' => $station->getKey(), 'evse_number' => 2, 'lifecycle_status' => 'active']);
            $connector = Connector::factory()->create(['evse_id' => $evse->getKey(), 'connector_number' => 1, 'lifecycle_status' => 'active']);
            $connection = (string) Str::ulid();
            StationConnection::query()->create(['charging_station_id' => $station->getKey(), 'connection_id' => $connection,
                'connected' => true, 'connected_at' => now('UTC'), 'last_event_at' => now('UTC'), 'last_seen_at' => now('UTC')]);
            ConnectorStatus::query()->create(['connector_id' => $connector->getKey(), 'ocpp_connection_id' => $connection,
                'status' => 'available', 'reported_status' => 'available', 'observed_at' => now('UTC'), 'stale_after_seconds' => 300]);
            $membership = $this->createMembership($tenant, $actor);
            $role = $this->createRole($tenant, 'recovery', [PermissionKey::AssetView, PermissionKey::ChargingSessionView,
                PermissionKey::ChargingConnectorUnlock, PermissionKey::ChargingStationRestart]);
            $this->assignDirectly($tenant, $membership, $role, new ResourceScope(ScopeType::Site, $station->site_id));
            $operation($actor, $station, $connector);
        });
    }
}
