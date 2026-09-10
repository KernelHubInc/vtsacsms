<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Application\RoleAssignmentService;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\TenantSecurityTestCase;

final class PermissionMatrixTest extends TenantSecurityTestCase
{
    public function test_organization_scope_does_not_expand_to_another_resource_or_tenant_scope(): void
    {
        $grantor = $this->createUser();
        $target = $this->createUser();
        $tenant = $this->createTenant('scope-test');

        $this->withinTenant($tenant, $grantor, function () use ($grantor, $target, $tenant): void {
            $grantorMembership = $this->createMembership($tenant, $grantor);
            $targetMembership = $this->createMembership($tenant, $target);
            $organizationA = Organization::query()->create(['name' => 'Site A', 'code' => 'A']);
            $organizationB = Organization::query()->create(['name' => 'Site B', 'code' => 'B']);
            $grantorRole = $this->createRole($tenant, 'grantor', [
                PermissionKey::RoleAssign,
                PermissionKey::OrganizationView,
            ]);
            $viewerRole = $this->createRole($tenant, 'organization-viewer', [
                PermissionKey::OrganizationView,
            ]);
            $this->assignDirectly($tenant, $grantorMembership, $grantorRole);

            app(RoleAssignmentService::class)->assign(
                actor: $grantor,
                membership: $targetMembership,
                role: $viewerRole,
                scope: new ResourceScope(
                    type: ScopeType::Organization,
                    id: (string) $organizationA->getKey(),
                ),
                reason: 'Scoped operational access',
            );

            $authorization = app(AuthorizationService::class);
            $this->assertTrue($authorization->allows(
                $target,
                PermissionKey::OrganizationView,
                new ResourceScope(
                    ScopeType::Organization,
                    (string) $organizationA->getKey(),
                ),
            ));
            $this->assertFalse($authorization->allows(
                $target,
                PermissionKey::OrganizationView,
                new ResourceScope(
                    ScopeType::Organization,
                    (string) $organizationB->getKey(),
                ),
            ));
            $this->assertFalse($authorization->allows($target, PermissionKey::OrganizationView));
        });
    }

    public function test_grantor_cannot_delegate_a_permission_they_do_not_hold(): void
    {
        $grantor = $this->createUser();
        $target = $this->createUser();
        $tenant = $this->createTenant('delegation-test');

        $this->withinTenant($tenant, $grantor, function () use ($grantor, $target, $tenant): void {
            $grantorMembership = $this->createMembership($tenant, $grantor);
            $targetMembership = $this->createMembership($tenant, $target);
            $grantorRole = $this->createRole($tenant, 'limited-grantor', [PermissionKey::RoleAssign]);
            $financeRole = $this->createRole($tenant, 'refund-operator', [PermissionKey::PaymentRefund]);
            $this->assignDirectly($tenant, $grantorMembership, $grantorRole);

            $this->expectException(AuthorizationException::class);
            app(RoleAssignmentService::class)->assign(
                actor: $grantor,
                membership: $targetMembership,
                role: $financeRole,
                scope: ResourceScope::tenant(),
                reason: 'Attempted privilege escalation',
            );
        });
    }

    public function test_expired_assignment_and_suspended_membership_are_denied(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('lifecycle-test');

        $this->withinTenant($tenant, $user, function () use ($user, $tenant): void {
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'viewer', [PermissionKey::OrganizationView]);
            $assignment = $this->assignDirectly($tenant, $membership, $role);
            $assignment->forceFill([
                'expires_at' => CarbonImmutable::now('UTC')->subSecond(),
            ])->save();

            $authorization = app(AuthorizationService::class);
            $this->assertFalse($authorization->allows($user, PermissionKey::OrganizationView));

            $assignment->forceFill(['expires_at' => null])->save();
            $membership->forceFill(['status' => MembershipStatus::Suspended])->save();
            $this->assertFalse($authorization->allows($user, PermissionKey::OrganizationView));
        });
    }
}
