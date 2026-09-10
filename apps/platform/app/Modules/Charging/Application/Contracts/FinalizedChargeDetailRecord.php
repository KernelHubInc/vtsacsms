<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application\Contracts;

use Carbon\CarbonImmutable;

final readonly class FinalizedChargeDetailRecord
{
    /** @param array<string, mixed> $sourceSnapshot */
    public function __construct(public string $id, public string $sessionId, public int $version, public string $snapshotHash,
        public string $currency, public int $subtotalMinor, public int $discountMinor, public int $taxMinor,
        public int $totalMinor, public array $sourceSnapshot, public CarbonImmutable $finalizedAt) {}
}
