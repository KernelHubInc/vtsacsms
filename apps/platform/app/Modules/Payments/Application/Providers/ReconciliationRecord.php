<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

final readonly class ReconciliationRecord
{
    /** @param array<string, scalar|null> $safeEvidence */
    public function __construct(
        public string $providerReference,
        public string $currency,
        public int $grossMinor,
        public int $refundMinor,
        public int $feeMinor,
        public int $netMinor,
        public array $safeEvidence = [],
    ) {}
}
