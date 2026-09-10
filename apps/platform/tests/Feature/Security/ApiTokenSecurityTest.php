<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Foundation\Audit\Models\AuditEvent;
use App\Models\User;
use App\Modules\Identity\Application\ApiTokenService;
use App\Modules\Identity\Application\IssuedApiToken;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Tests\Support\TenantSecurityTestCase;

final class ApiTokenSecurityTest extends TenantSecurityTestCase
{
    public function test_token_is_bound_to_verified_tenant_and_current_rbac(): void
    {
        $user = $this->createUser();
        $tenantA = $this->createTenant('token-a');
        $tenantB = $this->createTenant('token-b');
        [$issued, $assignment] = $this->withinTenant(
            $tenantA,
            $user,
            function () use ($user, $tenantA): array {
                $membership = $this->createMembership($tenantA, $user);
                $role = $this->createRole($tenantA, 'api-user', [
                    PermissionKey::IdentityContextView,
                    PermissionKey::TokenIssue,
                    PermissionKey::TokenRevoke,
                ]);
                $assignment = $this->assignDirectly($tenantA, $membership, $role);
                $issued = app(ApiTokenService::class)->issue(
                    actor: $user,
                    subject: $user,
                    name: 'Test device',
                    abilities: [PermissionKey::IdentityContextView],
                    expiresAt: CarbonImmutable::now('UTC')->addHour(),
                    reason: 'Security test',
                );

                return [$issued, $assignment];
            },
        );

        $this->withToken($issued->plainTextToken)
            ->withHeader('X-Tenant-ID', $tenantA->getKey())
            ->getJson('/api/v1/identity/context')
            ->assertOk()
            ->assertJsonPath('data.subject_id', $user->public_id)
            ->assertJsonPath('data.tenant_id', $tenantA->getKey());

        $this->withToken($issued->plainTextToken)
            ->withHeader('X-Tenant-ID', $tenantB->getKey())
            ->getJson('/api/v1/identity/context')
            ->assertNotFound()
            ->assertJsonMissing(['tenant_id' => $tenantA->getKey()]);

        $this->withinTenant($tenantA, $user, fn () => $assignment->delete());

        $this->withToken($issued->plainTextToken)
            ->withHeader('X-Tenant-ID', $tenantA->getKey())
            ->getJson('/api/v1/identity/context')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenantA->getKey(),
            'action' => 'security.authorization.denied',
            'result' => 'denied',
        ]);
    }

    public function test_revoked_token_is_rejected_immediately(): void
    {
        [$user, $tenant, $issued] = $this->issueTokenFixture('revocation');

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v1/identity/context')
            ->assertOk();

        $this->withinTenant($tenant, $user, fn () => app(ApiTokenService::class)->revoke(
            actor: $user,
            token: $issued->token,
            reason: 'Device reported lost',
        ));

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v1/identity/context')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->assertSame(1, AuditEvent::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('action', 'identity.api_token.revoked')
            ->count());
    }

    public function test_subject_security_version_revokes_every_existing_token(): void
    {
        [$user, $tenant, $issued] = $this->issueTokenFixture('security-version');

        $revoked = $this->withinTenant(
            $tenant,
            $user,
            fn (): int => app(ApiTokenService::class)->revokeAllForSubject(
                actor: $user,
                subject: $user,
                reason: 'Credential recovery completed',
            ),
        );

        $this->assertSame(1, $revoked);
        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v1/identity/context')
            ->assertUnauthorized();
    }

    /**
     * @return array{0: User, 1: Tenant, 2: IssuedApiToken}
     */
    private function issueTokenFixture(string $slug): array
    {
        $user = $this->createUser();
        $tenant = $this->createTenant($slug);
        $issued = $this->withinTenant(
            $tenant,
            $user,
            function () use ($user, $tenant): IssuedApiToken {
                $membership = $this->createMembership($tenant, $user);
                $role = $this->createRole($tenant, 'token-manager', [
                    PermissionKey::IdentityContextView,
                    PermissionKey::TokenIssue,
                    PermissionKey::TokenRevoke,
                ]);
                $this->assignDirectly($tenant, $membership, $role);

                return app(ApiTokenService::class)->issue(
                    actor: $user,
                    subject: $user,
                    name: 'Security test client',
                    abilities: [PermissionKey::IdentityContextView],
                    expiresAt: CarbonImmutable::now('UTC')->addHour(),
                    reason: 'Security test',
                );
            },
        );

        return [$user, $tenant, $issued];
    }
}
