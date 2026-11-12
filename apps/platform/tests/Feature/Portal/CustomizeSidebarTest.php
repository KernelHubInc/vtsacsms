<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Shared\Navigation\SidebarLayout;
use App\Filament\Shared\Pages\CustomizeSidebar;
use App\Models\User;
use App\Modules\Identity\Application\SidebarPreferences;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class CustomizeSidebarTest extends TenantSecurityTestCase
{
    public function test_admin_can_save_top_level_and_child_order_and_restore_defaults(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('sidebar-admin');
        $this->enablePanel($tenant, $user, 'admin', PermissionKey::cases());
        $this->actingAs($user)->get('/admin/customize-sidebar')->assertOk()->assertSee('Save layout')->assertSee('wire:sort="reorder"', false);
        $this->withinTenant($tenant, $user, function (): void {
            $this->selectPanel('admin');
            $lists = app(SidebarLayout::class)->lists(Filament::getNavigation());
            $network = $this->keyFor($lists[SidebarLayout::ROOT], 'Charging network');
            $dashboard = $this->keyFor($lists[SidebarLayout::ROOT], 'Dashboard');
            $this->assertSame($dashboard, array_key_first($lists[SidebarLayout::ROOT]));
            $children = array_keys($lists[$network]);
            $this->assertGreaterThan(1, count($children));

            Livewire::test(CustomizeSidebar::class)
                ->call('reorder', $network, 0, SidebarLayout::ROOT)
                ->set('section', $network)
                ->call('move', $children[1], -1)
                ->call('save')
                ->assertDispatched('refresh-sidebar');

            $saved = app(SidebarLayout::class)->lists(Filament::getNavigation());
            $this->assertSame($network, array_key_first($saved[SidebarLayout::ROOT]));
            $this->assertSame($children[1], array_key_first($saved[$network]));
            Livewire::test(CustomizeSidebar::class)
                ->assertSet('draft.root.0', $network)
                ->call('resetLayout')
                ->assertSet('draft.root.0', $dashboard)
                ->assertDispatched('refresh-sidebar');
            $this->assertDatabaseCount('identity_sidebar_preferences', 0);
        });
    }

    public function test_operator_can_save_without_admin_access_and_preferences_are_private_to_account_tenant_and_panel(): void
    {
        $operator = $this->createUser();
        $other = $this->createUser();
        $tenant = $this->createTenant('sidebar-operator');
        $secondTenant = $this->createTenant('sidebar-other-tenant');
        $this->enablePanel($tenant, $operator, 'operator', [PermissionKey::OperatorPanelAccess, PermissionKey::LocationView, PermissionKey::ReportingView]);
        $this->actingAs($operator)->get('/admin/customize-sidebar')->assertForbidden();
        $this->get('/operator/customize-sidebar')->assertOk()->assertSee('Customize sidebar');

        $this->withinTenant($tenant, $operator, function () use ($operator): void {
            $this->selectPanel('operator');
            $lists = app(SidebarLayout::class)->lists(Filament::getNavigation());
            $key = $this->keyFor($lists[SidebarLayout::ROOT], 'Charging network');
            Livewire::test(CustomizeSidebar::class)->call('reorder', $key, 0, SidebarLayout::ROOT)->call('save');

            $prefs = app(SidebarPreferences::class);
            $this->assertSame($key, $prefs->load($operator, 'operator')[SidebarLayout::ROOT][0]);
            $this->assertSame([], $prefs->load($operator, 'admin'));
        });
        $this->withinTenant($tenant, $other, function () use ($other, $operator): void {
            $this->assertSame([], app(SidebarPreferences::class)->load($other, 'operator'));
            $this->assertSame([], app(SidebarPreferences::class)->load($operator, 'operator'));
        });
        $this->withinTenant($secondTenant, $operator, fn () => $this->assertSame([], app(SidebarPreferences::class)->load($operator, 'operator')));
    }

    public function test_preferences_cannot_restore_revoked_items_or_reorder_hidden_links(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('sidebar-permissions');
        $this->enablePanel($tenant, $user, 'operator', [PermissionKey::OperatorPanelAccess, PermissionKey::LocationView, PermissionKey::ReportingView]);
        $this->actingAs($user);
        $this->withinTenant($tenant, $user, function () use ($tenant): void {
            $this->selectPanel('operator');
            $lists = app(SidebarLayout::class)->lists(Filament::getNavigation());
            $reports = $this->keyFor($lists[SidebarLayout::ROOT], 'Reports');
            Livewire::test(CustomizeSidebar::class)->call('reorder', $reports, 0, SidebarLayout::ROOT)->call('save');
            DB::table('role_permissions')->where('tenant_id', $tenant->getKey())->where('permission_key', PermissionKey::ReportingView->value)->delete();
            $this->selectPanel('operator');

            $visible = app(SidebarLayout::class)->lists(Filament::getNavigation());
            $this->assertArrayNotHasKey($reports, $visible[SidebarLayout::ROOT]);
            Livewire::test(CustomizeSidebar::class)->call('reorder', $reports, 0, SidebarLayout::ROOT)->assertStatus(422);
        });
    }

    /** @param list<PermissionKey> $permissions */
    private function enablePanel(Tenant $tenant, User $user, string $panel, array $permissions): void
    {
        $this->withinTenant($tenant, $user, function () use ($tenant, $user, $panel, $permissions): void {
            Organization::factory()->create(['type' => $panel === 'admin' ? OrganizationType::Platform : OrganizationType::ChargePointOperator]);
            $this->assignDirectly($tenant, $this->createMembership($tenant, $user), $this->createRole($tenant, 'staff', $permissions));
        });
    }

    private function selectPanel(string $panel): void
    {
        app()->forgetInstance(NavigationManager::class);
        Filament::setCurrentPanel(Filament::getPanel($panel));
    }

    /** @param array<string, NavigationGroup|NavigationItem> $entries */
    private function keyFor(array $entries, string $label): string
    {
        $key = collect($entries)->search(fn ($entry): bool => $entry->getLabel() === $label);
        $this->assertIsString($key);

        return $key;
    }
}
