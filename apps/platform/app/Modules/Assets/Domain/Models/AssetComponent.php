<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Modules\Assets\Domain\AssetLifecycleStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetComponent extends TenantAssetModel
{
    protected function casts(): array
    {
        return ['lifecycle_status' => AssetLifecycleStatus::class];
    }

    /** @return BelongsTo<Connector, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    /** @return BelongsTo<AssetClass, $this> */
    public function assetClass(): BelongsTo
    {
        return $this->belongsTo(AssetClass::class);
    }
}
