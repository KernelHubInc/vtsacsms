<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure;

use App\Modules\Payments\Application\Providers\PaymentProvider;
use App\Modules\Payments\Application\Providers\PaymentRequest;
use App\Modules\Payments\Application\Providers\PaymentResult;
use App\Modules\Payments\Application\Providers\ProviderException;
use App\Modules\Payments\Application\Providers\ReconciliationRecord;
use App\Modules\Payments\Application\Providers\VerifiedWebhook;
use App\Modules\Payments\Domain\Models\FakePaymentProviderResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use JsonException;

final class FakePaymentProvider implements PaymentProvider
{
    public function key(): string
    {
        return 'fake';
    }

    public function capabilities(): array
    {
        return ['hosted_checkout' => true, 'tokenization' => true, 'authorization' => true,
            'incremental_authorization' => true, 'capture' => true, 'void' => true, 'refund' => true,
            'polling' => true, 'reconciliation_export' => true, 'settlement' => true];
    }

    public function createHostedCheckout(PaymentRequest $request, string $returnUrl): PaymentResult
    {
        return new PaymentResult('succeeded', 'checkout_created', 'fake_checkout_'.$request->intentId, $returnUrl);
    }

    public function attachTokenizedPaymentMethod(string $providerCustomerReference, string $token): PaymentResult
    {
        return new PaymentResult('succeeded', 'attached', 'fake_pm_'.hash('sha256', $providerCustomerReference.$token));
    }

    public function authorize(PaymentRequest $request): PaymentResult
    {
        $scenario = $this->scenario($request->paymentMethodToken);
        $reference = $request->providerReference ?? 'fake_pi_'.$request->intentId;
        $resource = FakePaymentProviderResource::query()->firstOrCreate(
            ['provider_config_id' => $request->providerConfigId, 'provider_reference' => $reference],
            ['status' => 'authorized', 'currency' => $request->currency, 'authorized_minor' => $request->amountMinor,
                'captured_minor' => 0, 'refunded_minor' => 0, 'scenario' => $scenario],
        );
        if ($scenario === 'fail_authorization') {
            $resource->update(['status' => 'failed']);

            return new PaymentResult('failed', 'failed', $reference, errorCode: 'fake_authorization_failed', safeMessage: 'Fake authorization failure.');
        }
        if ($scenario === 'requires_action') {
            $resource->update(['status' => 'requires_action']);

            return new PaymentResult('requires_action', 'requires_action', $reference, 'https://example.invalid/fake-action');
        }
        if ($scenario === 'timeout_after_authorization') {
            return new PaymentResult('outcome_unknown', 'timeout', $reference, errorCode: 'provider_timeout', safeMessage: 'The provider outcome must be retrieved.');
        }

        return new PaymentResult('succeeded', 'authorized', $reference);
    }

    public function incrementAuthorization(PaymentRequest $request): PaymentResult
    {
        $resource = $this->resource($request);
        $resource->update(['authorized_minor' => $request->amountMinor, 'status' => 'authorized']);

        return new PaymentResult('succeeded', 'authorized', $resource->provider_reference);
    }

    public function capture(PaymentRequest $request): PaymentResult
    {
        $resource = $this->resource($request);
        if ($resource->scenario === 'fail_capture') {
            return new PaymentResult('failed', 'capture_failed', $resource->provider_reference, errorCode: 'fake_capture_failed', safeMessage: 'Fake capture failure.');
        }
        $resource->update(['captured_minor' => $request->amountMinor, 'status' => 'captured']);
        if ($resource->scenario === 'timeout_after_capture') {
            return new PaymentResult('outcome_unknown', 'timeout', $resource->provider_reference, errorCode: 'provider_timeout', safeMessage: 'The capture outcome must be retrieved.');
        }

        return new PaymentResult('succeeded', 'captured', $resource->provider_reference);
    }

    public function void(PaymentRequest $request): PaymentResult
    {
        $resource = $this->resource($request);
        $resource->update(['status' => 'canceled']);

        return new PaymentResult('succeeded', 'canceled', $resource->provider_reference);
    }

    public function refund(PaymentRequest $request): PaymentResult
    {
        $resource = $this->resource($request);
        $refunded = (int) $resource->refunded_minor + $request->amountMinor;
        if ($refunded > (int) $resource->captured_minor) {
            return new PaymentResult('failed', 'refund_failed', $resource->provider_reference, errorCode: 'amount_exceeds_captured');
        }
        $resource->update(['refunded_minor' => $refunded, 'status' => $refunded === (int) $resource->captured_minor ? 'refunded' : 'partially_refunded']);

        return new PaymentResult('succeeded', (string) $resource->fresh()->status, 'fake_re_'.Str::ulid());
    }

    public function retrievePaymentStatus(PaymentRequest $request): PaymentResult
    {
        $resource = $this->resource($request);

        return new PaymentResult('succeeded', (string) $resource->status, (string) $resource->provider_reference);
    }

    public function verifyWebhook(string $rawBody, string $signature): VerifiedWebhook
    {
        $secret = config('payments.fake.webhook_secret');
        if (! is_string($secret) || $secret === '' || ! hash_equals(hash_hmac('sha256', $rawBody, $secret), $this->signatureValue($signature))) {
            throw new ProviderException('Fake webhook signature verification failed.');
        }
        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException('Invalid webhook JSON.', previous: $exception);
        }
        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            throw new ProviderException('Invalid fake webhook envelope.');
        }
        $reference = is_string($payload['resource_reference'] ?? null) ? $payload['resource_reference'] : null;
        $status = is_string($payload['status'] ?? null) ? $payload['status'] : null;

        return new VerifiedWebhook($payload['id'], $payload['type'], $reference,
            isset($payload['created_at']) && is_string($payload['created_at']) ? CarbonImmutable::parse($payload['created_at'])->utc() : null,
            ['status' => $status, 'amount_minor' => is_int($payload['amount_minor'] ?? null) ? $payload['amount_minor'] : null,
                'currency' => is_string($payload['currency'] ?? null) ? strtoupper($payload['currency']) : null]);
    }

    public function reconciliationExport(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(FakePaymentProviderResource::query()->whereBetween('updated_at', [$from, $to])->get()->map(
            static fn (FakePaymentProviderResource $item): ReconciliationRecord => new ReconciliationRecord(
                (string) $item->provider_reference, (string) $item->currency, (int) $item->captured_minor,
                (int) $item->refunded_minor, 0, (int) $item->captured_minor - (int) $item->refunded_minor,
            ),
        )->all());
    }

    public function submitSettlement(string $batchId, int $amountMinor, string $currency, string $idempotencyKey): PaymentResult
    {
        return new PaymentResult('succeeded', 'submitted', 'fake_st_'.$batchId);
    }

    private function resource(PaymentRequest $request): FakePaymentProviderResource
    {
        return FakePaymentProviderResource::query()->where('provider_config_id', $request->providerConfigId)
            ->where('provider_reference', $request->providerReference)->firstOrFail();
    }

    private function scenario(?string $token): string
    {
        return match ($token) {
            'fake_fail_authorization' => 'fail_authorization', 'fake_requires_action' => 'requires_action',
            'fake_timeout_after_authorization' => 'timeout_after_authorization', 'fake_fail_capture' => 'fail_capture',
            'fake_timeout_after_capture' => 'timeout_after_capture', default => 'succeed',
        };
    }

    private function signatureValue(string $signature): string
    {
        return str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
    }
}
