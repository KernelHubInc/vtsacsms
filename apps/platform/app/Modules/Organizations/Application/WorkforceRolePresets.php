<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Organizations\Domain\WorkforceRolePreset;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WorkforceRolePresets
{
    public function __construct(private CurrentTenant $tenant, private AuditRecorder $audit) {}

    public function assertScope(Role $role, ResourceScope $scope): void
    {
        $preset = $role->is_system ? WorkforceRolePreset::tryFrom((string) $role->key) : null;
        if ($preset !== null && (! app()->environment('staging') || ! $preset->supportsScope($scope->type))) {
            throw new AuthorizationException('This preset is unavailable at the selected access scope.');
        }
        if ($preset !== null && $scope->type === ScopeType::Organization
            && ! Organization::query()->whereKey($scope->id)->where('type', OrganizationType::ChargePointOperator->value)->where('is_active', true)->exists()) {
            throw new AuthorizationException('This preset must be assigned to an active charging operator.');
        }
    }

    public function sync(): void
    {
        if (! app()->environment('staging')) {
            return;
        }

        Tenant::query()->where('status', TenantStatus::Active->value)->eachById(function (Tenant $tenant): void {
            $tenantId = (string) $tenant->getKey();
            $this->tenant->run(new TenantContext($tenantId, ActorType::Platform, null, (string) Str::ulid()), function () use ($tenantId): void {
                DB::transaction(function () use ($tenantId): void {
                    foreach (WorkforceRolePreset::cases() as $preset) {
                        $role = Role::query()->firstOrCreate(['key' => $preset->value], ['name' => $preset->label().' (Preset)', 'is_system' => true]);
                        if (! $role->is_system) {
                            throw new \RuntimeException('A custom role uses reserved preset key '.$preset->value);
                        }
                        $grants = DB::table('role_permissions')->where('tenant_id', $tenantId)->where('role_id', $role->getKey());
                        $keys = array_map(static fn (PermissionKey $permission): string => $permission->value, $preset->permissions());
                        $before = $grants->pluck('permission_key')->all();
                        if (array_diff($before, $keys) === [] && array_diff($keys, $before) === []) {
                            continue;
                        }
                        $grants->whereNotIn('permission_key', $keys)->delete();
                        DB::table('role_permissions')->insertOrIgnore(array_map(
                            static fn (string $key): array => ['tenant_id' => $tenantId, 'role_id' => $role->getKey(),
                                'permission_key' => $key, 'created_at' => now('UTC')],
                            $keys,
                        ));
                        $this->audit->record(new AuditEntry(
                            'identity.role_preset.synchronized', 'role', (string) $role->getKey(), AuditResult::Succeeded,
                            before: ['permissions' => $before], after: ['permissions' => $keys],
                        ));
                    }
                });
            });
        });
    }
}
