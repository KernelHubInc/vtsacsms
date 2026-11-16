<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Operator\Resources\Memberships\Pages\ListMemberships;
use App\Http\Resources\Api\V1\ChargingSessionResource;
use App\Modules\Charging\Domain\AuthorizationStatus;
use App\Modules\Charging\Domain\ChargingSessionOrigin;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Application\RoleAssignmentService;
use App\Modules\Organizations\Application\WorkforceRolePresets;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Organizations\Domain\WorkforceRolePreset;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class WorkforceRolePresetsTest extends TenantSecurityTestCase
{
    public function test_sync_adds_five_presets_to_staging_without_changing_grants(): void
    {
        $tenant = $this->createTenant('preset-catalog');
        $actor = $this->createUser();
        app(WorkforceRolePresets::class)->sync();
        $this->assertDatabaseCount('roles', 0);

        $this->app->instance('env', 'staging');
        self::assertSame(0, Artisan::call('security:sync-permissions'));
        self::assertSame(0, Artisan::call('security:sync-permissions'));
        $this->assertDatabaseCount('roles', 5);
        $this->assertDatabaseCount('role_assignments', 0);
        $this->withinTenant($tenant, $actor, static function (): void {
            foreach (Role::query()->with('permissions')->get() as $role) {
                self::assertTrue($role->is_system);
                $permissions = $role->permissions->pluck('key')->all();
                foreach ([PermissionKey::PaymentRefund, PermissionKey::SettlementApprove, PermissionKey::ChargingRemoteCommand,
                    PermissionKey::ReportingExport, PermissionKey::TariffPublish] as $permission) {
                    self::assertNotContains($permission->value, $permissions);
                }
            }
        });
    }

    public function test_operator_can_assign_a_viewer_only_within_its_own_sites(): void
    {
        $this->app->instance('env', 'staging');
        $tenant = $this->createTenant('preset-delegation');
        $actor = $this->createUser();
        $staff = $this->createUser();
        app(WorkforceRolePresets::class)->sync();
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor, $staff): void {
            $organization = Organization::factory()->create(['type' => OrganizationType::ChargePointOperator]);
            $outside = Organization::factory()->create(['type' => OrganizationType::ChargePointOperator]);
            $site = Site::factory()->activePublic()->create(['name' => 'Main office charging', 'operator_organization_id' => $organization->getKey()]);
            $otherSite = Site::factory()->activePublic()->create(['name' => 'Different operator site', 'operator_organization_id' => $outside->getKey()]);
            $member = $this->createMembership($tenant, $staff);
            $member->update(['organization_id' => $organization->getKey()]);
            $operatorRole = Role::query()->where('key', WorkforceRolePreset::OperatorAdministrator->value)->sole();
            $viewer = Role::query()->where('key', WorkforceRolePreset::StationViewer->value)->sole();
            $orgScope = new ResourceScope(ScopeType::Organization, (string) $organization->getKey());
            $scope = new ResourceScope(ScopeType::Site, (string) $site->getKey());
            $this->assignDirectly($tenant, $this->createMembership($tenant, $actor), $operatorRole, $orgScope);

            $actionData = ['coverage' => 'site:'.$site->getKey(), 'role_id' => $viewer->getKey()];
            Livewire::test(ListMemberships::class)->assertSee('Users & access')
                ->callTableAction('manageAccess', $member, data: array_replace($actionData, ['coverage' => 'site:'.$otherSite->getKey()]))
                ->assertHasActionErrors(['coverage']);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                Livewire::test(ListMemberships::class)->mountTableAction('manageAccess', $member)
                    ->set('mountedActions.0.data.coverage', $actionData['coverage'])
                    ->set('mountedActions.0.data.role_id', $actionData['role_id'])
                    ->callMountedTableAction()->assertHasNoActionErrors();
            }
            self::assertSame(1, $member->assignments()->count());
            $access = app(AuthorizationService::class);
            self::assertTrue($staff->canAccessPanel(Filament::getPanel('operator')));
            self::assertFalse($staff->canAccessPanel(Filament::getPanel('admin')));
            self::assertTrue($access->allows($staff, PermissionKey::ChargingSessionView, $scope));
            self::assertFalse($access->allows($staff, PermissionKey::PaymentView, $scope));
            self::assertFalse($access->allows($staff, PermissionKey::ChargingRemoteCommand, $scope));
            self::assertFalse($access->allows($staff, PermissionKey::ChargingSessionView, new ResourceScope(ScopeType::Site, (string) $otherSite->getKey())));
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'action' => 'identity.role_assignment.created']);

            $request = Request::create('/api/v1/charging-sessions');
            $request->setUserResolver(static fn () => $staff);
            $session = new ChargingSession([
                'site_id' => $site->getKey(), 'origin' => ChargingSessionOrigin::Charger,
                'state' => ChargingSessionState::Completed, 'authorization_status' => AuthorizationStatus::Approved,
                'requested_at' => now('UTC'), 'estimated_cost_minor' => 1800, 'final_cost_minor' => 1900,
                'tariff_snapshot' => ['currency' => 'PHP'],
            ]);
            $resource = new ChargingSessionResource($session);
            self::assertNull($resource->toArray($request)['final_cost_minor']);
            $finance = Role::query()->where('key', WorkforceRolePreset::FinanceViewer->value)->sole();
            $assignment = app(RoleAssignmentService::class)->assign($actor, $member, $finance, $scope);
            self::assertSame(1900, $resource->toArray($request)['final_cost_minor']);
            app(RoleAssignmentService::class)->revoke($actor, $assignment, 'End of finance assignment');
            self::assertNull($resource->toArray($request)['final_cost_minor']);

            Notification::fake();
            Livewire::test(ListMemberships::class)->mountAction('inviteUser')
                ->set('mountedActions.0.data.email', 'site-viewer@example.test')
                ->set('mountedActions.0.data.coverage', $actionData['coverage'])
                ->set('mountedActions.0.data.role_id', $actionData['role_id'])
                ->callMountedAction()->assertHasNoActionErrors();
            $this->assertDatabaseHas('user_invitations', ['tenant_id' => $tenant->getKey(), 'email' => 'site-viewer@example.test',
                'organization_id' => $organization->getKey(), 'role_id' => $viewer->getKey(), 'scope_type' => 'site', 'scope_id' => $site->getKey()]);

            $this->expectException(AuthorizationException::class);
            app(RoleAssignmentService::class)->assign($actor, $member, $operatorRole, $scope);
        });
    }
}
