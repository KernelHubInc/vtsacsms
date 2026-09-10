<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations\Pages;

use App\Filament\Platform\Resources\Organizations\OrganizationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
