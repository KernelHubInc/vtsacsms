<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Operator\Resources\PaymentIntents\PaymentIntentResource;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Locations\Domain\SiteLifecycleStatus;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Payments\Domain\Models\PaymentIntent;
use App\Modules\Payments\Domain\Models\PaymentProviderConfig;
use App\Modules\Reporting\Application\PortalDashboardQuery;
use App\Modules\Reporting\Application\PortalExportRowsQuery;
use App\Modules\Reporting\Application\PortalExportService;
use App\Modules\Reporting\Domain\Models\PortalExport;
use App\Modules\Reporting\Jobs\GeneratePortalExport;
use App\Modules\Tenancy\Application\Queue\UseTenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantSecurityTestCase;

final class PortalManagementTest extends TenantSecurityTestCase
{
    public function test_dashboard_map_and_cache_follow_the_users_exact_site_scope(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('portal-site-scope');

        [$siteA, $siteB, $assignment] = $this->withinTenant($tenant, $user, function () use ($tenant, $user): array {
            $operator = $this->operator($tenant);
            $siteA = $this->site($tenant, $operator, 'A', 14.55, 121.02);
            $siteB = $this->site($tenant, $operator, 'B', 14.60, 121.08);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'site-portal', [
                PermissionKey::LocationView,
                PermissionKey::ChargingSessionView,
                PermissionKey::ReportingView,
                PermissionKey::ReportingExport,
            ]);
            $assignment = $this->assignDirectly(
                $tenant,
                $membership,
                $role,
                new ResourceScope(ScopeType::Site, (string) $siteA->getKey()),
            );

            return [$siteA, $siteB, $assignment];
        });

        $this->withinTenant($tenant, $user, function () use ($user, $siteA, $siteB, $assignment): void {
            $first = app(PortalDashboardQuery::class)->map($user);
            $this->assertSame([(string) $siteA->getKey()], array_column($first, 'id'));

            $assignment->update(['scope_id' => $siteB->getKey()]);
            $second = app(PortalDashboardQuery::class)->map($user);
            $this->assertSame([(string) $siteB->getKey()], array_column($second, 'id'));
            $this->assertNotContains((string) $siteA->getKey(), array_column($second, 'id'));
        });
    }

    public function test_export_is_queued_with_tenant_context_and_rechecks_site_scope_when_generated(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->createUser();
        $tenant = $this->createTenant('portal-export');

        [$siteA, $siteB] = $this->withinTenant($tenant, $user, function () use ($tenant, $user): array {
            $operator = $this->operator($tenant);
            $siteA = $this->site($tenant, $operator, 'EX-A', 14.55, 121.02);
            $siteB = $this->site($tenant, $operator, 'EX-B', 14.60, 121.08);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'site-exporter', [
                PermissionKey::LocationView,
                PermissionKey::ReportingView,
                PermissionKey::ReportingExport,
            ]);
            $this->assignDirectly(
                $tenant,
                $membership,
                $role,
                new ResourceScope(ScopeType::Site, (string) $siteA->getKey()),
            );
            app(PortalExportService::class)->queue($user, 'assets');

            return [$siteA, $siteB];
        });

        $job = null;
        Queue::assertPushed(GeneratePortalExport::class, function (GeneratePortalExport $pushed) use ($tenant, &$job): bool {
            $job = $pushed;

            return $pushed->tenantJobEnvelope()->tenantId === (string) $tenant->getKey();
        });
        /** @var PortalExport $export */
        $export = PortalExport::withoutGlobalScopes()->firstOrFail();
        if (! $job instanceof GeneratePortalExport) {
            self::fail('The queued export job was not captured.');
        }
        app(UseTenantContext::class)->handle($job, fn () => $job->handle(
            app(PortalExportRowsQuery::class),
            app(AuthorizationService::class),
        ));

        $export->refresh();
        $this->assertSame('completed', $export->status);
        Storage::disk('local')->assertExists((string) $export->path);
        $csv = Storage::disk('local')->get((string) $export->path);
        $this->assertStringContainsString((string) $siteA->getKey(), $csv);
        $this->assertStringNotContainsString((string) $siteB->getKey(), $csv);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(),
            'action' => 'reporting.portal_export.queued',
            'target_id' => $export->getKey(),
        ]);
    }

    public function test_panel_operational_pages_require_authentication(): void
    {
        $this->get('/operator/network-map')->assertRedirect('/operator/login');
        $this->get('/operator/reports')->assertRedirect('/operator/login');
        $this->get('/admin/network-map')->assertRedirect('/admin/login');
        $this->get('/admin/integration-health')->assertRedirect('/admin/login');
    }

    public function test_authenticated_operator_map_renders_only_authorized_site_data(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('operator-browser');

        [$siteA, $siteB] = $this->withinTenant($tenant, $user, function () use ($tenant, $user): array {
            $operator = $this->operator($tenant);
            $siteA = $this->site($tenant, $operator, 'VISIBLE', 14.55, 121.02);
            $siteB = $this->site($tenant, $operator, 'HIDDEN', 14.60, 121.08);
            $membership = $this->createMembership($tenant, $user);
            $panelRole = $this->createRole($tenant, 'operator-panel', [PermissionKey::OperatorPanelAccess]);
            $siteRole = $this->createRole($tenant, 'operator-site-viewer', [
                PermissionKey::LocationView,
                PermissionKey::ChargingSessionView,
                PermissionKey::ReportingView,
            ]);
            $this->assignDirectly($tenant, $membership, $panelRole);
            $this->assignDirectly(
                $tenant,
                $membership,
                $siteRole,
                new ResourceScope(ScopeType::Site, (string) $siteA->getKey()),
            );

            return [$siteA, $siteB];
        });

        $this->actingAs($user)->get('/operator/network-map')
            ->assertOk()
            ->assertSee('Search authorized sites')
            ->assertSee($siteA->name)
            ->assertDontSee($siteB->name);
        $this->actingAs($user)->get('/operator/reports')
            ->assertOk()
            ->assertSee('Tenant-safe CSV exports');
        $this->actingAs($user)->get('/operator')
            ->assertOk()
            ->assertSee('Network availability');
    }

    public function test_authenticated_platform_admin_can_open_master_data_and_health_surfaces(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('platform-browser');

        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            Organization::query()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => 'VTSA Platform',
                'code' => 'PLATFORM',
                'type' => OrganizationType::Platform,
                'is_active' => true,
            ]);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'platform-operations', [
                PermissionKey::PlatformPanelAccess,
                PermissionKey::LocationView,
                PermissionKey::LocationManage,
                PermissionKey::IntegrationView,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
        });

        $this->actingAs($user)->get('/admin/master-data')
            ->assertOk()
            ->assertSee('Countries')
            ->assertSee('Referenced records are archived');
        $this->actingAs($user)->get('/admin/integration-health')
            ->assertOk()
            ->assertSee('Integration outbox');
    }

    public function test_site_scoped_finance_resource_intersects_payments_with_accessible_sessions(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('finance-resource-scope');

        [$visiblePayment, $hiddenPayment] = $this->withinTenant($tenant, $user, function () use ($tenant, $user): array {
            $operator = $this->operator($tenant);
            $siteA = $this->site($tenant, $operator, 'PAY-A', 14.55, 121.02);
            $siteB = $this->site($tenant, $operator, 'PAY-B', 14.60, 121.08);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'site-finance', [
                PermissionKey::ChargingSessionView,
                PermissionKey::PaymentView,
            ]);
            $this->assignDirectly(
                $tenant,
                $membership,
                $role,
                new ResourceScope(ScopeType::Site, (string) $siteA->getKey()),
            );
            ConnectorStandard::query()->create(['code' => 'TEST-CCS', 'name' => 'Test CCS']);
            ChargingCurrentType::query()->create(['code' => 'TEST-DC', 'name' => 'Test DC']);
            $sessionA = ChargingSession::factory()->create(['site_id' => $siteA->getKey()]);
            $sessionB = ChargingSession::factory()->create(['site_id' => $siteB->getKey()]);
            $provider = PaymentProviderConfig::query()->create([
                'tenant_id' => $tenant->getKey(),
                'provider' => 'fake',
                'environment' => 'local',
                'configuration_version' => 1,
                'capabilities' => [],
                'is_active' => true,
            ]);
            $paymentA = PaymentIntent::query()->create([
                'tenant_id' => $tenant->getKey(),
                'provider_config_id' => $provider->getKey(),
                'billable_type' => ChargingSession::class,
                'billable_id' => $sessionA->getKey(),
                'currency' => 'PHP',
                'amount_requested_minor' => 1000,
                'idempotency_key' => 'portal-finance-a',
            ]);
            $paymentB = PaymentIntent::query()->create([
                'tenant_id' => $tenant->getKey(),
                'provider_config_id' => $provider->getKey(),
                'billable_type' => ChargingSession::class,
                'billable_id' => $sessionB->getKey(),
                'currency' => 'PHP',
                'amount_requested_minor' => 2000,
                'idempotency_key' => 'portal-finance-b',
            ]);

            return [$paymentA, $paymentB];
        });

        $this->actingAs($user);
        $ids = $this->withinTenant(
            $tenant,
            $user,
            fn (): array => PaymentIntentResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertSame([(string) $visiblePayment->getKey()], $ids);
        $this->assertNotContains((string) $hiddenPayment->getKey(), $ids);
    }

    private function operator(Tenant $tenant): Organization
    {
        return Organization::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Portal Operator',
            'code' => 'PORTAL',
            'type' => OrganizationType::ChargePointOperator,
            'is_active' => true,
        ]);
    }

    private function site(
        Tenant $tenant,
        Organization $operator,
        string $code,
        float $latitude,
        float $longitude,
    ): Site {
        return Site::query()->create([
            'tenant_id' => $tenant->getKey(),
            'operator_organization_id' => $operator->getKey(),
            'name' => 'Site '.$code,
            'code' => $code,
            'timezone' => 'Asia/Manila',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'lifecycle_status' => SiteLifecycleStatus::Active,
            'is_public' => true,
            'published_at' => now('UTC'),
        ]);
    }
}
