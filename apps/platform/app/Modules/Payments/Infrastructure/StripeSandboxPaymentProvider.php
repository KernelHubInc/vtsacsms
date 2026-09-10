<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure;

use App\Modules\Payments\Application\Providers\PaymentProvider;
use App\Modules\Payments\Application\Providers\PaymentRequest;
use App\Modules\Payments\Application\Providers\PaymentResult;
use App\Modules\Payments\Application\Providers\ProviderException;
use App\Modules\Payments\Application\Providers\VerifiedWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;

final readonly class StripeSandboxPaymentProvider implements PaymentProvider
{
    public function __construct(private Factory $http) {}

    public function key(): string
    {
        return 'stripe_sandbox';
    }

    public function capabilities(): array
    {
        return ['hosted_checkout' => true, 'tokenization' => true, 'authorization' => true,
            'incremental_authorization' => true, 'capture' => true, 'void' => true, 'refund' => true,
            'polling' => true, 'reconciliation_export' => false, 'settlement' => false];
    }

    public function createHostedCheckout(PaymentRequest $request, string $returnUrl): PaymentResult
    {
        $response = $this->post('/v1/checkout/sessions', [
            'mode' => 'payment', 'success_url' => $returnUrl, 'cancel_url' => $returnUrl,
            'payment_intent_data[capture_method]' => 'manual',
            'line_items[0][price_data][currency]' => strtolower($request->currency),
            'line_items[0][price_data][product_data][name]' => 'EV charging authorization',
            'line_items[0][price_data][unit_amount]' => $request->amountMinor,
            'line_items[0][quantity]' => 1, 'client_reference_id' => $request->intentId,
        ], $request->idempotencyKey);

        return $this->map($response, 'url');
    }

    public function attachTokenizedPaymentMethod(string $providerCustomerReference, string $token): PaymentResult
    {
        $response = $this->post('/v1/payment_methods/'.rawurlencode($token).'/attach', ['customer' => $providerCustomerReference], 'attach-'.$providerCustomerReference.'-'.$token);

        return $this->map($response);
    }

    public function authorize(PaymentRequest $request): PaymentResult
    {
        if ($request->paymentMethodToken === null) {
            throw new ProviderException('Stripe authorization requires a tokenized payment method reference.');
        }
        $payload = ['amount' => $request->amountMinor, 'currency' => strtolower($request->currency),
            'payment_method' => $request->paymentMethodToken, 'confirm' => 'true', 'capture_method' => 'manual',
            'metadata[vtsa_intent_id]' => $request->intentId];

        return $this->map($this->post('/v1/payment_intents', $payload, $request->idempotencyKey));
    }

    public function incrementAuthorization(PaymentRequest $request): PaymentResult
    {
        return $this->map($this->post($this->intentPath($request).'/increment_authorization', ['amount' => $request->amountMinor], $request->idempotencyKey));
    }

    public function capture(PaymentRequest $request): PaymentResult
    {
        return $this->map($this->post($this->intentPath($request).'/capture', ['amount_to_capture' => $request->amountMinor], $request->idempotencyKey));
    }

    public function void(PaymentRequest $request): PaymentResult
    {
        return $this->map($this->post($this->intentPath($request).'/cancel', [], $request->idempotencyKey));
    }

    public function refund(PaymentRequest $request): PaymentResult
    {
        return $this->map($this->post('/v1/refunds', ['payment_intent' => $request->providerReference, 'amount' => $request->amountMinor,
            'metadata[vtsa_intent_id]' => $request->intentId], $request->idempotencyKey));
    }

    public function retrievePaymentStatus(PaymentRequest $request): PaymentResult
    {
        $response = $this->client()->get($this->baseUrl().$this->intentPath($request));

        return $this->map($response);
    }

    public function verifyWebhook(string $rawBody, string $signature): VerifiedWebhook
    {
        $secret = config('payments.stripe.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            throw new ProviderException('Stripe sandbox webhook secret is not configured.');
        }
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key][] = $value;
            }
        }
        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        if ($timestamp <= 0 || abs(time() - $timestamp) > (int) config('payments.stripe.webhook_tolerance_seconds', 300)) {
            throw new ProviderException('Stripe webhook timestamp is outside the replay window.');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        $valid = false;
        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $valid = true;
                break;
            }
        }
        if (! $valid) {
            throw new ProviderException('Stripe webhook signature verification failed.');
        }
        try {
            $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException('Invalid Stripe webhook JSON.', previous: $exception);
        }
        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            throw new ProviderException('Invalid Stripe webhook envelope.');
        }
        $object = $payload['data']['object'] ?? null;
        $object = is_array($object) ? $object : [];

        return new VerifiedWebhook($payload['id'], $payload['type'], is_string($object['id'] ?? null) ? $object['id'] : null,
            is_int($payload['created'] ?? null) ? CarbonImmutable::createFromTimestampUTC($payload['created']) : null,
            ['status' => is_string($object['status'] ?? null) ? $object['status'] : null,
                'amount_minor' => is_int($object['amount_received'] ?? null) ? $object['amount_received'] : (is_int($object['amount'] ?? null) ? $object['amount'] : null),
                'currency' => is_string($object['currency'] ?? null) ? strtoupper($object['currency']) : null]);
    }

    public function reconciliationExport(CarbonImmutable $from, CarbonImmutable $to): array
    {
        throw new ProviderException('Stripe reconciliation export requires a separately approved reporting adapter.');
    }

    public function submitSettlement(string $batchId, int $amountMinor, string $currency, string $idempotencyKey): PaymentResult
    {
        throw new ProviderException('Automated Stripe settlement submission is not configured.');
    }

    /** @param array<string, scalar|null> $payload */
    private function post(string $path, array $payload, string $idempotencyKey): Response
    {
        return $this->client()->withHeader('Idempotency-Key', $idempotencyKey)->asForm()->post($this->baseUrl().$path, $payload);
    }

    private function client(): PendingRequest
    {
        $secret = config('payments.stripe.secret_key');
        if (! is_string($secret) || ! str_starts_with($secret, 'sk_test_')) {
            throw new ProviderException('A Stripe test-mode secret key is required; live keys are rejected.');
        }

        return $this->http->withToken($secret)->acceptJson()->withHeader('Stripe-Version', (string) config('payments.stripe.api_version'))->timeout((int) config('payments.stripe.timeout_seconds', 20));
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('payments.stripe.base_url', 'https://api.stripe.com'), '/');
    }

    private function intentPath(PaymentRequest $request): string
    {
        if ($request->providerReference === null || $request->providerReference === '') {
            throw new ProviderException('Provider payment-intent reference is required.');
        }

        return '/v1/payment_intents/'.rawurlencode($request->providerReference);
    }

    private function map(Response $response, ?string $actionField = null): PaymentResult
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $reference = is_string($data['id'] ?? null) ? $data['id'] : null;
        $status = is_string($data['status'] ?? null) ? $data['status'] : ($response->successful() ? 'succeeded' : 'failed');
        if (! $response->successful()) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];

            return new PaymentResult('failed', $status, $reference, errorCode: is_string($error['code'] ?? null) ? $error['code'] : 'provider_error', safeMessage: 'Stripe rejected the sandbox request.');
        }
        $outcome = match ($status) {
            'requires_action', 'requires_source_action' => 'requires_action', 'canceled', 'requires_payment_method' => 'failed', default => 'succeeded'
        };
        $action = $actionField !== null && is_string($data[$actionField] ?? null) ? $data[$actionField] : null;

        return new PaymentResult($outcome, $status, $reference, $action);
    }
}
