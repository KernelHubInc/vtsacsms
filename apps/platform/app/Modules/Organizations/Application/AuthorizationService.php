<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class AuthorizationService
{
    public function __construct(private CurrentTenant $currentTenant) {}

    public function allows(
        User $user,
        PermissionKey $permission,
        ?ResourceScope $resourceScope = null,
    ): bool {
        $query = $this->permissionQuery($user, $permission);

        if ($query === null) {
            return false;
        }

        $this->applyResourceScope($query, $resourceScope);

        return $query->exists();
    }

    public function holdsAnywhere(User $user, PermissionKey $permission): bool
    {
        return $this->permissionQuery($user, $permission)?->exists() ?? false;
    }

    private function permissionQuery(User $user, PermissionKey $permission): ?Builder
    {
        if (! $user->isEnabled()) {
            return null;
        }

        $tenantId = $this->currentTenant->get()->tenantId;

        if (! Tenant::query()
            ->whereKey($tenantId)
            ->where('status', TenantStatus::Active->value)
            ->exists()) {
            return null;
        }

        return DB::table('memberships as memberships')
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
            ->where('memberships.status', MembershipStatus::Active->value)
            ->where(function (Builder $query): void {
                $query->whereNull('memberships.expires_at')
                    ->orWhere('memberships.expires_at', '>', now('UTC'));
            })
            ->where(function (Builder $query): void {
                $query->whereNull('assignments.expires_at')
                    ->orWhere('assignments.expires_at', '>', now('UTC'));
            })
            ->where('role_permissions.permission_key', $permission->value);
    }

    /**
     * @return list<string>
     */
    public function effectivePermissions(User $user, ?ResourceScope $resourceScope = null): array
    {
        return array_values(array_map(
            fn (PermissionKey $permission): string => $permission->value,
            array_filter(
                PermissionKey::cases(),
                fn (PermissionKey $permission): bool => $this->allows($user, $permission, $resourceScope),
            ),
        ));
    }

    private function applyResourceScope(Builder $query, ?ResourceScope $resourceScope): void
    {
        if ($resourceScope === null || $resourceScope->type === ScopeType::Tenant) {
            $query->where('assignments.scope_type', ScopeType::Tenant->value);

            return;
        }

        $query->where(function (Builder $query) use ($resourceScope): void {
            $query->where('assignments.scope_type', ScopeType::Tenant->value)
                ->orWhere(function (Builder $query) use ($resourceScope): void {
                    $query->where('assignments.scope_type', $resourceScope->type->value)
                        ->where('assignments.scope_id', $resourceScope->id);
                });
        });
    }
}
