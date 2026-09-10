<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Models\User;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class AccessibleChargingSessionsQuery
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    /** @return Builder<ChargingSession> */
    public function for(User $user, PermissionKey $permission = PermissionKey::ChargingSessionView): Builder
    {
        $query = ChargingSession::query();
        if (! $user->isEnabled()) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->authorization->allows($user, $permission)) {
            return $query;
        }

        $siteIds = DB::table('memberships as membership')
            ->join('role_assignments as assignment', fn ($join) => $join
                ->on('assignment.tenant_id', '=', 'membership.tenant_id')
                ->on('assignment.membership_id', '=', 'membership.id'))
            ->join('role_permissions as permission', fn ($join) => $join
                ->on('permission.tenant_id', '=', 'assignment.tenant_id')
                ->on('permission.role_id', '=', 'assignment.role_id'))
            ->where('membership.tenant_id', $this->tenant->get()->tenantId)
            ->where('membership.user_id', $user->getKey())
            ->where('membership.status', 'active')
            ->where('assignment.scope_type', ScopeType::Site->value)
            ->where('permission.permission_key', $permission->value)
            ->where(fn ($query) => $query->whereNull('membership.expires_at')->orWhere('membership.expires_at', '>', now('UTC')))
            ->where(fn ($query) => $query->whereNull('assignment.expires_at')->orWhere('assignment.expires_at', '>', now('UTC')))
            ->select('assignment.scope_id');

        return $query->whereIn('site_id', $siteIds);
    }
}
