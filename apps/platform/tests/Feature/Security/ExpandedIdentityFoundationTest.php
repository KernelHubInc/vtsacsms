<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Foundation\Audit\Models\AuditEvent;
use App\Models\User;
use App\Modules\Identity\Application\InvitationService;
use App\Modules\Identity\Application\PanelAccessService;
use App\Modules\Identity\Domain\Models\MobileAccessToken;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\Support\TenantSecurityTestCase;

final class ExpandedIdentityFoundationTest extends TenantSecurityTestCase
{
    public function test_revoked_sanctum_token_stops_working_immediately(): void
    {
        [$user, $tenant] = $this->tenantUserWithPermissions('revocation', [
            PermissionKey::IdentityContextView,
            PermissionKey::SessionView,
            PermissionKey::SessionRevoke,
        ]);
        $token = $this->login($user, $tenant);

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $deviceId = MobileAccessToken::query()->sole()->device_id;
        $this->withToken($token)->deleteJson('/api/v1/devices/'.$deviceId)->assertNoContent();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_invitation_activates_and_verifies_a_new_account(): void
    {
        Notification::fake();
        $actor = $this->createUser();
        $tenant = $this->createTenant('invitations');

        $issued = $this->withinTenant($tenant, $actor, function () use ($tenant, $actor) {
            $organization = $this->createOrganization($tenant, OrganizationType::ChargePointOperator);
            $membership = $this->createMembership($tenant, $actor);
            $role = $this->createRole($tenant, 'invitation-manager', [
                PermissionKey::InvitationManage,
            ]);
            $this->assignDirectly($tenant, $membership, $role);

            return app(InvitationService::class)->invite(
                $actor,
                'new.operator@example.test',
                $organization,
                $role,
                ResourceScope::tenant(),
            );
        });

        $accepted = app(InvitationService::class)->accept(
            $issued->plainTextToken,
            'New Operator',
            'A-strong-password-123!',
            '01K0M0KK6Y0M0KK6Y0M0KK6Y0M',
        );

        $this->assertTrue($accepted['user']->isEnabled());
        $this->assertTrue($accepted['user']->hasVerifiedEmail());
        $this->assertDatabaseHas('memberships', [
            'tenant_id' => $tenant->getKey(),
            'user_id' => $accepted['user']->getKey(),
            'status' => 'active',
        ]);
    }

    public function test_password_reset_revokes_existing_mobile_tokens(): void
    {
        [$user, $tenant] = $this->tenantUserWithPermissions('password-reset', [
            PermissionKey::IdentityContextView,
        ]);
        $token = $this->login($user, $tenant);
        $resetToken = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'A-new-secure-password-123!',
            'password_confirmation' => 'A-new-secure-password-123!',
        ])->assertOk();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'A-new-secure-password-123!',
            'tenant_id' => $tenant->getKey(),
            'device_name' => 'Replacement device',
        ])->assertOk();
    }

    public function test_signed_email_verification_uses_public_ulid_and_is_audited(): void
    {
        Notification::fake();
        [$user, $tenant] = $this->tenantUserWithPermissions('email-verification', [
            PermissionKey::IdentityContextView,
        ]);
        $user->forceFill(['email_verified_at' => null])->save();
        $token = $this->login($user, $tenant);

        $this->withToken($token)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertAccepted();
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $url = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now('UTC')->addMinutes(10),
            ['user' => $user->public_id, 'hash' => sha1($user->email)],
        );
        $this->withToken($token)->getJson($url)->assertOk();

        $this->assertNotNull($user->fresh()?->email_verified_at);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(),
            'action' => 'identity.email.verified',
            'target_id' => $user->public_id,
        ]);
    }

    public function test_membership_suspension_revokes_that_tenants_tokens_and_is_audited(): void
    {
        $actor = $this->createUser();
        $subject = $this->createUser();
        $tenant = $this->createTenant('membership-suspension');

        $subjectMembership = $this->withinTenant($tenant, $actor, function () use (
            $tenant,
            $actor,
            $subject,
        ): Membership {
            $this->createOrganization($tenant, OrganizationType::ChargePointOperator);
            $actorMembership = $this->createMembership($tenant, $actor);
            $subjectMembership = $this->createMembership($tenant, $subject);
            $manager = $this->createRole($tenant, 'membership-manager', [
                PermissionKey::MembershipManage,
            ]);
            $viewer = $this->createRole($tenant, 'membership-viewer', [
                PermissionKey::IdentityContextView,
            ]);
            $this->assignDirectly($tenant, $actorMembership, $manager);
            $this->assignDirectly($tenant, $subjectMembership, $viewer);

            return $subjectMembership;
        });
        $actorToken = $this->login($actor, $tenant);
        $subjectToken = $this->login($subject, $tenant);

        $this->withToken($actorToken)->postJson(
            '/api/v1/memberships/'.$subjectMembership->getKey().'/suspend',
            ['reason' => 'security review'],
        )->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->withToken($subjectToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(),
            'action' => 'identity.membership.suspended',
            'target_id' => $subjectMembership->getKey(),
        ]);
    }

    public function test_mobile_verification_audit_contains_full_request_evidence(): void
    {
        [$user, $tenant] = $this->tenantUserWithPermissions('mobile-audit', [
            PermissionKey::IdentityContextView,
        ]);
        $token = $this->login($user, $tenant);
        $headers = [
            'User-Agent' => 'VTSA-Mobile-Test/1.0',
            'X-Correlation-ID' => '01K0M0KK6Y0M0KK6Y0M0KK6Y0M',
        ];
        $start = $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/v1/auth/mobile/verification', ['mobile_number' => '+639171234567'])
            ->assertAccepted();
        $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/v1/auth/mobile/verify', [
                'challenge_id' => $start->json('data.challenge_id'),
                'code' => '000000',
            ])->assertOk();

        $event = AuditEvent::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('action', 'identity.mobile_number.verified')
            ->sole();
        $this->assertSame($user->public_id, $event->actor_id);
        $this->assertSame($tenant->getKey(), $event->tenant_id);
        $this->assertSame('127.0.0.1', $event->source_ip);
        $this->assertSame('VTSA-Mobile-Test/1.0', $event->user_agent);
        $this->assertSame('identity', $event->target_type);
        $this->assertSame($user->public_id, $event->target_id);
        $this->assertSame(['mobile_verified_at' => null], $event->before);
        $this->assertNotNull($event->after['mobile_verified_at']);
        $this->assertSame('01K0M0KK6Y0M0KK6Y0M0KK6Y0M', $event->correlation_id);
    }

    public function test_login_is_rate_limited_by_identity_and_ip(): void
    {
        $tenant = $this->createTenant('brute-force');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unknown@example.test',
                'password' => 'wrong-password',
                'tenant_id' => $tenant->getKey(),
                'device_name' => 'Unknown device',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
            'tenant_id' => $tenant->getKey(),
            'device_name' => 'Unknown device',
        ])->assertTooManyRequests();
    }

    public function test_platform_and_operator_panels_require_separate_permissions(): void
    {
        $platformUser = $this->createUser();
        $operatorUser = $this->createUser();
        $platformTenant = $this->createTenant('platform-panel');
        $operatorTenant = $this->createTenant('operator-panel');

        $this->withinTenant($platformTenant, $platformUser, function () use ($platformTenant, $platformUser): void {
            $this->createOrganization($platformTenant, OrganizationType::Platform);
            $membership = $this->createMembership($platformTenant, $platformUser);
            $role = $this->createRole($platformTenant, 'platform-admin', [PermissionKey::PlatformPanelAccess]);
            $this->assignDirectly($platformTenant, $membership, $role);
        });
        $this->withinTenant($operatorTenant, $operatorUser, function () use ($operatorTenant, $operatorUser): void {
            $this->createOrganization($operatorTenant, OrganizationType::ChargePointOperator);
            $membership = $this->createMembership($operatorTenant, $operatorUser);
            $role = $this->createRole($operatorTenant, 'operator-admin', [PermissionKey::OperatorPanelAccess]);
            $this->assignDirectly($operatorTenant, $membership, $role);
        });

        $access = app(PanelAccessService::class);
        $this->assertTrue($access->canAccess($platformUser, 'admin'));
        $this->assertFalse($access->canAccess($platformUser, 'operator'));
        $this->assertTrue($access->canAccess($operatorUser, 'operator'));
        $this->assertFalse($access->canAccess($operatorUser, 'admin'));
        $this->assertSame('admin', Filament::getPanel('admin')->getId());
        $this->assertSame('operator', Filament::getPanel('operator')->getId());
        $this->get('/operator/login')->assertOk()->assertSee('Sign in');
    }

    public function test_tenant_owned_factories_inherit_the_verified_context(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('factory-context');

        [$organization, $site] = $this->withinTenant($tenant, $actor, static function (): array {
            $organization = Organization::factory()->create();
            $site = Site::factory()->create();

            return [$organization, $site];
        });

        $this->assertSame($tenant->getKey(), $organization->tenant_id);
        $this->assertSame($tenant->getKey(), $site->tenant_id);
        $this->assertDatabaseHas('organizations', [
            'tenant_id' => $tenant->getKey(),
            'id' => $site->operator_organization_id,
        ]);
    }

    /**
     * @param  list<PermissionKey>  $permissions
     * @return array{0: User, 1: Tenant}
     */
    private function tenantUserWithPermissions(string $slug, array $permissions): array
    {
        $user = $this->createUser();
        $tenant = $this->createTenant($slug);
        $this->withinTenant($tenant, $user, function () use ($tenant, $user, $permissions, $slug): void {
            $this->createOrganization($tenant, OrganizationType::ChargePointOperator);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, $slug.'-role', $permissions);
            $this->assignDirectly($tenant, $membership, $role);
        });

        return [$user, $tenant];
    }

    private function createOrganization(Tenant $tenant, OrganizationType $type): Organization
    {
        return Organization::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => $type->name.' Organization',
            'code' => strtoupper(substr($type->value, 0, 10)).'-'.substr((string) $tenant->getKey(), -4),
            'type' => $type,
            'is_active' => true,
        ]);
    }

    private function login(User $user, Tenant $tenant): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->getKey(),
            'device_name' => 'Acceptance test device',
        ])->assertOk();

        return (string) $response->json('data.token');
    }
}
