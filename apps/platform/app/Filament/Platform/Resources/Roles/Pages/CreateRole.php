<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Roles\Pages;

use App\Filament\Platform\Resources\Roles\RolePermissionFields;
use App\Filament\Platform\Resources\Roles\RoleResource;
use App\Models\User;
use App\Modules\Organizations\Application\RoleManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new \LogicException('A panel actor is required.');
        }

        return app(RoleManager::class)->save($actor, RolePermissionFields::flatten($data));
    }
}
