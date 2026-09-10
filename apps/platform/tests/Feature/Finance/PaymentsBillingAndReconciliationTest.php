<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Billing\Application\CreditNoteService;
use App\Modules\Billing\Application\DoubleEntryLedger;
use App\Modules\Billing\Application\InvoiceService;
use App\Modules\Billing\Application\LedgerPosting;
use App\Modules\Billing\Application\PaymentAllocationService;
use App\Modules\Billing\Application\RevenueShareService;
use App\Modules\Billing\Domain\Models\BillingProfile;
use App\Modules\Billing\Domain\Models\LedgerEntry;
use App\Modules\Billing\Domain\Models\LedgerTransaction;
use App\Modules\Billing\Domain\Models\Receipt;
use App\Modules\Billing\Domain\Models\RevenueShareRule;
use App\Modules\Charging\Domain\AuthorizationStatus;
use App\Modules\Charging\Domain\ChargeDetailRecordState;
use App\Modules\Charging\Domain\ChargingSessionOrigin;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargeDetailRecord;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Payments\Application\Contracts\PaymentFactsQuery;
use App\Modules\Payments\Application\PaymentIntentService;
use App\Modules\Payments\Application\PaymentWebhookService;
use App\Modules\Payments\Application\StoredPaymentMethodService;
use App\Modules\Payments\Domain\Models\FakePaymentProviderResource;
use App\Modules\Payments\Domain\Models\FinanceReview;
use App\Modules\Payments\Domain\Models\PaymentIntent;
use App\Modules\Payments\Domain\Models\PaymentProviderConfig;
use App\Modules\Payments\Domain\Models\PaymentWebhookReceipt;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Settlements\Application\ReconciliationService;
use App\Modules\Settlements\Application\SettlementService;
use App\Modules\Settlements\Domain\Models\ReconciliationLine;
use App\Modules\Settlements\Domain\Models\SettlementBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\TenantSecurityTestCase;

final class PaymentsBillingAndReconciliationTest extends TenantSecurityTestCase
{
    public function test_duplicate_and_delayed_webhooks_are_safe_and_monotonic(): void
    {
        config()->set('payments.fake.webhook_secret', 'test-webhook-secret');
        $tenant = $this->createTenant('payments-webhooks');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            [$intent, $configuration] = $this->authorizedIntent('fake_success');
            $capturedBody = json_encode(['id' => 'evt_captured_1', 'type' => 'payment.captured',
                'resource_reference' => $intent->provider_intent_reference, 'status' => 'captured',
                'amount_minor' => 1500, 'currency' => 'PHP', 'created_at' => now('UTC')->subMinute()->toISOString()], JSON_THROW_ON_ERROR);
            $webhooks = app(PaymentWebhookService::class);
            $first = $webhooks->handle($configuration, $capturedBody, 'sha256='.hash_hmac('sha256', $capturedBody, 'test-webhook-secret'));
            $duplicate = $webhooks->handle($configuration, $capturedBody, 'sha256='.hash_hmac('sha256', $capturedBody, 'test-webhook-secret'));
            $this->assertSame('processed', $first->outcome);
            $this->assertSame('duplicate', $duplicate->outcome);
            $this->assertSame(PaymentIntentState::Captured, $intent->refresh()->state);

            $delayedBody = json_encode(['id' => 'evt_delayed_cancel', 'type' => 'payment.canceled',
                'resource_reference' => $intent->provider_intent_reference, 'status' => 'canceled',
                'created_at' => now('UTC')->subHour()->toISOString()], JSON_THROW_ON_ERROR);
            $webhooks->handle($configuration, $delayedBody, 'sha256='.hash_hmac('sha256', $delayedBody, 'test-webhook-secret'));
            $this->assertSame(PaymentIntentState::Captured, $intent->refresh()->state);
            $this->assertSame(2, PaymentWebhookReceipt::query()->count());
        });
    }

    public function test_payment_success_after_app_timeout_is_recovered_by_status_polling(): void
    {
        $tenant = $this->createTenant('payments-timeout');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            [$intent] = $this->authorizedIntent('fake_timeout_after_capture');
            $payments = app(PaymentIntentService::class);
            $intent = $payments->capture($intent, 1500, 'capture-timeout-1');
            $this->assertSame(PaymentIntentState::CapturePending, $intent->state);
            $this->assertDatabaseHas('finance_reviews', ['source_id' => $intent->getKey(), 'reason_code' => 'payment_outcome_unknown']);
            $intent = $payments->retrieve($intent, 'retrieve-after-timeout-1');
            $this->assertSame(PaymentIntentState::Captured, $intent->state);
            $this->assertSame(1500, $intent->amount_captured_minor);
        });
    }

    public function test_failed_capture_remains_retryable_and_enters_finance_review(): void
    {
        $tenant = $this->createTenant('payments-capture-failure');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            [$intent] = $this->authorizedIntent('fake_fail_capture');
            $intent = app(PaymentIntentService::class)->capture($intent, 1500, 'capture-failure-1');
            $this->assertSame(PaymentIntentState::Authorized, $intent->state);
            $this->assertSame(0, $intent->amount_captured_minor);
            $this->assertDatabaseHas('finance_reviews', ['source_id' => $intent->getKey(), 'reason_code' => 'capture_failed', 'status' => 'open']);
        });
    }

    public function test_partial_and_full_refunds_use_minor_units_and_are_idempotent(): void
    {
        $tenant = $this->createTenant('payments-refunds');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            [$intent] = $this->authorizedIntent('fake_success');
            $payments = app(PaymentIntentService::class);
            $intent = $payments->capture($intent, 1500, 'capture-refundable-1');
            $first = $payments->refund($intent, 400, 'refund-partial-1', 'customer_request');
            $this->assertSame('completed', $first->state);
            $this->assertSame(PaymentIntentState::PartiallyRefunded, $intent->refresh()->state);
            $same = $payments->refund($intent->refresh(), 400, 'refund-partial-1', 'customer_request');
            $this->assertSame($first->getKey(), $same->getKey());
            $payments->refund($intent->refresh(), 1100, 'refund-final-1', 'customer_request');
            $this->assertSame(PaymentIntentState::Refunded, $intent->refresh()->state);
            $this->assertSame(1500, $intent->amount_refunded_minor);
        });
    }

    public function test_reconciliation_mismatch_creates_review_evidence(): void
    {
        $tenant = $this->createTenant('payments-reconciliation');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            [$intent, $configuration] = $this->authorizedIntent('fake_success');
            $intent = app(PaymentIntentService::class)->capture($intent, 1500, 'capture-reconciliation-1');
            FakePaymentProviderResource::query()->where('provider_reference', $intent->provider_intent_reference)->update(['captured_minor' => 1400]);
            $run = app(ReconciliationService::class)->reconcile((string) $configuration->getKey(), CarbonImmutable::now('UTC')->subDay(), CarbonImmutable::now('UTC')->addMinute());
            $this->assertSame('review_required', $run->status);
            $this->assertSame(1, $run->mismatch_count);
            $this->assertSame(-100, ReconciliationLine::query()->firstOrFail()->difference_minor);
            $this->assertTrue(ReconciliationLine::query()->where('outcome', 'mismatch')->exists());
        });
    }

    public function test_ledger_rejects_unbalanced_entries_and_posts_balanced_entries_immutably(): void
    {
        $tenant = $this->createTenant('billing-ledger');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            $ledger = app(DoubleEntryLedger::class);
            $cash = $ledger->account('1000-CASH', 'Cash', 'asset', 'PHP');
            $revenue = $ledger->account('4000-TEST', 'Test revenue', 'revenue', 'PHP');
            try {
                $ledger->post('test', (string) Str::ulid(), 'test_unbalanced', 'PHP', 'ledger-unbalanced-1',
                    [new LedgerPosting($cash, debitMinor: 100), new LedgerPosting($revenue, creditMinor: 99)]);
                $this->fail('Unbalanced posting was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseMissing('ledger_transactions', ['idempotency_key' => 'ledger-unbalanced-1']);
            }
            $transaction = $ledger->post('test', (string) Str::ulid(), 'test_balanced', 'PHP', 'ledger-balanced-1',
                [new LedgerPosting($cash, debitMinor: 100), new LedgerPosting($revenue, creditMinor: 100)]);
            $this->assertSame(100, (int) LedgerEntry::query()->where('ledger_transaction_id', $transaction->getKey())->sum('debit_minor'));
            $this->assertSame(100, (int) LedgerEntry::query()->where('ledger_transaction_id', $transaction->getKey())->sum('credit_minor'));
            $this->expectException(\LogicException::class);
            $transaction->update(['description' => 'tampered']);
        });
    }

    public function test_financial_queries_are_tenant_scoped(): void
    {
        $tenantA = $this->createTenant('finance-tenant-a');
        $tenantB = $this->createTenant('finance-tenant-b');
        $actor = $this->createUser();
        $intentId = $this->withinTenant($tenantA, $actor, fn (): string => (string) $this->authorizedIntent('fake_success')[0]->getKey());
        $this->withinTenant($tenantB, $actor, function () use ($intentId): void {
            $this->assertNull(PaymentIntent::query()->find($intentId));
            $this->assertSame(0, FinanceReview::query()->count());
        });
    }

    public function test_raw_card_number_is_rejected_instead_of_stored_as_a_token(): void
    {
        $tenant = $this->createTenant('payment-data-minimization');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            $configuration = PaymentProviderConfig::query()->create(['provider' => 'fake', 'environment' => 'test',
                'api_version' => 'v1', 'configuration_version' => 1, 'capabilities' => ['tokenization' => true], 'is_active' => true]);
            $this->expectException(InvalidArgumentException::class);
            app(StoredPaymentMethodService::class)->store($configuration, (string) Str::ulid(), '4242 4242 4242 4242');
        });
    }

    public function test_public_webhook_boundary_verifies_raw_body_and_establishes_tenant(): void
    {
        config()->set('payments.fake.webhook_secret', 'http-test-secret');
        $tenant = $this->createTenant('webhook-http-boundary');
        $actor = $this->createUser();
        $configurationId = $this->withinTenant($tenant, $actor, function (): string {
            return (string) PaymentProviderConfig::query()->create(['provider' => 'fake', 'environment' => 'test',
                'api_version' => 'v1', 'configuration_version' => 1, 'capabilities' => ['authorization' => true], 'is_active' => true])->getKey();
        });
        $body = json_encode(['id' => 'evt_http_1', 'type' => 'payment.captured', 'resource_reference' => 'fake_pi_unknown',
            'status' => 'captured', 'amount_minor' => 100, 'currency' => 'PHP'], JSON_THROW_ON_ERROR);
        $path = '/api/v1/webhooks/payments/'.$tenant->getKey().'/'.$configurationId;
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'http-test-secret')], $body)
            ->assertAccepted()->assertJsonPath('data.outcome', 'review_required');
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYMENT_SIGNATURE' => 'sha256=invalid'], $body)
            ->assertStatus(400)->assertJsonPath('error.code', 'invalid_webhook');
        $this->withinTenant($tenant, $actor, fn () => $this->assertSame(1, PaymentWebhookReceipt::query()->count()));
    }

    public function test_invoice_receipt_credit_note_and_revenue_shares_post_balanced_entries(): void
    {
        $tenant = $this->createTenant('billing-documents');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, function (): void {
            [$session, $cdr] = $this->finalizedCdr(1500);
            $profile = BillingProfile::query()->create(['user_id' => $actorId = (string) Str::ulid(), 'profile_type' => 'individual',
                'legal_name' => 'Test Customer', 'email' => 'billing@example.invalid', 'country_code' => 'PH', 'is_default' => true]);
            $invoice = app(InvoiceService::class)->issueForChargeDetailRecord((string) $cdr->getKey(), $profile);
            $this->assertTrue($invoice->legal_review_required);
            $this->assertNull($invoice->legal_invoice_number);
            $this->assertSame(1500, $invoice->total_minor);

            [$intent] = $this->authorizedIntent('fake_success', (string) $session->getKey(), (string) $profile->getKey(), $actorId);
            $intent = app(PaymentIntentService::class)->capture($intent, 1500, 'billing-capture-1');
            app(PaymentAllocationService::class)->allocate(app(PaymentFactsQuery::class)->get((string) $intent->getKey()), $invoice, 1500);
            $this->assertSame('paid', $invoice->refresh()->status);
            $this->assertTrue(Receipt::query()->where('invoice_id', $invoice->getKey())->exists());

            $rule = RevenueShareRule::query()->create(['operator_organization_id' => (string) Str::ulid(),
                'site_host_organization_id' => (string) Str::ulid(), 'platform_basis_points' => 1000,
                'operator_basis_points' => 7000, 'site_host_basis_points' => 2000, 'effective_from' => now('UTC'), 'is_active' => true]);
            app(RevenueShareService::class)->allocate($invoice, $rule);
            $note = app(CreditNoteService::class)->issue($invoice, 200, 'service_adjustment');
            $this->assertTrue($note->legal_review_required);
            $transactions = LedgerTransaction::query()->with('entries')->get();
            foreach ($transactions as $transaction) {
                $this->assertSame((int) $transaction->entries->sum('debit_minor'), (int) $transaction->entries->sum('credit_minor'));
            }
        });
    }

    public function test_settlement_requires_separate_preparer_and_approver(): void
    {
        $tenant = $this->createTenant('settlement-controls');
        $preparer = $this->createUser();
        $approver = $this->createUser();
        $batch = $this->withinTenant($tenant, $preparer, function () use ($preparer): SettlementBatch {
            $configuration = PaymentProviderConfig::query()->create(['provider' => 'fake', 'environment' => 'test',
                'api_version' => 'v1', 'configuration_version' => 1, 'capabilities' => ['settlement' => true], 'is_active' => true]);
            $service = app(SettlementService::class);
            $batch = $service->prepare((string) $configuration->getKey(), 'PHP', CarbonImmutable::now('UTC')->subDay(), CarbonImmutable::now('UTC'), [[
                'beneficiary_type' => 'operator', 'beneficiary_id' => (string) Str::ulid(), 'source_type' => 'ledger_transaction',
                'source_id' => (string) Str::ulid(), 'gross_minor' => 1000, 'fee_minor' => 100, 'adjustment_minor' => 0,
            ]]);
            try {
                $service->approve($batch);
                $this->fail('The preparer approved their own settlement.');
            } catch (InvalidArgumentException) {
                $this->assertSame($preparer->public_id, $batch->prepared_by);
            }

            return $batch;
        });
        $this->withinTenant($tenant, $approver, function () use ($batch): void {
            $service = app(SettlementService::class);
            $approved = $service->approve($batch->refresh());
            $this->assertSame('approved', $approved->status);
            $settled = $service->submit($approved, 'settlement-submit-1');
            $this->assertSame('settled', $settled->status);
        });
    }

    /** @return array{PaymentIntent, PaymentProviderConfig} */
    private function authorizedIntent(string $token, ?string $billableId = null, ?string $billingProfileId = null, ?string $userId = null): array
    {
        $configuration = PaymentProviderConfig::query()->create(['provider' => 'fake', 'environment' => 'test',
            'api_version' => 'v1', 'configuration_version' => 1, 'capabilities' => ['authorization' => true, 'capture' => true, 'refund' => true], 'is_active' => true]);
        $userId ??= (string) Str::ulid();
        $method = app(StoredPaymentMethodService::class)->store($configuration, $userId, $token, last4: '4242');
        $payments = app(PaymentIntentService::class);
        $intent = $payments->create($configuration, 'charging_session', $billableId ?? (string) Str::ulid(), 1500, 'PHP', 'create-'.Str::ulid(),
            userId: $userId, billingProfileId: $billingProfileId, paymentMethodId: (string) $method->getKey(), preauthorizationRequired: true);

        return [$payments->authorize($intent, 'authorize-'.Str::ulid()), $configuration];
    }

    /** @return array{ChargingSession, ChargeDetailRecord} */
    private function finalizedCdr(int $totalMinor): array
    {
        $session = ChargingSession::query()->create(['site_id' => (string) Str::ulid(), 'operator_id' => null,
            'charging_station_id' => (string) Str::ulid(), 'evse_id' => (string) Str::ulid(), 'connector_id' => (string) Str::ulid(),
            'connector_maximum_power_w' => 22000, 'charge_point_identity' => 'TEST-CHARGER', 'protocol' => 'ocpp1.6',
            'origin' => ChargingSessionOrigin::Remote, 'state' => ChargingSessionState::Completed,
            'authorization_status' => AuthorizationStatus::Approved, 'tariff_snapshot' => ['currency' => 'PHP'],
            'tariff_snapshot_hash' => hash('sha256', 'test-tariff'), 'currency' => 'PHP', 'requested_at' => now('UTC')->subHour(),
            'started_at' => now('UTC')->subHour(), 'stopped_at' => now('UTC'), 'energy_wh' => 10000,
            'duration_seconds' => 3600, 'parking_seconds' => 0, 'idle_seconds' => 0, 'final_cost_minor' => $totalMinor,
            'finalization_outcome' => 'complete', 'anomaly_flags' => [], 'aggregate_version' => 1]);
        $snapshot = ['session_id' => (string) $session->getKey(), 'rating' => ['total_minor' => $totalMinor]];
        $cdr = ChargeDetailRecord::query()->create(['session_id' => $session->getKey(), 'version' => 1,
            'state' => ChargeDetailRecordState::Finalized, 'snapshot' => $snapshot,
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'currency' => 'PHP',
            'energy_wh' => 10000, 'duration_seconds' => 3600, 'subtotal_minor' => $totalMinor,
            'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => $totalMinor,
            'generated_at' => now('UTC'), 'finalized_at' => now('UTC')]);

        return [$session, $cdr];
    }
}
