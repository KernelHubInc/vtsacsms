<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application\Contracts;

interface FinalizedChargeDetailRecordQuery
{
    public function get(string $chargeDetailRecordId): FinalizedChargeDetailRecord;
}
