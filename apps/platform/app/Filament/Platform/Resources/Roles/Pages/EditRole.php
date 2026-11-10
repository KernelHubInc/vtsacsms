<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Roles\Pages;

use App\Filament\Platform\Resources\Roles\RolePermissionFields;
use App\Filament\Platform\Resources\Roles\RoleResource;
use App\Models\User;
use App\Modules\Organizations\Application\RoleManager;
use App\Modules\Organizations\Domain\Models\Role;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    public function getSubheading(): string
    {
        $role = $this->role();
        $assignments = $role->assignments()->count();
        $members = $role->assignments()->distinct()->count('membership_id');

        return "Assigned memberships: {$members} · Role assignments: {$assignments}. Saving updates this role for all assignees; other roles may still grant access.";
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['module_permissions'] = RolePermissionFields::group($this->role()->permissions()->pluck('permissions.key')->all());

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $record instanceof Role) {
            throw new \LogicException('A panel actor and role are required.');
        }

        return app(RoleManager::class)->save($actor, RolePermissionFields::flatten($data), $record);
    }

    private function role(): Role
    {
        $role = $this->getRecord();
        if (! $role instanceof Role) {
            throw new \LogicException('A role record is required.');
        }

        return $role;
    }
}
