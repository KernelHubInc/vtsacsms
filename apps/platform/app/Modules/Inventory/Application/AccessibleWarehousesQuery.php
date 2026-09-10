<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\User;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class AccessibleWarehousesQuery
{
    public function __construct(
        private CurrentTenant $tenant,
        private AuthorizationService $authorization,
    ) {}

    /** @return Builder<Warehouse> */
    public function for(User $user, PermissionKey $permission = PermissionKey::InventoryView): Builder
    {
        $query = Warehouse::query();

        if ($this->authorization->allows($user, $permission)) {
            return $query;
        }

        $tenantId = $this->tenant->get()->tenantId;
        $assignments = DB::table('memberships as memberships')
            ->join('role_assignments as assignments', function ($join): void {
                $join->on('assignments.tenant_id', '=', 'memberships.tenant_id')
                    ->on('assignments.membership_id', '=', 'memberships.id');
            })
            ->join('role_permissions as role_permissions', function ($join): void {
                $join->on('role_permissions.tenant_id', '=', 'assignments.tenant_id')
                    ->on('role_permissions.role_id', '=', 'assignments.role_id');
            })
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.user_id', $user->getKey())
            ->where('memberships.status', 'active')
            ->where('role_permissions.permission_key', $permission->value)
            ->whereIn('assignments.scope_type', [ScopeType::Warehouse->value, ScopeType::Site->value])
            ->where(function ($query): void {
                $query->whereNull('memberships.expires_at')->orWhere('memberships.expires_at', '>', now('UTC'));
            })
            ->where(function ($query): void {
                $query->whereNull('assignments.expires_at')->orWhere('assignments.expires_at', '>', now('UTC'));
            })
            ->select(['assignments.scope_type', 'assignments.scope_id']);

        return $query->where(function (Builder $query) use ($assignments): void {
            $query->whereIn('id', (clone $assignments)->where('assignments.scope_type', ScopeType::Warehouse->value)
                ->select('assignments.scope_id'))
                ->orWhereIn('site_id', (clone $assignments)->where('assignments.scope_type', ScopeType::Site->value)
                    ->select('assignments.scope_id'));
        });
    }
}
