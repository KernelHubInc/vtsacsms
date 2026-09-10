<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\InventoryAdjustments\Pages;

use App\Filament\Operator\Resources\InventoryAdjustments\InventoryAdjustmentResource;
use Filament\Resources\Pages\ListRecords;

final class ListInventoryAdjustments extends ListRecords
{
    protected static string $resource = InventoryAdjustmentResource::class;
}
