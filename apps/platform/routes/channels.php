<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

Broadcast::channel('tenants.{tenantId}.charging-sessions', function (User $user, string $tenantId): bool {
    if (! $user->isEnabled()) {
        return false;
    }

    return DB::table('memberships as memberships')
        ->join('role_assignments as assignments', function ($join): void {
            $join->on('assignments.tenant_id', '=', 'memberships.tenant_id')
                ->on('assignments.membership_id', '=', 'memberships.id');
        })
        ->join('role_permissions as permissions', function ($join): void {
            $join->on('permissions.tenant_id', '=', 'assignments.tenant_id')
                ->on('permissions.role_id', '=', 'assignments.role_id');
        })
        ->where('memberships.tenant_id', $tenantId)
        ->where('memberships.user_id', $user->getKey())
        ->where('memberships.status', MembershipStatus::Active->value)
        ->where('assignments.scope_type', ScopeType::Tenant->value)
        ->where('permissions.permission_key', PermissionKey::ChargingSessionView->value)
        ->where(fn ($query) => $query->whereNull('memberships.expires_at')->orWhere('memberships.expires_at', '>', now('UTC')))
        ->where(fn ($query) => $query->whereNull('assignments.expires_at')->orWhere('assignments.expires_at', '>', now('UTC')))
        ->exists();
});

Broadcast::channel('tenants.{tenantId}.charging-sessions.{sessionId}', function (User $user, string $tenantId, string $sessionId): bool {
    if (! $user->isEnabled()) {
        return false;
    }

    return DB::table('charging_sessions')
        ->join('memberships', 'memberships.tenant_id', '=', 'charging_sessions.tenant_id')
        ->join('role_assignments as assignments', function ($join): void {
            $join->on('assignments.tenant_id', '=', 'memberships.tenant_id')
                ->on('assignments.membership_id', '=', 'memberships.id');
        })
        ->join('role_permissions as permissions', function ($join): void {
            $join->on('permissions.tenant_id', '=', 'assignments.tenant_id')
                ->on('permissions.role_id', '=', 'assignments.role_id');
        })
        ->where('charging_sessions.id', $sessionId)
        ->where('charging_sessions.tenant_id', $tenantId)
        ->where('memberships.user_id', $user->getKey())
        ->where('memberships.status', MembershipStatus::Active->value)
        ->where('permissions.permission_key', PermissionKey::ChargingSessionView->value)
        ->where(function ($query): void {
            $query->where('assignments.scope_type', ScopeType::Tenant->value)
                ->orWhere(function ($query): void {
                    $query->where('assignments.scope_type', ScopeType::Site->value)
                        ->whereColumn('assignments.scope_id', 'charging_sessions.site_id');
                });
        })
        ->where(fn ($query) => $query->whereNull('memberships.expires_at')->orWhere('memberships.expires_at', '>', now('UTC')))
        ->where(fn ($query) => $query->whereNull('assignments.expires_at')->orWhere('assignments.expires_at', '>', now('UTC')))
        ->exists();
});
