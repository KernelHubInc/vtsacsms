<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;

final class GatewayTenantQuery
{
    public function isActive(string $tenantId): bool
    {
        return Tenant::query()->whereKey($tenantId)->where('status', TenantStatus::Active)->exists();
    }
}
