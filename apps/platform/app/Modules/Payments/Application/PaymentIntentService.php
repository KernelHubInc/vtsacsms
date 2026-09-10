<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Payments\Application\Providers\PaymentProvider;
use App\Modules\Payments\Application\Providers\PaymentProviderRegistry;
use App\Modules\Payments\Application\Providers\PaymentRequest;
use App\Modules\Payments\Application\Providers\PaymentResult;
use App\Modules\Payments\Domain\Models\FinanceReview;
use App\Modules\Payments\Domain\Models\PaymentAttempt;
use App\Modules\Payments\Domain\Models\PaymentIntent;
use App\Modules\Payments\Domain\Models\PaymentProviderConfig;
use App\Modules\Payments\Domain\Models\PaymentRefund;
use App\Modules\Payments\Domain\Models\StoredPaymentMethod;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Payments\Domain\ProviderAttemptState;
use App\Modules\Payments\Domain\ProviderOperation;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Throwable;

final readonly class PaymentIntentService
{
    public function __construct(
        private CurrentTenant $tenant,
        private PaymentProviderRegistry $providers,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
    ) {}

    public function create(
        PaymentProviderConfig $configuration,
        string $billableType,
        string $billableId,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        ?string $userId = null,
        ?string $billingProfileId = null,
        ?string $paymentMethodId = null,
        bool $preauthorizationRequired = false,
    ): PaymentIntent {
        $currency = strtoupper($currency);
        if ($amountMinor <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('A positive minor-unit amount and ISO currency are required.');
        }
        $existing = PaymentIntent::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if ($existing->billable_type !== $billableType || $existing->billable_id !== $billableId
                || (int) $existing->amount_requested_minor !== $amountMinor || $existing->currency !== $currency) {
                throw new InvalidArgumentException('The idempotency key was already used for a different payment intent.');
            }

            return $existing;
        }

        return DB::transaction(function () use ($configuration, $billableType, $billableId, $amountMinor, $currency, $idempotencyKey, $userId, $billingProfileId, $paymentMethodId, $preauthorizationRequired): PaymentIntent {
            $intent = PaymentIntent::query()->create([
                'provider_config_id' => $configuration->getKey(), 'user_id' => $userId,
                'billing_profile_id' => $billingProfileId, 'payment_method_id' => $paymentMethodId,
                'billable_type' => $billableType, 'billable_id' => $billableId,
                'state' => $paymentMethodId === null ? PaymentIntentState::RequiresPaymentMethod : PaymentIntentState::Created,
                'currency' => $currency, 'amount_requested_minor' => $amountMinor,
                'idempotency_key' => $idempotencyKey, 'preauthorization_required' => $preauthorizationRequired,
            ]);
            $this->outbox->record('payments.intent.created.v1', 'payment_intent', (string) $intent->getKey(), [
                'state' => $intent->state->value, 'amount_minor' => $amountMinor, 'currency' => $currency,
                'billable_type' => $billableType, 'billable_id' => $billableId,
            ]);

            return $intent;
        });
    }

    public function authorize(PaymentIntent $intent, string $idempotencyKey): PaymentIntent
    {
        if ($intent->state === PaymentIntentState::Authorized) {
            return $intent;
        }
        if (! in_array($intent->state, [PaymentIntentState::Created, PaymentIntentState::RequiresPaymentMethod, PaymentIntentState::AuthorizationPending, PaymentIntentState::RequiresAction], true)) {
            throw new InvalidArgumentException('The payment intent cannot be authorized in its current state.');
        }
        $method = $this->method($intent);
        $intent->forceFill(['state' => PaymentIntentState::AuthorizationPending, 'aggregate_version' => $intent->aggregate_version + 1])->save();
        $request = $this->request($intent, (int) $intent->amount_requested_minor, $idempotencyKey, $method);
        $result = $this->call(fn (): PaymentResult => $this->provider($intent)->authorize($request));

        return $this->applyOperation($intent, ProviderOperation::Authorize, $request, $result);
    }

    public function incrementAuthorization(PaymentIntent $intent, int $newTotalMinor, string $idempotencyKey): PaymentIntent
    {
        if ($intent->state !== PaymentIntentState::Authorized || $newTotalMinor <= (int) $intent->amount_authorized_minor) {
            throw new InvalidArgumentException('Incremental authorization requires an authorized intent and a higher total amount.');
        }
        $request = $this->request($intent, $newTotalMinor, $idempotencyKey, $this->method($intent));
        $result = $this->call(fn (): PaymentResult => $this->provider($intent)->incrementAuthorization($request));

        return $this->applyOperation($intent, ProviderOperation::IncrementAuthorization, $request, $result);
    }

    public function capture(PaymentIntent $intent, int $amountMinor, string $idempotencyKey): PaymentIntent
    {
        if (! in_array($intent->state, [PaymentIntentState::Authorized, PaymentIntentState::PartiallyCaptured, PaymentIntentState::CapturePending], true)) {
            throw new InvalidArgumentException('Only an authorized payment intent can be captured.');
        }
        if ($amountMinor <= 0 || (int) $intent->amount_captured_minor + $amountMinor > (int) $intent->amount_authorized_minor) {
            throw new InvalidArgumentException('Capture amount exceeds the remaining authorization.');
        }
        $intent->forceFill(['state' => PaymentIntentState::CapturePending, 'aggregate_version' => $intent->aggregate_version + 1])->save();
        $request = $this->request($intent, $amountMinor, $idempotencyKey, $this->method($intent));
        $result = $this->call(fn (): PaymentResult => $this->provider($intent)->capture($request));

        return $this->applyOperation($intent, ProviderOperation::Capture, $request, $result);
    }

    public function void(PaymentIntent $intent, string $idempotencyKey): PaymentIntent
    {
        if (! in_array($intent->state, [PaymentIntentState::Authorized, PaymentIntentState::AuthorizationPending], true)) {
            throw new InvalidArgumentException('Only an authorization can be voided.');
        }
        $intent->forceFill(['state' => PaymentIntentState::CancelPending, 'aggregate_version' => $intent->aggregate_version + 1])->save();
        $request = $this->request($intent, (int) $intent->amount_authorized_minor, $idempotencyKey, $this->method($intent));

        return $this->applyOperation($intent, ProviderOperation::Void, $request,
            $this->call(fn (): PaymentResult => $this->provider($intent)->void($request)));
    }

    public function refund(PaymentIntent $intent, int $amountMinor, string $idempotencyKey, string $reasonCode, ?string $notes = null): PaymentRefund
    {
        $remaining = (int) $intent->amount_captured_minor - (int) $intent->amount_refunded_minor;
        if ($amountMinor <= 0 || $amountMinor > $remaining) {
            throw new InvalidArgumentException('Refund amount exceeds the refundable balance.');
        }
        $existing = PaymentRefund::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        $refund = PaymentRefund::query()->create(['payment_intent_id' => $intent->getKey(), 'state' => 'pending',
            'currency' => $intent->currency, 'amount_minor' => $amountMinor, 'idempotency_key' => $idempotencyKey,
            'reason_code' => $reasonCode, 'reason_notes' => $notes, 'requested_by' => $this->tenant->get()->actorId]);
        $request = $this->request($intent, $amountMinor, $idempotencyKey, $this->method($intent));
        $result = $this->call(fn (): PaymentResult => $this->provider($intent)->refund($request));
        $this->recordAttempt($intent, ProviderOperation::Refund, $request, $result);
        DB::transaction(function () use ($intent, $refund, $result, $amountMinor): void {
            if ($result->succeeded()) {
                $refunded = (int) $intent->amount_refunded_minor + $amountMinor;
                $intent->forceFill(['amount_refunded_minor' => $refunded,
                    'state' => $refunded === (int) $intent->amount_captured_minor ? PaymentIntentState::Refunded : PaymentIntentState::PartiallyRefunded,
                    'aggregate_version' => $intent->aggregate_version + 1])->save();
                $refund->forceFill(['state' => 'completed', 'provider_reference' => $result->providerReference, 'completed_at' => now('UTC')])->save();
                $this->outbox->record('payments.refund.succeeded.v1', 'payment_refund', (string) $refund->getKey(), ['payment_intent_id' => (string) $intent->getKey(), 'amount_minor' => $amountMinor, 'currency' => $intent->currency]);
            } else {
                $refund->forceFill(['state' => $result->outcome === 'outcome_unknown' ? 'outcome_unknown' : 'failed', 'failed_at' => now('UTC')])->save();
                $this->review($intent, $result->outcome === 'outcome_unknown' ? 'refund_outcome_unknown' : 'refund_failed', $result);
            }
        });
        $this->audit->record(new AuditEntry('payments.refund.requested', 'payment_refund', (string) $refund->getKey(), $result->succeeded() ? AuditResult::Succeeded : AuditResult::Failed,
            reason: $reasonCode, metadata: ['amount_minor' => $amountMinor, 'currency' => $intent->currency]));

        return $refund->refresh();
    }

    public function retrieve(PaymentIntent $intent, string $idempotencyKey): PaymentIntent
    {
        $request = $this->request($intent, 0, $idempotencyKey, $this->method($intent));
        $result = $this->call(fn (): PaymentResult => $this->provider($intent)->retrievePaymentStatus($request));

        return $this->applyOperation($intent, ProviderOperation::Retrieve, $request, $result);
    }

    private function applyOperation(PaymentIntent $intent, ProviderOperation $operation, PaymentRequest $request, PaymentResult $result): PaymentIntent
    {
        $this->recordAttempt($intent, $operation, $request, $result);
        DB::transaction(function () use ($intent, $operation, $request, $result): void {
            $changes = ['provider_intent_reference' => $result->providerReference ?? $intent->provider_intent_reference,
                'aggregate_version' => $intent->aggregate_version + 1, 'failure_code' => $result->errorCode,
                'failure_message' => $result->safeMessage];
            if ($result->outcome === 'outcome_unknown') {
                $changes['state'] = $operation === ProviderOperation::Capture ? PaymentIntentState::CapturePending : PaymentIntentState::AuthorizationPending;
                $this->review($intent, 'payment_outcome_unknown', $result);
            } elseif (! $result->succeeded()) {
                $changes['state'] = match ($operation) {
                    ProviderOperation::Capture => PaymentIntentState::Authorized,
                    ProviderOperation::Refund => $intent->state,
                    default => PaymentIntentState::Failed,
                };
                $this->review($intent, $operation === ProviderOperation::Capture ? 'capture_failed' : 'provider_operation_failed', $result);
            } elseif ($operation === ProviderOperation::Authorize || $operation === ProviderOperation::IncrementAuthorization) {
                $changes['state'] = $result->outcome === 'requires_action' ? PaymentIntentState::RequiresAction : PaymentIntentState::Authorized;
                if ($result->outcome !== 'requires_action') {
                    $changes['amount_authorized_minor'] = $request->amountMinor;
                    $changes['authorized_at'] = now('UTC');
                }
            } elseif ($operation === ProviderOperation::Capture) {
                $captured = (int) $intent->amount_captured_minor + $request->amountMinor;
                $changes['amount_captured_minor'] = $captured;
                $changes['captured_at'] = now('UTC');
                $changes['state'] = $captured === (int) $intent->amount_authorized_minor ? PaymentIntentState::Captured : PaymentIntentState::PartiallyCaptured;
            } elseif ($operation === ProviderOperation::Void) {
                $changes['state'] = PaymentIntentState::Canceled;
                $changes['canceled_at'] = now('UTC');
            } elseif ($operation === ProviderOperation::Retrieve) {
                $changes = [...$changes, ...$this->retrievedChanges($intent, $result)];
            }
            $intent->forceFill($changes)->save();
            $event = match ($operation) {
                ProviderOperation::Authorize, ProviderOperation::IncrementAuthorization => $intent->state === PaymentIntentState::Authorized ? 'payments.authorization.succeeded.v1' : 'payments.authorization.failed.v1',
                ProviderOperation::Capture => $intent->state === PaymentIntentState::Captured || $intent->state === PaymentIntentState::PartiallyCaptured ? 'payments.capture.succeeded.v1' : 'payments.capture.failed.v1',
                default => 'payments.intent.status_changed.v1',
            };
            $this->outbox->record($event, 'payment_intent', (string) $intent->getKey(), ['state' => $intent->state->value, 'provider_status' => $result->providerStatus,
                'amount_authorized_minor' => (int) $intent->amount_authorized_minor, 'amount_captured_minor' => (int) $intent->amount_captured_minor, 'currency' => $intent->currency]);
        });

        return $intent->refresh();
    }

    /** @return array<string, mixed> */
    private function retrievedChanges(PaymentIntent $intent, PaymentResult $result): array
    {
        return match ($result->providerStatus) {
            'authorized', 'requires_capture' => ['state' => PaymentIntentState::Authorized, 'amount_authorized_minor' => max((int) $intent->amount_authorized_minor, (int) $intent->amount_requested_minor), 'authorized_at' => $intent->authorized_at ?? now('UTC')],
            'captured', 'succeeded' => ['state' => PaymentIntentState::Captured, 'amount_authorized_minor' => max((int) $intent->amount_authorized_minor, (int) $intent->amount_requested_minor), 'amount_captured_minor' => max((int) $intent->amount_captured_minor, (int) $intent->amount_requested_minor), 'captured_at' => $intent->captured_at ?? now('UTC')],
            'partially_refunded' => ['state' => PaymentIntentState::PartiallyRefunded], 'refunded' => ['state' => PaymentIntentState::Refunded],
            'canceled' => ['state' => PaymentIntentState::Canceled, 'canceled_at' => now('UTC')],
            'requires_action' => ['state' => PaymentIntentState::RequiresAction],
            default => [],
        };
    }

    private function method(PaymentIntent $intent): ?StoredPaymentMethod
    {
        return $intent->payment_method_id === null ? null : StoredPaymentMethod::query()->findOrFail($intent->payment_method_id);
    }

    private function request(PaymentIntent $intent, int $amountMinor, string $idempotencyKey, ?StoredPaymentMethod $method): PaymentRequest
    {
        return new PaymentRequest((string) $intent->getKey(), (string) $intent->provider_config_id, $amountMinor, (string) $intent->currency,
            $idempotencyKey, $method === null ? null : (string) $method->provider_token_encrypted, $intent->provider_intent_reference,
            ['billable_type' => (string) $intent->billable_type, 'billable_id' => (string) $intent->billable_id]);
    }

    private function provider(PaymentIntent $intent): PaymentProvider
    {
        return $this->providers->for(PaymentProviderConfig::query()->findOrFail($intent->provider_config_id));
    }

    private function call(callable $operation): PaymentResult
    {
        try {
            return $operation();
        } catch (Throwable) {
            return new PaymentResult('outcome_unknown', 'transport_error', errorCode: 'provider_transport_error', safeMessage: 'Provider outcome is unknown and requires retrieval.');
        }
    }

    private function recordAttempt(PaymentIntent $intent, ProviderOperation $operation, PaymentRequest $request, PaymentResult $result): void
    {
        $evidence = ['outcome' => $result->outcome, 'provider_status' => $result->providerStatus, 'error_code' => $result->errorCode, ...$result->safeData];
        PaymentAttempt::query()->firstOrCreate(['idempotency_key' => $request->idempotencyKey], [
            'payment_intent_id' => $intent->getKey(), 'operation' => $operation, 'state' => match ($result->outcome) {
                'succeeded', 'requires_action' => ProviderAttemptState::Succeeded, 'failed' => ProviderAttemptState::Failed, default => ProviderAttemptState::OutcomeUnknown,
            }, 'currency' => $request->currency, 'amount_minor' => $request->amountMinor,
            'provider_reference' => $result->providerReference, 'provider_status' => $result->providerStatus,
            'error_code' => $result->errorCode, 'safe_message' => $result->safeMessage, 'safe_evidence' => $evidence,
            'evidence_hash' => $this->hash($evidence), 'submitted_at' => now('UTC'), 'resolved_at' => $result->outcome === 'outcome_unknown' ? null : now('UTC'),
        ]);
    }

    private function review(PaymentIntent $intent, string $reason, PaymentResult $result): void
    {
        FinanceReview::query()->firstOrCreate(['source_type' => 'payment_intent', 'source_id' => $intent->getKey(), 'reason_code' => $reason, 'status' => 'open'],
            ['severity' => 'warning', 'evidence' => ['provider_status' => $result->providerStatus, 'error_code' => $result->errorCode], 'opened_at' => now('UTC')]);
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        try {
            return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException) {
            return hash('sha256', 'unencodable');
        }
    }
}
