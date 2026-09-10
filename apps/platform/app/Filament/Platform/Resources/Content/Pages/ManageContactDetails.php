<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content\Pages;

use App\Filament\Platform\Resources\Content\ContactDetailResource;

final class ManageContactDetails extends ManageCmsRecords
{
    protected static string $resource = ContactDetailResource::class;
}
