<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\CountPlans\Pages;

use App\Filament\Operator\Resources\CountPlans\CountPlanResource;
use App\Filament\Shared\Actions\InventoryActions;
use Filament\Resources\Pages\ListRecords;

final class ListCountPlans extends ListRecords
{
    protected static string $resource = CountPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [InventoryActions::createCount()];
    }
}
