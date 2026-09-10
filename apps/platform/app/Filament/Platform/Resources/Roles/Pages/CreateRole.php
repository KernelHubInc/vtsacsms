<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Roles\Pages;

use App\Filament\Platform\Resources\Roles\RoleResource;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $permissions = $this->permissions($data);
        $actor = $this->actor();
        foreach ($permissions as $permission) {
            if (! app(AuthorizationService::class)->allows($actor, $permission)) {
                throw new AuthorizationException('A role cannot grant authority the actor does not hold.');
            }
        }
        $tenantId = app(CurrentTenant::class)->get()->tenantId;

        return DB::transaction(function () use ($data, $permissions, $tenantId): Role {
            $role = Role::query()->create([
                'tenant_id' => $tenantId,
                'key' => $this->requiredString($data, 'key'),
                'name' => $this->requiredString($data, 'name'),
                'is_system' => false,
            ]);
            $this->writePermissions($role, $permissions);
            app(AuditRecorder::class)->record(new AuditEntry(
                'identity.role.created',
                'role',
                (string) $role->getKey(),
                AuditResult::Succeeded,
                after: ['key' => $role->key, 'name' => $role->name, 'permissions' => array_map(fn (PermissionKey $permission): string => $permission->value, $permissions)],
            ));

            return $role;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<PermissionKey>
     */
    private function permissions(array $data): array
    {
        $values = $data['permissions'] ?? null;
        if (! is_array($values)) {
            throw new \InvalidArgumentException('Role permissions are required.');
        }

        return array_values(array_map(
            fn (mixed $value): PermissionKey => PermissionKey::from($this->permissionString($value)),
            $values,
        ));
    }

    /** @param list<PermissionKey> $permissions */
    private function writePermissions(Role $role, array $permissions): void
    {
        foreach ($permissions as $permission) {
            DB::table('role_permissions')->insert([
                'tenant_id' => $role->tenant_id,
                'role_id' => $role->getKey(),
                'permission_key' => $permission->value,
                'created_at' => now('UTC'),
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("{$key} is required.");
        }

        return $value;
    }

    private function permissionString(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException('Permission keys must be strings.');
        }

        return $value;
    }

    private function actor(): User
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new \LogicException('A panel actor is required.');
        }

        return $user;
    }
}
