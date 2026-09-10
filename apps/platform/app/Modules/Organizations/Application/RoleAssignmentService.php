<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\Models\RoleAssignment;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\Exceptions\TenantMismatch;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class RoleAssignmentService
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuthorizationService $authorization,
        private AuditRecorder $audit,
    ) {}

    public function assign(
        User $actor,
        Membership $membership,
        Role $role,
        ResourceScope $scope,
        ?CarbonImmutable $expiresAt = null,
        ?string $reason = null,
    ): RoleAssignment {
        $tenantId = $this->currentTenant->get()->tenantId;
        $this->assertSameTenant($tenantId, $membership, $role);

        if (! $this->authorization->allows($actor, PermissionKey::RoleAssign, $scope)) {
            throw new AuthorizationException('Role assignment is not permitted.');
        }

        $rolePermissions = DB::table('role_permissions')
            ->where('tenant_id', $tenantId)
            ->where('role_id', $role->getKey())
            ->pluck('permission_key');

        foreach ($rolePermissions as $permissionValue) {
            $permission = PermissionKey::tryFrom((string) $permissionValue);

            if ($permission === null || ! $this->authorization->allows($actor, $permission, $scope)) {
                throw new AuthorizationException('A grantor cannot delegate authority they do not hold.');
            }
        }

        if ($scope->type === ScopeType::Organization) {
            Organization::query()->whereKey($scope->id)->firstOrFail();
        }

        if ($scope->type === ScopeType::Site) {
            Site::query()->whereKey($scope->id)->firstOrFail();
        }

        return DB::transaction(function () use (
            $actor,
            $membership,
            $role,
            $scope,
            $expiresAt,
            $reason,
            $tenantId,
        ): RoleAssignment {
            $assignment = RoleAssignment::query()->create([
                'tenant_id' => $tenantId,
                'membership_id' => $membership->getKey(),
                'role_id' => $role->getKey(),
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
                'granted_by_user_id' => $actor->getKey(),
                'expires_at' => $expiresAt,
            ]);

            $this->audit->record(new AuditEntry(
                action: 'identity.role_assignment.created',
                targetType: 'role_assignment',
                targetId: (string) $assignment->getKey(),
                result: AuditResult::Succeeded,
                reason: $reason,
                changes: [
                    'membership_id' => (string) $membership->getKey(),
                    'role_id' => (string) $role->getKey(),
                    'scope_type' => $scope->type->value,
                    'scope_id' => $scope->id,
                    'expires_at' => $expiresAt?->utc()->toIso8601String(),
                ],
            ));

            return $assignment;
        });
    }

    public function revoke(User $actor, RoleAssignment $assignment, string $reason): void
    {
        $scope = new ResourceScope($assignment->scope_type, $assignment->scope_id);

        if (! $this->authorization->allows($actor, PermissionKey::RoleAssign, $scope)) {
            throw new AuthorizationException('Role assignment revocation is not permitted.');
        }

        DB::transaction(function () use ($assignment, $reason): void {
            $assignmentId = (string) $assignment->getKey();
            $assignment->delete();
            $this->audit->record(new AuditEntry(
                action: 'identity.role_assignment.revoked',
                targetType: 'role_assignment',
                targetId: $assignmentId,
                result: AuditResult::Succeeded,
                reason: $reason,
            ));
        });
    }

    private function assertSameTenant(string $tenantId, Membership $membership, Role $role): void
    {
        if (
            ! hash_equals($tenantId, $membership->tenant_id)
            || ! hash_equals($tenantId, $role->tenant_id)
        ) {
            throw new TenantMismatch;
        }
    }
}
