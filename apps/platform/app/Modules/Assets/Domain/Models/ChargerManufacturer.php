<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Foundation\Database\ArchivableMasterModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ChargerManufacturer extends ArchivableMasterModel
{
    /** @return HasMany<ChargerModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(ChargerModel::class);
    }
}
