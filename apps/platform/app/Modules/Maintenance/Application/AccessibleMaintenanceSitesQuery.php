<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class AccessibleMaintenanceSitesQuery
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    public function for(User $user, PermissionKey $permission = PermissionKey::MaintenanceView): Builder
    {
        $query = DB::table('sites')->where('tenant_id', $this->tenant->get()->tenantId);
        if ($this->authorization->allows($user, $permission)) {
            return $query;
        }

        $assignments = DB::table('memberships as membership')
            ->join('role_assignments as assignment', function ($join): void {
                $join->on('assignment.tenant_id', '=', 'membership.tenant_id')
                    ->on('assignment.membership_id', '=', 'membership.id');
            })
            ->join('role_permissions as permission', function ($join): void {
                $join->on('permission.tenant_id', '=', 'assignment.tenant_id')
                    ->on('permission.role_id', '=', 'assignment.role_id');
            })
            ->where('membership.tenant_id', $this->tenant->get()->tenantId)
            ->where('membership.user_id', $user->getKey())
            ->where('membership.status', 'active')
            ->where('permission.permission_key', $permission->value)
            ->where(fn ($query) => $query->whereNull('membership.expires_at')->orWhere('membership.expires_at', '>', now('UTC')))
            ->where(fn ($query) => $query->whereNull('assignment.expires_at')->orWhere('assignment.expires_at', '>', now('UTC')))
            ->select(['assignment.scope_type', 'assignment.scope_id']);

        return $query->where(function (Builder $sites) use ($assignments): void {
            $sites->whereIn('id', (clone $assignments)
                ->where('assignment.scope_type', ScopeType::Site->value)
                ->select('assignment.scope_id'))
                ->orWhereIn('operator_organization_id', (clone $assignments)
                    ->where('assignment.scope_type', ScopeType::Organization->value)
                    ->select('assignment.scope_id'))
                ->orWhereIn('site_host_organization_id', (clone $assignments)
                    ->where('assignment.scope_type', ScopeType::Organization->value)
                    ->select('assignment.scope_id'));
        });
    }
}
