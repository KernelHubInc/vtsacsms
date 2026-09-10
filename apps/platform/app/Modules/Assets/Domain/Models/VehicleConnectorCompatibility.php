<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VehicleConnectorCompatibility extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<VehicleVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(VehicleVariant::class, 'vehicle_variant_id');
    }

    /** @return BelongsTo<ConnectorStandard, $this> */
    public function connectorStandard(): BelongsTo
    {
        return $this->belongsTo(ConnectorStandard::class);
    }

    /** @return BelongsTo<ChargingCurrentType, $this> */
    public function currentType(): BelongsTo
    {
        return $this->belongsTo(ChargingCurrentType::class, 'charging_current_type_id');
    }
}
