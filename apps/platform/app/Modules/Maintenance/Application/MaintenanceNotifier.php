<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Models\User;
use App\Modules\Maintenance\Notifications\MaintenanceAlertNotification;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class MaintenanceNotifier
{
    public function __construct(private CurrentTenant $tenant) {}

    /** @param array<string, int|string|null> $context */
    public function user(
        string $userId,
        string $kind,
        string $subject,
        string $message,
        array $context,
    ): void {
        DB::afterCommit(static function () use ($userId, $kind, $subject, $message, $context): void {
            User::query()->whereKey($userId)->first()?->notify(
                new MaintenanceAlertNotification($kind, $subject, $message, $context),
            );
        });
    }

    /** @param array<string, int|string|null> $context */
    public function sitePermission(
        string $siteId,
        PermissionKey $permission,
        string $kind,
        string $subject,
        string $message,
        array $context,
    ): void {
        $tenantId = $this->tenant->get()->tenantId;
        $userIds = DB::table('memberships as membership')
            ->join('role_assignments as assignment', function ($join): void {
                $join->on('assignment.tenant_id', '=', 'membership.tenant_id')
                    ->on('assignment.membership_id', '=', 'membership.id');
            })
            ->join('role_permissions as permission', function ($join): void {
                $join->on('permission.tenant_id', '=', 'assignment.tenant_id')
                    ->on('permission.role_id', '=', 'assignment.role_id');
            })
            ->where('membership.tenant_id', $tenantId)
            ->where('membership.status', 'active')
            ->where('permission.permission_key', $permission->value)
            ->where(function ($query) use ($siteId): void {
                $query->where('assignment.scope_type', ScopeType::Tenant->value)
                    ->orWhere(function ($query) use ($siteId): void {
                        $query->where('assignment.scope_type', ScopeType::Site->value)
                            ->where('assignment.scope_id', $siteId);
                    });
            })
            ->where(fn ($query) => $query->whereNull('membership.expires_at')->orWhere('membership.expires_at', '>', now('UTC')))
            ->where(fn ($query) => $query->whereNull('assignment.expires_at')->orWhere('assignment.expires_at', '>', now('UTC')))
            ->distinct()
            ->pluck('membership.user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        DB::afterCommit(static function () use ($userIds, $kind, $subject, $message, $context): void {
            User::query()->whereIn('id', $userIds)->get()->each(
                static fn (User $user) => $user->notify(
                    new MaintenanceAlertNotification($kind, $subject, $message, $context),
                ),
            );
        });
    }
}
