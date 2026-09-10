<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use Database\Factories\Modules\Assets\Domain\Models\ConnectorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property string $evse_id
 * @property int $connector_number
 * @property int $maximum_power_w
 * @property AssetLifecycleStatus $lifecycle_status
 * @property Evse $evse
 */
final class Connector extends TenantAssetModel
{
    /** @use HasFactory<ConnectorFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['lifecycle_status' => AssetLifecycleStatus::class];
    }

    /** @return BelongsTo<Evse, $this> */
    public function evse(): BelongsTo
    {
        return $this->belongsTo(Evse::class);
    }

    /** @return BelongsTo<ConnectorStandard, $this> */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(ConnectorStandard::class, 'connector_standard_id');
    }

    /** @return BelongsTo<ChargingCurrentType, $this> */
    public function currentType(): BelongsTo
    {
        return $this->belongsTo(ChargingCurrentType::class, 'charging_current_type_id');
    }

    /** @return HasMany<AssetComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(AssetComponent::class);
    }
}
