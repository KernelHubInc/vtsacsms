<?php

declare(strict_types=1);

namespace App\Modules\Locations\Application;

use App\Models\User;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class AccessibleSitesQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuthorizationService $authorization,
    ) {}

    /** @return Builder<Site> */
    public function for(User $user): Builder
    {
        $query = Site::query();

        if ($this->authorization->allows($user, PermissionKey::LocationView)) {
            return $query;
        }

        $tenantId = $this->currentTenant->get()->tenantId;
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
            ->where('role_permissions.permission_key', PermissionKey::LocationView->value)
            ->where(function ($query): void {
                $query->whereNull('memberships.expires_at')->orWhere('memberships.expires_at', '>', now('UTC'));
            })
            ->where(function ($query): void {
                $query->whereNull('assignments.expires_at')->orWhere('assignments.expires_at', '>', now('UTC'));
            })
            ->select(['assignments.scope_type', 'assignments.scope_id']);

        return $query->where(function (Builder $sites) use ($assignments): void {
            $sites->whereIn('id', (clone $assignments)
                ->where('assignments.scope_type', ScopeType::Site->value)
                ->select('assignments.scope_id'))
                ->orWhereIn('operator_organization_id', (clone $assignments)
                    ->where('assignments.scope_type', ScopeType::Organization->value)
                    ->select('assignments.scope_id'))
                ->orWhereIn('site_host_organization_id', (clone $assignments)
                    ->where('assignments.scope_type', ScopeType::Organization->value)
                    ->select('assignments.scope_id'));
        });
    }
}
