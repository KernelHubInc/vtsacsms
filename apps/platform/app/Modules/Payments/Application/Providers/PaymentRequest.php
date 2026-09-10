<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

final readonly class PaymentRequest
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $intentId,
        public string $providerConfigId,
        public int $amountMinor,
        public string $currency,
        public string $idempotencyKey,
        public ?string $paymentMethodToken = null,
        public ?string $providerReference = null,
        public array $metadata = [],
    ) {}
}
