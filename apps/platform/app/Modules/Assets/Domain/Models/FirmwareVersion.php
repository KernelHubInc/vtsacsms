<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Foundation\Database\ArchivableMasterModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FirmwareVersion extends ArchivableMasterModel
{
    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ChargerModel, $this> */
    public function chargerModel(): BelongsTo
    {
        return $this->belongsTo(ChargerModel::class);
    }
}
