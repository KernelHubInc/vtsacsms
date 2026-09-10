<?php

declare(strict_types=1);

namespace App\Modules\Locations\Domain\Models;

use App\Foundation\Database\ArchivableMasterModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Barangay extends ArchivableMasterModel
{
    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
