<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ElectricityMeter extends TenantAssetModel
{
    protected function casts(): array
    {
        return ['lifecycle_status' => AssetLifecycleStatus::class];
    }

    /** @return BelongsTo<ChargingStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(ChargingStation::class, 'charging_station_id');
    }

    /** @return BelongsTo<Evse, $this> */
    public function evse(): BelongsTo
    {
        return $this->belongsTo(Evse::class);
    }
}
