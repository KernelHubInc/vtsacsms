<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Queries;

use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Support\Facades\DB;

final class AccountRequestTenant
{
    public function forUser(int $userId): ?string
    {
        $tenants = DB::table('memberships')
            ->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
            ->where('memberships.user_id', $userId)
            ->where('tenants.status', TenantStatus::Active->value)
            ->distinct()
            ->orderBy('memberships.tenant_id')
            ->pluck('memberships.tenant_id');

        $publicTenant = config('registration.tenant_id');
        $tenant = $tenants->containsStrict($publicTenant) ? $publicTenant : $tenants->first();

        return is_string($tenant) ? $tenant : null;
    }
}
