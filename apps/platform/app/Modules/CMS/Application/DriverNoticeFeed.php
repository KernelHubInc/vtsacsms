<?php

declare(strict_types=1);

namespace App\Modules\CMS\Application;

use App\Modules\CMS\Domain\Models\DriverNotice;
use Illuminate\Database\Eloquent\Collection;

final class DriverNoticeFeed
{
    /** @return Collection<int, DriverNotice> */
    public function forTenant(string $tenantId): Collection
    {
        return DriverNotice::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->orderByDesc('starts_at')
            ->limit(30)
            ->get(['id', 'category', 'title', 'message', 'show_on_open', 'starts_at', 'ends_at']);
    }
}
