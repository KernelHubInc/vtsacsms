<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Foundation\Database\ArchivableMasterModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ChargerModel extends ArchivableMasterModel
{
    /** @return BelongsTo<ChargerManufacturer, $this> */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(ChargerManufacturer::class, 'charger_manufacturer_id');
    }

    /** @return HasMany<FirmwareVersion, $this> */
    public function firmwareVersions(): HasMany
    {
        return $this->hasMany(FirmwareVersion::class);
    }
}
