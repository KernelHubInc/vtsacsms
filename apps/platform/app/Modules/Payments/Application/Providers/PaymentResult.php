<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

final readonly class PaymentResult
{
    /** @param array<string, scalar|null> $safeData */
    public function __construct(
        public string $outcome,
        public string $providerStatus,
        public ?string $providerReference = null,
        public ?string $actionUrl = null,
        public ?string $errorCode = null,
        public ?string $safeMessage = null,
        public array $safeData = [],
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->outcome, ['succeeded', 'requires_action'], true);
    }
}
