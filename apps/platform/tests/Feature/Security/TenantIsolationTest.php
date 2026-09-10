<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\Exceptions\TenantMismatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantSecurityTestCase;

final class TenantIsolationTest extends TenantSecurityTestCase
{
    public function test_queries_fail_closed_and_never_return_another_tenants_rows(): void
    {
        $actor = $this->createUser();
        $tenantA = $this->createTenant('north-network');
        $tenantB = $this->createTenant('south-network');
        $organizationA = $this->withinTenant(
            $tenantA,
            $actor,
            fn (): Organization => Organization::query()->create([
                'name' => 'Shared site host',
                'code' => 'HOST-01',
            ]),
        );
        $organizationB = $this->withinTenant(
            $tenantB,
            $actor,
            fn (): Organization => Organization::query()->create([
                'name' => 'Shared site host',
                'code' => 'HOST-01',
            ]),
        );

        $this->assertSame(0, Organization::query()->count());

        $this->withinTenant($tenantA, $actor, function () use ($organizationA, $organizationB): void {
            $this->assertSame([$organizationA->getKey()], Organization::query()->pluck('id')->all());
            $this->assertNull(Organization::query()->find($organizationB->getKey()));
        });

        $this->assertFalse(app(CurrentTenant::class)->has());
    }

    public function test_cross_tenant_model_writes_are_rejected_before_persistence(): void
    {
        $actor = $this->createUser();
        $tenantA = $this->createTenant('tenant-a');
        $tenantB = $this->createTenant('tenant-b');

        $this->expectException(TenantMismatch::class);

        $this->withinTenant($tenantA, $actor, fn (): Organization => Organization::query()->create([
            'tenant_id' => $tenantB->getKey(),
            'name' => 'Injected organization',
            'code' => 'INJECTED',
        ]));
    }

    public function test_composite_foreign_key_rejects_cross_tenant_parent_reference(): void
    {
        $actor = $this->createUser();
        $tenantA = $this->createTenant('tenant-a');
        $tenantB = $this->createTenant('tenant-b');
        $parentB = $this->withinTenant(
            $tenantB,
            $actor,
            fn (): Organization => Organization::query()->create([
                'name' => 'Foreign parent',
                'code' => 'PARENT',
            ]),
        );

        $this->expectException(QueryException::class);

        DB::table('organizations')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantA->getKey(),
            'parent_id' => $parentB->getKey(),
            'name' => 'Cross tenant child',
            'code' => 'CHILD',
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
