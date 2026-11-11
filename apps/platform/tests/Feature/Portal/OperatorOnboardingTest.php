<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Platform\Pages\OnboardOperator;
use App\Models\User;
use App\Modules\Assets\Application\AccessibleStationsQuery;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Identity\Application\InvitationService;
use App\Modules\Identity\Application\PanelAccessService;
use App\Modules\Identity\Domain\Models\UserInvitation;
use App\Modules\Identity\Notifications\UserInvitationNotification;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Application\AccessibleMembershipsQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Application\OperatorOnboarding;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class OperatorOnboardingTest extends TenantSecurityTestCase
{
    private User $admin;

    private Tenant $tenant;

    private string $role;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = $this->createUser();
        $this->tenant = $this->createTenant('onboard');
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            Organization::factory()->create(['type' => OrganizationType::Platform, 'is_active' => true]);
            $membership = $this->createMembership($this->tenant, $this->admin);
            $this->assignDirectly($this->tenant, $membership, $this->createRole($this->tenant, 'platform-admin', PermissionKey::cases()));
            $this->role = (string) $this->createRole($this->tenant, 'operator-manager', [PermissionKey::OperatorPanelAccess, PermissionKey::LocationView, PermissionKey::AssetView])->getKey();
        });
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['mode' => 'new', 'name' => 'North Coast Charging', 'code' => 'north-coast', 'organization_id' => null, 'email' => 'operator@example.test', 'role_id' => $this->role, 'scope' => 'organization', 'site_id' => null];
    }

    public function test_admin_sidebar_places_onboard_operator_immediately_after_dashboard(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $navigation = Filament::getNavigation();
            $firstGroup = collect($navigation)->first();

            self::assertNotNull($firstGroup);
            self::assertSame(['Dashboard', 'Onboard operator'], collect($firstGroup->getItems())->map->getLabel()->take(2)->all());
        });
    }

    public function test_onboarding_is_atomic_idempotent_scoped_and_audited(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $id = (string) Str::ulid();
            $service = app(OperatorOnboarding::class);
            $first = $service->create($this->admin, $id, $this->data());
            $this->assertSame($first, $service->create($this->admin, $id, $this->data()));
            $this->assertSame(1, UserInvitation::query()->count());
            $this->assertDatabaseHas('organizations', ['id' => $first['organization_id'], 'code' => 'NORTH-COAST']);
            $this->assertDatabaseHas('user_invitations', ['id' => $first['invitation_id'], 'scope_type' => 'organization', 'scope_id' => $first['organization_id']]);
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenant->getKey(), 'action' => 'organizations.operator.onboarded']);
            Notification::assertSentOnDemandTimes(UserInvitationNotification::class, 1);
            Notification::assertSentOnDemand(UserInvitationNotification::class, fn (UserInvitationNotification $notification): bool => $notification->queue === 'invitations' && $notification->afterCommit === true);
        });
    }

    public function test_invalid_site_rolls_back_the_organization_and_invitation(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            try {
                app(OperatorOnboarding::class)->create($this->admin, (string) Str::ulid(), array_replace($this->data(), ['scope' => 'site', 'site_id' => (string) Str::ulid()]));
                $this->fail('Invalid site was accepted');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('site_id', $error->errors());
            }
            $this->assertFalse(Organization::query()->where('code', 'NORTH-COAST')->exists());
            $this->assertSame(0, UserInvitation::query()->count());
            Notification::assertNothingSent();
        });
    }

    public function test_wizard_validates_steps_and_completes_once(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $component = Livewire::test(OnboardOperator::class)->call('next')->assertHasErrors(['name', 'code'])->assertSet('step', 1);
            foreach ($this->data() as $key => $value) {
                $component->set($key, $value);
            }
            $component->call('next')->assertSet('step', 2)->call('next')->assertSet('step', 3)->call('next')->assertSet('step', 4)->assertSee('Review before inviting')->call('finish')->assertHasNoErrors()->assertSee('Operator ready for invitation acceptance')->call('finish');
            $this->assertSame(1, UserInvitation::query()->count());
        });
    }

    public function test_accepted_operator_can_sign_in_only_to_its_own_network_and_replay_fails(): void
    {
        $issued = $this->withinTenant($this->tenant, $this->admin, function () {
            $organization = Organization::factory()->create(['type' => OrganizationType::ChargePointOperator, 'is_active' => true]);
            Site::factory()->create(['operator_organization_id' => $organization->getKey(), 'name' => 'Own operator site']);
            Site::factory()->create(['name' => 'Other operator site']);

            return app(InvitationService::class)->invite($this->admin, 'new@example.test', $organization, Role::query()->findOrFail($this->role), new ResourceScope(ScopeType::Organization, (string) $organization->getKey()));
        });
        $this->get('/accept-invitation?token='.$issued->plainTextToken)->assertOk()->assertSee('Welcome to your operator workspace');
        $this->post('/accept-invitation', ['token' => $issued->plainTextToken, 'name' => 'New Operator', 'password' => 'ValidPassword1!', 'password_confirmation' => 'ValidPassword1!'])->assertOk()->assertSee('Your invitation is accepted');
        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(app(PanelAccessService::class)->canAccess($user, 'operator'));
        $this->assertFalse(app(PanelAccessService::class)->canAccess($user, 'admin'));
        $this->withinTenant($this->tenant, $user, function () use ($user): void {
            $this->assertSame(['Own operator site'], app(AccessibleSitesQuery::class)->for($user)->pluck('name')->all());
        });
        $this->post('/accept-invitation', ['token' => $issued->plainTextToken])->assertSessionHasErrors('token');
        $this->actingAs($user)->get('/operator')->assertOk();
    }

    public function test_invitation_cannot_delegate_a_permission_the_sender_does_not_hold(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $actor = $this->createUser();
            $membership = $this->createMembership($this->tenant, $actor);
            $this->assignDirectly($this->tenant, $membership, $this->createRole($this->tenant, 'invite-only', [PermissionKey::InvitationManage]));
            $this->expectException(AuthorizationException::class);
            app(InvitationService::class)->invite($actor, 'escalated@example.test', null, Role::query()->findOrFail($this->role), ResourceScope::tenant());
        });
    }

    public function test_all_registered_admin_modules_render_for_authorized_admin(): void
    {
        config(['kyc.enabled' => true]);
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $url = $resource::getUrl('index', panel: 'admin');
            $response = $this->get($url);
            $this->assertSame(200, $response->status(), $resource.' could not render');
        }
        foreach (Filament::getPanel('admin')->getPages() as $page) {
            $response = $this->get($page::getUrl(panel: 'admin'));
            $this->assertSame(200, $response->status(), $page.' could not render');
        }
    }

    public function test_other_tenant_role_and_read_only_actor_are_rejected(): void
    {
        $other = $this->createTenant('onboard-other');
        $role = $this->withinTenant($other, $this->admin, fn () => $this->createRole($other, 'foreign', [PermissionKey::OperatorPanelAccess]));
        $reader = $this->withinTenant($this->tenant, $this->admin, function () use ($role): User {
            try {
                app(OperatorOnboarding::class)->create($this->admin, (string) Str::ulid(), array_replace($this->data(), ['role_id' => $role->getKey()]));
                $this->fail('Cross-tenant role accepted');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('role_id', $error->errors());
            }
            $reader = $this->createUser();
            $membership = $this->createMembership($this->tenant, $reader);
            $this->assignDirectly($this->tenant, $membership, $this->createRole($this->tenant, 'reader', [PermissionKey::PlatformPanelAccess, PermissionKey::OrganizationView]));

            return $reader;
        });
        $this->actingAs($reader)->get('/admin/onboard-operator')->assertForbidden();
        $this->assertSame(0, DB::table('operator_onboardings')->count());
    }

    public function test_operator_scope_covers_own_station_details_sessions_and_members_only(): void
    {
        $this->withinTenant($this->tenant, $this->admin, function (): void {
            $operator = Organization::factory()->create(['type' => OrganizationType::ChargePointOperator, 'is_active' => true]);
            $other = Organization::factory()->create(['type' => OrganizationType::ChargePointOperator, 'is_active' => true]);
            $user = $this->createUser();
            $membership = $this->createMembership($this->tenant, $user);
            $membership->update(['organization_id' => $operator->getKey()]);
            $otherMembership = $this->createMembership($this->tenant, $this->createUser());
            $otherMembership->update(['organization_id' => $other->getKey()]);
            $role = $this->createRole($this->tenant, 'scoped-operations', [PermissionKey::OperatorPanelAccess, PermissionKey::AssetView, PermissionKey::MembershipView, PermissionKey::ChargingSessionView]);
            $this->assignDirectly($this->tenant, $membership, $role, new ResourceScope(ScopeType::Organization, (string) $operator->getKey()));
            $ownSite = Site::factory()->create(['operator_organization_id' => $operator->getKey()]);
            $otherSite = Site::factory()->create(['operator_organization_id' => $other->getKey()]);
            $own = ChargingStation::factory()->create(['site_id' => $ownSite->getKey()]);
            ChargingStation::factory()->create(['site_id' => $otherSite->getKey()]);
            $authorization = app(AuthorizationService::class);
            $this->assertTrue($authorization->allows($user, PermissionKey::AssetView, new ResourceScope(ScopeType::Site, (string) $ownSite->getKey())));
            $this->assertFalse($authorization->allows($user, PermissionKey::AssetView, new ResourceScope(ScopeType::Site, (string) $otherSite->getKey())));
            $this->assertSame([$own->getKey()], app(AccessibleStationsQuery::class)->for($user)->pluck('id')->all());
            $this->assertSame([$membership->getKey()], app(AccessibleMembershipsQuery::class)->for($user)->pluck('id')->all());
            $operator->update(['is_active' => false]);
            $this->assertFalse(app(PanelAccessService::class)->canAccess($user, 'operator'));
            $this->assertSame(0, app(AccessibleStationsQuery::class)->for($user)->count());
            $this->assertSame(0, app(AccessibleMembershipsQuery::class)->for($user)->count());
            $this->assertFalse($authorization->allows($user, PermissionKey::AssetView, new ResourceScope(ScopeType::Site, (string) $ownSite->getKey())));
        });
    }

    public function test_resending_invalidates_the_previous_link_and_revocation_blocks_acceptance(): void
    {
        [$first, $replacement] = $this->withinTenant($this->tenant, $this->admin, function (): array {
            $service = app(InvitationService::class);
            $first = $service->invite($this->admin, 'links@example.test', null, Role::query()->findOrFail($this->role), ResourceScope::tenant());
            $replacement = $service->resend($this->admin, $first->invitation);
            $this->assertNotNull($first->invitation->fresh()->revoked_at);
            $service->revoke($this->admin, $replacement->invitation, 'Invitation no longer required');

            return [$first, $replacement];
        });
        foreach ([$first, $replacement] as $invitation) {
            $this->post('/accept-invitation', ['token' => $invitation->plainTextToken, 'name' => 'Operator', 'password' => 'ValidPassword1!', 'password_confirmation' => 'ValidPassword1!'])->assertSessionHasErrors('token');
        }
        $this->assertFalse(User::query()->where('email', 'links@example.test')->exists());
    }

    public function test_invitation_does_not_restore_a_disabled_account(): void
    {
        $issued = $this->withinTenant($this->tenant, $this->admin, function () {
            $user = $this->createUser();
            $user->forceFill(['email' => 'disabled@example.test', 'disabled_at' => now()])->save();

            return app(InvitationService::class)->invite($this->admin, $user->email, null, Role::query()->findOrFail($this->role), ResourceScope::tenant());
        });
        $this->post('/accept-invitation', ['token' => $issued->plainTextToken])->assertSessionHasErrors('token');
        $this->assertNotNull(User::query()->where('email', 'disabled@example.test')->sole()->disabled_at);
    }
}
