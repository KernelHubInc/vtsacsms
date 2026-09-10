<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Application\PermissionCatalog;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\Models\RoleAssignment;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class TenantSecurityTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionCatalog::class)->sync();
    }

    protected function createTenant(string $slug, TenantStatus $status = TenantStatus::Active): Tenant
    {
        return Tenant::query()->create([
            'name' => Str::headline($slug),
            'slug' => $slug,
            'status' => $status,
        ]);
    }

    protected function createUser(): User
    {
        return User::factory()->create();
    }

    protected function tenantContext(Tenant $tenant, User $actor): TenantContext
    {
        return new TenantContext(
            tenantId: (string) $tenant->getKey(),
            actorType: ActorType::Human,
            actorId: $actor->public_id,
            correlationId: (string) Str::ulid(),
        );
    }

    protected function withinTenant(Tenant $tenant, User $actor, Closure $operation): mixed
    {
        return app(CurrentTenant::class)->run($this->tenantContext($tenant, $actor), $operation);
    }

    protected function createMembership(Tenant $tenant, User $user): Membership
    {
        return Membership::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'status' => MembershipStatus::Active,
            'joined_at' => now('UTC'),
        ]);
    }

    /**
     * @param  list<PermissionKey>  $permissions
     */
    protected function createRole(Tenant $tenant, string $key, array $permissions): Role
    {
        $role = Role::query()->create([
            'tenant_id' => $tenant->getKey(),
            'key' => $key,
            'name' => Str::headline($key),
        ]);

        foreach ($permissions as $permission) {
            DB::table('role_permissions')->insert([
                'tenant_id' => $tenant->getKey(),
                'role_id' => $role->getKey(),
                'permission_key' => $permission->value,
                'created_at' => now('UTC'),
            ]);
        }

        return $role;
    }

    protected function assignDirectly(
        Tenant $tenant,
        Membership $membership,
        Role $role,
        ?ResourceScope $scope = null,
    ): RoleAssignment {
        $scope ??= ResourceScope::tenant();

        return RoleAssignment::query()->create([
            'tenant_id' => $tenant->getKey(),
            'membership_id' => $membership->getKey(),
            'role_id' => $role->getKey(),
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
        ]);
    }
}
