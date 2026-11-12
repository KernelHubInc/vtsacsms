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
        $this->enablePanel($tenant, $operator, 'operator', [PermissionKey::OperatorPanelAccess, PermissionKey::AssetView, PermissionKey::ReportingView]);
        $this->actingAs($operator)->get('/admin/customize-sidebar')->assertForbidden();
        $this->get('/operator/customize-sidebar')->assertOk()->assertSee('Customize sidebar');

        $this->withinTenant($tenant, $operator, function () use ($operator): void {
            $this->selectPanel('operator');
            $lists = app(SidebarLayout::class)->lists(Filament::getNavigation());
            $key = $this->keyFor($lists[SidebarLayout::ROOT], 'Charging network');
            $station = $this->keyFor($lists[$key], 'Charging Stations');
            Livewire::test(CustomizeSidebar::class)
                ->set('section', $key)
                ->call('moveTo', $station, SidebarLayout::ROOT)
                ->assertSet('section', SidebarLayout::ROOT)
                ->call('reorder', $key, 0, SidebarLayout::ROOT)
                ->call('save');

            $prefs = app(SidebarPreferences::class);
            $saved = $prefs->load($operator, 'operator');
            $this->assertSame($key, $saved[SidebarLayout::ROOT][0]);
            $this->assertContains($station, $saved[SidebarLayout::ROOT]);
            $this->assertNotContains($station, $saved[$key]);
            $this->assertSame([], $prefs->load($operator, 'admin'));
        });
        $this->withinTenant($tenant, $other, function () use ($other, $operator): void {
            $this->assertSame([], app(SidebarPreferences::class)->load($other, 'operator'));
            $this->assertSame([], app(SidebarPreferences::class)->load($operator, 'operator'));
        });
        $this->withinTenant($secondTenant, $operator, fn () => $this->assertSame([], app(SidebarPreferences::class)->load($operator, 'operator')));
    }

    public function test_onboard_operator_can_move_between_sections_and_back_below_dashboard_after_reload(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('sidebar-onboarding-placement');
        $this->enablePanel($tenant, $user, 'admin', PermissionKey::cases());
        $this->actingAs($user);
        $this->withinTenant($tenant, $user, function (): void {
            $this->selectPanel('admin');
            $layout = app(SidebarLayout::class);
            $lists = $layout->lists(Filament::getNavigation());
            $onboard = $this->keyFor($lists[SidebarLayout::ROOT], 'Onboard operator');
            $network = $this->keyFor($lists[SidebarLayout::ROOT], 'Charging network');
            $people = $this->keyFor($lists[SidebarLayout::ROOT], 'People and access');

            Livewire::test(CustomizeSidebar::class)
                ->call('moveTo', $onboard, $network)
                ->assertSet('section', $network)
                ->call('moveTo', $onboard, $people)
                ->assertSet('section', $people)
                ->call('save');
            $rendered = $layout->lists(Filament::getNavigation());
            $this->keyFor($rendered[$people], 'Onboard operator');
            $this->assertArrayNotHasKey($onboard, $rendered[SidebarLayout::ROOT]);

            Livewire::test(CustomizeSidebar::class)
                ->set('section', $people)
                ->call('moveTo', $onboard, SidebarLayout::ROOT)
                ->assertSet('section', SidebarLayout::ROOT)
                ->call('reorder', $onboard, 1, SidebarLayout::ROOT)
                ->call('save');

            $rendered = $layout->lists(Filament::getNavigation());
            $this->assertSame(['Dashboard', 'Onboard operator'], array_map(
                fn ($entry): ?string => $entry->getLabel(),
                array_slice(array_values($rendered[SidebarLayout::ROOT]), 0, 2),
            ));
            $this->assertNotContains('Onboard operator', array_map(fn ($entry): ?string => $entry->getLabel(), $rendered[$people]));

            Livewire::test(CustomizeSidebar::class)->call('moveTo', $network, $people)->assertStatus(422);
            Livewire::test(CustomizeSidebar::class)->call('moveTo', $onboard, 'unknown-section')->assertStatus(422);
        });
    }

    public function test_preferences_cannot_restore_revoked_items_or_reorder_hidden_links(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('sidebar-permissions');
        $this->enablePanel($tenant, $user, 'operator', [PermissionKey::OperatorPanelAccess, PermissionKey::AssetView, PermissionKey::ReportingView]);
        $this->actingAs($user);
        $this->withinTenant($tenant, $user, function () use ($tenant): void {
            $this->selectPanel('operator');
            $lists = app(SidebarLayout::class)->lists(Filament::getNavigation());
            $reports = $this->keyFor($lists[SidebarLayout::ROOT], 'Reports');
            $export = $this->keyFor($lists[$reports], 'Queued exports');
            $network = $this->keyFor($lists[SidebarLayout::ROOT], 'Charging network');
            $station = $this->keyFor($lists[$network], 'Charging Stations');
            Livewire::test(CustomizeSidebar::class)
                ->set('section', $reports)
                ->call('moveTo', $export, SidebarLayout::ROOT)
                ->set('section', $network)
                ->call('moveTo', $station, $reports)
                ->call('save');
            DB::table('role_permissions')->where('tenant_id', $tenant->getKey())->where('permission_key', PermissionKey::ReportingView->value)->delete();
            $this->selectPanel('operator');

            $visible = app(SidebarLayout::class)->lists(Filament::getNavigation());
            $this->assertArrayNotHasKey($reports, $visible[SidebarLayout::ROOT]);
            $this->assertNotContains('Queued exports', array_map(fn ($entry): ?string => $entry->getLabel(), $visible[SidebarLayout::ROOT]));
            $this->assertArrayHasKey($station, $visible[$network]);
            Livewire::test(CustomizeSidebar::class)->call('moveTo', $export, $network)->assertStatus(422);
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
