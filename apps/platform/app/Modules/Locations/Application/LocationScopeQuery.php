<?php

declare(strict_types=1);

namespace App\Modules\Locations\Application;

use App\Modules\Locations\Domain\Models\Site;

final class LocationScopeQuery
{
    public function siteExists(string $siteId): bool
    {
        return Site::query()->whereKey($siteId)->exists();
    }
}
