<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ChargingStationFirmwareHistory extends TenantAssetModel
{
    protected $table = 'charging_station_firmware_history';

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ChargingStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(ChargingStation::class, 'charging_station_id');
    }

    /** @return BelongsTo<FirmwareVersion, $this> */
    public function firmwareVersion(): BelongsTo
    {
        return $this->belongsTo(FirmwareVersion::class);
    }
}
