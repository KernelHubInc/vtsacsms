<?php

declare(strict_types=1);

namespace App\Modules\Locations\Application;

use App\Modules\Locations\Domain\Models\Site;

final class LocationScopeQuery
{
    /** @return array<string, string> */
    public function pricingOptions(): array
    {
        return Site::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function siteExists(string $siteId): bool
    {
        return Site::query()->whereKey($siteId)->exists();
    }
}
