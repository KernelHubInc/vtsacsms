<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Foundation\Database\ArchivableMasterModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class VehicleVariant extends ArchivableMasterModel
{
    /** @return BelongsTo<VehicleModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    /** @return HasMany<VehicleConnectorCompatibility, $this> */
    public function connectorCompatibilities(): HasMany
    {
        return $this->hasMany(VehicleConnectorCompatibility::class);
    }
}
