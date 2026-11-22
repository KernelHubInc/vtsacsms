<?php

declare(strict_types=1);

namespace Tests\Feature\Charging;

use App\Filament\Operator\Resources\Tariffs\Pages\ListTariffs;
use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Charging\Application\ChargeDetailRecordGenerator;
use App\Modules\Charging\Application\EnergyFlowDuration;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\InboundOcppEvent;
use App\Modules\Charging\Domain\Models\MeterReading;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tariffs\Application\PortalPricing;
use App\Modules\Tariffs\Application\RatingInput;
use App\Modules\Tariffs\Application\TariffEngine;
use App\Modules\Tariffs\Application\TariffManagementService;
use App\Modules\Tariffs\Application\TariffSelector;
use App\Modules\Tariffs\Application\TariffSnapshotFactory;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TenantSecurityTestCase;

final class PortalPricingTest extends TenantSecurityTestCase
{
    #[DataProvider('suspendedTails')]
    public function test_time_billing_accepts_only_a_confirmed_suspended_tail(string $status, int $pauseAt, ?int $resumeAt, int $connector, string $outcome, bool $complete, int $lastPower = 6500): void
    {
        $this->freezeTime();
        $actor = $this->createUser();
        $tenant = $this->createTenant('suspended-tail');
        $this->withinTenant($tenant, $actor, function () use ($status, $pauseAt, $resumeAt, $connector, $outcome, $complete, $lastPower): void {
            $start = CarbonImmutable::now('UTC')->subMinutes(10);
            $session = ChargingSession::factory()->create([
                'started_at' => $start, 'stopped_at' => $start->addSeconds(200),
                'tariff_snapshot' => ['subject' => ['connector_number' => 1]],
            ]);
            foreach ([0, 30, 60] as $seconds) {
                MeterReading::query()->create([
                    'session_id' => $session->id, 'event_id' => (string) Str::ulid(), 'sample_index' => 0,
                    'sampled_at' => $start->addSeconds($seconds), 'received_at' => now(),
                    'measurand' => 'Power.Active.Import', 'unit' => 'W', 'value' => $seconds === 60 ? $lastPower : 6500,
                ]);
            }
            $events = [[$pauseAt, $status]];
            if ($resumeAt !== null) {
                $events[] = [$resumeAt, 'Charging'];
            }
            foreach ($events as [$at, $reported]) {
                InboundOcppEvent::query()->create([
                    'event_id' => (string) Str::ulid(), 'event_type' => 'gateway.ocpp.status_notification.received.v1',
                    'correlation_id' => (string) Str::ulid(), 'schema_version' => 1,
                    'aggregate_id' => $session->charging_station_id, 'occurred_at' => $start->addSeconds($at),
                    'received_at' => now(), 'outcome' => $outcome === 'quarantined' ? $outcome : 'processed',
                    'payload' => ['data' => ['connector_id' => $connector, 'status' => $reported, 'clock_skew_detected' => $outcome === 'clock skew']],
                ]);
            }
            if ($outcome === 'disconnected' || $outcome === 'conflicting') {
                InboundOcppEvent::query()->create([
                    'event_id' => (string) Str::ulid(), 'event_type' => $outcome === 'disconnected'
                        ? 'gateway.ocpp.charger_disconnected.received.v1' : 'gateway.ocpp.status_notification.received.v1',
                    'correlation_id' => (string) Str::ulid(), 'schema_version' => 1,
                    'aggregate_id' => $session->charging_station_id,
                    'occurred_at' => $start->addSeconds($outcome === 'disconnected' ? 100 : $pauseAt),
                    'received_at' => now(), 'outcome' => 'processed',
                    'payload' => ['data' => ['connector_id' => $connector, 'status' => 'Charging']],
                ]);
            }
            self::assertSame(['seconds' => $lastPower === 0 ? 30 : 60, 'complete' => $complete], app(EnergyFlowDuration::class)->measure($session));
        });
    }

    /** @return array<string, array{0: string, 1: int, 2: ?int, 3: int, 4: string, 5: bool, 6?: int}> */
    public static function suspendedTails(): array
    {
        return [
            'EV suspended without final power samples' => ['SuspendedEV', 65, null, 1, 'processed', true],
            'EVSE suspended without final power samples' => ['SuspendedEVSE', 65, null, 1, 'processed', true],
            'charging gap remains unsafe' => ['Charging', 65, null, 1, 'processed', false],
            'available is not a pause measurement' => ['Available', 65, null, 1, 'processed', false],
            'pause reported after an unobserved active gap' => ['SuspendedEV', 125, null, 1, 'processed', false],
            'charging resumed after suspension' => ['SuspendedEV', 65, 150, 1, 'processed', false],
            'different connector cannot justify pause' => ['SuspendedEV', 65, null, 2, 'processed', false],
            'quarantined evidence cannot justify pause' => ['SuspendedEV', 65, null, 1, 'quarantined', false],
            'zero power confirms continued suspension' => ['SuspendedEV', 45, null, 1, 'processed', true, 0],
            'disconnected charger cannot prove continued suspension' => ['SuspendedEV', 65, null, 1, 'disconnected', false],
            'conflicting statuses cannot prove continued suspension' => ['SuspendedEV', 65, null, 1, 'conflicting', false],
            'skewed status cannot prove continued suspension' => ['SuspendedEV', 65, null, 1, 'clock skew', false],
        ];
    }

    public function test_another_tenants_pause_cannot_complete_time_evidence(): void
    {
        $this->freezeTime();
        $actor = $this->createUser();
        $tenant = $this->createTenant('local-pause');
        $foreign = $this->createTenant('foreign-pause');
        $start = CarbonImmutable::now('UTC')->subMinutes(10);
        $session = $this->withinTenant($tenant, $actor, function () use ($start): ChargingSession {
            $session = ChargingSession::factory()->create([
                'started_at' => $start, 'stopped_at' => $start->addSeconds(200),
                'tariff_snapshot' => ['subject' => ['connector_number' => 1]],
            ]);
            foreach ([0, 30, 60] as $seconds) {
                MeterReading::query()->create([
                    'session_id' => $session->id, 'event_id' => (string) Str::ulid(), 'sample_index' => 0,
                    'sampled_at' => $start->addSeconds($seconds), 'received_at' => now(),
                    'measurand' => 'Power.Active.Import', 'unit' => 'W', 'value' => 6500,
                ]);
            }

            return $session;
        });
        $this->withinTenant($foreign, $actor, fn () => InboundOcppEvent::query()->create([
            'event_id' => (string) Str::ulid(), 'event_type' => 'gateway.ocpp.status_notification.received.v1',
            'correlation_id' => (string) Str::ulid(), 'schema_version' => 1,
            'aggregate_id' => $session->charging_station_id, 'occurred_at' => $start->addSeconds(65),
            'received_at' => now(), 'outcome' => 'processed',
            'payload' => ['data' => ['connector_id' => 1, 'status' => 'SuspendedEV']],
        ]));
        $this->withinTenant($tenant, $actor, function () use ($session): void {
            self::assertSame(['seconds' => 60, 'complete' => false], app(EnergyFlowDuration::class)->measure($session));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        ConnectorStandard::query()->create(['code' => 'type2', 'name' => 'Type 2']);
        ChargingCurrentType::query()->create(['code' => 'ac', 'name' => 'AC']);
    }

    public function test_replacing_a_session_price_with_energy_preserves_history_and_rates_fractional_kwh(): void
    {
        $this->freezeTime();
        $actor = $this->createUser();
        $tenant = $this->createTenant('prices');
        $this->withinTenant($tenant, $actor, function (): void {
            $connector = Connector::factory()->create(['lifecycle_status' => 'active']);
            $connector->evse->update(['lifecycle_status' => 'active']);
            $connector->evse->station->update(['lifecycle_status' => 'active']);
            $target = app(ConnectorSnapshotQuery::class)->byId((string) $connector->getKey());
            self::assertNotNull($target);
            $tariff = app(TariffManagementService::class)->create(['name' => 'Lumina test', 'currency' => 'PHP']);
            $input = ['mode' => 'session', 'price' => '1.00', 'time_unit' => 'minute', 'timezone' => 'Asia/Manila', 'tax_treatment' => 'inclusive', 'tax_rate_basis_points' => 0, 'connector_id' => (string) $connector->getKey()];
            $old = app(PortalPricing::class)->publish($tariff, $input);
            $at = CarbonImmutable::now('UTC');
            $snapshot = app(TariffSnapshotFactory::class)->create($old, $target, $at);
            $this->travel(2)->seconds();
            $new = app(PortalPricing::class)->publish($tariff, [...$input, 'mode' => 'energy']);
            self::assertSame($new->id, app(TariffSelector::class)->select($target, CarbonImmutable::now('UTC'))?->id);
            self::assertSame($old->id, app(TariffSelector::class)->select($target, $at)?->id);
            self::assertNull(app(TariffSelector::class)->select($target, CarbonImmutable::now('UTC'), (string) $old->getKey()));
            self::assertNull($old->refresh()->effective_to);
            $newSnapshot = app(TariffSnapshotFactory::class)->create($new, $target, CarbonImmutable::now('UTC'));
            $rating = new RatingInput(1250, 900, 0, 0, $at);
            self::assertSame(125, app(TariffEngine::class)->rate($newSnapshot['snapshot'], $rating)->totalMinor);
            self::assertSame(100, app(TariffEngine::class)->rate($snapshot['snapshot'], $rating)->totalMinor);
            self::assertSame($snapshot, app(TariffSnapshotFactory::class)->create($old, $target, $at));
        });
    }

    public function test_energy_flow_time_excludes_zero_power_and_does_not_extrapolate_missing_samples(): void
    {
        $this->freezeTime();
        $actor = $this->createUser();
        $tenant = $this->createTenant('flow-time');
        $this->withinTenant($tenant, $actor, function (): void {
            $start = CarbonImmutable::now('UTC')->subMinutes(10);
            $session = ChargingSession::factory()->create(['started_at' => $start, 'stopped_at' => $start->addSeconds(120)]);
            foreach ([0 => 7000, 15 => 7000, 30 => 0, 45 => 0, 60 => 7000, 75 => 7000, 90 => 7000, 105 => 0, 120 => 0] as $seconds => $power) {
                MeterReading::query()->create(['session_id' => $session->id, 'event_id' => (string) Str::ulid(), 'sample_index' => 0,
                    'sampled_at' => $start->addSeconds($seconds), 'received_at' => now(), 'measurand' => 'Power.Active.Import', 'unit' => 'W', 'value' => $power]);
            }
            self::assertSame(['seconds' => 45, 'complete' => true], app(EnergyFlowDuration::class)->measure($session));
            $snapshot = ['pricing_status' => 'selected', 'currency' => 'PHP', 'timezone' => 'UTC', 'time_basis' => 'energy_flow', 'tax_treatment' => 'inclusive', 'components' => [['dimension' => 'time', 'price_minor' => 100, 'unit_quantity' => 60, 'priority' => 0, 'day_of_week_mask' => 127]]];
            self::assertSame(75, app(TariffEngine::class)->rate($snapshot, new RatingInput(10, 120, 0, 0, $start, 45))->totalMinor);
            $session->forceFill(['stopped_at' => $start->addSeconds(300), 'state' => 'finalizing', 'tariff_snapshot' => $snapshot])->save();
            self::assertSame(['seconds' => 45, 'complete' => false], app(EnergyFlowDuration::class)->measure($session));
            $cdr = app(ChargeDetailRecordGenerator::class)->generate($session);
            self::assertSame('review_required', $cdr->state->value);
            self::assertNull($cdr->total_minor);
        });
    }

    public function test_portal_requires_publish_permission_and_saves_time_price(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('portal-pricing');
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
            $membership = $this->createMembership($tenant, $actor);
            $role = $this->createRole($tenant, 'pricing-editor', [
                PermissionKey::PlatformPanelAccess,
                PermissionKey::TariffView,
                PermissionKey::TariffManage,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
            $tariff = app(TariffManagementService::class)->create(['name' => 'Portal time tariff', 'currency' => 'PHP']);
            Livewire::test(ListTariffs::class)
                ->assertTableActionHidden('publishPricing', $tariff);
            $publisher = $this->createRole($tenant, 'pricing-publisher', [PermissionKey::TariffPublish]);
            $this->assignDirectly($tenant, $membership, $publisher);
            Livewire::test(ListTariffs::class)
                ->callTableAction('publishPricing', $tariff, data: [
                    'name' => 'Portal time tariff',
                    'mode' => 'time', 'price' => '2.50', 'time_unit' => 'minute', 'timezone' => 'Asia/Manila',
                    'tax_treatment' => 'inclusive', 'tax_rate_basis_points' => 0,
                ])->assertHasNoActionErrors();
            $version = $tariff->versions()->with('components')->firstOrFail();
            self::assertSame('published', $version->status->value);
            self::assertSame('energy_flow', $version->time_basis);
            self::assertSame(250, $version->components->first()->price_minor);
            self::assertSame(60, $version->components->first()->unit_quantity);
        });
    }

    public function test_positive_power_samples_do_not_bill_across_a_reported_pause_or_resume(): void
    {
        $this->freezeTime();
        $actor = $this->createUser();
        $tenant = $this->createTenant('pause-evidence');
        $this->withinTenant($tenant, $actor, function (): void {
            $start = CarbonImmutable::now('UTC')->subMinutes(2);
            $session = ChargingSession::factory()->create(['started_at' => $start, 'stopped_at' => $start->addSeconds(60), 'tariff_snapshot' => ['subject' => ['connector_number' => 1]]]);
            foreach ([0, 15, 30, 45, 60] as $seconds) {
                MeterReading::query()->create(['session_id' => $session->id, 'event_id' => (string) Str::ulid(), 'sample_index' => 0,
                    'sampled_at' => $start->addSeconds($seconds), 'received_at' => now(), 'measurand' => 'Power.Active.Import', 'unit' => 'W', 'value' => 7000]);
            }
            foreach ([20 => 'SuspendedEV', 50 => 'Charging'] as $seconds => $status) {
                InboundOcppEvent::query()->create([
                    'event_id' => (string) Str::ulid(), 'event_type' => 'gateway.ocpp.status_notification.received.v1',
                    'correlation_id' => (string) Str::ulid(),
                    'schema_version' => 1, 'aggregate_id' => $session->charging_station_id,
                    'occurred_at' => $start->addSeconds($seconds), 'received_at' => now(), 'outcome' => 'processed',
                    'payload' => ['data' => ['connector_id' => 1, 'status' => $status]],
                ]);
            }
            self::assertSame(['seconds' => 15, 'complete' => true], app(EnergyFlowDuration::class)->measure($session));
        });
    }
}
