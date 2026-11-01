<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\InventoryAdjustments\Pages;

use App\Filament\Operator\Resources\InventoryAdjustments\InventoryAdjustmentResource;
use App\Filament\Shared\Actions\InventoryActions;
use Filament\Resources\Pages\ListRecords;

final class ListInventoryAdjustments extends ListRecords
{
    protected static string $resource = InventoryAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [InventoryActions::createAdjustment()];
    }
}
