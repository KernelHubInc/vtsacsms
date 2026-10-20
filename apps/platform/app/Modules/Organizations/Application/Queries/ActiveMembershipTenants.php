<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application\Queries;

use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Support\Facades\DB;

final class ActiveMembershipTenants
{
    /** @return list<string> */
    public function forUser(int $userId): array
    {
        $tenantIds = DB::table('memberships')
            ->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
            ->where('memberships.user_id', $userId)
            ->where('memberships.status', MembershipStatus::Active->value)
            ->where('tenants.status', TenantStatus::Active->value)
            ->where(fn ($query) => $query->whereNull('memberships.expires_at')
                ->orWhere('memberships.expires_at', '>', now('UTC')))
            ->distinct()->orderBy('memberships.tenant_id')->pluck('memberships.tenant_id')
            ->map(static fn (mixed $tenantId): string => (string) $tenantId)->all();

        return array_values($tenantIds);
    }
}
