<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Platform\Resources\Roles\Pages\CreateRole;
use App\Filament\Platform\Resources\Roles\Pages\EditRole;
use App\Foundation\Audit\Models\AuditEvent;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Application\PermissionCatalog;
use App\Modules\Organizations\Application\RoleManager;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class RoleModulePermissionsTest extends TenantSecurityTestCase
{
    public function test_custom_role_selects_only_requested_module_functions(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('module-create');
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
            $membership = $this->createMembership($tenant, $actor);
            $owner = $this->createRole($tenant, 'owner', PermissionKey::cases());
            $this->assignDirectly($tenant, $membership, $owner);

            Livewire::test(CreateRole::class)
                ->assertSee('Modules and functions')
                ->assertSee('Charging operations')
                ->fillForm([
                    'name' => 'Network observer',
                    'key' => 'network-observer',
                    'module_permissions.panels' => [PermissionKey::OperatorPanelAccess->value],
                    'module_permissions.locations' => [PermissionKey::LocationView->value],
                    'module_permissions.charging' => [PermissionKey::ChargingSessionView->value],
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $role = Role::query()->where('key', 'network-observer')->sole();
            $this->assertEqualsCanonicalizing([
                PermissionKey::OperatorPanelAccess->value,
                PermissionKey::LocationView->value,
                PermissionKey::ChargingSessionView->value,
            ], $role->permissions()->pluck('permissions.key')->all());
            $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->getKey(), 'target_id' => $role->getKey(), 'action' => 'identity.role.created']);
        });
    }

    public function test_removing_a_module_updates_existing_users_navigation_and_direct_access_in_both_panels(): void
    {
        $actor = $this->createUser();
        $user = $this->createUser();
        $tenant = $this->createTenant('module-remove');
        $role = $this->withinTenant($tenant, $actor, function () use ($tenant, $actor, $user): Role {
            Organization::factory()->create(['type' => OrganizationType::Platform]);
            Organization::factory()->create(['type' => OrganizationType::ChargePointOperator]);
            $admin = $this->createMembership($tenant, $actor);
            $this->assignDirectly($tenant, $admin, $this->createRole($tenant, 'owner', PermissionKey::cases()));
            $member = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'stock-viewer', [PermissionKey::PlatformPanelAccess, PermissionKey::OperatorPanelAccess, PermissionKey::InventoryView]);
            $this->assignDirectly($tenant, $member, $role);

            return $role;
        });
        foreach (['admin', 'operator'] as $panel) {
            // HTTP tests reuse the container; production requests get a fresh scoped navigation manager.
            $this->app->forgetInstance(NavigationManager::class);
            Filament::setCurrentPanel(Filament::getPanel($panel));
            $this->actingAs($user)->get('/'.$panel)->assertOk()->assertSee('/'.$panel.'/inventory-items', false)->assertDontSee('/'.$panel.'/payment-intents', false);
            $this->get('/'.$panel.'/inventory-items')->assertOk();
        }

        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $actor, function () use ($role, $tenant): void {
            Livewire::test(EditRole::class, ['record' => $role->getKey()])
                ->assertSee('Assigned memberships: 1 · Role assignments: 1')
                ->assertFormSet(['module_permissions.inventory' => [PermissionKey::InventoryView->value]])
                ->callAction(TestAction::make('remove_inventory')->schemaComponent('role_modules.module_inventory'))
                ->assertFormSet(['module_permissions.inventory' => []])
                ->call('save')
                ->assertHasNoFormErrors();

            $audit = AuditEvent::query()->where('tenant_id', $tenant->getKey())->where('target_id', $role->getKey())->where('action', 'identity.role.updated')->sole();
            $this->assertContains(PermissionKey::InventoryView->value, $audit->before['permissions']);
            $this->assertNotContains(PermissionKey::InventoryView->value, $audit->after['permissions']);
        });

        foreach (['admin', 'operator'] as $panel) {
            $this->app->forgetInstance(NavigationManager::class);
            Filament::setCurrentPanel(Filament::getPanel($panel));
            $this->actingAs($user)->get('/'.$panel)->assertOk()->assertDontSee('/'.$panel.'/inventory-items', false)->assertDontSee('>Inventory<', false);
            $this->get('/'.$panel.'/inventory-items')->assertForbidden();
        }
    }

    public function test_a_second_role_can_still_grant_a_removed_module(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('module-union');
        $this->actingAs($actor);
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
            $membership = $this->createMembership($tenant, $actor);
            $admin = $this->createRole($tenant, 'role-admin', [PermissionKey::RoleManage, PermissionKey::InventoryView]);
            $this->assignDirectly($tenant, $membership, $admin);
            $role = $this->createRole($tenant, 'additional-stock', [PermissionKey::InventoryView]);
            $this->assignDirectly($tenant, $membership, $role);
            app(RoleManager::class)->save($actor, ['key' => $role->key, 'name' => $role->name, 'permissions' => []], $role);

            $this->assertSame([], $role->permissions()->pluck('permissions.key')->all());
            $this->assertTrue(app(AuthorizationService::class)->allows($actor, PermissionKey::InventoryView));
        });
    }

    public function test_role_editor_does_not_grant_functions_the_actor_does_not_hold(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('module-escalation');
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
            $member = $this->createMembership($tenant, $actor);
            $role = $this->createRole($tenant, 'limited-admin', [PermissionKey::RoleView, PermissionKey::RoleManage]);
            $this->assignDirectly($tenant, $member, $role);

            Livewire::test(CreateRole::class)->fillForm([
                'key' => 'forged-refund', 'name' => 'Forged refund',
                'module_permissions.payments' => [PermissionKey::PaymentRefund->value],
            ])->call('create')->assertForbidden();
            $this->assertDatabaseMissing('roles', ['tenant_id' => $tenant->getKey(), 'key' => 'forged-refund']);
        });
    }

    public function test_functions_cannot_be_submitted_under_a_different_module(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('module-forgery');
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
            $member = $this->createMembership($tenant, $actor);
            $this->assignDirectly($tenant, $member, $this->createRole($tenant, 'owner', PermissionKey::cases()));
            Livewire::test(CreateRole::class)->fillForm([
                'key' => 'wrong-module', 'name' => 'Wrong module',
                'module_permissions.inventory' => [PermissionKey::PaymentRefund->value],
            ])->call('create')->assertHasFormErrors(['module_permissions.inventory.0']);
            $this->assertDatabaseMissing('roles', ['tenant_id' => $tenant->getKey(), 'key' => 'wrong-module']);
        });
    }

    public function test_system_roles_and_other_tenants_roles_are_protected(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('module-isolation');
        $otherTenant = $this->createTenant('module-isolation-other');
        $foreign = $this->withinTenant($otherTenant, $actor, fn (): Role => $this->createRole($otherTenant, 'foreign-role', [PermissionKey::InventoryView]));
        $system = $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): Role {
            Organization::factory()->create(['type' => OrganizationType::Platform]);
            $member = $this->createMembership($tenant, $actor);
            $system = $this->createRole($tenant, 'owner', PermissionKey::cases());
            $system->update(['is_system' => true]);
            $this->assignDirectly($tenant, $member, $system);

            return $system;
        });
        $this->actingAs($actor)->get('/admin/roles/'.$system->getKey().'/edit')->assertForbidden();
        $this->get('/admin/roles/'.$foreign->getKey().'/edit')->assertNotFound();

        $this->withinTenant($tenant, $actor, function () use ($actor, $system, $foreign): void {
            foreach ([$system, $foreign] as $role) {
                try {
                    app(RoleManager::class)->save($actor, ['key' => $role->key, 'name' => 'Changed', 'permissions' => []], $role);
                    $this->fail('Protected roles must not be changed.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseMissing('roles', ['id' => $role->getKey(), 'name' => 'Changed']);
                }
            }
        });
    }

    public function test_every_permission_is_available_in_exactly_one_module(): void
    {
        $keys = [];
        foreach (app(PermissionCatalog::class)->modules() as $module) {
            $this->assertNotEmpty($module['permissions']);
            array_push($keys, ...array_keys($module['permissions']));
        }
        $this->assertCount(count(array_unique($keys)), $keys);
        $this->assertEqualsCanonicalizing(array_column(PermissionKey::cases(), 'value'), $keys);
    }

    public function test_role_with_no_functions_is_valid_and_duplicate_key_has_a_field_error(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('module-empty');
        $otherTenant = $this->createTenant('module-empty-other');
        $this->withinTenant($otherTenant, $actor, fn (): Role => $this->createRole($otherTenant, 'inactive-custom', []));
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $actor, function () use ($tenant, $actor): void {
            $member = $this->createMembership($tenant, $actor);
            $this->assignDirectly($tenant, $member, $this->createRole($tenant, 'owner', [PermissionKey::RoleView, PermissionKey::RoleManage]));
            Livewire::test(CreateRole::class)->fillForm(['key' => 'inactive-custom', 'name' => 'Inactive custom'])
                ->call('create')->assertHasNoFormErrors();
            $role = Role::query()->where('key', 'inactive-custom')->sole();
            $this->assertSame([], $role->permissions()->pluck('permissions.key')->all());
            Livewire::test(CreateRole::class)->fillForm(['key' => 'inactive-custom', 'name' => 'Duplicate'])
                ->call('create')->assertHasFormErrors(['key' => 'unique']);
        });
    }
}
