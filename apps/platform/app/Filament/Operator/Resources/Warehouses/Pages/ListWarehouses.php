<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Warehouses\Pages;

use App\Filament\Operator\Resources\Warehouses\WarehouseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListWarehouses extends ListRecords
{
    protected static string $resource = WarehouseResource::class;

    /** @return array<CreateAction> */
    protected function getHeaderActions(): array
    {
        return [WarehouseResource::auditedCreateAction()];
    }
}
