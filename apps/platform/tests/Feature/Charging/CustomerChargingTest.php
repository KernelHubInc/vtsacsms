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
use App\Modules\Charging\Domain\Models\AuthorizationToken;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\ConnectorStatus;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\Charging\Domain\Models\InboundOcppEvent;
use App\Modules\Charging\Domain\Models\StationConnection;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Notifications\Application\ChargingReceiptPdf;
use App\Modules\Notifications\Application\ChargingSummaryDelivery;
use App\Modules\Notifications\Application\ChargingSummaryQueue;
use App\Modules\Notifications\Domain\Models\ChargingEmailDelivery;
use App\Modules\Notifications\Mail\ChargingSummaryMail;
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
use Illuminate\Support\Facades\Mail;
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
        Mail::fake();
        config(['mail.default' => 'smtp']);
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
            $this->assertDatabaseCount('charging_email_deliveries', 0);
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            $this->assertDatabaseCount('charging_email_deliveries', 1);
            ChargingEmailDelivery::query()->where('session_id', $session->getKey())->delete();
            $this->assertDatabaseCount('charging_email_deliveries', 0);
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            $this->assertDatabaseCount('charging_email_deliveries', 1);
            Mail::assertNothingSent();
            $actor->forceFill(['email_verified_at' => null])->save();
            app(ChargingSummaryDelivery::class)->deliverDue();
            Mail::assertNothingSent();
            $this->assertDatabaseHas('charging_email_deliveries', ['session_id' => $session->getKey(), 'attempt_count' => 1, 'sent_at' => null]);
            $actor->forceFill(['email_verified_at' => now('UTC')])->save();
            $this->travel(61)->seconds();
            app(ChargingSummaryDelivery::class)->deliverDue();
            app(ChargingSummaryDelivery::class)->deliverDue();
            Mail::assertSent(ChargingSummaryMail::class, function (ChargingSummaryMail $mail) use ($actor, $session): bool {
                return $mail->hasTo($actor->email)
                    && $mail->summary['session_id'] === (string) $session->getKey()
                    && $mail->summary['amount_minor'] === 75
                    && $mail->summary['energy_wh'] === 750
                    && $mail->summary['payment_method'] === 'Personal prepaid wallet'
                    && str_contains($mail->render(), 'PHP 0.75')
                    && str_contains($mail->render(), 'not an official tax receipt')
                    && count($mail->attachments()) === 1
                    && $mail->attachments()[0]->as === 'power-solutions-charging-'.$session->getKey().'.pdf'
                    && $mail->attachments()[0]->mime === 'application/pdf';
            });
            Mail::assertSentCount(1);
            $balance = app(PrepaidWallet::class)->summary($actor->public_id, 'live');
            self::assertSame(25, $balance['available_minor']);
            self::assertSame(0, $balance['reserved_minor']);
            self::assertSame('captured', $service->present($session)['payment_state']);
            $this->assertDatabaseCount('charging_customer_sessions', 1);
        });
    }

    public function test_charging_receipt_email_and_pdf_show_the_same_confirmed_charge(): void
    {
        $summary = [
            'session_id' => '01M5TESTRECEIPT0000000000',
            'station' => 'Lumina Subic',
            'connector' => 'Type 2',
            'started_at' => '2026-10-24T02:00:00Z',
            'stopped_at' => '2026-10-24T02:15:30Z',
            'energy_wh' => 3443,
            'duration_seconds' => 930,
            'amount_minor' => 51,
            'currency' => 'PHP',
            'payment_method' => 'Personal prepaid wallet',
        ];

        $receipt = app(ChargingReceiptPdf::class);
        $details = $receipt->details($summary);
        self::assertSame('PHP 0.51', $details['amount']);
        self::assertSame('3.443 kWh', $details['energy']);
        self::assertSame('24 Oct 2026, 10:00 AM PHT', $details['started']);

        $mail = new ChargingSummaryMail($summary);
        self::assertSame('Your Power Solutions charging receipt', $mail->envelope()->subject);
        self::assertCount(1, $mail->attachments());
        self::assertSame('application/pdf', $mail->attachments()[0]->mime);
        self::assertStringContainsString('PHP 0.51', $mail->render());
        self::assertStringContainsString($summary['session_id'], $mail->render());
        self::assertStringStartsWith('%PDF-', $receipt->render($summary));
    }

    public function test_charging_email_queue_is_tenant_scoped(): void
    {
        Mail::fake();
        $actor = $this->createUser();
        $first = $this->createTenant('charging-email-first');
        $second = $this->createTenant('charging-email-second');

        $this->withinTenant($first, $actor, function () use ($actor): void {
            $session = ChargingSession::factory()->create();
            app(ChargingSummaryQueue::class)->record((string) $session->getKey(), $actor->public_id, [
                'amount_minor' => 75,
            ]);
            self::assertSame(1, ChargingEmailDelivery::query()->count());
        });

        $this->withinTenant($second, $actor, function (): void {
            self::assertSame(0, ChargingEmailDelivery::query()->count());
            app(ChargingSummaryDelivery::class)->deliverDue();
            Mail::assertNothingSent();
        });

        $this->withinTenant($first, $actor, function (): void {
            self::assertSame(1, ChargingEmailDelivery::query()->count());
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

    public function test_optional_charger_state_of_charge_is_reported_without_affecting_billing(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('charger-soc');
        $this->withinTenant($tenant, $actor, function () use ($actor, $tenant): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'soc-start-01');
            self::assertNull($service->present($session)['battery_percentage']);

            $this->event((string) $tenant->getKey(), $connector, 'start_transaction', [
                'connector_id' => 1, 'protocol_transaction_id' => 94, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted',
            ]);
            $this->event((string) $tenant->getKey(), $connector, 'meter_values', [
                'connector_id' => 1, 'protocol_transaction_id' => 94,
                'samples' => [
                    ['timestamp' => now('UTC')->addSecond()->toISOString(), 'measurand' => 'SoC', 'unit' => 'Percent', 'value' => 64],
                    ['timestamp' => now('UTC')->toISOString(), 'measurand' => 'Energy.Active.Import.Register', 'unit' => 'Wh', 'value' => 1250],
                ],
            ]);

            $projection = $service->present($session->refresh());
            self::assertSame(64, $projection['battery_percentage']);
            self::assertNotNull($projection['battery_reported_at']);
            self::assertSame(250, $projection['energy_wh']);
            self::assertNotContains('invalid_meter_value', $session->anomaly_flags);
            self::assertNotContains('out_of_order_meter', $session->anomaly_flags);

            $this->travel(10)->seconds();
            $this->event((string) $tenant->getKey(), $connector, 'meter_values', [
                'connector_id' => 1, 'protocol_transaction_id' => 94,
                'samples' => [
                    ['timestamp' => now('UTC')->toISOString(), 'measurand' => 'SoC', 'unit' => '%', 'value' => 65.6],
                    ['timestamp' => now('UTC')->toISOString(), 'measurand' => 'Energy.Active.Import.Register', 'unit' => 'Wh', 'value' => 1300],
                ],
            ]);
            self::assertSame(66, $service->present($session->refresh())['battery_percentage']);

            $this->travel(10)->seconds();
            $this->event((string) $tenant->getKey(), $connector, 'stop_transaction', [
                'protocol_transaction_id' => 94, 'meter_stop_wh' => 1300, 'reason' => 'Remote', 'samples' => [],
            ]);
            $final = $service->present($session->refresh());
            self::assertSame('completed', $final['state']);
            self::assertNotNull($final['started_at']);
            self::assertNotNull($final['stopped_at']);
            self::assertSame(66, $final['battery_percentage']);
            self::assertSame(300, $final['energy_wh']);
        });
    }

    public function test_five_hundred_peso_budget_is_reserved_and_requests_stop_before_limit(): void
    {
        config(['mobile_charging.maximum_reservation_minor' => 50_000]);
        $actor = $this->createUser();
        $tenant = $this->createTenant('five-hundred-budget');
        $this->withinTenant($tenant, $actor, function () use ($actor, $tenant): void {
            $connector = $this->fixture($actor);
            app(PrepaidWallet::class)->credit($actor->public_id, 'live', (string) Str::ulid(), 49_900);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            self::assertSame(50_000, $quote['quote']['estimated_preauthorization']['amount_minor']);
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'five-hundred-start');
            self::assertSame(50_000, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
            $this->event((string) $tenant->getKey(), $connector, 'start_transaction', [
                'connector_id' => 1, 'protocol_transaction_id' => 93, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted',
            ]);
            $session->refresh()->forceFill(['energy_wh' => 499_900, 'estimated_cost_minor' => 49_990])->save();
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            self::assertSame('stopping', $session->refresh()->state->value);
            self::assertSame(1, ChargerCommand::query()->where('session_id', $session->getKey())->where('type', 'remote_stop')->count());
            self::assertNull(CustomerChargingSession::query()->where('session_id', $session->getKey())->firstOrFail()->settled_at);
        });
    }

    public function test_over_budget_final_charge_stays_in_review_without_debit_or_email(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('over-budget-review');
        $this->withinTenant($tenant, $actor, function () use ($actor, $tenant): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'over-budget-start');
            $this->event((string) $tenant->getKey(), $connector, 'start_transaction', [
                'connector_id' => 1, 'protocol_transaction_id' => 94, 'meter_start_wh' => 1000, 'authorization_status' => 'Accepted',
            ]);
            $this->travel(1200)->seconds();
            $this->event((string) $tenant->getKey(), $connector, 'stop_transaction', [
                'protocol_transaction_id' => 94, 'meter_stop_wh' => 2500, 'reason' => 'Remote', 'samples' => [],
            ]);
            self::assertSame(150, $session->refresh()->final_cost_minor);
            app(PrepaidChargingMonitor::class)->reconcile((string) $session->getKey());
            $owner = CustomerChargingSession::query()->where('session_id', $session->getKey())->firstOrFail();
            self::assertSame('requires_action', $owner->payment_state);
            self::assertNull($owner->settled_at);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
            $this->assertDatabaseCount('charging_email_deliveries', 0);
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

    public function test_cancelled_unknown_start_recovers_only_after_current_idle_evidence_and_releases_once(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('idle-cancel');
        $this->withinTenant($tenant, $actor, function () use ($actor, $tenant): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'idle-cancel-start');
            $id = (string) $session->getKey();
            $requestedAt = $session->requested_at;
            $command = ChargerCommand::query()->where('session_id', $id)->firstOrFail();
            $command->forceFill(['state' => 'delivery_unknown', 'dispatched_at' => now()])->save();
            app(CommandOutcomeService::class)->apply($command);
            $service->cancel($actor->public_id, $id, null);
            self::assertNotNull(AuthorizationToken::query()->where('session_id', $id)->firstOrFail()->revoked_at);
            $socket = (string) Str::ulid();
            $this->event((string) $tenant->getKey(), $connector, 'status_notification', ['connector_id' => 1, 'status' => 'Available', 'connection_id' => $socket]);
            $this->travel(240)->seconds();
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame('starting', $session->refresh()->state->value, 'An idle report from before the deadline is insufficient.');
            $this->event((string) $tenant->getKey(), $connector, 'status_notification', ['connector_id' => 1, 'status' => 'Preparing', 'connection_id' => $socket]);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame('starting', $session->refresh()->state->value);
            $this->event((string) $tenant->getKey(), $connector, 'status_notification', ['connector_id' => 1, 'status' => 'Available', 'connection_id' => $socket]);
            $connection = StationConnection::query()->firstOrFail();
            $connection->update(['connected' => false]);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame('starting', $session->refresh()->state->value, 'Offline evidence must not release funds.');
            $connection->update(['connected' => true, 'connection_id' => (string) Str::ulid()]);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame('starting', $session->refresh()->state->value, 'A different socket must report its own status.');
            $connection->update(['connection_id' => $socket]);
            $evidence = InboundOcppEvent::query()->create([
                'event_id' => (string) Str::ulid(), 'event_type' => 'gateway.ocpp.start_transaction.received.v1',
                'schema_version' => 1, 'aggregate_id' => $session->charging_station_id, 'correlation_id' => (string) Str::ulid(),
                'payload' => [], 'occurred_at' => now(), 'received_at' => now(), 'outcome' => 'quarantined',
            ]);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame('starting', $session->refresh()->state->value, 'Unresolved transaction evidence must block release.');
            $evidence->delete();
            app(PrepaidChargingMonitor::class)->reconcile($id);
            $service->cancel($actor->public_id, $id, null);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame('cancelled', $session->refresh()->state->value);
            self::assertSame(0, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['available_minor']);
            self::assertNull($service->active($actor->public_id));
            self::assertSame(0, ChargerCommand::query()->where('type', 'remote_stop')->count());
            self::assertSame('delivery_unknown', $command->refresh()->state->value);

            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $next = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'next-start');
            $this->event((string) $tenant->getKey(), $connector, 'start_transaction', ['connector_id' => 1, 'protocol_transaction_id' => 991, 'meter_start_wh' => 1000, 'protocol_timestamp' => $requestedAt->addSecond()->toISOString()]);
            self::assertSame('starting', $next->refresh()->state->value, 'A delayed old transaction must not attach to the new request.');
            self::assertNull($next->protocol_transaction_id);
        });
    }

    public function test_pending_cancellation_does_not_release_when_meter_evidence_exists(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('idle-meter');
        $this->withinTenant($tenant, $actor, function () use ($actor, $tenant): void {
            $connector = $this->fixture($actor);
            $service = app(CustomerCharging::class);
            $quote = $service->prepare($actor->public_id, (string) $connector->getKey());
            $session = $service->start($actor->public_id, $quote['quote']['id'], (string) $connector->getKey(), 'meter-cancel-start');
            $id = (string) $session->getKey();
            ChargerCommand::query()->where('session_id', $id)->update(['state' => 'delivery_unknown', 'dispatched_at' => now()]);
            $service->cancel($actor->public_id, $id, null);
            $session->update(['meter_start_wh' => 0]);
            $this->travel(240)->seconds();
            $this->event((string) $tenant->getKey(), $connector, 'status_notification', ['connector_id' => 1, 'status' => 'Available', 'connection_id' => (string) Str::ulid()]);
            app(PrepaidChargingMonitor::class)->reconcile($id);
            self::assertSame('starting', $session->refresh()->state->value);
            self::assertSame(100, app(PrepaidWallet::class)->summary($actor->public_id, 'live')['reserved_minor']);
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
