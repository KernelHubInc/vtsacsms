<?php

declare(strict_types=1);

namespace App\Modules\Charging\Infrastructure;

use App\Modules\Charging\Application\Contracts\FinalizedChargeDetailRecord;
use App\Modules\Charging\Application\Contracts\FinalizedChargeDetailRecordQuery;
use App\Modules\Charging\Domain\ChargeDetailRecordState;
use App\Modules\Charging\Domain\Models\ChargeDetailRecord;
use Carbon\CarbonImmutable;

final class EloquentFinalizedChargeDetailRecordQuery implements FinalizedChargeDetailRecordQuery
{
    public function get(string $chargeDetailRecordId): FinalizedChargeDetailRecord
    {
        $record = ChargeDetailRecord::query()->whereKey($chargeDetailRecordId)->where('state', ChargeDetailRecordState::Finalized->value)->firstOrFail();

        return new FinalizedChargeDetailRecord((string) $record->getKey(), (string) $record->session_id, (int) $record->version,
            (string) $record->snapshot_hash, (string) $record->currency, (int) $record->subtotal_minor,
            (int) $record->discount_minor, (int) $record->tax_minor, (int) $record->total_minor,
            is_array($record->snapshot) ? $record->snapshot : [], $record->finalized_at ?? CarbonImmutable::now('UTC'));
    }
}
