<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Auth\Login as PanelLogin;
use App\Filament\Operator\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Platform\Pages\MasterData;
use App\Foundation\Audit\Models\AuditEvent;
use App\Modules\Identity\Application\AccountLifecycleService;
use App\Modules\Inventory\Application\InventoryCatalogService;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Locations\Domain\Models\Country;
use App\Modules\Maintenance\Domain\IncidentState;
use App\Modules\Maintenance\Domain\WorkOrderState;
use App\Modules\Organizations\Application\OrganizationLifecycleService;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Domain\Models\Tenant;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class AdminCrudFoundationTest extends TenantSecurityTestCase
{
    public function test_admin_registry_contains_every_audited_resource_and_custom_page(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertCount(47, $panel->getResources());
        $this->assertContains(SupportTicketResource::class, $panel->getResources());
        $this->assertCount(7, $panel->getPages());
        $this->assertContains(MasterData::class, $panel->getPages());
    }

    public function test_representative_admin_pages_render_for_an_authorized_platform_administrator(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('admin-crud-render');

        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $this->createOrganization($tenant);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'admin-crud-auditor', [
                PermissionKey::PlatformPanelAccess,
                PermissionKey::OrganizationView,
                PermissionKey::LocationView,
                PermissionKey::AssetView,
                PermissionKey::ProcurementView,
                PermissionKey::InventoryView,
                PermissionKey::MaintenanceView,
                PermissionKey::PaymentView,
                PermissionKey::SupportView,
                PermissionKey::CmsEdit,
                PermissionKey::AuditView,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
        });

        foreach ([
            '/admin/organizations',
            '/admin/sites',
            '/admin/charging-stations',
            '/admin/purchase-requests',
            '/admin/inventory-items',
            '/admin/work-orders',
            '/admin/payment-intents',
            '/admin/support-tickets',
            '/admin/content/cms-pages',
            '/admin/audit-events',
        ] as $route) {
            $response = $this->actingAs($user)->get($route);
            $this->assertSame(200, $response->status(), "Failed to render {$route}.");
        }
    }

    public function test_read_only_administrator_cannot_open_direct_create_routes(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('admin-crud-read-only');

        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $this->createOrganization($tenant);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'admin-read-only', [
                PermissionKey::PlatformPanelAccess,
                PermissionKey::LocationView,
                PermissionKey::AssetView,
                PermissionKey::InventoryView,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
        });

        $this->actingAs($user)->get('/admin/sites/create')->assertForbidden();
        $this->actingAs($user)->get('/admin/charging-stations/create')->assertForbidden();
    }

    public function test_organization_lifecycle_is_tenant_checked_and_audited(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('admin-org-lifecycle');
        $otherTenant = $this->createTenant('admin-org-other');

        $organization = $this->withinTenant(
            $tenant,
            $actor,
            fn (): Organization => $this->createOrganization($tenant),
        );
        $other = $this->withinTenant(
            $otherTenant,
            $actor,
            fn (): Organization => $this->createOrganization($otherTenant),
        );

        $this->withinTenant($tenant, $actor, function () use ($organization, $other, $tenant): void {
            app(OrganizationLifecycleService::class)->setActive($organization, false, 'contract ended');

            $this->assertFalse($organization->fresh()?->is_active);
            $this->assertDatabaseHas('audit_events', [
                'tenant_id' => $tenant->getKey(),
                'action' => 'organizations.organization.deactivated',
                'target_id' => $organization->getKey(),
                'reason' => 'contract ended',
            ]);

            $this->expectException(DomainException::class);
            app(OrganizationLifecycleService::class)->setActive($other, false, 'cross tenant attempt');
        });
    }

    public function test_inventory_catalog_archives_without_deleting_history_and_can_restore(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('admin-item-lifecycle');

        $item = $this->withinTenant(
            $tenant,
            $actor,
            fn (): InventoryItem => InventoryItem::factory()->create(),
        );

        $this->withinTenant($tenant, $actor, function () use ($item, $tenant): void {
            $service = app(InventoryCatalogService::class);
            $service->setItemArchived($item, true, 'obsolete spare');

            $archived = $item->fresh();
            $this->assertInstanceOf(InventoryItem::class, $archived);
            $this->assertNotNull($archived->archived_at);
            $this->assertFalse($archived->is_active);
            $this->assertDatabaseHas('inventory_items', ['id' => $item->getKey()]);
            $this->assertDatabaseHas('audit_events', [
                'tenant_id' => $tenant->getKey(),
                'action' => 'inventory.item.archived',
                'target_id' => $item->getKey(),
                'reason' => 'obsolete spare',
            ]);

            $service->setItemArchived($archived, false, 'returned to catalog');
            $this->assertNull($item->fresh()?->archived_at);
            $this->assertTrue($item->fresh()?->is_active);
            $this->assertSame(2, AuditEvent::query()
                ->where('target_type', 'inventory_item')
                ->where('target_id', $item->getKey())
                ->count());
        });
    }

    public function test_inventory_policies_are_deny_by_default_for_read_only_roles(): void
    {
        $viewer = $this->createUser();
        $operator = $this->createUser();
        $tenant = $this->createTenant('admin-inventory-policy');

        [$item, $warehouse] = $this->withinTenant(
            $tenant,
            $operator,
            function () use ($tenant, $viewer): array {
                $viewerMembership = $this->createMembership($tenant, $viewer);
                $viewerRole = $this->createRole($tenant, 'inventory-auditor', [
                    PermissionKey::InventoryView,
                ]);
                $this->assignDirectly($tenant, $viewerMembership, $viewerRole);

                return [InventoryItem::factory()->create(), Warehouse::factory()->create()];
            },
        );

        $this->withinTenant($tenant, $viewer, function () use ($item, $viewer, $warehouse): void {
            $this->assertFalse(Gate::forUser($viewer)->allows('update', $item));
            $this->assertFalse(Gate::forUser($viewer)->allows('delete', $item));
            $this->assertFalse(Gate::forUser($viewer)->allows('update', $warehouse));
            $this->assertFalse(Gate::forUser($viewer)->allows('delete', $warehouse));
        });
    }

    public function test_platform_panel_access_alone_cannot_change_account_lifecycle(): void
    {
        $actor = $this->createUser();
        $subject = $this->createUser();
        $tenant = $this->createTenant('account-lifecycle-separation');

        $this->withinTenant($tenant, $actor, function () use ($actor, $subject, $tenant): void {
            $membership = $this->createMembership($tenant, $actor);
            $role = $this->createRole($tenant, 'panel-only', [PermissionKey::PlatformPanelAccess]);
            $this->assignDirectly($tenant, $membership, $role);

            $this->expectException(AuthorizationException::class);
            app(AccountLifecycleService::class)->activate($actor, $subject);
        });
    }

    public function test_master_data_create_edit_archive_and_restore_are_audited(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('admin-master-data-crud');

        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $this->createOrganization($tenant);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'master-data-manager', [
                PermissionKey::PlatformPanelAccess,
                PermissionKey::LocationView,
                PermissionKey::LocationManage,
            ]);
            $this->assignDirectly($tenant, $membership, $role);

            Filament::setCurrentPanel(Filament::getPanel('admin'));
            $component = Livewire::actingAs($user)
                ->test(new MasterData)
                ->set('list', 'countries')
                ->set('code', 'QZ')
                ->set('secondaryCode', 'QZZ')
                ->set('name', 'Audit Test Country')
                ->call('createRecord')
                ->assertHasNoErrors();

            $country = Country::query()->where('iso_alpha_2', 'QZ')->sole();
            $component
                ->call('beginEdit', (string) $country->getKey())
                ->set('editName', 'Audited Country')
                ->call('updateRecord')
                ->assertHasNoErrors()
                ->call('archiveRecord', (string) $country->getKey())
                ->call('restoreRecord', (string) $country->getKey());

            $freshCountry = $country->fresh();
            $this->assertInstanceOf(Country::class, $freshCountry);
            $this->assertSame('Audited Country', $freshCountry->name);
            $this->assertNull($freshCountry->archived_at);
            $this->assertSame(4, AuditEvent::query()
                ->where('target_type', 'master_data')
                ->where('target_id', $country->getKey())
                ->count());
        });
    }

    public function test_work_order_and_incident_states_remain_distinct_backing_values(): void
    {
        $this->assertNotContains('acknowledged', array_column(WorkOrderState::cases(), 'value'));
        $this->assertSame(IncidentState::Acknowledged, IncidentState::from('acknowledged'));
        $this->assertSame(WorkOrderState::Triaged, WorkOrderState::from('triaged'));
    }

    public function test_successful_panel_login_clears_the_consecutive_failure_limiter(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('panel-login-rate-limit');

        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $this->createOrganization($tenant);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'platform-login', [
                PermissionKey::PlatformPanelAccess,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
        });

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $key = 'livewire-rate-limiter:'.sha1(PanelLogin::class.'|authenticate|127.0.0.1');
        RateLimiter::hit($key, 60);

        Livewire::test(PanelLogin::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    private function createOrganization(
        Tenant $tenant,
        OrganizationType $type = OrganizationType::Platform,
    ): Organization {
        return Organization::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Admin Audit Operator',
            'code' => 'ADMIN-'.substr((string) $tenant->getKey(), -6),
            'type' => $type,
            'is_active' => true,
        ]);
    }
}
