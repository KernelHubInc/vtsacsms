<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ChargingStationPhoto extends TenantAssetModel
{
    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    /** @return BelongsTo<ChargingStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(ChargingStation::class, 'charging_station_id');
    }
}
