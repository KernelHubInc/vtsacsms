<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content\Pages;

use App\Filament\Platform\Resources\Content\RedirectResource;

final class ManageRedirects extends ManageCmsRecords
{
    protected static string $resource = RedirectResource::class;
}
