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
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $role = $this->getRecord();
        if (! $role instanceof Role) {
            throw new \LogicException('A role record is required.');
        }
        $data['permissions'] = DB::table('role_permissions')
            ->where('tenant_id', $role->tenant_id)
            ->where('role_id', $role->getKey())
            ->pluck('permission_key')
            ->all();

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Role || $record->is_system) {
            throw new AuthorizationException('System roles are immutable.');
        }
        $values = $data['permissions'] ?? null;
        if (! is_array($values)) {
            throw new \InvalidArgumentException('Role permissions are required.');
        }
        $permissions = array_values(array_map(function (mixed $value): PermissionKey {
            if (! is_string($value)) {
                throw new \InvalidArgumentException('Permission keys must be strings.');
            }

            return PermissionKey::from($value);
        }, $values));
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new \LogicException('A panel actor is required.');
        }
        foreach ($permissions as $permission) {
            if (! app(AuthorizationService::class)->allows($actor, $permission)) {
                throw new AuthorizationException('A role cannot grant authority the actor does not hold.');
            }
        }
        $before = $record->toArray();

        return DB::transaction(function () use ($record, $data, $permissions, $before): Role {
            $record->update(['key' => $data['key'], 'name' => $data['name']]);
            DB::table('role_permissions')
                ->where('tenant_id', $record->tenant_id)
                ->where('role_id', $record->getKey())
                ->delete();
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insert([
                    'tenant_id' => $record->tenant_id,
                    'role_id' => $record->getKey(),
                    'permission_key' => $permission->value,
                    'created_at' => now('UTC'),
                ]);
            }
            app(AuditRecorder::class)->record(new AuditEntry(
                'identity.role.updated',
                'role',
                (string) $record->getKey(),
                AuditResult::Succeeded,
                before: $before,
                after: ['key' => $record->key, 'name' => $record->name, 'permissions' => array_map(fn (PermissionKey $permission): string => $permission->value, $permissions)],
            ));

            return $record;
        });
    }
}
