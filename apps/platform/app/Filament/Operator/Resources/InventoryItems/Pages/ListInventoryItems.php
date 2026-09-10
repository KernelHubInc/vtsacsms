<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\InventoryItems\Pages;

use App\Filament\Operator\Resources\InventoryItems\InventoryItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListInventoryItems extends ListRecords
{
    protected static string $resource = InventoryItemResource::class;

    /** @return array<CreateAction> */
    protected function getHeaderActions(): array
    {
        return [InventoryItemResource::auditedCreateAction()];
    }
}
