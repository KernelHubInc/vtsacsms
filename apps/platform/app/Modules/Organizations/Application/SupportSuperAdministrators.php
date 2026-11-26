<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class SupportSuperAdministrators
{
    public function __construct(private CurrentTenant $tenant) {}

    /** @return list<string> */
    public function ids(): array
    {
        return array_values($this->eligible()->distinct()->pluck('users.public_id')->map(static fn ($id): string => (string) $id)->all());
    }

    public function includes(string $publicId): bool
    {
        return $this->eligible()->where('users.public_id', $publicId)->exists();
    }

    private function eligible(): Builder
    {
        $tenant = $this->tenant->get()->tenantId;
        $query = DB::table('memberships as memberships')
            ->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->join('role_assignments as assignments', function ($join): void {
                $join->on('assignments.tenant_id', '=', 'memberships.tenant_id')->on('assignments.membership_id', '=', 'memberships.id');
            })
            ->join('roles', function ($join): void {
                $join->on('roles.tenant_id', '=', 'assignments.tenant_id')->on('roles.id', '=', 'assignments.role_id');
            })
            ->where('memberships.tenant_id', $tenant)->where('tenants.status', 'active')
            ->where('memberships.status', 'active')->where('assignments.scope_type', 'tenant')
            ->whereIn('roles.key', ['platform-super-admin', 'production-super-administrator'])
            ->whereNotNull('users.public_id')->whereNotNull('users.email_verified_at')
            ->whereNotNull('users.activated_at')->whereNull('users.disabled_at')
            ->where(fn (Builder $q) => $q->whereNull('memberships.expires_at')->orWhere('memberships.expires_at', '>', now('UTC')))
            ->where(fn (Builder $q) => $q->whereNull('assignments.expires_at')->orWhere('assignments.expires_at', '>', now('UTC')));
        foreach ([PermissionKey::PlatformPanelAccess, PermissionKey::SupportView, PermissionKey::SupportManage] as $permission) {
            $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('role_permissions as grants')
                ->whereColumn('grants.tenant_id', 'assignments.tenant_id')->whereColumn('grants.role_id', 'assignments.role_id')
                ->where('grants.permission_key', $permission->value));
        }

        return $query;
    }
}
