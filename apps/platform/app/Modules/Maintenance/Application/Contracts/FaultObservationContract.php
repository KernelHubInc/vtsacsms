<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application\Contracts;

use Carbon\CarbonImmutable;

interface FaultObservationContract
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function observe(
        string $sourceEventId,
        string $siteId,
        string $assetType,
        string $assetId,
        string $status,
        ?string $faultCode,
        CarbonImmutable $observedAt,
        array $evidence = [],
    ): void;
}
