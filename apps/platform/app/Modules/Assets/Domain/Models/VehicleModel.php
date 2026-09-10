<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Foundation\Database\ArchivableMasterModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class VehicleModel extends ArchivableMasterModel
{
    /** @return BelongsTo<VehicleManufacturer, $this> */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(VehicleManufacturer::class);
    }

    /** @return HasMany<VehicleVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(VehicleVariant::class);
    }
}
