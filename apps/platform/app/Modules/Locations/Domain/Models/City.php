<?php

declare(strict_types=1);

namespace App\Modules\Locations\Domain\Models;

use App\Foundation\Database\ArchivableMasterModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class City extends ArchivableMasterModel
{
    /** @return BelongsTo<Province, $this> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /** @return HasMany<Barangay, $this> */
    public function barangays(): HasMany
    {
        return $this->hasMany(Barangay::class);
    }
}
