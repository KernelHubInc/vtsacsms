<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class RoleManager
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuthorizationService $authorization,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data, ?Role $role = null): Role
    {
        $tenantId = $this->currentTenant->get()->tenantId;
        if (! $this->authorization->allows($actor, PermissionKey::RoleManage)
            || ($role !== null && ($role->tenant_id !== $tenantId || $role->is_system))) {
            throw new AuthorizationException('Custom roles can only be managed within the active tenant.');
        }

        $validated = Validator::make($data, [
            'key' => ['required', 'string', 'max:80', Rule::unique('roles', 'key')->where('tenant_id', $tenantId)->ignore($role?->getKey())],
            'name' => ['required', 'string', 'max:120'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::enum(PermissionKey::class)],
        ])->validate();
        $permissions = [];
        foreach ($validated['permissions'] as $value) {
            if (! is_string($value)) {
                throw new \InvalidArgumentException('Permission keys must be strings.');
            }
            $permission = PermissionKey::from($value);
            if (! $this->authorization->allows($actor, $permission)) {
                throw new AuthorizationException('A role cannot grant authority the actor does not hold.');
            }
            $permissions[] = $permission->value;
        }

        return DB::transaction(function () use ($tenantId, $role, $validated, $permissions): Role {
            $before = null;
            if ($role !== null) {
                $role = Role::query()->lockForUpdate()->whereKey($role->getKey())->firstOrFail();
                if ($role->is_system) {
                    throw new AuthorizationException('System roles are immutable.');
                }
                $before = ['key' => $role->key, 'name' => $role->name, 'permissions' => $role->permissions()->pluck('permissions.key')->all()];
                $role->update(['key' => $validated['key'], 'name' => $validated['name']]);
            } else {
                $role = Role::query()->create([
                    'tenant_id' => $tenantId,
                    'key' => $validated['key'],
                    'name' => $validated['name'],
                    'is_system' => false,
                ]);
            }

            DB::table('role_permissions')->where('tenant_id', $tenantId)->where('role_id', $role->getKey())->delete();
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insert([
                    'tenant_id' => $tenantId,
                    'role_id' => $role->getKey(),
                    'permission_key' => $permission,
                    'created_at' => now('UTC'),
                ]);
            }
            $this->audit->record(new AuditEntry(
                $before === null ? 'identity.role.created' : 'identity.role.updated',
                'role',
                (string) $role->getKey(),
                AuditResult::Succeeded,
                before: $before,
                after: ['key' => $role->key, 'name' => $role->name, 'permissions' => $permissions],
            ));

            return $role;
        });
    }
}
