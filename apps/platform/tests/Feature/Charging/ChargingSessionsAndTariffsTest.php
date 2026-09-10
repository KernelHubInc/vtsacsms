<?php

declare(strict_types=1);

namespace Tests\Feature\Charging;

use App\Models\User;
use App\Modules\Assets\Domain\AssetLifecycleStatus;
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
use App\Modules\Charging\Application\ManualSessionReviewService;
use App\Modules\Charging\Application\OcppEventConsumer;
use App\Modules\Charging\Application\RemoteStartService;
use App\Modules\Charging\Application\SessionLifecycleService;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\ConnectorAvailability;
use App\Modules\Charging\Domain\Models\AuthorizationToken;
use App\Modules\Charging\Domain\Models\ChargeDetailRecord;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Domain\SessionAnomaly;
use App\Modules\Charging\Jobs\DispatchChargerCommand;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Locations\Domain\SiteLifecycleStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tariffs\Application\TariffManagementService;
use App\Modules\Tariffs\Domain\Models\TariffVersion;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\TenantSecurityTestCase;

final class ChargingSessionsAndTariffsTest extends TenantSecurityTestCase
{
    public function test_acknowledged_remote_start_remains_starting_until_transaction_evidence_arrives(): void
    {
        Queue::fake();
        $actor = $this->createUser();
        $tenant = $this->createTenant('remote-start');
        [$connector, $version] = $this->withinTenant($tenant, $actor, fn (): array => $this->fixture($tenant, $actor, 'START', '1.6J'));
        $queued = null;

        $result = $this->withinTenant($tenant, $actor, function () use ($connector, $version, &$queued) {
            $first = app(RemoteStartService::class)->request((string) $connector->getKey(), 'remote-start-key-001', (string) $version->getKey());
            $second = app(RemoteStartService::class)->request((string) $connector->getKey(), 'remote-start-key-001', (string) $version->getKey());
            self::assertSame($first->session->getKey(), $second->session->getKey());
            self::assertSame($first->command->getKey(), $second->command->getKey());
            Queue::assertPushed(DispatchChargerCommand::class, function (DispatchChargerCommand $job) use (&$queued): bool {
                $queued = $job;

                return true;
            });

            return $first;
        });

        self::assertInstanceOf(DispatchChargerCommand::class, $queued);
        $this->withinTenant($tenant, $actor, function () use ($queued): void {
            $queued->handle(
                new class implements GatewayCommandClient
                {
                    public function dispatch(ChargerCommand $command): GatewayCommandResult
                    {
                        return new GatewayCommandResult('completed', ['status' => 'Accepted'], null);
                    }
                },
                app(ChargerCommandStateMachine::class),
                app(CommandOutcomeService::class),
            );
        });

        self::assertSame(ChargingSessionState::Starting, $result->session->refresh()->state);
        self::assertSame(ChargerCommandState::Acknowledged, $result->command->refresh()->state);
        $this->assertDatabaseCount('charging_sessions', 1);
        $this->assertDatabaseCount('charging_commands', 1);
        $this->assertDatabaseCount('charging_connector_reservations', 1);

        $event = $this->event($tenant, $connector, 'start_transaction', '2026-07-22T10:00:00Z', [
            'connector_id' => 1,
            'protocol_transaction_id' => 77,
            'meter_start_wh' => 1000,
            'authorization_status' => 'Accepted',
        ]);
        $consumed = $this->withinTenant($tenant, $actor, fn () => app(OcppEventConsumer::class)->consume($event));
        self::assertSame('processed', $consumed->outcome);
        self::assertSame(ChargingSessionState::Charging, $result->session->refresh()->state);
        self::assertSame('77', $result->session->protocol_transaction_id);
        self::assertNotSame('', $result->session->tariff_snapshot_hash);
        $this->assertDatabaseHas('charging_connector_statuses', [
            'connector_id' => $connector->getKey(),
            'status' => ConnectorAvailability::Occupied->value,
        ]);

        $duplicate = $this->withinTenant($tenant, $actor, fn () => app(OcppEventConsumer::class)->consume($event));
        self::assertTrue($duplicate->duplicate);
        $this->assertDatabaseCount('charging_inbound_events', 1);
    }

    public function test_clean_meter_and_stop_evidence_finalize_cost_and_immutable_cdr(): void
    {
        Queue::fake();
        $actor = $this->createUser();
        $tenant = $this->createTenant('clean-cdr');
        [$connector, $version] = $this->withinTenant($tenant, $actor, fn (): array => $this->fixture($tenant, $actor, 'CDR', '1.6J'));
        $session = $this->withinTenant($tenant, $actor, fn () => app(RemoteStartService::class)
            ->request((string) $connector->getKey(), 'clean-session-001', (string) $version->getKey())->session);

        $this->consume($tenant, $actor, $this->event($tenant, $connector, 'start_transaction', '2026-07-22T10:00:00Z', [
            'connector_id' => 1, 'protocol_transaction_id' => 90, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted',
        ]));
        $this->consume($tenant, $actor, $this->event($tenant, $connector, 'meter_values', '2026-07-22T10:10:00Z', [
            'connector_id' => 1, 'protocol_transaction_id' => 90,
            'samples' => [$this->meterSample('2026-07-22T10:10:00Z', 1500)],
        ]));
        $this->consume($tenant, $actor, $this->event($tenant, $connector, 'stop_transaction', '2026-07-22T10:20:00Z', [
            'protocol_transaction_id' => 90, 'meter_stop_wh' => 2000, 'reason' => 'Local', 'samples' => [],
        ]));

        $session->refresh();
        self::assertSame(ChargingSessionState::Completed, $session->state);
        self::assertSame(1000, $session->energy_wh);
        self::assertSame(1200, $session->duration_seconds);
        self::assertSame(30, $session->final_cost_minor);
        $cdr = $this->withinTenant($tenant, $actor, fn () => ChargeDetailRecord::query()->where('session_id', $session->getKey())->firstOrFail());
        self::assertSame('finalized', $cdr->state->value);
        self::assertSame(30, $cdr->total_minor);
        self::assertSame(64, strlen((string) $cdr->snapshot_hash));
        $cdrMutationBlocked = false;
        try {
            $this->withinTenant($tenant, $actor, fn () => $cdr->update(['total_minor' => 999]));
        } catch (LogicException $exception) {
            $cdrMutationBlocked = str_contains($exception->getMessage(), 'immutable');
        }
        self::assertTrue($cdrMutationBlocked, 'Finalized CDR evidence should be immutable.');
        $this->assertDatabaseHas('charging_connector_reservations', [
            'session_id' => $session->getKey(),
            'release_reason' => 'transaction_started',
        ]);
    }

    public function test_out_of_order_and_reset_meter_values_are_recalculated_and_sent_to_review(): void
    {
        Queue::fake();
        $actor = $this->createUser();
        $tenant = $this->createTenant('meter-review');
        [$connector, $version] = $this->withinTenant($tenant, $actor, fn (): array => $this->fixture($tenant, $actor, 'METER', '1.6J'));
        $session = $this->withinTenant($tenant, $actor, fn () => app(RemoteStartService::class)
            ->request((string) $connector->getKey(), 'meter-session-001', (string) $version->getKey())->session);
        $this->consume($tenant, $actor, $this->event($tenant, $connector, 'start_transaction', '2026-07-22T10:00:00Z', [
            'connector_id' => 1, 'protocol_transaction_id' => 91, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted',
        ]));

        foreach ([
            ['2026-07-22T10:20:00Z', 1200],
            ['2026-07-22T10:10:00Z', 1100],
            ['2026-07-22T10:30:00Z', 50],
        ] as [$time, $value]) {
            $this->consume($tenant, $actor, $this->event($tenant, $connector, 'meter_values', $time, [
                'connector_id' => 1,
                'protocol_transaction_id' => 91,
                'samples' => [$this->meterSample($time, $value)],
            ]));
        }
        $this->consume($tenant, $actor, $this->event($tenant, $connector, 'stop_transaction', '2026-07-22T10:40:00Z', [
            'protocol_transaction_id' => 91, 'meter_stop_wh' => 100, 'reason' => 'Local', 'samples' => [],
        ]));

        $session->refresh();
        self::assertSame(300, $session->energy_wh);
        self::assertSame(ChargingSessionState::ReviewRequired, $session->state);
        self::assertContains(SessionAnomaly::OutOfOrderMeter->value, $session->anomaly_flags);
        self::assertContains(SessionAnomaly::MeterReset->value, $session->anomaly_flags);
        $this->withinTenant($tenant, $actor, fn () => app(ManualSessionReviewService::class)
            ->resolve($session, 'estimated', 'Reviewed meter reset evidence.', ['energy_wh' => 300]));
        self::assertSame(ChargingSessionState::Completed, $session->refresh()->state);
        self::assertSame('estimated', $session->finalization_outcome);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(),
            'action' => 'charging.session_review.resolved',
            'target_id' => $session->getKey(),
        ]);
    }

    public function test_transaction_update_reconstructs_session_after_reconnect(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('reconstruction');
        [$connector] = $this->withinTenant($tenant, $actor, fn (): array => $this->fixture($tenant, $actor, 'RECON', '2.0.1'));
        $event = $this->event($tenant, $connector, 'transaction_event', '2026-07-22T11:00:00Z', [
            'event_type' => 'Updated',
            'protocol_transaction_id' => 'tx-reconnected',
            'charging_state' => 'Charging',
            'evse' => ['id' => 1, 'connector_id' => 1],
            'sequence_number' => 12,
            'samples' => [$this->meterSample('2026-07-22T11:00:00Z', 5000)],
        ]);

        $this->consume($tenant, $actor, $event);
        $session = $this->withinTenant($tenant, $actor, fn () => ChargingSession::query()->firstOrFail());
        self::assertSame(ChargingSessionState::Charging, $session->state);
        self::assertSame('tx-reconnected', $session->protocol_transaction_id);
        self::assertContains(SessionAnomaly::ReconstructedAfterReconnect->value, $session->anomaly_flags);
        self::assertSame('selected', $session->tariff_snapshot['pricing_status']);
    }

    public function test_tariff_snapshot_and_published_version_are_immutable(): void
    {
        Queue::fake();
        $actor = $this->createUser();
        $tenant = $this->createTenant('tariff-immutability');
        [$connector, $version] = $this->withinTenant($tenant, $actor, fn (): array => $this->fixture($tenant, $actor, 'IMMUTABLE', '1.6J'));
        $session = $this->withinTenant($tenant, $actor, fn () => app(RemoteStartService::class)
            ->request((string) $connector->getKey(), 'immutable-session-001', (string) $version->getKey())->session);
        $hash = $session->tariff_snapshot_hash;

        $mutationBlocked = false;
        try {
            $this->withinTenant($tenant, $actor, function () use ($version): void {
                $version->update(['minimum_fee_minor' => 999]);
            });
        } catch (LogicException $exception) {
            $mutationBlocked = str_contains($exception->getMessage(), 'immutable');
        }
        self::assertTrue($mutationBlocked, 'Published tariff version update should fail.');
        $componentMutationBlocked = false;
        try {
            $this->withinTenant($tenant, $actor, fn () => $version->components()->firstOrFail()->update(['price_minor' => 999]));
        } catch (LogicException $exception) {
            $componentMutationBlocked = str_contains($exception->getMessage(), 'immutable');
        }
        self::assertTrue($componentMutationBlocked, 'Published tariff components should be immutable.');

        self::assertSame($hash, $session->refresh()->tariff_snapshot_hash);
    }

    public function test_acknowledged_start_expires_without_physical_evidence_and_releases_connector(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-07-22T09:00:00Z');

        try {
            $actor = $this->createUser();
            $tenant = $this->createTenant('start-expiry');
            [$connector, $version] = $this->withinTenant($tenant, $actor, fn (): array => $this->fixture($tenant, $actor, 'EXPIRY', '1.6J'));
            $result = $this->withinTenant($tenant, $actor, fn () => app(RemoteStartService::class)
                ->request((string) $connector->getKey(), 'expiry-session-001', (string) $version->getKey()));
            $this->withinTenant($tenant, $actor, function () use ($result): void {
                $states = app(ChargerCommandStateMachine::class);
                $states->transition($result->command, ChargerCommandState::Dispatched, 'test_dispatched');
                $states->transition($result->command, ChargerCommandState::Acknowledged, 'test_acknowledged');
            });

            CarbonImmutable::setTestNow('2026-07-22T09:03:00Z');
            self::assertSame(0, Artisan::call('charging:expire-operations'));

            self::assertSame(ChargingSessionState::Expired, $result->session->refresh()->state);
            self::assertSame(ChargerCommandState::Acknowledged, $result->command->refresh()->state);
            $this->assertDatabaseHas('charging_connector_reservations', [
                'session_id' => $result->session->getKey(),
                'release_reason' => 'start_deadline_elapsed',
            ]);
            $this->assertDatabaseHas('charging_connector_statuses', [
                'connector_id' => $connector->getKey(),
                'status' => ConnectorAvailability::Available->value,
            ]);
            $token = $this->withinTenant($tenant, $actor, fn () => AuthorizationToken::query()->whereKey($result->session->authorization_token_id)->firstOrFail());
            self::assertSame('revoked', $token->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_pre_start_cancellation_is_audited_and_releases_the_reservation(): void
    {
        Queue::fake();
        $actor = $this->createUser();
        $tenant = $this->createTenant('start-cancellation');
        [$connector, $version] = $this->withinTenant($tenant, $actor, fn (): array => $this->fixture($tenant, $actor, 'CANCEL', '1.6J'));
        $session = $this->withinTenant($tenant, $actor, fn () => app(RemoteStartService::class)
            ->request((string) $connector->getKey(), 'cancel-session-001', (string) $version->getKey())->session);

        $this->withinTenant($tenant, $actor, fn () => app(SessionLifecycleService::class)->cancel($session, 'driver_cancelled'));

        self::assertSame(ChargingSessionState::Cancelled, $session->refresh()->state);
        self::assertSame('driver_cancelled', $session->cancellation_reason);
        $this->assertDatabaseHas('charging_connector_statuses', [
            'connector_id' => $connector->getKey(),
            'status' => ConnectorAvailability::Available->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(),
            'action' => 'charging.session.cancelled',
            'target_id' => $session->getKey(),
        ]);
    }

    public function test_mobile_api_exposes_idempotent_commands_without_secret_payloads(): void
    {
        Queue::fake();
        $actor = $this->createUser();
        $tenant = $this->createTenant('charging-api');
        [$connector, $version] = $this->withinTenant($tenant, $actor, fn (): array => $this->fixture($tenant, $actor, 'API', '1.6J'));
        [$otherConnector, $otherVersion] = $this->withinTenant($tenant, $actor, fn (): array => $this->fixture($tenant, $actor, 'API-OTHER', '1.6J'));
        $token = $this->login($actor, $tenant);

        $first = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'mobile-start-001')
            ->postJson('/api/v1/charging-sessions/remote-start', [
                'connector_id' => $connector->getKey(),
                'tariff_version_id' => $version->getKey(),
            ])
            ->assertAccepted()
            ->assertJsonPath('data.session.state', ChargingSessionState::Starting->value)
            ->assertJsonMissingPath('data.command.secret_payload');
        $sessionId = (string) $first->json('data.session.id');
        $commandId = (string) $first->json('data.command.id');

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'mobile-start-001')
            ->postJson('/api/v1/charging-sessions/remote-start', [
                'connector_id' => $connector->getKey(),
                'tariff_version_id' => $version->getKey(),
            ])
            ->assertAccepted()
            ->assertJsonPath('data.session.id', $sessionId)
            ->assertJsonPath('data.command.id', $commandId);
        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'mobile-start-001')
            ->postJson('/api/v1/charging-sessions/remote-start', [
                'connector_id' => $otherConnector->getKey(),
                'tariff_version_id' => $otherVersion->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'charging_start_rejected');
        $this->assertDatabaseCount('charging_commands', 1);
        $this->withToken($token)->getJson('/api/v1/charging-sessions/'.$sessionId)
            ->assertOk()->assertJsonPath('data.id', $sessionId);
        $this->withToken($token)->postJson('/api/v1/charging-sessions/'.$sessionId.'/cancel', [
            'reason' => 'driver_cancelled',
        ])->assertOk()->assertJsonPath('data.state', ChargingSessionState::Cancelled->value);
    }

    public function test_event_tenant_mismatch_and_session_queries_do_not_leak(): void
    {
        $user = $this->createUser();
        $tenantA = $this->createTenant('charging-a');
        $tenantB = $this->createTenant('charging-b');
        [$connectorA, $versionA] = $this->withinTenant($tenantA, $user, fn (): array => $this->fixture($tenantA, $user, 'A', '1.6J'));
        [$connectorB, $versionB] = $this->withinTenant($tenantB, $user, fn (): array => $this->fixture($tenantB, $user, 'B', '1.6J'));
        Queue::fake();
        $sessionA = $this->withinTenant($tenantA, $user, fn () => app(RemoteStartService::class)
            ->request((string) $connectorA->getKey(), 'tenant-a-session', (string) $versionA->getKey())->session);
        $sessionB = $this->withinTenant($tenantB, $user, fn () => app(RemoteStartService::class)
            ->request((string) $connectorB->getKey(), 'tenant-b-session', (string) $versionB->getKey())->session);

        $eventB = $this->event($tenantB, $connectorB, 'start_transaction', '2026-07-22T12:00:00Z', [
            'connector_id' => 1, 'protocol_transaction_id' => 501, 'meter_start_wh' => 0,
        ]);
        self::assertNotSame($sessionA->getKey(), $sessionB->getKey());
        $leaked = $this->withinTenant($tenantA, $user, fn () => ChargingSession::query()->whereKey($sessionB->getKey())->first());
        self::assertNull($leaked);
        $this->expectException(DomainException::class);
        $this->withinTenant($tenantA, $user, fn () => app(OcppEventConsumer::class)->consume($eventB));
    }

    /** @return array{Connector, TariffVersion} */
    private function fixture(Tenant $tenant, User $actor, string $code, string $protocol): array
    {
        $ocpp = OcppVersion::query()->firstOrCreate(['code' => $protocol], ['name' => 'OCPP '.$protocol]);
        $standard = ConnectorStandard::query()->firstOrCreate(['code' => 'ccs2'], ['name' => 'CCS2']);
        $current = ChargingCurrentType::query()->firstOrCreate(['code' => 'dc'], ['name' => 'DC']);
        $operator = Organization::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Operator '.$code,
            'code' => 'OP-'.$code,
            'type' => OrganizationType::ChargePointOperator,
            'is_active' => true,
        ]);
        $site = Site::query()->create([
            'tenant_id' => $tenant->getKey(),
            'operator_organization_id' => $operator->getKey(),
            'name' => 'Site '.$code,
            'code' => 'SITE-'.$code,
            'timezone' => 'UTC',
            'lifecycle_status' => SiteLifecycleStatus::Active,
            'is_active' => true,
        ]);
        $station = ChargingStation::query()->create([
            'tenant_id' => $tenant->getKey(),
            'site_id' => $site->getKey(),
            'ocpp_version_id' => $ocpp->getKey(),
            'name' => 'Station '.$code,
            'charge_point_identity' => 'CP-'.$code,
            'serial_number' => 'SN-'.$code,
            'qr_identifier' => 'QR-'.$code,
            'lifecycle_status' => AssetLifecycleStatus::Active,
            'is_public' => false,
        ]);
        $evse = Evse::query()->create([
            'tenant_id' => $tenant->getKey(),
            'charging_station_id' => $station->getKey(),
            'evse_number' => 1,
            'lifecycle_status' => AssetLifecycleStatus::Active,
        ]);
        $connector = Connector::query()->create([
            'tenant_id' => $tenant->getKey(),
            'evse_id' => $evse->getKey(),
            'connector_standard_id' => $standard->getKey(),
            'charging_current_type_id' => $current->getKey(),
            'connector_number' => 1,
            'qr_identifier' => 'CONNECTOR-'.$code,
            'maximum_power_w' => 150_000,
            'lifecycle_status' => AssetLifecycleStatus::Active,
        ]);
        ConnectorStatus::query()->create([
            'tenant_id' => $tenant->getKey(),
            'connector_id' => $connector->getKey(),
            'status' => ConnectorAvailability::Available,
            'observed_at' => now('UTC'),
            'stale_after_seconds' => 300,
        ]);
        if (! $actor->memberships()->where('tenant_id', $tenant->getKey())->exists()) {
            $membership = $this->createMembership($tenant, $actor);
            $role = $this->createRole($tenant, 'charging-'.$code, [
                PermissionKey::ChargingSessionView,
                PermissionKey::ChargingRemoteCommand,
                PermissionKey::ChargingSessionReview,
                PermissionKey::TariffView,
                PermissionKey::TariffManage,
                PermissionKey::TariffPublish,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
        }
        $tariff = app(TariffManagementService::class)->create([
            'name' => 'Tariff '.$code,
            'currency' => 'USD',
        ]);
        $version = app(TariffManagementService::class)->addVersion($tariff, [
            'effective_from' => '2026-01-01T00:00:00Z',
            'tax_treatment' => 'inclusive',
            'timezone' => 'UTC',
            'connector_id' => (string) $connector->getKey(),
            'components' => [[
                'dimension' => 'energy',
                'price_minor' => 30,
                'unit_quantity' => 1000,
            ]],
        ]);

        return [$connector->load('evse.station.ocppVersion'), app(TariffManagementService::class)->publish($version)];
    }

    /** @param array<string, mixed> $event */
    private function consume(Tenant $tenant, User $actor, array $event): void
    {
        $result = $this->withinTenant($tenant, $actor, fn () => app(OcppEventConsumer::class)->consume($event));
        self::assertSame('processed', $result->outcome, (string) $result->errorCode);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function event(Tenant $tenant, Connector $connector, string $action, string $at, array $data): array
    {
        $connector->loadMissing('evse.station.ocppVersion');
        $protocol = $connector->evse->station->ocppVersion?->code === '2.0.1' ? 'ocpp2.0.1' : 'ocpp1.6';

        return [
            'event_id' => (string) Str::ulid(),
            'event_type' => 'gateway.ocpp.'.$action.'.received.v1',
            'schema_version' => 1,
            'occurred_at' => $at,
            'tenant_id' => (string) $tenant->getKey(),
            'aggregate_type' => 'charging_station',
            'aggregate_id' => (string) $connector->evse->station->getKey(),
            'correlation_id' => (string) Str::ulid(),
            'causation_id' => (string) Str::ulid(),
            'data' => [
                'charge_point_identity' => $connector->evse->station->charge_point_identity,
                'protocol' => $protocol,
                'protocol_timestamp' => $at,
                'clock_skew_detected' => false,
                ...$data,
            ],
        ];
    }

    /** @return array<string, int|string|null> */
    private function meterSample(string $at, int $value): array
    {
        return [
            'timestamp' => $at,
            'measurand' => 'Energy.Active.Import.Register',
            'value' => $value,
            'unit' => 'Wh',
            'context' => 'Sample.Periodic',
            'phase' => null,
        ];
    }

    private function login(User $user, Tenant $tenant): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->getKey(),
            'device_name' => 'Charging tests',
        ])->assertOk()->json('data.token');
    }
}
