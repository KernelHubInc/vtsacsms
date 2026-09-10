<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Tests\Support\TenantSecurityTestCase;

final class TenantSiteAccessTest extends TenantSecurityTestCase
{
    public function test_one_operator_cannot_access_another_operators_sites(): void
    {
        $user = $this->createUser();
        $tenantA = $this->createTenant('operator-a');
        $tenantB = $this->createTenant('operator-b');

        $siteA = $this->withinTenant($tenantA, $user, function () use ($tenantA, $user): Site {
            $organization = $this->createOperator($tenantA, 'OPA');
            $membership = $this->createMembership($tenantA, $user);
            $role = $this->createRole($tenantA, 'operator-viewer', [PermissionKey::LocationView]);
            $this->assignDirectly($tenantA, $membership, $role);

            return $this->createSite($tenantA, $organization, 'A-001');
        });
        $siteB = $this->withinTenant($tenantB, $user, function () use ($tenantB): Site {
            $organization = $this->createOperator($tenantB, 'OPB');

            return $this->createSite($tenantB, $organization, 'B-001');
        });

        $token = $this->login($user, $tenantA);

        $this->withToken($token)->getJson('/api/v1/sites')
            ->assertOk()
            ->assertJsonPath('data.0.id', $siteA->getKey())
            ->assertJsonMissing(['id' => $siteB->getKey()]);
        $this->withToken($token)->getJson('/api/v1/sites/'.$siteB->getKey())
            ->assertNotFound();
    }

    public function test_site_scoped_user_cannot_access_or_report_on_a_sibling_site(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('site-scope');

        [$siteA, $siteB] = $this->withinTenant($tenant, $user, function () use ($tenant, $user): array {
            $organization = $this->createOperator($tenant, 'OPS');
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'site-reporter', [
                PermissionKey::LocationView,
                PermissionKey::ReportingView,
                PermissionKey::ReportingExport,
            ]);
            $siteA = $this->createSite($tenant, $organization, 'S-001');
            $siteB = $this->createSite($tenant, $organization, 'S-002');
            $this->assignDirectly(
                $tenant,
                $membership,
                $role,
                new ResourceScope(ScopeType::Site, (string) $siteA->getKey()),
            );

            return [$siteA, $siteB];
        });

        $token = $this->login($user, $tenant);

        $this->withToken($token)->getJson('/api/v1/sites/'.$siteA->getKey())->assertOk();
        $this->withToken($token)->getJson('/api/v1/sites/'.$siteB->getKey())->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/reports/sites')
            ->assertOk()
            ->assertJsonPath('data.0.id', $siteA->getKey())
            ->assertJsonCount(1, 'data');
        $export = $this->withToken($token)->get('/api/v1/exports/sites')->assertOk();
        $export->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString((string) $siteA->getKey(), (string) $export->getContent());
        $this->assertStringNotContainsString((string) $siteB->getKey(), (string) $export->getContent());
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->getKey(),
            'action' => 'reporting.sites.exported',
        ]);
    }

    private function createOperator(Tenant $tenant, string $code): Organization
    {
        return Organization::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => "Operator {$code}",
            'code' => $code,
            'type' => OrganizationType::ChargePointOperator,
            'is_active' => true,
        ]);
    }

    private function createSite(Tenant $tenant, Organization $operator, string $code): Site
    {
        return Site::query()->create([
            'tenant_id' => $tenant->getKey(),
            'operator_organization_id' => $operator->getKey(),
            'name' => "Site {$code}",
            'code' => $code,
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
