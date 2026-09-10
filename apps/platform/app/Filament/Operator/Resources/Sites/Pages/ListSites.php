<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Sites\Pages;

use App\Filament\Operator\Resources\Sites\SiteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    /** @return array<CreateAction> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
