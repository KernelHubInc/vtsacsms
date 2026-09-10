<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use Database\Factories\Modules\Assets\Domain\Models\EvseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property string $charging_station_id
 * @property int $evse_number
 * @property AssetLifecycleStatus $lifecycle_status
 * @property ChargingStation $station
 */
final class Evse extends TenantAssetModel
{
    /** @use HasFactory<EvseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['lifecycle_status' => AssetLifecycleStatus::class];
    }

    /** @return BelongsTo<ChargingStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(ChargingStation::class, 'charging_station_id');
    }

    /** @return HasMany<Connector, $this> */
    public function connectors(): HasMany
    {
        return $this->hasMany(Connector::class);
    }
}
