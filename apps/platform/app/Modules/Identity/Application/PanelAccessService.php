<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PanelAccessService
{
    public function canAccess(User $user, string $panelId): bool
    {
        return $this->tenantIdFor($user, $panelId) !== null;
    }

    public function tenantIdFor(User $user, string $panelId): ?string
    {
        $permission = match ($panelId) {
            'admin' => PermissionKey::PlatformPanelAccess,
            'operator' => PermissionKey::OperatorPanelAccess,
            default => null,
        };

        if ($permission === null || ! $user->isEnabled()) {
            return null;
        }

        $query = DB::table('memberships as memberships')
            ->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
            ->join('role_assignments as assignments', function ($join): void {
                $join->on('assignments.tenant_id', '=', 'memberships.tenant_id')
                    ->on('assignments.membership_id', '=', 'memberships.id');
            })
            ->join('role_permissions as role_permissions', function ($join): void {
                $join->on('role_permissions.tenant_id', '=', 'assignments.tenant_id')
                    ->on('role_permissions.role_id', '=', 'assignments.role_id');
            })
            ->where('memberships.user_id', $user->getKey())
            ->where('memberships.status', MembershipStatus::Active->value)
            ->where('tenants.status', TenantStatus::Active->value)
            ->where('assignments.scope_type', ScopeType::Tenant->value)
            ->where('role_permissions.permission_key', $permission->value)
            ->where(function (Builder $query): void {
                $query->whereNull('memberships.expires_at')->orWhere('memberships.expires_at', '>', now('UTC'));
            })
            ->where(function (Builder $query): void {
                $query->whereNull('assignments.expires_at')->orWhere('assignments.expires_at', '>', now('UTC'));
            });

        $organizationType = $panelId === 'admin'
            ? OrganizationType::Platform
            : OrganizationType::ChargePointOperator;
        $query->whereExists(function (Builder $organization) use ($organizationType): void {
            $organization->selectRaw('1')
                ->from('organizations')
                ->whereColumn('organizations.tenant_id', 'memberships.tenant_id')
                ->where('organizations.type', $organizationType->value)
                ->where('organizations.is_active', true);
        });

        $tenantId = $query->orderBy('memberships.tenant_id')->value('memberships.tenant_id');

        return is_string($tenantId) ? $tenantId : null;
    }
}
