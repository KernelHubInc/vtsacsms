<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SimCard extends TenantAssetModel
{
    /** @return BelongsTo<ChargingStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(ChargingStation::class, 'charging_station_id');
    }

    /** @return BelongsTo<NetworkProvider, $this> */
    public function networkProvider(): BelongsTo
    {
        return $this->belongsTo(NetworkProvider::class);
    }
}
