<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Permissions\Pages;

use App\Filament\Platform\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\ListRecords;

final class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;
}
