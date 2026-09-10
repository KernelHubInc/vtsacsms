<?php

declare(strict_types=1);

namespace App\Modules\Assets\Application;

use App\Models\User;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class AccessibleStationsQuery
{
    public function __construct(private CurrentTenant $tenant, private AuthorizationService $authorization) {}

    /** @return Builder<ChargingStation> */
    public function for(User $user, PermissionKey $permission = PermissionKey::AssetView): Builder
    {
        $query = ChargingStation::query();
        if ($this->authorization->allows($user, $permission)) {
            return $query;
        }

        $siteIds = DB::table('memberships as membership')
            ->join('role_assignments as assignment', fn ($join) => $join->on('assignment.tenant_id', '=', 'membership.tenant_id')->on('assignment.membership_id', '=', 'membership.id'))
            ->join('role_permissions as permission', fn ($join) => $join->on('permission.tenant_id', '=', 'assignment.tenant_id')->on('permission.role_id', '=', 'assignment.role_id'))
            ->where('membership.tenant_id', $this->tenant->get()->tenantId)
            ->where('membership.user_id', $user->getKey())
            ->where('membership.status', 'active')
            ->where('assignment.scope_type', ScopeType::Site->value)
            ->where('permission.permission_key', $permission->value)
            ->select('assignment.scope_id');

        return $query->whereIn('site_id', $siteIds);
    }
}
