<?php

declare(strict_types=1);

namespace App\Modules\Locations\Domain\Models;

use App\Foundation\Database\ArchivableMasterModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Country extends ArchivableMasterModel
{
    /** @return HasMany<Region, $this> */
    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }
}
